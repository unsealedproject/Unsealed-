<?php
/**
 * api_press.php — journalist inquiry intake.
 *
 * Queues incoming inquiries from the Journalists tab into an append-only
 * SQLite table. The operator retrieves them via a token-protected admin
 * script (see _press_inbox.py) — no public "recent inquiries" feed exists.
 *
 * Privacy model:
 *   - No IP address stored. Only an IP hash bucketed per-hour for rate
 *     limiting (same pattern as api_submit.php / api_token.php).
 *   - No cookies, no tracking pixels, no third-party analytics.
 *   - Body fields are htmlspecialchars-scrubbed before insert.
 *   - Rate limit: 10 inquiries per IP per hour (journalists submitting
 *     more than that should escalate to a direct channel).
 *
 * Ops: POST {outlet, name, reply, topic, detail}
 */

define('DB_PATH',   '/var/www/fca/data/press_inquiries.db');
define('MAX_BODY',  16384);
define('RATE_LIMIT', 10);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') { http_response_code(200); exit; }
if ($method !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'Method not allowed']); exit; }

// Rate limit (per-hour bucket). Uses REMOTE_ADDR only (ignores XFF) +
// HMAC-SHA256 counter-file key. See api_keys.php / fca_rate_ok().
require_once __DIR__ . '/api_keys.php';
if (!fca_rate_ok('press', RATE_LIMIT)) {
    http_response_code(429);
    echo json_encode(['ok'=>false,'error'=>'Rate limit: 10 inquiries per hour.']);
    exit;
}

$raw = file_get_contents('php://input', false, null, 0, MAX_BODY);
$body = $raw ? json_decode($raw, true) : null;
if (!$body || !is_array($body)) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Invalid JSON']); exit; }

$fields = ['outlet','name','reply','topic','detail'];
$clean = [];
foreach ($fields as $f) {
    $v = $body[$f] ?? '';
    if (!is_string($v)) $v = '';
    $clean[$f] = htmlspecialchars(substr($v, 0, 4000), ENT_QUOTES, 'UTF-8');
}
if ($clean['outlet'] === '' || $clean['name'] === '' || $clean['reply'] === '' || $clean['detail'] === '') {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'outlet, name, reply, detail are required']);
    exit;
}

try {
    $dir = dirname(DB_PATH);
    if (!is_dir($dir)) mkdir($dir, 0750, true);
    $ht = $dir . '/.htaccess';
    if (!file_exists($ht)) file_put_contents($ht, "Deny from all\n");
    $db = new SQLite3(DB_PATH);
    $db->busyTimeout(3000);
    $db->exec('PRAGMA journal_mode=WAL;');
    $db->exec('CREATE TABLE IF NOT EXISTS inquiries(id INTEGER PRIMARY KEY AUTOINCREMENT, outlet TEXT, name TEXT, reply TEXT, topic TEXT, detail TEXT, created_at TEXT NOT NULL)');
    $stmt = $db->prepare('INSERT INTO inquiries(outlet,name,reply,topic,detail,created_at) VALUES(:o,:n,:r,:t,:d,:ts)');
    $stmt->bindValue(':o',  $clean['outlet'], SQLITE3_TEXT);
    $stmt->bindValue(':n',  $clean['name'],   SQLITE3_TEXT);
    $stmt->bindValue(':r',  $clean['reply'],  SQLITE3_TEXT);
    $stmt->bindValue(':t',  $clean['topic'],  SQLITE3_TEXT);
    $stmt->bindValue(':d',  $clean['detail'], SQLITE3_TEXT);
    $stmt->bindValue(':ts', gmdate('Y-m-d H:i:s'), SQLITE3_TEXT);
    $stmt->execute();
} catch (Throwable $e) {
    error_log('api_press insert: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'Storage error — please try again.']);
    exit;
}

echo json_encode(['ok'=>true, 'queued'=>true]);
