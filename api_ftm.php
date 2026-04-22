<?php
/**
 * api_ftm.php — server-side proxy for FollowTheMoney.org (NIMP) API.
 *
 * FTM covers STATE-level judicial elections (FEC only covers federal), so
 * this is where most state supreme court / circuit judge donation data
 * lives. FTM's public endpoint is https://api.followthemoney.org/ and
 * requires the APIKey query parameter.
 *
 * Supported ops:
 *   op=search_candidate  q=<name>  state=<XX>?  year=<YYYY>?
 *   op=candidate         eid=<entity_id>                         — candidate detail
 *   op=top_contributors  eid=<entity_id>  year=<YYYY>?           — donor list
 *   op=judicial_by_state state=<XX>  year=<YYYY>?                — all judicial candidates
 *
 * Rate limit: 120 requests/hour per IP. Cache TTL: 24h.
 */

require_once __DIR__ . '/api_keys.php';
fca_proxy_headers();

if (!fca_rate_ok('ftm', 120)) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'Rate limit: 120 requests per hour per IP.']);
    exit;
}

$key = fca_load_key('FTM_API_KEY');
if (!$key) {
    http_response_code(503);
    echo json_encode([
        'ok'    => false,
        'error' => 'FollowTheMoney API key not configured on server.',
        'setup' => 'Add FTM_API_KEY=... to /etc/fca/api_keys.env',
    ]);
    exit;
}

$op = preg_replace('/[^a-z_]/', '', (string)($_GET['op'] ?? ''));
$ALLOWED = ['search_candidate', 'candidate', 'top_contributors', 'judicial_by_state'];
if (!in_array($op, $ALLOWED, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Unsupported op. Allowed: ' . implode(', ', $ALLOWED)]);
    exit;
}

// ── SANITIZE ─────────────────────────────────────────────────────────
$q      = preg_replace('/[^a-zA-Z0-9 .,\'\-]/u', '', substr((string)($_GET['q']     ?? ''), 0, 200));
$eid    = preg_replace('/[^0-9]/',               '', substr((string)($_GET['eid']   ?? ''), 0, 12));
$state  = preg_replace('/[^A-Z]/', '', strtoupper(substr((string)($_GET['state']    ?? ''), 0, 2)));
$year   = (int)($_GET['year'] ?? 0);
if ($year && ($year < 1990 || $year > 2030)) $year = 0;

// ── URL BUILD ────────────────────────────────────────────────────────
// FTM's API returns JSON when `mode=json` is included. `so=...` selects the
// output-schema (s_cand, s_cand_cont, etc.). Upstream docs:
// https://www.followthemoney.org/our-data/apis/
$base = 'https://api.followthemoney.org/';
$url  = null;

switch ($op) {
    case 'search_candidate':
        if ($q === '') { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'q required']); exit; }
        // s=s_cand = candidate record schema; c-r-id=J restricts to judicial candidates
        $params = [
            'APIKey'   => $key,
            'so'       => 's_cand',
            'p'        => 'bulk',
            'mode'     => 'json',
            'c-t-id'   => $q,                     // candidate name filter
            'c-r-id'   => 'J',                    // judicial office filter
        ];
        if ($state) $params['s'] = $state;
        if ($year)  $params['y'] = $year;
        $url = $base . '?' . http_build_query($params);
        break;
    case 'candidate':
        if ($eid === '') { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'eid required']); exit; }
        $url = $base . '?' . http_build_query([
            'APIKey'   => $key,
            'so'       => 's_cand',
            'mode'     => 'json',
            'c-t-eid'  => $eid,
        ]);
        break;
    case 'top_contributors':
        if ($eid === '') { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'eid required']); exit; }
        $params = [
            'APIKey'  => $key,
            'so'      => 's_cand_cont',
            'p'       => 'bulk',
            'mode'    => 'json',
            'c-t-eid' => $eid,
        ];
        if ($year) $params['y'] = $year;
        $url = $base . '?' . http_build_query($params);
        break;
    case 'judicial_by_state':
        if ($state === '') { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'state required']); exit; }
        $params = [
            'APIKey' => $key,
            'so'     => 's_cand',
            'p'      => 'bulk',
            'mode'   => 'json',
            's'      => $state,
            'c-r-id' => 'J',    // judicial
        ];
        if ($year) $params['y'] = $year;
        $url = $base . '?' . http_build_query($params);
        break;
}

// Redact apikey from the cache key so requests with different per-user keys
// (not that we have any today) still share cache rows.
$cache_key = $op . '|' . md5(preg_replace('/(APIKey=)[^&]+/', '$1X', $url));
$cached = fca_cache_get('ftm', $cache_key, 86400);
if ($cached !== null) {
    echo json_encode(['ok' => true, 'source' => 'ftm', 'op' => $op, 'data' => json_decode($cached, true), 'cached' => true]);
    exit;
}

$resp = fca_http_get_json($url, [], 30);   // FTM is slow
if (!$resp['ok']) {
    http_response_code($resp['status'] ?: 502);
    echo json_encode([
        'ok'     => false,
        'source' => 'ftm',
        'op'     => $op,
        'status' => $resp['status'],
        'error'  => $resp['error'] ?: 'upstream error',
    ]);
    exit;
}

fca_cache_put('ftm', $cache_key, json_encode($resp['body']));

echo json_encode([
    'ok'     => true,
    'source' => 'ftm',
    'op'     => $op,
    'data'   => $resp['body'],
    'cached' => false,
]);
