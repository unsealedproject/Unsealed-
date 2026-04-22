<?php
/**
 * api_courtlistener.php — server-side proxy for CourtListener v4 API.
 *
 * Why this exists: we never want the CourtListener token in client code.
 * Client calls this file with an `op` param; we validate it against a
 * whitelist, forward the request to CourtListener with the token server
 * side, cache the response in SQLite for 24h, and return JSON.
 *
 * Supported ops (all GET):
 *   op=search_people     q=<free text>                — find judges/people
 *   op=person            id=<person_id>               — full bio record
 *   op=positions         person=<person_id>           — judicial positions held
 *   op=educations        person=<person_id>           — education history
 *   op=political         person=<person_id>           — party affiliations
 *   op=disclosures       person=<person_id>           — financial-disclosure list
 *   op=disclosure        id=<disclosure_id>           — single disclosure detail
 *   op=investments       person=<person_id>           — aggregated investments
 *   op=gifts             person=<person_id>           — aggregated gifts
 *
 * Rate limit: 120 requests/hour per IP. Cache TTL: 24h.
 *
 * Response shape: {"ok":bool, "source":"courtlistener", "op":"...",
 *                  "data":<upstream json>, "cached":bool, "error":string?}
 */

require_once __DIR__ . '/api_keys.php';
fca_proxy_headers();

if (!fca_rate_ok('cl', 120)) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'Rate limit: 120 requests per hour per IP.']);
    exit;
}

$token = fca_load_key('COURTLISTENER_TOKEN');
if (!$token) {
    http_response_code(503);
    echo json_encode([
        'ok' => false,
        'error' => 'CourtListener token not configured on server.',
        'setup' => 'Add COURTLISTENER_TOKEN=... to /etc/fca/api_keys.env',
    ]);
    exit;
}

// ── INPUT ────────────────────────────────────────────────────────────
$op = preg_replace('/[^a-z_]/', '', (string)($_GET['op'] ?? ''));
$ALLOWED = [
    'search_people', 'person', 'positions', 'educations', 'political',
    'disclosures', 'disclosure', 'investments', 'gifts',
];
if (!in_array($op, $ALLOWED, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Unsupported op. Allowed: ' . implode(', ', $ALLOWED)]);
    exit;
}

// Clean ID inputs (numeric only, at most 12 digits)
$id     = preg_replace('/[^0-9]/', '', (string)($_GET['id']     ?? ''));
$person = preg_replace('/[^0-9]/', '', (string)($_GET['person'] ?? ''));
$q      = substr((string)($_GET['q'] ?? ''), 0, 200);
// Keep only characters a person's name might reasonably include
$q      = preg_replace('/[^a-zA-Z0-9 .,\'\-]/u', '', $q);

// ── URL BUILD ────────────────────────────────────────────────────────
// CourtListener v4 REST base. Endpoints: /people/, /positions/, /educations/,
// /political-affiliations/, /financial-disclosures/, /disclosure-positions/,
// /investments/, /gifts/.
$base = 'https://www.courtlistener.com/api/rest/v4';
$url  = null;

switch ($op) {
    case 'search_people':
        if ($q === '') { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'q required']); exit; }
        // CourtListener v4 rejects unknown filters; `name_full` is not one of
        // them. Split the query heuristically: last token = name_last, first
        // token = name_first. Works for "Roberts" or "John Roberts" alike.
        // Strip common honorifics first so "Hon. Jane Doe" → first=Jane, last=Doe.
        $stripped = trim(preg_replace('/^(Hon\.|Judge|Justice|The Honorable)\s+/i', '', $q));
        $parts    = preg_split('/\s+/', $stripped, -1, PREG_SPLIT_NO_EMPTY);
        $params   = ['page_size' => 20];
        if (count($parts) === 1) {
            $params['name_last'] = $parts[0];
        } else {
            $params['name_first'] = $parts[0];
            $params['name_last']  = end($parts);
        }
        $url = $base . '/people/?' . http_build_query($params);
        break;
    case 'person':
        if ($id === '') { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'id required']); exit; }
        $url = $base . '/people/' . $id . '/';
        break;
    case 'positions':
        if ($person === '') { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'person required']); exit; }
        $url = $base . '/positions/?' . http_build_query(['person' => $person, 'page_size' => 50]);
        break;
    case 'educations':
        if ($person === '') { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'person required']); exit; }
        $url = $base . '/educations/?' . http_build_query(['person' => $person, 'page_size' => 20]);
        break;
    case 'political':
        if ($person === '') { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'person required']); exit; }
        $url = $base . '/political-affiliations/?' . http_build_query(['person' => $person, 'page_size' => 20]);
        break;
    case 'disclosures':
        if ($person === '') { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'person required']); exit; }
        $url = $base . '/financial-disclosures/?' . http_build_query(['person' => $person, 'page_size' => 20]);
        break;
    case 'disclosure':
        if ($id === '') { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'id required']); exit; }
        $url = $base . '/financial-disclosures/' . $id . '/';
        break;
    case 'investments':
        if ($person === '') { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'person required']); exit; }
        // v4 exposes investments under /investments/ filtered by the disclosure's person chain
        $url = $base . '/investments/?' . http_build_query(['financial_disclosure__person' => $person, 'page_size' => 100]);
        break;
    case 'gifts':
        if ($person === '') { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'person required']); exit; }
        $url = $base . '/gifts/?' . http_build_query(['financial_disclosure__person' => $person, 'page_size' => 100]);
        break;
}

// ── CACHE LOOKUP ─────────────────────────────────────────────────────
$cache_key = $op . '|' . md5($url);
$cached = fca_cache_get('courtlistener', $cache_key, 86400);
if ($cached !== null) {
    echo json_encode(['ok' => true, 'source' => 'courtlistener', 'op' => $op, 'data' => json_decode($cached, true), 'cached' => true]);
    exit;
}

// ── FORWARD ──────────────────────────────────────────────────────────
$resp = fca_http_get_json($url, ['Authorization' => 'Token ' . $token], 20);

if (!$resp['ok']) {
    http_response_code($resp['status'] ?: 502);
    echo json_encode([
        'ok'     => false,
        'source' => 'courtlistener',
        'op'     => $op,
        'status' => $resp['status'],
        'error'  => $resp['error'] ?: 'upstream error',
    ]);
    exit;
}

// Cache the raw upstream body as a JSON string so we can re-serve it cheaply.
fca_cache_put('courtlistener', $cache_key, json_encode($resp['body']));

echo json_encode([
    'ok'     => true,
    'source' => 'courtlistener',
    'op'     => $op,
    'data'   => $resp['body'],
    'cached' => false,
]);
