<?php
/**
 * api_token.php — token-based read / update / delete for submissions.
 *
 * The token generated at submission time is the ONLY credential for this
 * endpoint. No email, no account, no password. If you have the token, you can
 * view, edit, or delete your own submission. If you lose it, nobody (including
 * us) can recover your submission — that's the zero-knowledge guarantee.
 *
 * Ops:
 *   GET  ?op=get&token=<hex32>              → returns current case_data
 *   POST ?op=update  body: {token, case}    → replaces case_data (keeps id/token)
 *   POST ?op=delete  body: {token}          → hard-deletes the row
 *
 * Rate-limit per IP: 60/hr (same as other write endpoints).
 * Tokens are opaque hex strings; we look them up as-is, no further auth.
 */

define('DB_PATH', '/var/www/fca/data/submissions.db');
define('RATE_LIMIT', 60);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') { http_response_code(200); exit; }

// Per-IP rate limit. IPs are not stored — REMOTE_ADDR is HMAC-hashed into
// a per-hour temp file. We ignore X-Forwarded-For on purpose (no trusted
// proxy in front of nginx).
require_once __DIR__ . '/api_keys.php';
if (!fca_rate_ok('tok', RATE_LIMIT)) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'Rate limit — try again later.']);
    exit;
}

function getDB(): SQLite3 {
    $db = new SQLite3(DB_PATH, SQLITE3_OPEN_READWRITE);
    $db->busyTimeout(3000);
    return $db;
}

function sanitize_token(string $raw): string {
    // Accept with or without dashes; strip everything that isn't a-f0-9.
    $t = strtolower(preg_replace('/[^a-fA-F0-9]/', '', $raw));
    return (strlen($t) === 32) ? $t : '';
}

$op = preg_replace('/[^a-z]/', '', (string)($_GET['op'] ?? ($method === 'POST' ? 'update' : 'get')));

try {
    $db = getDB();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB unavailable']);
    exit;
}

if ($op === 'get') {
    $token = sanitize_token((string)($_GET['token'] ?? ''));
    if (!$token) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'Valid token required.']); exit; }
    $stmt = $db->prepare('SELECT state, year, case_data, created_at, release_at FROM submissions WHERE token = :t LIMIT 1');
    $stmt->bindValue(':t', $token, SQLITE3_TEXT);
    $res = $stmt->execute();
    $row = $res ? $res->fetchArray(SQLITE3_ASSOC) : null;
    if (!$row) { http_response_code(404); echo json_encode(['ok' => false, 'error' => 'Token not found.']); exit; }
    $data     = json_decode($row['case_data'] ?? '{}', true) ?: [];
    $released = empty($row['release_at']) || strtotime($row['release_at']) <= time();
    echo json_encode([
        'ok'        => true,
        'state'     => $row['state'],
        'year'      => (int)$row['year'],
        'created'   => $row['created_at'],
        'releaseAt' => $row['release_at'],
        'released'  => $released,
        'case'      => $data,
    ]);
    exit;
}

if ($op === 'delete') {
    $body  = json_decode(file_get_contents('php://input'), true) ?: [];
    $token = sanitize_token((string)($body['token'] ?? $_GET['token'] ?? ''));
    if (!$token) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'Valid token required.']); exit; }
    $stmt = $db->prepare('DELETE FROM submissions WHERE token = :t');
    $stmt->bindValue(':t', $token, SQLITE3_TEXT);
    $stmt->execute();
    $affected = $db->changes();
    if ($affected === 0) { http_response_code(404); echo json_encode(['ok' => false, 'error' => 'Token not found.']); exit; }
    echo json_encode(['ok' => true, 'deleted' => true]);
    exit;
}

if ($op === 'update') {
    $body  = json_decode(file_get_contents('php://input'), true) ?: [];
    $token = sanitize_token((string)($body['token'] ?? ''));
    $case  = $body['case'] ?? null;
    if (!$token) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'Valid token required.']); exit; }
    if (!$case || !is_array($case) || empty($case['state'])) {
        http_response_code(400); echo json_encode(['ok' => false, 'error' => 'Case payload required with state.']); exit;
    }
    // Scrub the same way api_submit.php does so payloads can't smuggle PHP/HTML.
    $clean = [];
    foreach ($case as $k => $v) {
        if (strlen((string)$k) > 100) continue;
        $clean[htmlspecialchars((string)$k, ENT_QUOTES, 'UTF-8')] =
            is_string($v) ? htmlspecialchars(substr($v, 0, 10000), ENT_QUOTES, 'UTF-8') : $v;
    }
    $clean['_token']    = $token;           // preserve
    $clean['_editedAt'] = gmdate('c');      // mark that it was edited

    $stmt = $db->prepare('UPDATE submissions SET state = :s, year = :y, case_data = :d WHERE token = :t');
    $stmt->bindValue(':s', $clean['state'], SQLITE3_TEXT);
    $stmt->bindValue(':y', (int)($clean['year'] ?? 0), SQLITE3_INTEGER);
    $stmt->bindValue(':d', json_encode($clean), SQLITE3_TEXT);
    $stmt->bindValue(':t', $token, SQLITE3_TEXT);
    $stmt->execute();
    $affected = $db->changes();
    if ($affected === 0) { http_response_code(404); echo json_encode(['ok' => false, 'error' => 'Token not found.']); exit; }
    echo json_encode(['ok' => true, 'updated' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Unknown op. Use get | update | delete.']);
