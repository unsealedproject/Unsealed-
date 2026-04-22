<?php
// api_submit.php — zero-knowledge submission endpoint with randomized release.
//
// Privacy design: a court or opposing party who sees a case filed on day X
// could correlate a submission timestamped day X to the filer. To break that
// correlation, every new submission is stamped with a RANDOMIZED release_at
// between 24 and 168 hours in the future. Only submissions whose release
// window has passed are included in the public GET feed. Published timestamps
// are further bucketed to ISO-week (not day) so that even after release, the
// submission can't be pinned to a specific court date.
//
// The submitter's own edit/delete flow (via /api_token.php) is immediate and
// unaffected by the release window — they can always see their own entry.

define('DB_PATH',   '/var/www/fca/data/submissions.db');
define('MAX_BODY',  524288);
define('RATE_LIMIT', 30);
// Privacy release window: 1 to 3 weeks (168–504 hours). Wider spread gives
// better cover than the original 1–7 day window — a court can't reasonably
// correlate "walked out of court today" to "appeared in dataset up to 3
// weeks later" when other submissions from other states also entered the
// queue on their own randomized schedules.
define('RELEASE_MIN_HOURS', 168);
define('RELEASE_MAX_HOURS', 504);

if (php_sapi_name() === 'cli') {
    if (in_array('--init', $argv ?? [])) { initDB(); echo "DB initialized.\n"; }
    elseif (in_array('--migrate', $argv ?? [])) { migrateDB(); echo "DB migrated.\n"; }
    else { echo "Usage: php api_submit.php --init|--migrate\n"; }
    exit(0);
}

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('X-Content-Type-Options: nosniff');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') { http_response_code(200); exit; }

if ($method === 'GET') {
    $db = getDB();
    $now = gmdate('Y-m-d H:i:s');
    $stmt = $db->prepare('SELECT case_data FROM submissions WHERE release_at IS NULL OR release_at <= :now ORDER BY id DESC LIMIT 500');
    $stmt->bindValue(':now', $now, SQLITE3_TEXT);
    $result = $stmt->execute();
    $out = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $d = json_decode($row['case_data'], true);
        if (!$d) continue;
        // Replace fine-grained _ts with ISO-week bucket so it can't be pinned
        // to a specific court-day. Keep _wk as "YYYY-W##" (ISO).
        if (!empty($d['_ts'])) {
            $t = strtotime($d['_ts']);
            if ($t !== false) $d['_wk'] = gmdate('o-\WW', $t);
            unset($d['_ts']);
        }
        unset($d['_token'], $d['_ip_hash']);
        $out[] = $d;
    }
    $pending = 0;
    $r = $db->query("SELECT COUNT(*) AS c FROM submissions WHERE release_at > '$now'");
    if ($r) { $row = $r->fetchArray(SQLITE3_ASSOC); $pending = (int)($row['c'] ?? 0); }
    echo json_encode(['ok' => true, 'cases' => $out, 'count' => count($out), 'pending' => $pending]);
    exit;
}

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$rawIp = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
$ip = preg_replace('/[^0-9a-fA-F.:,]/', '', explode(',', $rawIp)[0]);
$rlf = sys_get_temp_dir() . '/uns_rl_' . md5($ip) . '.json';
$rld = file_exists($rlf) ? (json_decode(file_get_contents($rlf), true) ?? []) : [];
if (($rld['h'] ?? '') !== date('YmdH')) $rld = ['c' => 0, 'h' => date('YmdH')];
if (($rld['c'] ?? 0) >= RATE_LIMIT) {
    http_response_code(429);
    echo json_encode(['error' => 'Rate limit — try again later.']);
    exit;
}
$rld['c']++;
file_put_contents($rlf, json_encode($rld), LOCK_EX);

$raw = file_get_contents('php://input', false, null, 0, MAX_BODY);
if (!$raw) { http_response_code(400); echo json_encode(['error' => 'Empty body']); exit; }
$body = json_decode($raw, true);
if (!$body || !is_array($body)) { http_response_code(400); echo json_encode(['error' => 'Invalid JSON']); exit; }

$case = $body['case'] ?? null;
if (!$case || empty($case['state'])) {
    http_response_code(400);
    echo json_encode(['error' => 'State is required']);
    exit;
}

$clean = [];
foreach ($case as $k => $v) {
    if (strlen((string)$k) > 100) continue;
    $clean[htmlspecialchars((string)$k, ENT_QUOTES, 'UTF-8')] =
        is_string($v) ? htmlspecialchars(substr($v, 0, 10000), ENT_QUOTES, 'UTF-8') : $v;
}
$token           = bin2hex(random_bytes(16));
$clean['_token'] = $token;
$clean['_ts']    = gmdate('c');

// Randomized release: pick a delay in seconds uniformly from
// [RELEASE_MIN_HOURS*3600, RELEASE_MAX_HOURS*3600]. Using random_int for
// cryptographic uniformity (NOT rand/mt_rand).
//
// Small-jurisdiction protection: if this state+county has fewer than
// SMALL_JX_THRESHOLD existing submissions, we multiply the release window
// by SMALL_JX_MULTIPLIER. That way a rural county where one hearing is a
// week's entire caseload can't be correlated to a same-week submission —
// the submission lands in a larger batch window (3-9 weeks instead of 1-3).
try {
    $dbEarly = getDB();
    $cs = $dbEarly->prepare('SELECT COUNT(*) AS c FROM submissions WHERE state = :s AND case_data LIKE :c');
    $cs->bindValue(':s', $clean['state'], SQLITE3_TEXT);
    $cs->bindValue(':c', '%"county":"' . str_replace('"','', $clean['county'] ?? '') . '"%', SQLITE3_TEXT);
    $er = $cs->execute();
    $existing = $er ? (int)(($er->fetchArray(SQLITE3_ASSOC)['c'] ?? 0)) : 0;
} catch (Throwable $e) {
    $existing = 999;  // fail open — don't extend if we can't count
}
$smallJx = ($existing < 5);
$minSec = RELEASE_MIN_HOURS * 3600 * ($smallJx ? 3 : 1);
$maxSec = RELEASE_MAX_HOURS * 3600 * ($smallJx ? 3 : 1);
$delaySec  = random_int($minSec, $maxSec);
$releaseAt = gmdate('Y-m-d H:i:s', time() + $delaySec);

