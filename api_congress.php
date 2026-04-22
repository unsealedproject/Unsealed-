<?php
/**
 * api_congress.php — Congress.gov v3 API proxy.
 *
 * Client calls this file with an `op` param; we validate against a whitelist,
 * forward to api.congress.gov with api_key injected server-side, cache 24h.
 *
 * Ops:
 *   op=members_by_state  state=<2-letter>  chamber=senate|house|both  currentOnly=1|0
 *   op=member            id=<bioguideId>
 *   op=member_sponsored  id=<bioguideId>   congress=119
 *   op=member_cosponsored id=<bioguideId>  congress=119
 *   op=bill              congress=119  billType=hr|s  billNumber=123
 *   op=bill_actions      congress=119  billType=hr|s  billNumber=123
 *   op=search_bills      q=<keyword>   congress=119  policyArea=Families
 *
 * Rate limit: 180 req/hr per IP. Cache TTL: 24h.
 */

require_once __DIR__ . '/api_keys.php';
fca_proxy_headers();

if (!fca_rate_ok('congress', 180)) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'Rate limit: 180 requests per hour per IP.']);
    exit;
}

$key = fca_load_key('CONGRESS_API_KEY');
if (!$key) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Congress.gov API key not configured on server.',
                      'setup' => 'Add CONGRESS_API_KEY=... to /etc/fca/api_keys.env']);
    exit;
}

$op = preg_replace('/[^a-z_]/', '', (string)($_GET['op'] ?? ''));
$ALLOWED = ['members_by_state','member','member_sponsored','member_cosponsored',
            'bill','bill_actions','search_bills'];
if (!in_array($op, $ALLOWED, true)) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'Unsupported op. Allowed: '.implode(', ',$ALLOWED)]);
    exit;
}

// Sanitize inputs
$state      = preg_replace('/[^A-Z]/',      '', strtoupper(substr((string)($_GET['state']      ?? ''), 0, 2)));
$chamber    = preg_replace('/[^a-z]/',      '',         substr((string)($_GET['chamber']    ?? 'both'), 0, 10));
$bioguide   = preg_replace('/[^A-Z0-9]/',   '',         substr((string)($_GET['id']         ?? ''), 0, 12));
$congress   = preg_replace('/[^0-9]/',      '',         substr((string)($_GET['congress']   ?? '119'), 0, 3));
$billType   = preg_replace('/[^a-z]/',      '',         substr((string)($_GET['billType']   ?? ''), 0, 6));
$billNumber = preg_replace('/[^0-9]/',      '',         substr((string)($_GET['billNumber'] ?? ''), 0, 8));
$q          = preg_replace('/[^a-zA-Z0-9 .,\'\-]/u',  '', substr((string)($_GET['q']        ?? ''), 0, 200));
$policyArea = preg_replace('/[^a-zA-Z ]/',  '',         substr((string)($_GET['policyArea'] ?? ''), 0, 60));
$currentOnly= ($_GET['currentOnly'] ?? '1') === '1';
$limit      = min(250, max(1, (int)($_GET['limit'] ?? 50)));

$base = 'https://api.congress.gov/v3';
$url  = null;

switch ($op) {
    case 'members_by_state':
        if ($state === '') { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'state required']); exit; }
        $params = ['format'=>'json', 'limit'=>$limit, 'api_key'=>$key];
        if ($currentOnly) $params['currentMember'] = 'true';
        $url = $base . '/member/' . $state . '?' . http_build_query($params);
        break;
    case 'member':
        if ($bioguide === '') { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'id required']); exit; }
        $url = $base . '/member/' . $bioguide . '?' . http_build_query(['format'=>'json','api_key'=>$key]);
        break;
    case 'member_sponsored':
        if ($bioguide === '') { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'id required']); exit; }
        $url = $base . '/member/' . $bioguide . '/sponsored-legislation?' .
               http_build_query(['format'=>'json','limit'=>$limit,'api_key'=>$key]);
        break;
    case 'member_cosponsored':
        if ($bioguide === '') { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'id required']); exit; }
        $url = $base . '/member/' . $bioguide . '/cosponsored-legislation?' .
               http_build_query(['format'=>'json','limit'=>$limit,'api_key'=>$key]);
        break;
    case 'bill':
        if ($congress === '' || $billType === '' || $billNumber === '') {
            http_response_code(400); echo json_encode(['ok'=>false,'error'=>'congress, billType, billNumber required']); exit;
        }
        $url = $base . "/bill/$congress/$billType/$billNumber?" . http_build_query(['format'=>'json','api_key'=>$key]);
        break;
    case 'bill_actions':
        if ($congress === '' || $billType === '' || $billNumber === '') {
            http_response_code(400); echo json_encode(['ok'=>false,'error'=>'congress, billType, billNumber required']); exit;
        }
        $url = $base . "/bill/$congress/$billType/$billNumber/actions?" .
               http_build_query(['format'=>'json','limit'=>$limit,'api_key'=>$key]);
        break;
    case 'search_bills':
        // Congress.gov has no search_bills endpoint that supports free-text yet — we fetch
        // recent bills and optionally filter client-side by policyArea via the listing endpoint.
        $params = ['format'=>'json','limit'=>$limit,'api_key'=>$key];
        if ($billType === '') $billType = 'hr'; // default House
        $url = $base . "/bill/$congress/$billType?" . http_build_query($params);
        break;
}

// Redact the key from the cache key so responses are shared across users.
$cache_key = $op . '|' . md5(preg_replace('/(api_key=)[^&]+/', '$1X', $url));
$cached = fca_cache_get('congress', $cache_key, 86400);
if ($cached !== null) {
    echo json_encode(['ok'=>true,'source'=>'congress','op'=>$op,'data'=>json_decode($cached,true),'cached'=>true]);
    exit;
}

$resp = fca_http_get_json($url, [], 25);
if (!$resp['ok']) {
    http_response_code($resp['status'] ?: 502);
    echo json_encode(['ok'=>false,'source'=>'congress','op'=>$op,'status'=>$resp['status'],'error'=>$resp['error']?:'upstream error']);
    exit;
}

fca_cache_put('congress', $cache_key, json_encode($resp['body']));
echo json_encode(['ok'=>true,'source'=>'congress','op'=>$op,'data'=>$resp['body'],'cached'=>false]);
