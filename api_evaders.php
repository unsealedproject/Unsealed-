<?php
/**
 * api_evaders.php — serves per-state "public evader" counts used by the
 * paradox panel. For each state with a public registry:
 *   - If the page is server-rendered, live-count via a per-state parser.
 *   - Otherwise, fall back to the seed in state_evader_counts.json.
 * Results cached in SQLite for 24 h (shared api_cache.db).
 *
 * Ops:
 *   op=all                 → {states: {State: {count, asOf, method, sourceUrl}}}
 *   op=state&state=<Name>  → {state, count, asOf, method, sourceUrl}
 *   op=refresh             → re-run scrapers for scrape-method states, bust cache
 */

require_once __DIR__ . '/api_keys.php';
fca_proxy_headers();

if (!fca_rate_ok('evaders', 180)) {
    http_response_code(429);
    echo json_encode(['ok'=>false,'error'=>'Rate limit: 180 req/hr per IP']);
    exit;
}

$REGISTRY_FILE = __DIR__ . '/state_evader_lists.json';
$COUNTS_FILE   = __DIR__ . '/state_evader_counts.json';

if (!is_file($REGISTRY_FILE) || !is_file($COUNTS_FILE)) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'registry or counts file missing on server']);
    exit;
}

$registry = json_decode(file_get_contents($REGISTRY_FILE), true);
$counts   = json_decode(file_get_contents($COUNTS_FILE),   true);
if (!is_array($registry) || !is_array($counts)) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'registry or counts file malformed']);
    exit;
}

$op    = preg_replace('/[^a-z_]/', '', (string)($_GET['op'] ?? 'all'));
$state = preg_replace('/[^A-Za-z ]/', '', substr((string)($_GET['state'] ?? ''), 0, 40));

// ── Per-state server-side counters ─────────────────────────────────────────
// Returns int count, or null if the fetch succeeded but no count could be
// extracted. Throws nothing — network failures return null.
function _count_texas($url) {
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 12,
            'header'  => "User-Agent: Mozilla/5.0 (Unsealed-is evader-count; +https://unsealed.is)\r\n",
            'ignore_errors' => true,
        ],
    ]);
    $html = @file_get_contents($url, false, $ctx);
    if ($html === false || strlen($html) < 200) return null;
    // Count unique /evaders/<slug> hrefs (exclude the category indexes).
    if (!preg_match_all('#href="/evaders/([a-z0-9\-]+)"#i', $html, $m)) return 0;
    $slugs = array_unique($m[1]);
    $slugs = array_filter($slugs, fn($s) => !in_array($s, ['all','arrested','current'], true));
    return count($slugs);
}

// Generic counter factory: count matches of a CSS-ish regex. Used for states
// whose list markup is server-rendered with a predictable pattern.
function _count_by_regex($url, $pattern) {
    $ctx = stream_context_create([
        'http' => ['timeout' => 12,
                   'header'  => "User-Agent: Mozilla/5.0 (Unsealed-is evader-count; +https://unsealed.is)\r\n",
                   'ignore_errors' => true],
    ]);
    $html = @file_get_contents($url, false, $ctx);
    if ($html === false || strlen($html) < 200) return null;
    if (!preg_match_all($pattern, $html, $m)) return 0;
    return count($m[0]);
}

// Per-state scraper dispatch. Keyed by state name, values are callables that
// return ?int. Only states whose live HTML yields a reliable floor count go
// here; everything else falls back to the seed.
$SCRAPERS = [
    'Texas' => function() use ($registry) {
        $url = $registry['states']['Texas']['url'] ?? null;
        return $url ? _count_texas($url) : null;
    },
];

function _state_record($name, $registry, $counts, $SCRAPERS, $bypassCache = false) {
    $regEntry    = $registry['states'][$name] ?? null;
    $countEntry  = $counts['states'][$name]   ?? null;
    if (!$regEntry || !$regEntry['hasList'] || !$countEntry) return null;

    $out = [
        'state'     => $name,
        'count'     => $countEntry['count'],
        'asOf'      => $countEntry['as_of'] ?? null,
        'method'    => $countEntry['method'] ?? 'unknown',
        'sourceUrl' => $regEntry['url'] ?? null,
        'program'   => $regEntry['programName'] ?? null,
        'agency'    => $regEntry['agency'] ?? null,
        'notes'     => $countEntry['notes'] ?? null,
    ];

    // If this state has a live scraper, try it and overlay a fresh count.
    if (($countEntry['method'] ?? null) === 'scrape' && isset($SCRAPERS[$name])) {
        $cacheKey = 'count|' . $name;
        $cached   = $bypassCache ? null : fca_cache_get('evaders', $cacheKey, 86400);
        if ($cached !== null) {
            $out['count']  = (int)$cached;
            $out['asOf']   = gmdate('Y-m-d');
            $out['cached'] = true;
        } else {
            $live = call_user_func($SCRAPERS[$name]);
            if ($live !== null) {
                fca_cache_put('evaders', $cacheKey, (string)$live);
                $out['count']  = $live;
                $out['asOf']   = gmdate('Y-m-d');
                $out['cached'] = false;
            }
        }
    }
    return $out;
}

$bypass = ($op === 'refresh');
switch ($op) {
    case 'state':
        if ($state === '') { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'state required']); exit; }
        $rec = _state_record($state, $registry, $counts, $SCRAPERS, $bypass);
        if (!$rec) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'unknown or listless state']); exit; }
        echo json_encode(['ok'=>true,'source'=>'evaders','data'=>$rec]);
        exit;

    case 'refresh':
    case 'all':
    default:
        $out = [];
        $stateNames = array_keys($registry['states']);
        sort($stateNames);
        foreach ($stateNames as $n) {
            if ($n && $n[0] === '_') continue;  // skip meta keys (_lien_docket_registries etc.)
            $rec = _state_record($n, $registry, $counts, $SCRAPERS, $bypass);
            if ($rec) $out[$n] = $rec;
        }
        echo json_encode(['ok'=>true,'source'=>'evaders','data'=>['states'=>$out]]);
        exit;
}
