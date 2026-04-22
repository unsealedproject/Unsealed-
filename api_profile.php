<?php
/**
 * api_profile.php — Unsealed / Best Interests of Who Foundation
 * Receives profile contribution submissions from the platform.
 * All submissions go into a review queue — nothing is published automatically.
 *
 * POST /api_profile.php
 * Body: { profiles: [...], timestamp: unix_ms }
 *
 * Each profile object contains:
 *   entityType: judge | attorney | gal | pc | evaluator | therapist | expert
 *   source: required verified URL
 *   name: full name with credentials
 *   state / county / court: jurisdiction
 *   phone, email, web: contact info
 *   barnum / license: verifiable credential numbers
 *   Plus type-specific fields (afcc, rate, caseload, etc.)
 *   caseTimestamp: unix ms when case was submitted
 *
 * Review queue: /data/profile_queue.json (readable only by admin)
 * Email alert: configured below
 */

// ── CONFIG ─────────────────────────────────────────────────────
define('QUEUE_FILE',  __DIR__ . '/data/profile_queue.json');
define('ADMIN_EMAIL', 'unsealedproject@proton.me');
define('RATE_LIMIT',  20);   // max submissions per IP per hour
define('MAX_SIZE',    65536); // 64KB max body size

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// ── RATE LIMIT ─────────────────────────────────────────────────
$ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$ip = preg_replace('/[^0-9a-fA-F.:,]/', '', explode(',', $ip)[0]);

$rl_file = sys_get_temp_dir() . '/unsealed_profile_rl_' . md5($ip) . '.json';
$rl_data = file_exists($rl_file) ? json_decode(file_get_contents($rl_file), true) : ['count' => 0, 'hour' => date('YmdH')];
if ($rl_data['hour'] !== date('YmdH')) { $rl_data = ['count' => 0, 'hour' => date('YmdH')]; }
if ($rl_data['count'] >= RATE_LIMIT) {
    http_response_code(429);
    echo json_encode(['error' => 'Rate limit exceeded — please try again later.']);
    exit;
}
$rl_data['count']++;
file_put_contents($rl_file, json_encode($rl_data), LOCK_EX);

// ── READ BODY ──────────────────────────────────────────────────
$raw = file_get_contents('php://input', false, null, 0, MAX_SIZE);
if (!$raw || strlen($raw) > MAX_SIZE) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid or oversized request body']);
    exit;
}

$body = json_decode($raw, true);
if (!$body || !isset($body['profiles']) || !is_array($body['profiles'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON or missing profiles array']);
    exit;
}

$profiles = $body['profiles'];
if (empty($profiles)) {
    echo json_encode(['ok' => true, 'queued' => 0, 'message' => 'No profiles to queue']);
    exit;
}

// ── SANITIZE + VALIDATE EACH PROFILE ──────────────────────────
$valid_types = ['judge', 'attorney', 'gal', 'pc', 'evaluator', 'therapist', 'expert'];

function sanitize_str($v) {
    return htmlspecialchars(trim((string)($v ?? '')), ENT_QUOTES, 'UTF-8');
}

function sanitize_url($v) {
    $u = filter_var(trim((string)($v ?? '')), FILTER_SANITIZE_URL);
    return (filter_var($u, FILTER_VALIDATE_URL) && preg_match('/^https?:\/\//', $u)) ? $u : '';
}

$queued = [];
$skipped = 0;

foreach ($profiles as $pr) {
    if (!is_array($pr)) { $skipped++; continue; }

    $type   = sanitize_str($pr['entityType'] ?? '');
    $source = sanitize_url($pr['source'] ?? '');
    $name   = sanitize_str($pr['name'] ?? '');

    if (!in_array($type, $valid_types)) { $skipped++; continue; }
    if (!$source && !$name) { $skipped++; continue; }  // need at least one

    // Build clean profile record
    $clean = [
        'id'          => bin2hex(random_bytes(8)),
        'entityType'  => $type,
        'status'      => 'pending_review',
        'receivedAt'  => date('c'),
        'source'      => $source,
        'name'        => $name,
    ];

    // Copy all sanitized string fields, reject anything suspicious
    $allowed_keys = [
        'state','county','court','phone','email','web','barnum','year',
        'address','lawschool','prior','dirurl','jtcurl','notes',  // judge
        'firm','role','afcc',                                      // attorney
        'org','creds','rate','caseload','cert','license',          // gal/pc/eval
        'licenseType','practice','referral',                       // therapist
        'area','fee','side','pubs','count','type',                 // expert/eval
        'appt','dirUrl'
    ];

    foreach ($allowed_keys as $key) {
        if (isset($pr[$key])) {
            $val = in_array($key, ['web','source','dirurl','jtcurl','dirUrl','pubs'])
                ? sanitize_url($pr[$key])
                : sanitize_str($pr[$key]);
            if ($val !== '') $clean[$key] = $val;
        }
    }

    // Preserve the case timestamp if provided
    if (isset($pr['caseTimestamp']) && is_numeric($pr['caseTimestamp'])) {
        $clean['caseTimestamp'] = (int)$pr['caseTimestamp'];
    }

    $queued[] = $clean;
}

if (empty($queued)) {
    http_response_code(400);
    echo json_encode(['error' => 'No valid profiles after validation', 'skipped' => $skipped]);
    exit;
}

// ── SAVE TO QUEUE ──────────────────────────────────────────────
$data_dir = dirname(QUEUE_FILE);
if (!is_dir($data_dir)) {
    mkdir($data_dir, 0750, true);
}

// Protect the data directory
$htaccess = $data_dir . '/.htaccess';
if (!file_exists($htaccess)) {
    file_put_contents($htaccess, "Deny from all\n");
}

$existing = [];
if (file_exists(QUEUE_FILE)) {
    $existing = json_decode(file_get_contents(QUEUE_FILE), true) ?? [];
}

array_push($existing, ...$queued);

// Keep most recent 5000 pending entries
if (count($existing) > 5000) {
    $existing = array_slice($existing, -5000);
}

$written = file_put_contents(QUEUE_FILE, json_encode($existing, JSON_PRETTY_PRINT), LOCK_EX);
if ($written === false) {
    error_log('api_profile.php: could not write queue file');
    http_response_code(500);
    echo json_encode(['error' => 'Could not save profiles — check server permissions']);
    exit;
}

// ── EMAIL ALERT (optional — requires sendmail configured) ──────
if (ADMIN_EMAIL && count($existing) % 10 === 0) {  // Alert every 10 new profiles
    $subject = '[Unsealed] New profile submissions in queue: ' . count($existing) . ' total';
    $body    = "New profile contributions received.\n\nTotal in queue: " . count($existing) .
               "\nLatest type: " . ($queued[0]['entityType'] ?? 'unknown') .
               "\n\nReview at: /admin or directly in data/profile_queue.json";
    @mail(ADMIN_EMAIL, $subject, $body, 'From: noreply@unsealed.is');
}

// ── RESPOND ────────────────────────────────────────────────────
http_response_code(200);
echo json_encode([
    'ok'      => true,
    'queued'  => count($queued),
    'skipped' => $skipped,
    'total'   => count($existing),
    'message' => 'Profile contributions received — under review before publishing. Thank you.'
]);
