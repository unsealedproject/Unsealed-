<?php
/**
 * api_fec.php — server-side proxy for OpenFEC v1 (fec.gov) API.
 *
 * Why this exists: prior client code read the FEC key from localStorage and
 * called api.open.fec.gov directly. That leaks the key to anyone who opens
 * DevTools and lets a malicious page drain the quota. This proxy keeps the
 * key server-side and caches responses for 24h.
 *
 * Supported ops (all GET):
 *   op=contributions  name=<contributor_name>   state=<XX>?  cycle=<year>?
 *   op=disbursements  committee=<committee_id>  cycle=<year>?
 *   op=committees     name=<committee_name>
 *   op=candidates     name=<candidate_name>     state=<XX>?
 *   op=attorneys_by_state  state=<XX>  cycle=<year>?   (contributor_occupation=attorney)
 *
 * Rate limit: 180 requests/hour per IP (OpenFEC itself is 1000/hr per key).
 * Cache TTL: 24h.
 */

require_once __DIR__ . '/api_keys.php';
fca_proxy_headers();

if (!fca_rate_ok('fec', 180)) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'Rate limit: 180 requests per hour per IP.']);
    exit;
}

$key = fca_load_key('FEC_API_KEY');
if (!$key) {
    http_response_code(503);
    echo json_encode([
        'ok'    => false,
        'error' => 'FEC API key not configured on server.',
        'setup' => 'Add FEC_API_KEY=... to /etc/fca/api_keys.env',
    ]);
    exit;
}

