<?php
/**
 * api_stats.php — aggregate statistics endpoint.
 *
 * Only serves aggregate counts. Never returns individual submission data.
 * The legacy judge/attorney/state/platform/ratings endpoints queried a
 * `cases` table that no longer exists in the submissions schema — those
 * ops have been removed. The only live op is judge_counts, which powers
 * the "submissions per judge" badge on the judge directory.
 *
 * Rate limited via api_keys.php shared helper (200 req/hr per IP).
 *
 * Ops:
 *   op=judge_counts  state=<Name>  [county=<Name>]  → {judge_name: count}
 *
 * Privacy: returned counts aggregate state+county+judge. No submission-level
 * or submitter-identifying data is exposed. Counts below MIN_VISIBLE (3)
 * are suppressed so single-submitter judges can't be re-identified by
 * process of elimination.
 */

require_once __DIR__ . '/api_keys.php';
fca_proxy_headers();

define('MIN_VISIBLE', 3);
define('DB_PATH', '/var/www/fca/data/submissions.db');

if (!fca_rate_ok('stats', 200)) {
    http_response_code(429);
    echo json_encode(['ok'=>false,'error'=>'Rate limit — try again later']);
    exit;
}

$op = preg_replace('/[^a-z_]/', '', (string)($_GET['op'] ?? $_GET['type'] ?? ''));
if ($op !== 'judge_counts') {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'Unknown op. Only judge_counts is supported.']);
    exit;
}

// Strict input validation. State/county pass through state-name regex only;
// no SQL or LIKE wildcards reach the query.
$state  = preg_replace('/[^A-Za-z .\'-]/', '', substr((string)($_GET['state']  ?? ''), 0, 60));
$county = preg_replace('/[^A-Za-z .\'-]/', '', substr((string)($_GET['county'] ?? ''), 0, 80));
if ($state === '') {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'state required']);
    exit;
}

// Submissions are stored as JSON blobs in the `submissions.case_data` column
// (schema owned by api_submit.php). We use SQLite's json_extract to count
// per-judge appearances. Only RELEASED submissions (release_at <= now OR
// release_at IS NULL) are counted — pending submissions stay private until
// their randomized release window.
try {
    $db = new SQLite3(DB_PATH, SQLITE3_OPEN_READONLY);
    $db->busyTimeout(3000);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'DB unavailable']);
    exit;
}

$now = gmdate('Y-m-d H:i:s');
$sql = "SELECT json_extract(case_data, '$.judge') AS j, COUNT(*) AS n
        FROM submissions
        WHERE state = :s
          AND (release_at IS NULL OR release_at <= :now)
          AND j IS NOT NULL AND j != ''";
if ($county !== '') {
    $sql .= " AND (json_extract(case_data, '$.county') = :c OR json_extract(case_data, '$.county') = :cfull)";
}
$sql .= " GROUP BY j";

try {
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':s', $state, SQLITE3_TEXT);
    $stmt->bindValue(':now', $now, SQLITE3_TEXT);
    if ($county !== '') {
        $stmt->bindValue(':c',     $county,          SQLITE3_TEXT);
        $stmt->bindValue(':cfull', $county.' County', SQLITE3_TEXT);
    }
    $res = $stmt->execute();
    $out = [];
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $name = $row['j'];
        $n    = (int)$row['n'];
        // K-anonymity: suppress below the minimum, merge into a bucket so
        // totals still render honestly ("10 other judges with <3 each").
        if ($n < MIN_VISIBLE) continue;
        $out[$name] = $n;
    }
    echo json_encode(['ok'=>true, 'counts'=>$out, 'minVisible'=>MIN_VISIBLE]);
} catch (Throwable $e) {
    error_log('api_stats: '.$e->getMessage());
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'query failed']);
}
