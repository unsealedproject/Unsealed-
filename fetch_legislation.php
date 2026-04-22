<?php
/**
 * Cron-callable legislation harvester.
 *
 *   php fetch_legislation.php               # uses LEGISCAN_API_KEY env var
 *   LEGISCAN_API_KEY=xxxxx php fetch_legislation.php
 *
 * Queries LegiScan for state bills + Congress.gov for federal bills whose
 * titles or summaries contain family-court keywords. Deduplicates, ranks
 * by most-recent action, and writes the top 30 to legislation_feed.json
 * for the client-side ticker.
 *
 * Schedule with cron (once a day is plenty — bill statuses don't change
 * faster than that for this use case):
 *
 *   0 6 * * *  cd /var/www/fca && php fetch_legislation.php >> /var/log/fca_leg.log 2>&1
 *
 * NOTE: this does not ship with keys. Set LEGISCAN_API_KEY (free tier at
 * https://legiscan.com/legiscan) and optionally CONGRESS_API_KEY
 * (free at https://api.congress.gov/sign-up/). Without LEGISCAN_API_KEY the
 * state portion is skipped; without CONGRESS_API_KEY the federal portion is
 * skipped. If both are missing the script exits with no file change so a
 * half-working cron doesn't blank out a previously-populated feed.
 */

define('OUT_PATH',  __DIR__ . '/legislation_feed.json');
define('MAX_BILLS', 30);

// Keyword list applied to bill titles + descriptions. Hits bump relevance score.
$KEYWORDS = [
    'custody', 'parenting plan', 'parenting time', 'visitation',
    'child support', 'child welfare', 'foster care',
    'parental rights', 'family court', 'family law',
    'guardian ad litem', 'shared parenting', 'equal parenting',
    'child protective services', 'kinship care',
    'UCCJEA', 'UIFSA', 'paternity', 'adoption',
    'termination of parental rights', 'domestic relations',
    'protective order', 'restraining order', 'PFA',
    'child abduction', 'alienation',
];

$legiscanKey = getenv('LEGISCAN_API_KEY') ?: '';
$congressKey = getenv('CONGRESS_API_KEY') ?: '';
if (!$legiscanKey && !$congressKey) {
    fwrite(STDERR, "[fetch_legislation] No API keys configured — exiting without touching the feed.\n");
    exit(2);
}

$collected = [];

/** Lightweight JSON GET with a short timeout and null fallback. */
function http_get_json(string $url, int $timeoutSec = 15): ?array {
    $ctx = stream_context_create([
        'http' => [
            'method'  => 'GET',
            'timeout' => $timeoutSec,
            'header'  => "User-Agent: Unsealed-LegFetcher/1.0\r\nAccept: application/json\r\n",
            'ignore_errors' => true,
        ],
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) return null;
    $parsed = json_decode($raw, true);
    return is_array($parsed) ? $parsed : null;
}

function score_bill(string $haystack, array $keywords): int {
    $h = strtolower($haystack);
    $n = 0;
    foreach ($keywords as $kw) {
        if (strpos($h, strtolower($kw)) !== false) $n++;
    }
    return $n;
}

// ── STATE BILLS via LegiScan ──────────────────────────────────────────
if ($legiscanKey) {
    // getSearch returns up to ~50 results per query. We issue a handful of
    // targeted queries rather than one huge OR expression (LegiScan's query
    // parser treats adjacent words as a phrase by default).
    $queries = [
        'custody+OR+"child support"',
        '"parenting plan"+OR+visitation',
        '"family court"+OR+"guardian ad litem"',
        '"parental rights"+OR+"shared parenting"',
        '"foster care"+OR+"child welfare"',
        '"protective order"+OR+"restraining order"',
    ];
    foreach ($queries as $q) {
        $url = 'https://api.legiscan.com/?key=' . urlencode($legiscanKey)
             . '&op=getSearch&state=ALL&query=' . $q;
        $data = http_get_json($url);
        if (!$data || ($data['status'] ?? '') !== 'OK') continue;
        $results = $data['searchresult'] ?? [];
        foreach ($results as $k => $r) {
            if ($k === 'summary' || !is_array($r)) continue;
            $title  = $r['title'] ?? '';
            $rel    = score_bill(($title . ' ' . ($r['last_action'] ?? '')), $GLOBALS['KEYWORDS']);
            if ($rel === 0) continue;
            $collected[$r['bill_id']] = [
                'source'       => 'legiscan',
                'billId'       => $r['bill_number'] ?? '',
                'jurisdiction' => $r['state'] ?? '',
                'title'        => $title,
                'status'       => $r['last_action'] ?? '',
                'statusDate'   => $r['last_action_date'] ?? '',
                'url'          => $r['url'] ?? $r['text_url'] ?? '',
                'relevance'    => $rel,
            ];
        }
    }
}

// ── FEDERAL BILLS via Congress.gov ────────────────────────────────────
if ($congressKey) {
    // /bill/{congress}/{billType} with query filter — use current Congress (119th = 2025-2026)
    $congress = 119;
    $types = ['hr', 's'];  // House + Senate; skip resolutions
    foreach ($types as $t) {
        $url = "https://api.congress.gov/v3/bill/{$congress}/{$t}?format=json&limit=100&api_key=" . urlencode($congressKey);
        $data = http_get_json($url, 20);
        if (!$data) continue;
        $bills = $data['bills'] ?? [];
        foreach ($bills as $b) {
            $title = $b['title'] ?? '';
            $rel = score_bill($title, $GLOBALS['KEYWORDS']);
            if ($rel === 0) continue;
            $key = 'cgr-' . ($b['number'] ?? '') . '-' . $t;
            $latestAction = $b['latestAction'] ?? [];
            $collected[$key] = [
                'source'       => 'congress',
                'billId'       => strtoupper($t) . ($b['number'] ?? ''),
                'jurisdiction' => 'Federal',
                'title'        => $title,
                'status'       => $latestAction['text'] ?? '',
                'statusDate'   => $latestAction['actionDate'] ?? '',
                'url'          => $b['url'] ?? '',
                'relevance'    => $rel,
            ];
        }
    }
}

// Rank: higher relevance first, then most recent status date
usort($collected, function($a, $b) {
    if ($a['relevance'] !== $b['relevance']) return $b['relevance'] - $a['relevance'];
    return strcmp($b['statusDate'] ?? '', $a['statusDate'] ?? '');
});
$top = array_slice(array_values($collected), 0, MAX_BILLS);

$out = [
    'version'     => 1,
    'lastUpdated' => gmdate('c'),
    'source'      => 'LegiScan + Congress.gov',
    'bills'       => $top,
];

$tmp = OUT_PATH . '.tmp';
file_put_contents($tmp, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
rename($tmp, OUT_PATH);

fwrite(STDERR, "[fetch_legislation] wrote " . count($top) . " bills.\n");
exit(0);
