<?php
/**
 * api_keys.php — shared server-side config for external-API proxies.
 *
 * NEVER include this file from any client-facing page. It is `require`d by
 * the api_*.php proxy endpoints only, which read keys into local variables
 * and never echo them back.
 *
 * Three lookup paths, in priority order:
 *   1. /etc/fca/api_keys.env   (preferred; outside the web root)
 *   2. <webroot>/config.php    (fallback; same dir as this file)
 *   3. Process env vars        (docker / systemd)
 *
 * api_keys.env format (key=value, one per line, no quotes):
 *   COURTLISTENER_TOKEN=<token>
 *   FEC_API_KEY=<key>
 *   FTM_API_KEY=<key>
 *   ANTHROPIC_API_KEY=<key>         # already used by api_ai.php
 *   LEGISCAN_API_KEY=<key>          # already used by fetch_legislation.php
 *   CONGRESS_API_KEY=<key>          # already used by fetch_legislation.php
 *
 * Deployment: `chmod 600 /etc/fca/api_keys.env` owned by the PHP-FPM user.
 */

if (!defined('FCA_API_KEYS_LOADED')) {
    define('FCA_API_KEYS_LOADED', true);

    /** @return string empty string if not configured */
    function fca_load_key(string $name): string {
        static $env_cache = null;
        if ($env_cache === null) {
            $env_cache = [];
            $env_file = '/etc/fca/api_keys.env';
            if (is_file($env_file) && is_readable($env_file)) {
                foreach (file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                    $line = trim($line);
                    if ($line === '' || $line[0] === '#') continue;
                    $eq = strpos($line, '=');
                    if ($eq === false) continue;
                    $k = trim(substr($line, 0, $eq));
                    $v = trim(substr($line, $eq + 1));
                    // Strip optional quotes around the value
                    if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && substr($v, -1) === $v[0]) {
                        $v = substr($v, 1, -1);
                    }
                    $env_cache[$k] = $v;
                }
            }
        }
        if (!empty($env_cache[$name])) return $env_cache[$name];

        $cfg = __DIR__ . '/config.php';
        if (is_file($cfg)) {
            include_once $cfg;
            if (defined($name)) return (string)constant($name);
        }

        $val = getenv($name);
        return $val ? (string)$val : '';
    }

    /**
     * Return the client's IP. We deliberately do NOT trust
     * X-Forwarded-For — nginx is the direct-facing reverse proxy and there
     * is no CDN or additional hop, so REMOTE_ADDR is always the true peer.
     * Trusting XFF would let a client spoof a different "IP" per request
     * and bypass every rate limit by rotating the header. The returned
     * string is sanitized to IPv4/IPv6 chars only.
     */
    function fca_client_ip(): string {
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $ip = preg_replace('/[^0-9a-fA-F.:]/', '', $ip);
        return $ip !== '' ? $ip : 'unknown';
    }

    /**
     * HMAC-SHA256 bucket key for rate-limit counter files. MD5 was
     * cryptographically fine here (collision, not preimage, is what matters
     * for this use), but using a keyed hash with an on-disk secret means
     * the counter-file naming is not predictable to anyone who reads the
     * source on GitHub. First call generates the secret if missing.
     */
    function fca_rate_hash(string $ip): string {
        static $secret = null;
        if ($secret === null) {
            $path = '/etc/fca/rate_limit.secret';
            if (is_file($path) && is_readable($path)) {
                $secret = trim(@file_get_contents($path));
            }
            if (!$secret) {
                // Fresh server secret, 32 bytes hex. Written 0600 so only the
                // FPM user can read it. We don't error if write fails —
                // fall back to in-memory-only secret for the request lifetime.
                $secret = bin2hex(random_bytes(32));
                @file_put_contents($path, $secret);
                @chmod($path, 0600);
            }
        }
        return hash_hmac('sha256', $ip, $secret);
    }

    /**
     * Per-IP hourly rate-limit check. Returns true if allowed, false if over limit.
     * Prefix namespaces the counter so limits are per-endpoint.
     */
    function fca_rate_ok(string $prefix, int $limit_per_hour): bool {
        $ip   = fca_client_ip();
        $rl   = sys_get_temp_dir() . '/uns_' . $prefix . '_rl_' . fca_rate_hash($ip) . '.json';
        $rld  = is_file($rl) ? (json_decode(@file_get_contents($rl), true) ?: []) : [];
        $hour = date('YmdH');
        if (($rld['h'] ?? '') !== $hour) $rld = ['c' => 0, 'h' => $hour];
        if (($rld['c'] ?? 0) >= $limit_per_hour) return false;
        $rld['c']++;
        @file_put_contents($rl, json_encode($rld), LOCK_EX);
        return true;
    }

    /**
     * Simple SQLite response cache shared across proxy endpoints. Keyed by
     * (api, param_hash). Entries expire after $ttl_seconds. Returning stale
     * cached responses is safer than hitting the external API repeatedly, so
     * we bump the TTL long (24h default).
     */
    function fca_cache_db(): ?SQLite3 {
        static $db = null;
        if ($db !== null) return $db;
        $path = '/var/www/fca/data/api_cache.db';
        $dir  = dirname($path);
        if (!is_dir($dir)) @mkdir($dir, 0750, true);
        try {
            $db = new SQLite3($path);
            $db->busyTimeout(3000);
            $db->exec('PRAGMA journal_mode=WAL;');
            $db->exec('CREATE TABLE IF NOT EXISTS api_cache(api TEXT NOT NULL, key TEXT NOT NULL, response TEXT NOT NULL, created_at INTEGER NOT NULL, PRIMARY KEY(api,key))');
            return $db;
        } catch (Throwable $e) {
            error_log('api_keys: cache DB open failed: ' . $e->getMessage());
            return null;
        }
    }

    function fca_cache_get(string $api, string $key, int $ttl_seconds = 86400): ?string {
        $db = fca_cache_db();
        if (!$db) return null;
        $cutoff = time() - $ttl_seconds;
        $st = $db->prepare('SELECT response FROM api_cache WHERE api=:a AND key=:k AND created_at>=:c LIMIT 1');
        $st->bindValue(':a', $api,    SQLITE3_TEXT);
        $st->bindValue(':k', $key,    SQLITE3_TEXT);
        $st->bindValue(':c', $cutoff, SQLITE3_INTEGER);
        $r = $st->execute();
        $row = $r ? $r->fetchArray(SQLITE3_ASSOC) : null;
        return $row ? (string)$row['response'] : null;
    }

    function fca_cache_put(string $api, string $key, string $response): void {
        $db = fca_cache_db();
        if (!$db) return;
        $st = $db->prepare('INSERT OR REPLACE INTO api_cache(api,key,response,created_at) VALUES(:a,:k,:r,:t)');
        $st->bindValue(':a', $api,       SQLITE3_TEXT);
        $st->bindValue(':k', $key,       SQLITE3_TEXT);
        $st->bindValue(':r', $response,  SQLITE3_TEXT);
        $st->bindValue(':t', time(),     SQLITE3_INTEGER);
        @$st->execute();
    }

    /**
     * HTTPS GET with short timeout, JSON decoding, and error normalization.
     * Returns ['ok'=>bool, 'status'=>int, 'body'=>mixed, 'error'=>string?].
     */
    function fca_http_get_json(string $url, array $headers = [], int $timeout = 20): array {
        $h = ["User-Agent: Unsealed-Proxy/1.0", "Accept: application/json"];
        foreach ($headers as $k => $v) $h[] = $k . ': ' . $v;
        $ctx = stream_context_create([
            'http' => [
                'method'        => 'GET',
                'header'        => implode("\r\n", $h),
                'timeout'       => $timeout,
                'ignore_errors' => true,
            ],
        ]);
        $raw = @file_get_contents($url, false, $ctx);
        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('/^HTTP\/\S+\s+(\d+)/', $line, $m)) $status = (int)$m[1];
        }
        if ($raw === false) return ['ok' => false, 'status' => 0, 'body' => null, 'error' => 'network failure'];
        $decoded = json_decode($raw, true);
        if ($decoded === null && trim($raw) !== 'null') {
            return ['ok' => false, 'status' => $status, 'body' => $raw, 'error' => 'non-JSON response'];
        }
        return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'body' => $decoded, 'error' => null];
    }

    /**
     * Emit standard proxy response headers. Call from every api_*.php
     * endpoint before sending the body.
     */
    function fca_proxy_headers(): void {
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
            http_response_code(200); exit;
        }
    }
}
