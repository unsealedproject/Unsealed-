<?php
/**
 * api_ai.php — Anthropic API proxy
 * The Best Interests of Who Foundation / Unsealed
 *
 * WHY THIS EXISTS:
 * Direct browser-to-Anthropic calls require the API key in client JS
 * (a security risk) and fail on Tor/.onion due to CORS/network policy.
 * This proxy keeps the key server-side and forwards requests.
 *
 * SETUP:
 * Add your Anthropic API key in ONE of these places (checked in order):
 *
 *   1. /etc/fca/api_keys.env   →   ANTHROPIC_API_KEY=sk-ant-...
 *   2. config.php (same dir)   →   <?php define('ANTHROPIC_KEY','sk-ant-...');
 *   3. Environment variable     →   export ANTHROPIC_API_KEY=sk-ant-...
 *
 * Deploy: copy this file to your webroot alongside the platform files.
 * Nginx: no extra config needed — PHP-FPM handles .php files already.
 */

// ── CORS / METHOD ─────────────────────────────────────────────
// Only accept requests from our own origin. This endpoint costs money per
// call, so an unrestricted CORS policy = open AI gateway that any third-
// party site could drive at our expense.
header('Content-Type: application/json');
$allowed_origins = ['https://unsealed.is', 'https://www.unsealed.is'];
$req_origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($req_origin, $allowed_origins, true)) {
    header('Access-Control-Allow-Origin: ' . $req_origin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200); exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// ── LOAD API KEY ──────────────────────────────────────────────
$api_key = '';

// 1. /etc/fca/api_keys.env
$env_file = '/etc/fca/api_keys.env';
if (!$api_key && file_exists($env_file) && is_readable($env_file)) {
    foreach (file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos($line, 'ANTHROPIC_API_KEY=') === 0) {
            $api_key = trim(substr($line, strlen('ANTHROPIC_API_KEY=')));
            break;
        }
    }
}

// 2. config.php in same directory
if (!$api_key) {
    $cfg = __DIR__ . '/config.php';
    if (file_exists($cfg)) {
        include $cfg;
        if (defined('ANTHROPIC_KEY')) $api_key = ANTHROPIC_KEY;
    }
}

// 3. Server environment variable
if (!$api_key) {
    $api_key = getenv('ANTHROPIC_API_KEY') ?: '';
}

if (!$api_key) {
    http_response_code(503);
    echo json_encode([
        'error' => 'API key not configured on server.',
        'setup' => 'Add ANTHROPIC_API_KEY=sk-ant-... to /etc/fca/api_keys.env and restart PHP-FPM.'
    ]);
    exit;
}

// ── RATE LIMIT ────────────────────────────────────────────────
// fca_rate_ok() uses REMOTE_ADDR only (ignores X-Forwarded-For) and
// keys the counter file with HMAC-SHA256 + an on-disk server secret.
require_once __DIR__ . '/api_keys.php';
if (!fca_rate_ok('ai', 60)) {
    http_response_code(429);
    echo json_encode(['error' => 'Rate limit: 60 AI requests per hour per IP.']);
    exit;
}

// ── READ + VALIDATE BODY ──────────────────────────────────────
$raw = file_get_contents('php://input', false, null, 0, 131072); // 128KB max
if (!$raw) {
    http_response_code(400);
    echo json_encode(['error' => 'Empty request body']);
    exit;
}

$body = json_decode($raw, true);
if (!$body || !isset($body['messages']) || !is_array($body['messages'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON — messages array required']);
    exit;
}

// Enforce caller identity + reasonable caps. The caller must tag their
// request with a known task ID we're willing to pay tokens for. This stops
// the endpoint from being an open AI gateway that any third party can abuse.
$ALLOWED_TASKS = [
    'rep_letter'       => 1200,  // legislator-contact letter drafting
    'complaint'        => 1500,  // judicial complaint drafting
    'summary'          => 1200,  // case-summary rewriting
    'factsheet'        => 1500,  // one-page factsheet generation
];
$task = (string)($body['task'] ?? '');
if (!array_key_exists($task, $ALLOWED_TASKS)) {
    http_response_code(400);
    echo json_encode(['error'=>'Valid task required','allowed'=>array_keys($ALLOWED_TASKS)]);
    exit;
}
$maxTokCap = $ALLOWED_TASKS[$task];

// Enforce safe defaults. Tokens capped per task to stop runaway billing.
$payload = [
    'model'      => 'claude-sonnet-4-20250514',
    'max_tokens' => min((int)($body['max_tokens'] ?? $maxTokCap), $maxTokCap),
    'messages'   => $body['messages'],
];
if (isset($body['system']))      $payload['system']      = (string)$body['system'];
if (isset($body['temperature'])) $payload['temperature'] = max(0.0, min((float)$body['temperature'], 1.0));

// ── FORWARD TO ANTHROPIC ──────────────────────────────────────
// Dropped the 'anthropic-dangerous-direct-browser-access' header — that was
// copy-pasted from a browser-side example and only exists to bypass CORS
// for browser callers. We are server-to-server; this header actively
// disables a defense and shouldn't be here.
$ctx = stream_context_create([
    'http' => [
        'method'        => 'POST',
        'header'        => implode("\r\n", [
            'Content-Type: application/json',
            'x-api-key: ' . $api_key,
            'anthropic-version: 2023-06-01',
        ]),
        'content'       => json_encode($payload),
        'timeout'       => 60,
        'ignore_errors' => true,
    ],
]);

$result = @file_get_contents('https://api.anthropic.com/v1/messages', false, $ctx);

// Pass through Anthropic's status code — but collapse 4xx/5xx to generic
// error (upstream detail can leak server state).
$status = 200;
foreach ($http_response_header ?? [] as $h) {
    if (preg_match('/^HTTP\/\S+\s+(\d+)/', $h, $m)) {
        $status = (int)$m[1];
    }
}

if ($result === false) {
    http_response_code(502);
    echo json_encode(['error'=>'AI upstream unreachable']);
    exit;
}
if ($status >= 400) {
    // Log the upstream body (which may contain key-state info) but don't
    // leak it to the client — return a normalized generic error.
    error_log('api_ai upstream status=' . $status . ' body=' . substr($result, 0, 500));
    http_response_code(502);
    echo json_encode(['error'=>'AI upstream error','status'=>$status]);
    exit;
}

http_response_code(200);
echo $result;
