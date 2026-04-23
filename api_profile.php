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
// Queue lives in SQLite so concurrent POSTs don't race (previously a JSON
// file with LOCK_EX on writes — but LOCK_EX doesn't help across a
// read-decode-append-encode-write cycle; concurrent callers could both
// read the same snapshot and one would clobber the other's entries).
define('QUEUE_DB',    __DIR__ . '/data/profile_queue.db');
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
// REMOTE_ADDR only (XFF ignored), HMAC-SHA256 counter key.
require_once __DIR__ . '/api_keys.php';
if (!fca_rate_ok('profile', RATE_LIMIT)) {
    http_response_code(429);
    echo json_encode(['error' => 'Rate limit exceeded — please try again later.']);
    exit;
}

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
// The /data/ directory is blocked at the nginx level (fca-security.conf
// returns 404 for any /data/* request) so the DB file is not reachable
// via the web. No .htaccess shim needed — nginx doesn't read those.
$data_dir = dirname(QUEUE_DB);
if (!is_dir($data_dir)) {
    mkdir($data_dir, 0750, true);
}

try {
    $qdb = new SQLite3(QUEUE_DB);
    $qdb->busyTimeout(5000);
    $qdb->exec('PRAGMA journal_mode=WAL;');
    $qdb->exec('CREATE TABLE IF NOT EXISTS profile_queue (
        id TEXT PRIMARY KEY NOT NULL,
        payload TEXT NOT NULL,
        received_at INTEGER NOT NULL
    )');
} catch (Throwable $e) {
    error_log('api_profile.php: queue DB open failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server storage unavailable']);
    exit;
}

// Insert each queued profile atomically — SQLite handles concurrent
// writers via the WAL + busyTimeout combination above. No race.
$stmt = $qdb->prepare('INSERT OR IGNORE INTO profile_queue (id, payload, received_at) VALUES (:id, :pl, :t)');
$now  = time();
$inserted = 0;
foreach ($queued as $clean) {
    $stmt->bindValue(':id', $clean['id'],              SQLITE3_TEXT);
    $stmt->bindValue(':pl', json_encode($clean),       SQLITE3_TEXT);
    $stmt->bindValue(':t',  $now,                      SQLITE3_INTEGER);
    if (@$stmt->execute()) $inserted++;
    $stmt->reset();
}

// Trim to the most recent 5000 pending entries (rolling cap).
@$qdb->exec('DELETE FROM profile_queue WHERE id NOT IN (
    SELECT id FROM profile_queue ORDER BY received_at DESC LIMIT 5000
)');

$totalRow = $qdb->querySingle('SELECT COUNT(*) AS c FROM profile_queue', true);
$total = (int)($totalRow['c'] ?? 0);

// ── EMAIL ALERT (optional — requires sendmail configured) ──────
if (ADMIN_EMAIL && $total > 0 && $total % 10 === 0) {  // Alert every 10 new profiles
    $subject = '[Unsealed] New profile submissions in queue: ' . $total . ' total';
    $body    = "New profile contributions received.\n\nTotal in queue: " . $total .
               "\nLatest type: " . ($queued[0]['entityType'] ?? 'unknown') .
               "\n\nReview the queue DB at " . QUEUE_DB;
    @mail(ADMIN_EMAIL, $subject, $body, 'From: noreply@unsealed.is');
}

// ── RESPOND ────────────────────────────────────────────────────
http_response_code(200);
echo json_encode([
    'ok'      => true,
    'queued'  => $inserted,
    'skipped' => $skipped,
    'total'   => $total,
    'message' => 'Profile contributions received — under review before publishing. Thank you.'
]);