try {
    $db   = getDB();
    $stmt = $db->prepare('INSERT INTO submissions(token,state,year,case_data,created_at,release_at) VALUES(:t,:s,:y,:d,:ts,:ra)');
    $stmt->bindValue(':t',  $token,                      SQLITE3_TEXT);
    $stmt->bindValue(':s',  $clean['state'],             SQLITE3_TEXT);
    $stmt->bindValue(':y',  (int)($clean['year'] ?? 0), SQLITE3_INTEGER);
    $stmt->bindValue(':d',  json_encode($clean),         SQLITE3_TEXT);
    $stmt->bindValue(':ts', gmdate('Y-m-d H:i:s'),       SQLITE3_TEXT);
    $stmt->bindValue(':ra', $releaseAt,                  SQLITE3_TEXT);
    $stmt->execute();
} catch (Throwable $e) {
    error_log('api_submit INSERT: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database error — please try again.']);
    exit;
}

$queued = 0;
foreach (($body['profileContributions'] ?? []) as $pr) {
    if (!is_array($pr)) continue;
    try {
        $ps = $db->prepare('INSERT INTO profile_queue(entity_type,source_url,name,data,status,created_at) VALUES(:t,:s,:n,:d,:st,:ts)');
        $ps->bindValue(':t',  substr($pr['entityType'] ?? '', 0, 50),  SQLITE3_TEXT);
        $ps->bindValue(':s',  substr($pr['source']     ?? '', 0, 500), SQLITE3_TEXT);
        $ps->bindValue(':n',  substr($pr['name']       ?? '', 0, 200), SQLITE3_TEXT);
        $ps->bindValue(':d',  json_encode($pr),                        SQLITE3_TEXT);
        $ps->bindValue(':st', 'pending',                               SQLITE3_TEXT);
        $ps->bindValue(':ts', gmdate('Y-m-d H:i:s'),                   SQLITE3_TEXT);
        $ps->execute();
        $queued++;
    } catch (Throwable $e) {
        error_log('api_submit profile: ' . $e->getMessage());
    }
}

echo json_encode([
    'ok'           => true,
    'token'        => $token,
    'message'      => 'Submitted anonymously. Save your token to reference this submission.',
    'profQueued'   => $queued,
    'releaseAt'    => $releaseAt,
    'releaseHours' => (int)round($delaySec / 3600),
    'smallJx'      => $smallJx,
    'privacyNote'  => $smallJx
        ? 'Your county has few existing submissions, so your entry is queued in a larger, less-frequent batch (3-9 weeks) to prevent timestamp correlation. Aggregate counts will still include it. You can always edit or delete it with your token.'
        : 'Your submission is queued and will be published with other submissions in a randomized batch over the next 1-3 weeks. This prevents timestamp correlation with a specific court event. You can always edit or delete it with your token.',
]);

function getDB(): SQLite3 {
    $dir = dirname(DB_PATH);
    if (!is_dir($dir)) mkdir($dir, 0750, true);
    $ht = $dir . '/.htaccess';
    if (!file_exists($ht)) file_put_contents($ht, "Deny from all\n");
    $db = new SQLite3(DB_PATH);
    $db->busyTimeout(3000);
    $db->exec('PRAGMA journal_mode=WAL;');
    initDB($db);
    migrateDB($db);
    return $db;
}

function initDB(?SQLite3 $db = null): void {
    if ($db === null) {
        $dir = dirname(DB_PATH);
        if (!is_dir($dir)) mkdir($dir, 0750, true);
        $db = new SQLite3(DB_PATH);
        $db->exec('PRAGMA journal_mode=WAL;');
    }
    $db->exec('CREATE TABLE IF NOT EXISTS submissions(id INTEGER PRIMARY KEY AUTOINCREMENT,token TEXT NOT NULL UNIQUE,state TEXT NOT NULL,year INTEGER,case_data TEXT NOT NULL,created_at TEXT NOT NULL,release_at TEXT)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_sub_state ON submissions(state)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_sub_year ON submissions(year)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_sub_release ON submissions(release_at)');
    $db->exec('CREATE TABLE IF NOT EXISTS profile_queue(id INTEGER PRIMARY KEY AUTOINCREMENT,entity_type TEXT,source_url TEXT,name TEXT,data TEXT,status TEXT,created_at TEXT NOT NULL)');
}

function migrateDB(?SQLite3 $db = null): void {
    if ($db === null) {
        $db = new SQLite3(DB_PATH);
        $db->exec('PRAGMA journal_mode=WAL;');
    }
    // Add release_at column if missing (for upgrades from pre-release-window schema)
    $r = $db->query("PRAGMA table_info(submissions)");
    $hasRelease = false;
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
        if (($row['name'] ?? '') === 'release_at') { $hasRelease = true; break; }
    }
    if (!$hasRelease) {
        $db->exec('ALTER TABLE submissions ADD COLUMN release_at TEXT');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_sub_release ON submissions(release_at)');
    }
}
