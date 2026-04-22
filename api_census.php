<?php
/**
 * api_census.php — server-side proxy for Census ACS 5-year data.
 *
 * No API key required for low-volume queries. Added as a proxy anyway so we
 * get SQLite caching (this data only updates annually — 90-day TTL is fine)
 * and consistent input validation with the other api_*.php endpoints.
 *
 * Supported ops:
 *   op=parent_households  year=<YYYY>            — table B09005 by state
 *                                                   (single-parent household counts)
 *   op=child_population   year=<YYYY>            — table B09001 by state
 *                                                   (own children under 18)
 *   op=household_income   year=<YYYY>            — table B19013 by state
 *   op=state_lookup       — static state-FIPS lookup table (handy for the client)
 *
 * Used by the ACF-vs-Census gap metric: ACF counts IV-D cases per state;
 * Census tells us roughly how many parents/children live there. The ratio
 * surfaces which states over- or under-enroll relative to population.
 */

require_once __DIR__ . '/api_keys.php';
fca_proxy_headers();

if (!fca_rate_ok('census', 300)) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'Rate limit: 300 requests per hour per IP.']);
    exit;
}

$op = preg_replace('/[^a-z_]/', '', (string)($_GET['op'] ?? ''));
$ALLOWED = ['parent_households', 'child_population', 'household_income', 'state_lookup', 'zip_to_cd'];
if (!in_array($op, $ALLOWED, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Unsupported op. Allowed: ' . implode(', ', $ALLOWED)]);
    exit;
}

// state_lookup is constant — serve directly, no upstream call
if ($op === 'state_lookup') {
    echo json_encode([
        'ok'     => true,
        'source' => 'census',
        'op'     => 'state_lookup',
        'data'   => [
            '01' => 'Alabama','02' => 'Alaska','04' => 'Arizona','05' => 'Arkansas','06' => 'California',
            '08' => 'Colorado','09' => 'Connecticut','10' => 'Delaware','11' => 'District of Columbia',
            '12' => 'Florida','13' => 'Georgia','15' => 'Hawaii','16' => 'Idaho','17' => 'Illinois',
            '18' => 'Indiana','19' => 'Iowa','20' => 'Kansas','21' => 'Kentucky','22' => 'Louisiana',
            '23' => 'Maine','24' => 'Maryland','25' => 'Massachusetts','26' => 'Michigan','27' => 'Minnesota',
            '28' => 'Mississippi','29' => 'Missouri','30' => 'Montana','31' => 'Nebraska','32' => 'Nevada',
            '33' => 'New Hampshire','34' => 'New Jersey','35' => 'New Mexico','36' => 'New York',
            '37' => 'North Carolina','38' => 'North Dakota','39' => 'Ohio','40' => 'Oklahoma','41' => 'Oregon',
            '42' => 'Pennsylvania','44' => 'Rhode Island','45' => 'South Carolina','46' => 'South Dakota',
            '47' => 'Tennessee','48' => 'Texas','49' => 'Utah','50' => 'Vermont','51' => 'Virginia',
            '53' => 'Washington','54' => 'West Virginia','55' => 'Wisconsin','56' => 'Wyoming',
        ],
        'cached' => true,
    ]);
    exit;
}

// zip_to_cd: resolve a ZIP code to its US House congressional district. Uses
// the Census Geocoder onelineaddress endpoint with the ZIP as the address.
// Returns {state, district, cd} — no key required. Cached per ZIP for 90 days
// since district boundaries only change at reapportionment.
if ($op === 'zip_to_cd') {
    $zip = preg_replace('/[^0-9]/', '', (string)($_GET['zip'] ?? ''));
    if (strlen($zip) !== 5) {
        http_response_code(400);
        echo json_encode(['ok'=>false,'error'=>'5-digit ZIP required']);
        exit;
    }
    $cacheKey = 'zip_cd|' . $zip;
    $cached = fca_cache_get('census', $cacheKey, 90 * 86400);
    if ($cached !== null) {
        echo json_encode(['ok'=>true,'source'=>'census','op'=>'zip_to_cd','data'=>json_decode($cached, true),'cached'=>true]);
        exit;
    }
    // Census geocoder — plug ZIP as the one-line address. Returns matched
    // addresses with geographies including the 119th Congress CD.
    $url = 'https://geocoding.geo.census.gov/geocoder/geographies/onelineaddress?' . http_build_query([
        'address'   => $zip,
        'benchmark' => 'Public_AR_Current',
        'vintage'   => 'Current_Current',
        'layers'    => '119th Congressional Districts',
        'format'    => 'json',
    ]);
    $resp = fca_http_get_json($url, [], 12);
    if (!$resp['ok']) {
        http_response_code($resp['status'] ?: 502);
        echo json_encode(['ok'=>false,'source'=>'census','op'=>'zip_to_cd','error'=>$resp['error'] ?: 'upstream error']);
        exit;
    }
    $match = ($resp['body']['result']['addressMatches'][0] ?? null);
    if (!$match) {
        echo json_encode(['ok'=>false,'source'=>'census','op'=>'zip_to_cd','error'=>'No geocoder match for ZIP. Try with full address.']);
        exit;
    }
    $geogs = $match['geographies'] ?? [];
    $cd    = null;
    // The congressional-districts key can be "119th Congressional Districts"
    // or fall back to a shorter name depending on vintage.
    foreach ($geogs as $k => $arr) {
        if (stripos($k, 'congressional') !== false && is_array($arr) && count($arr)) {
            $cd = $arr[0];
            break;
        }
    }
    if (!$cd) {
        echo json_encode(['ok'=>false,'source'=>'census','op'=>'zip_to_cd','error'=>'No CD found in geocoder response']);
        exit;
    }
    $stateAB = $cd['STATE'] ?? null;
    $districtCode = $cd['CD119'] ?? $cd['BASENAME'] ?? null;
    // State comes back as FIPS ("06" = California) in geocoder. Convert.
    $FIPS_AB = ['01'=>'AL','02'=>'AK','04'=>'AZ','05'=>'AR','06'=>'CA','08'=>'CO','09'=>'CT','10'=>'DE','11'=>'DC','12'=>'FL','13'=>'GA','15'=>'HI','16'=>'ID','17'=>'IL','18'=>'IN','19'=>'IA','20'=>'KS','21'=>'KY','22'=>'LA','23'=>'ME','24'=>'MD','25'=>'MA','26'=>'MI','27'=>'MN','28'=>'MS','29'=>'MO','30'=>'MT','31'=>'NE','32'=>'NV','33'=>'NH','34'=>'NJ','35'=>'NM','36'=>'NY','37'=>'NC','38'=>'ND','39'=>'OH','40'=>'OK','41'=>'OR','42'=>'PA','44'=>'RI','45'=>'SC','46'=>'SD','47'=>'TN','48'=>'TX','49'=>'UT','50'=>'VT','51'=>'VA','53'=>'WA','54'=>'WV','55'=>'WI','56'=>'WY'];
    $stateAbbr = $FIPS_AB[$stateAB] ?? null;
    $out = ['zip'=>$zip,'stateFips'=>$stateAB,'stateAB'=>$stateAbbr,'district'=>$districtCode,'cdName'=>($cd['NAME'] ?? null)];
    fca_cache_put('census', $cacheKey, json_encode($out));
    echo json_encode(['ok'=>true,'source'=>'census','op'=>'zip_to_cd','data'=>$out,'cached'=>false]);
    exit;
}

$year = (int)($_GET['year'] ?? 2023);
// ACS 5-year releases roughly 1 year behind; clamp to a sane window
if ($year < 2010 || $year > 2030) $year = 2023;

// Census ACS 5-year data API endpoint format:
//   https://api.census.gov/data/<year>/acs/acs5?get=<vars>&for=state:*
// Variables (_E suffix = estimate, _M = margin of error):
//   B09005_001E — total households with own children under 18
//   B09005_004E — one-parent households: male householder, no spouse, with own children
//   B09005_005E — one-parent households: female householder, no spouse, with own children
//   B09001_001E — own children under 18 in households
//   B19013_001E — median household income

$vars = null;
switch ($op) {
    case 'parent_households':
        $vars = 'NAME,B09005_001E,B09005_004E,B09005_005E';
        break;
    case 'child_population':
        $vars = 'NAME,B09001_001E';
        break;
    case 'household_income':
        $vars = 'NAME,B19013_001E';
        break;
}

$url = 'https://api.census.gov/data/' . $year . '/acs/acs5?' . http_build_query([
    'get' => $vars,
    'for' => 'state:*',
]);

// 90-day cache — ACS 5-year doesn't update faster than that
$cache_key = $op . '|' . $year;
$cached = fca_cache_get('census', $cache_key, 90 * 86400);
if ($cached !== null) {
    echo json_encode(['ok' => true, 'source' => 'census', 'op' => $op, 'year' => $year, 'data' => json_decode($cached, true), 'cached' => true]);
    exit;
}

$resp = fca_http_get_json($url, [], 30);
if (!$resp['ok']) {
    http_response_code($resp['status'] ?: 502);
    echo json_encode([
        'ok'     => false,
        'source' => 'census',
        'op'     => $op,
        'status' => $resp['status'],
        'error'  => $resp['error'] ?: 'upstream error',
    ]);
    exit;
}

fca_cache_put('census', $cache_key, json_encode($resp['body']));

echo json_encode([
    'ok'     => true,
    'source' => 'census',
    'op'     => $op,
    'year'   => $year,
    'data'   => $resp['body'],
    'cached' => false,
]);
