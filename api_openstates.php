<?php
/**
 * api_openstates.php — OpenStates v3 API proxy (state legislators, bills, votes).
 *
 * Ops:
 *   op=legislators    state=<2-letter>  page=1
 *   op=legislator     id=<ocd-person/uuid>
 *   op=bills_by_subject state=<2-letter>  subject=<string>   session=<string>?
 *   op=bill           id=<ocd-bill/uuid>
 *   op=bill_votes     id=<ocd-bill/uuid>
 *   op=search_bills   state=<2-letter>  q=<keyword>
 *
 * Auth: OpenStates uses X-API-KEY header.
 * Rate limit: 180 req/hr per IP (OpenStates free tier is ~500/day per key).
 */

require_once __DIR__ . '/api_keys.php';
fca_proxy_headers();

if (!fca_rate_ok('openstates', 180)) {
    http_response_code(429);
    echo json_encode(['ok'=>false,'error'=>'Rate limit: 180 requests per hour per IP.']);
    exit;
}

$key = fca_load_key('OPENSTATES_API_KEY');
if (!$key) {
    http_response_code(503);
    echo json_encode(['ok'=>false,'error'=>'OpenStates API key not configured on server.',
                      'setup'=>'Add OPENSTATES_API_KEY=... to /etc/fca/api_keys.env']);
    exit;
}

$op = preg_replace('/[^a-z_]/', '', (string)($_GET['op'] ?? ''));
$ALLOWED = ['legislators','legislator','bills_by_subject','bill','bill_votes','search_bills'];
if (!in_array($op, $ALLOWED, true)) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'Unsupported op. Allowed: '.implode(', ',$ALLOWED)]);
    exit;
}

$state   = preg_replace('/[^a-z]/', '', strtolower(substr((string)($_GET['state']   ?? ''), 0, 2)));
$id      = preg_replace('/[^a-z0-9\-\/]/', '', substr((string)($_GET['id']      ?? ''), 0, 100));
$q       = preg_replace('/[^a-zA-Z0-9 .,\'\-]/u', '', substr((string)($_GET['q']       ?? ''), 0, 200));
$subject = preg_replace('/[^a-zA-Z0-9 \-]/',       '', substr((string)($_GET['subject'] ?? ''), 0, 60));
$session = preg_replace('/[^A-Za-z0-9\-]/',        '', substr((string)($_GET['session'] ?? ''), 0, 30));
$page    = max(1, min(20, (int)($_GET['page'] ?? 1)));
$perPage = min(50, max(1, (int)($_GET['per_page'] ?? 20)));

$base = 'https://v3.openstates.org';
$url  = null;

// OpenStates v3 `include` params must be repeated (include=a&include=b), not
// comma-separated. http_build_query comma-concatenates, which OpenStates rejects
// with 422. Helper builds the `&include=x` suffix manually.
$mk_includes = function(array $inc) {
    if (!$inc) return '';
    $parts = [];
    foreach ($inc as $v) { $parts[] = 'include=' . urlencode($v); }
    return '&' . implode('&', $parts);
};

switch ($op) {
    case 'legislators':
        if ($state === '') { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'state required']); exit; }
        $params = [
            'jurisdiction' => "ocd-jurisdiction/country:us/state:$state/government",
            'per_page' => $perPage,
            'page' => $page,
        ];
        $url = $base . '/people?' . http_build_query($params) . $mk_includes(['offices','other_identifiers']);
        break;
    case 'legislator':
        if ($id === '') { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'id required']); exit; }
        $url = $base . '/people?id=' . urlencode($id) . $mk_includes(['offices','other_identifiers','sources']);
        break;
    case 'bills_by_subject':
        if ($state === '' || $subject === '') {
            http_response_code(400); echo json_encode(['ok'=>false,'error'=>'state and subject required']); exit;
        }
        $params = [
            'jurisdiction' => "ocd-jurisdiction/country:us/state:$state/government",
            'subject' => $subject,
            'per_page' => $perPage,
            'page' => $page,
            'sort' => 'updated_desc',
        ];
        if ($session) $params['session'] = $session;
        $url = $base . '/bills?' . http_build_query($params) . $mk_includes(['sponsorships','abstracts']);
        break;
    case 'search_bills':
        if ($state === '' || $q === '') {
            http_response_code(400); echo json_encode(['ok'=>false,'error'=>'state and q required']); exit;
        }
        $params = [
            'jurisdiction' => "ocd-jurisdiction/country:us/state:$state/government",
            'q' => $q,
            'per_page' => $perPage,
            'page' => $page,
            'sort' => 'updated_desc',
        ];
        $url = $base . '/bills?' . http_build_query($params);
        break;
    case 'bill':
        if ($id === '') { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'id required']); exit; }
        $url = $base . '/bills?id=' . urlencode($id) . $mk_includes(['sponsorships','abstracts','actions','votes','documents','versions','sources']);
        break;
    case 'bill_votes':
        if ($id === '') { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'id required']); exit; }
        $url = $base . '/bills?id=' . urlencode($id) . $mk_includes(['votes']);
        break;
}

$cache_key = $op . '|' . md5($url);
$cached = fca_cache_get('openstates', $cache_key, 86400);
if ($cached !== null) {
    echo json_encode(['ok'=>true,'source'=>'openstates','op'=>$op,'data'=>json_decode($cached,true),'cached'=>true]);
    exit;
}

$resp = fca_http_get_json($url, ['X-API-KEY'=>$key], 25);
if (!$resp['ok']) {
    http_response_code($resp['status'] ?: 502);
    echo json_encode(['ok'=>false,'source'=>'openstates','op'=>$op,'status'=>$resp['status'],'error'=>$resp['error']?:'upstream error']);
    exit;
}

fca_cache_put('openstates', $cache_key, json_encode($resp['body']));
echo json_encode(['ok'=>true,'source'=>'openstates','op'=>$op,'data'=>$resp['body'],'cached'=>false]);