$op = preg_replace('/[^a-z_]/', '', (string)($_GET['op'] ?? ''));
$ALLOWED = ['contributions', 'disbursements', 'committees', 'candidates', 'attorneys_by_state', 'candidate_contribs'];
if (!in_array($op, $ALLOWED, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Unsupported op. Allowed: ' . implode(', ', $ALLOWED)]);
    exit;
}

// ── SANITIZE INPUTS ──────────────────────────────────────────────────
// Names get character filtering but keep apostrophes/hyphens/commas (legal in names)
$name      = preg_replace('/[^a-zA-Z0-9 .,\'\-]/u', '', substr((string)($_GET['name']      ?? ''), 0, 200));
$committee = preg_replace('/[^A-Z0-9]/',            '', substr((string)($_GET['committee'] ?? ''), 0, 12));
$state     = preg_replace('/[^A-Z]/',               '', strtoupper(substr((string)($_GET['state'] ?? ''), 0, 2)));
$cycle     = (int)($_GET['cycle'] ?? 0);
// Enforce a plausible FEC election cycle (even years, 1976-2030)
if ($cycle && ($cycle < 1976 || $cycle > 2030 || $cycle % 2 !== 0)) $cycle = 0;
$per_page  = min(100, max(1, (int)($_GET['per_page'] ?? 50)));

// ── URL BUILD ────────────────────────────────────────────────────────
$base = 'https://api.open.fec.gov/v1';
$url  = null;

switch ($op) {
    case 'contributions':
        if ($name === '') { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'name required']); exit; }
        $params = [
            'api_key'          => $key,
            'contributor_name' => $name,
            'per_page'         => $per_page,
            'sort'             => '-contribution_receipt_date',
        ];
        if ($state) $params['contributor_state'] = $state;
        if ($cycle) $params['two_year_transaction_period'] = $cycle;
        $url = $base . '/schedules/schedule_a/?' . http_build_query($params);
        break;
    case 'disbursements':
        if ($committee === '') { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'committee required']); exit; }
        $params = [
            'api_key'      => $key,
            'committee_id' => $committee,
            'per_page'     => $per_page,
            'sort'         => '-disbursement_date',
        ];
        if ($cycle) $params['two_year_transaction_period'] = $cycle;
        $url = $base . '/schedules/schedule_b/?' . http_build_query($params);
        break;
    case 'committees':
        if ($name === '') { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'name required']); exit; }
        $url = $base . '/committees/?' . http_build_query([
            'api_key' => $key, 'name' => $name, 'per_page' => $per_page,
        ]);
        break;
    case 'candidates':
        if ($name === '') { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'name required']); exit; }
        $params = ['api_key' => $key, 'q' => $name, 'per_page' => $per_page];
        if ($state) $params['state'] = $state;
        $url = $base . '/candidates/search/?' . http_build_query($params);
        break;
    case 'attorneys_by_state':
        if ($state === '') { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'state required']); exit; }
        $params = [
            'api_key'                => $key,
            'contributor_occupation' => 'attorney',
            'contributor_state'      => $state,
            'per_page'               => $per_page,
            'sort'                   => '-contribution_receipt_date',
        ];
        if ($cycle) $params['two_year_transaction_period'] = $cycle;
        $url = $base . '/schedules/schedule_a/?' . http_build_query($params);
        break;
    case 'candidate_contribs':
        // Contributions TO a named candidate. Two-step: resolve candidate_id
        // from name, then fetch top contributions to their principal committee.
        // The client-side helper should tag law-firm donors for sorting.
        if ($name === '') { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'name required']); exit; }
        $lookupParams = ['api_key' => $key, 'q' => $name, 'per_page' => 5];
        if ($state) $lookupParams['state'] = $state;
        $lookupUrl = $base . '/candidates/search/?' . http_build_query($lookupParams);
        $lookupResp = fca_http_get_json($lookupUrl, [], 20);
        if (!$lookupResp['ok']) {
            http_response_code($lookupResp['status'] ?: 502);
            echo json_encode(['ok'=>false,'source'=>'fec','op'=>$op,'stage'=>'candidate-lookup','error'=>$lookupResp['error']?:'upstream error']);
            exit;
        }
        $results = $lookupResp['body']['results'] ?? [];
        if (empty($results)) {
            echo json_encode(['ok'=>true,'source'=>'fec','op'=>$op,'data'=>['contribs'=>[],'candidate'=>null,'note'=>'No FEC candidate found for that name']]);
            exit;
        }
        $candidate = $results[0];
        $candidate_id = $candidate['candidate_id'] ?? '';
        if (!$candidate_id) {
            echo json_encode(['ok'=>true,'source'=>'fec','op'=>$op,'data'=>['contribs'=>[],'candidate'=>$candidate,'note'=>'Candidate matched but no candidate_id']]);
            exit;
        }
        // Contributions TO this candidate (filed on their schedule_a).
        $pcc = $candidate['principal_committees'][0]['committee_id'] ?? '';
        if (!$pcc) {
            // Fall back to all candidate committees
            $pcc = implode(',', array_column($candidate['principal_committees'] ?? [], 'committee_id'));
        }
        $aParams = [
            'api_key'  => $key,
            'per_page' => $per_page,
            'sort'     => '-contribution_receipt_amount',
        ];
        if ($pcc) $aParams['committee_id'] = $pcc;
        else      $aParams['candidate_id']  = $candidate_id;
        if ($cycle) $aParams['two_year_transaction_period'] = $cycle;
        $aUrl = $base . '/schedules/schedule_a/?' . http_build_query($aParams);
        $aResp = fca_http_get_json($aUrl, [], 25);
        if (!$aResp['ok']) {
            http_response_code($aResp['status'] ?: 502);
            echo json_encode(['ok'=>false,'source'=>'fec','op'=>$op,'stage'=>'schedule-a','error'=>$aResp['error']?:'upstream error','candidate'=>$candidate]);
            exit;
        }
        $raw = $aResp['body']['results'] ?? [];
        // Law-firm tagging: occupation or employer contains any of these needles.
        $lf = ['attorney','lawyer','law firm',' law ','llp','pllc','p.c.','p.a.','legal','counsel','& associates','and associates','esq'];
        $tagged = [];
        foreach ($raw as $row) {
            $occ = strtolower($row['contributor_occupation'] ?? '');
            $emp = strtolower($row['contributor_employer'] ?? '');
            $isLaw = false;
            foreach ($lf as $needle) {
                if (strpos($occ, $needle) !== false || strpos($emp, $needle) !== false) { $isLaw = true; break; }
            }
            $tagged[] = [
                'contributor_name'       => $row['contributor_name'] ?? '',
                'contributor_employer'   => $row['contributor_employer'] ?? '',
                'contributor_occupation' => $row['contributor_occupation'] ?? '',
                'contributor_state'      => $row['contributor_state'] ?? '',
                'contribution_receipt_date'   => $row['contribution_receipt_date'] ?? '',
                'contribution_receipt_amount' => (float)($row['contribution_receipt_amount'] ?? 0),
                'committee_name'         => $row['committee']['name'] ?? '',
                'is_law_firm'            => $isLaw,
            ];
        }
        // Sort: law-firm donors first, then by amount desc within each group.
        usort($tagged, function($a, $b) {
            if ($a['is_law_firm'] !== $b['is_law_firm']) return $a['is_law_firm'] ? -1 : 1;
            return ($b['contribution_receipt_amount'] <=> $a['contribution_receipt_amount']);
        });
        $out = [
            'candidate' => [
                'name'         => $candidate['name'] ?? '',
                'candidate_id' => $candidate_id,
                'party'        => $candidate['party'] ?? '',
                'office'       => $candidate['office_full'] ?? '',
                'state'        => $candidate['state'] ?? '',
            ],
            'contribs'     => $tagged,
            'law_firm_sum' => array_sum(array_map(fn($r) => $r['is_law_firm'] ? $r['contribution_receipt_amount'] : 0, $tagged)),
            'total_sum'    => array_sum(array_column($tagged, 'contribution_receipt_amount')),
        ];
        $cache_key = $op . '|' . md5($name . '|' . $state . '|' . $cycle);
        fca_cache_put('fec', $cache_key, json_encode($out));
        echo json_encode(['ok'=>true,'source'=>'fec','op'=>$op,'data'=>$out,'cached'=>false]);
        exit;
}

// ── CACHE LOOKUP ─────────────────────────────────────────────────────
// Cache key excludes the API key so different users' requests collide on the same data.
$cache_key = $op . '|' . md5(preg_replace('/(api_key=)[^&]+/', '$1X', $url));
$cached = fca_cache_get('fec', $cache_key, 86400);
if ($cached !== null) {
    echo json_encode(['ok' => true, 'source' => 'fec', 'op' => $op, 'data' => json_decode($cached, true), 'cached' => true]);
    exit;
}

// ── FORWARD ──────────────────────────────────────────────────────────
$resp = fca_http_get_json($url, [], 20);

if (!$resp['ok']) {
    http_response_code($resp['status'] ?: 502);
    echo json_encode([
        'ok'     => false,
        'source' => 'fec',
        'op'     => $op,
        'status' => $resp['status'],
        'error'  => $resp['error'] ?: 'upstream error',
    ]);
    exit;
}

fca_cache_put('fec', $cache_key, json_encode($resp['body']));

echo json_encode([
    'ok'     => true,
    'source' => 'fec',
    'op'     => $op,
    'data'   => $resp['body'],
    'cached' => false,
]);
