<?php
// ============================================================
// RmHttpClient — one polite, diagnosable HTTP layer for every source.
//
// Three things the old inline curl calls did not do:
//   1. CLASSIFY failures. "Scraper failed" is useless; DNS failure,
//      TLS failure, 403-blocked, 429-rate-limited, 404-missing and
//      "200 but empty body" each need a different response, and the
//      retry policy below branches on exactly that classification.
//   2. Only retry TRANSIENT failures. A 404 is a fact, not a hiccup —
//      retrying it three times just triples the load for the same
//      answer. Timeouts and 5xx are retried with exponential backoff
//      plus jitter so parallel runs never resonate into a storm.
//   3. Be polite. Per-host minimum spacing, Retry-After compliance,
//      robots.txt checking, and negative caching so a blocking host is
//      asked once per window rather than once per episode.
// ============================================================
require_once __DIR__ . '/Cache.php';

class RmHttpResponse
{
    public bool    $ok          = false;
    public int     $status      = 0;
    public ?string $body        = null;
    public string  $errorClass  = 'ok';     // see RmHttpClient::CLASS_*
    public ?string $error       = null;
    public int     $ms          = 0;
    public bool    $fromCache   = false;
    public string  $contentType = '';
    public string  $url         = '';
    public int     $attempts    = 0;

    public function length(): int { return $this->body === null ? 0 : strlen($this->body); }

    public function toArray(): array {
        return ['ok'=>$this->ok,'status'=>$this->status,'error_class'=>$this->errorClass,
                'error'=>$this->error,'ms'=>$this->ms,'cached'=>$this->fromCache,
                'bytes'=>$this->length(),'url'=>$this->url,'attempts'=>$this->attempts];
    }
}

class RmHttpClient
{
    // Failure taxonomy — these strings are stored in source_health and
    // shown verbatim in Admin, so they are part of the diagnostics API.
    const CLASS_OK           = 'ok';
    const CLASS_DNS          = 'dns_failure';
    const CLASS_CONNECT      = 'connection_refused';
    const CLASS_TIMEOUT      = 'timeout';
    const CLASS_SSL          = 'ssl_failure';
    const CLASS_NOT_FOUND    = 'not_found';        // 404/410 — permanent, never retried
    const CLASS_BLOCKED      = 'blocked';          // 401/403 — bot filtering
    const CLASS_RATE_LIMITED = 'rate_limited';     // 429
    const CLASS_HTTP_4XX     = 'http_client_error';
    const CLASS_HTTP_5XX     = 'http_server_error';
    const CLASS_EMPTY        = 'empty_response';   // 200 with nothing usable in it
    const CLASS_REDIRECT     = 'redirect_loop';
    const CLASS_ROBOTS       = 'robots_denied';
    const CLASS_PROXY        = 'proxy_blocked';      // a proxy/gateway refused the tunnel
    const CLASS_COOLING_DOWN = 'cooling_down';       // suppressed by source health, not attempted
    const CLASS_OFFLINE      = 'offline_mode';       // outbound requests disabled (tests/CI)
    const CLASS_NO_CURL      = 'curl_missing';
    const CLASS_OTHER        = 'unknown_failure';

    /** @var array<string,float> host → unix ts of last request (per-process spacing) */
    private static array $lastHit = [];
    private RmCache $cache;
    private ?string $lastError = null;

    public function __construct(?RmCache $cache = null) {
        $this->cache = $cache ?: RmCache::instance();
    }

    public static function instance(): self {
        static $i = null;
        return $i ?: ($i = new self());
    }

    public function lastError(): ?string { return $this->lastError; }

    /**
     * @param array $opt timeout, retries, headers[], min_bytes, cache_ttl,
     *                   cache_key, delay_ms, source, bypass_cache, accept_json
     */
    public function get(string $url, array $opt = []): RmHttpResponse {
        $r = new RmHttpResponse();
        $r->url = $url;

        $ttl      = (int)($opt['cache_ttl'] ?? 0);
        $cacheKey = 'http:' . ($opt['cache_key'] ?? $url);
        $minBytes = (int)($opt['min_bytes'] ?? 0);

        // ── Negative cache: a recent failure for this exact request is
        // replayed instead of re-issued. Keeps bulk runs off dead hosts.
        $negKey = 'neg:' . $cacheKey;
        $neg = $this->cache->get($negKey);
        if (is_array($neg) && empty($opt['bypass_cache'])) {
            $r->status = (int)($neg['status'] ?? 0);
            $r->errorClass = (string)($neg['class'] ?? self::CLASS_OTHER);
            $r->error = ($neg['error'] ?? 'previous failure') . ' (suppressed retry)';
            $r->fromCache = true;
            $this->lastError = $r->error;
            return $r;
        }

        if ($ttl > 0 && empty($opt['bypass_cache'])) {
            $hit = $this->cache->get($cacheKey);
            if (is_string($hit) && ($minBytes === 0 || strlen($hit) >= $minBytes)) {
                $r->ok = true; $r->status = 200; $r->body = $hit; $r->fromCache = true;
                return $r;
            }
        }

        // cache_only: the caller has decided this source must not be
        // contacted right now — it is in a health cool-down. Cached data
        // is still perfectly usable, so the check sits HERE, after the
        // cache lookup and before the socket, rather than at source
        // selection where it would discard the cache too.
        if (!empty($opt['cache_only'])) {
            $r->errorClass = self::CLASS_COOLING_DOWN;
            $r->error = 'Not contacted — source is in a health cool-down and nothing is cached for this request';
            $this->lastError = $r->error;
            return $r;
        }

        if (!function_exists('curl_init')) {
            $r->errorClass = self::CLASS_NO_CURL;
            $r->error = 'PHP curl extension not available';
            $this->lastError = $r->error;
            return $r;
        }

        // Offline mode: loopback still works (test fixtures live there),
        // everything else is refused before a socket is opened.
        if (rmScrapeConfig('http.offline', false) && !self::isLoopback($url)) {
            $r->errorClass = self::CLASS_OFFLINE;
            $r->error = 'Outbound requests are disabled (RM_SCRAPE_OFFLINE) — no live source was contacted';
            $this->lastError = $r->error;
            return $r;
        }

        if (!empty($opt['respect_robots'] ?? rmScrapeConfig('http.respect_robots', true))
            && !$this->robotsAllows($url)) {
            $r->errorClass = self::CLASS_ROBOTS;
            $r->error = 'Disallowed by robots.txt for this path';
            $this->lastError = $r->error;
            $this->cache->set($negKey, ['status'=>0,'class'=>$r->errorClass,'error'=>$r->error],
                              (int)rmScrapeConfig('cache.ttl_blocked', 3600), 'negative');
            return $r;
        }

        $timeout  = (int)($opt['timeout'] ?? rmScrapeConfig('http.timeout', 15));
        $retries  = (int)($opt['retries'] ?? rmScrapeConfig('http.max_retries', 2));
        $baseMs   = (int)rmScrapeConfig('http.backoff_base_ms', 400);
        $maxMs    = (int)rmScrapeConfig('http.backoff_max_ms', 8000);
        $delayMs  = (int)($opt['delay_ms'] ?? rmScrapeConfig('http.default_delay_ms', 900));

        $headers = array_merge([
            'Accept: text/html,application/xhtml+xml,application/json;q=0.9,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.9,ko;q=0.6',
        ], $opt['headers'] ?? []);

        $started = microtime(true);
        $lastClass = self::CLASS_OTHER; $lastMsg = 'no attempt made'; $lastStatus = 0;

        for ($attempt = 0; $attempt <= $retries; $attempt++) {
            $this->throttle($url, $delayMs);
            $r->attempts = $attempt + 1;

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 4,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => (int)rmScrapeConfig('http.connect_timeout', 8),
                CURLOPT_USERAGENT      => (string)($opt['user_agent'] ?? rmScrapeConfig('http.user_agent', RM_SCRAPER_UA)),
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_ENCODING       => 'gzip, deflate',
                CURLOPT_HEADER         => true,
            ]);
            $raw     = curl_exec($ch);
            $info    = curl_getinfo($ch);
            $errno   = curl_errno($ch);
            $errMsg  = curl_error($ch);
            curl_close($ch);

            $status  = (int)($info['http_code'] ?? 0);
            if (!empty($info['url'])) $r->url = (string)$info['url'];   // after redirects, success or not
            $hdrSize = (int)($info['header_size'] ?? 0);
            $rawHeaders = is_string($raw) ? substr($raw, 0, $hdrSize) : '';
            $body    = is_string($raw) ? substr($raw, $hdrSize) : false;
            $lastStatus = $status;

            if ($errno !== 0) {
                [$lastClass, $lastMsg] = $this->classifyCurl($errno, $errMsg);
            } elseif ($status >= 200 && $status < 300) {
                if ($body === false || $body === '' || ($minBytes > 0 && strlen($body) < $minBytes)) {
                    $lastClass = self::CLASS_EMPTY;
                    $lastMsg   = 'HTTP 200 but body was ' . (($body === false || $body === '') ? 'empty' : strlen($body) . ' bytes (expected ≥ ' . $minBytes . ')');
                } else {
                    $r->ok = true; $r->status = $status; $r->body = $body;
                    $r->contentType = (string)($info['content_type'] ?? '');
                    $r->url = (string)($info['url'] ?? $url);
                    $r->errorClass = self::CLASS_OK;
                    $r->ms = (int)round((microtime(true) - $started) * 1000);
                    $this->lastError = null;
                    if ($ttl > 0) $this->cache->set($cacheKey, $body, $ttl, $opt['cache_type'] ?? 'page');
                    return $r;
                }
            } else {
                [$lastClass, $lastMsg] = $this->classifyHttp($status);
            }

            $transient = in_array($lastClass, [
                self::CLASS_TIMEOUT, self::CLASS_HTTP_5XX, self::CLASS_CONNECT,
                self::CLASS_EMPTY, self::CLASS_OTHER,
            ], true);
            // Rate limiting is retried at most once, and only when the
            // server told us how long to wait. Guessing is what turns a
            // soft limit into a hard ban.
            $retryAfter = $lastClass === self::CLASS_RATE_LIMITED ? $this->retryAfter($rawHeaders) : null;
            if ($retryAfter !== null && $attempt === 0 && $retryAfter <= 30) $transient = true;

            if (!$transient || $attempt === $retries) break;

            $sleepMs = $retryAfter !== null
                ? $retryAfter * 1000
                : min($maxMs, (int)($baseMs * pow(2, $attempt)));
            $sleepMs += random_int(0, 250);   // jitter — de-synchronise concurrent runs
            usleep($sleepMs * 1000);
        }

        $r->status     = $lastStatus;
        $r->errorClass = $lastClass;
        $r->error      = $lastMsg;
        $r->ms         = (int)round((microtime(true) - $started) * 1000);
        $this->lastError = $lastMsg;

        // Suppress repeats of permanent / blocking failures for a window.
        $negTtl = match ($lastClass) {
            self::CLASS_BLOCKED, self::CLASS_RATE_LIMITED,
            self::CLASS_PROXY                              => (int)rmScrapeConfig('cache.ttl_blocked', 3600),
            self::CLASS_NOT_FOUND                          => (int)rmScrapeConfig('cache.ttl_page', 21600),
            default                                        => (int)rmScrapeConfig('cache.ttl_negative', 600),
        };
        $this->cache->set($negKey, ['status'=>$lastStatus,'class'=>$lastClass,'error'=>$lastMsg], $negTtl, 'negative');
        return $r;
    }

    /** Convenience: GET + json_decode, with the same policy/caching. */
    public function getJson(string $url, array $opt = []): array {
        $opt['headers'] = array_merge(['Accept: application/json'], $opt['headers'] ?? []);
        $opt['cache_type'] = $opt['cache_type'] ?? 'api';
        $res  = $this->get($url, $opt);
        $data = null;
        if ($res->ok) {
            $data = json_decode((string)$res->body, true);
            if (!is_array($data)) {
                $res->ok = false;
                $res->errorClass = self::CLASS_EMPTY;
                $res->error = 'Response was not valid JSON (' . substr(trim(strip_tags((string)$res->body)), 0, 80) . ')';
                $data = null;
            }
        }
        return [$data, $res];
    }

    /** Loopback hosts stay reachable in offline mode so fixtures work. */
    public static function isLoopback(string $url): bool
    {
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        return in_array($host, ['127.0.0.1', 'localhost', '::1', '[::1]'], true);
    }

    private function classifyCurl(int $errno, string $msg): array {
        // A proxy refusing CONNECT surfaces under several errnos with the
        // real cause only in the message, so match on that first.
        if (stripos($msg, 'CONNECT tunnel failed') !== false
            || stripos($msg, 'proxy CONNECT') !== false
            || stripos($msg, 'Received HTTP code 403 from proxy') !== false) {
            return [self::CLASS_PROXY,
                'Blocked by a proxy or gateway before reaching the site (' . trim($msg) . ') — '
                . 'the network is refusing the request, not the source'];
        }
        return match ($errno) {
            5       => [self::CLASS_PROXY, 'Could not resolve the configured HTTP proxy — ' . $msg],
            6       => [self::CLASS_DNS, 'DNS resolution failed — host could not be resolved (no internet, DNS blocked, or domain gone)'],
            7       => [self::CLASS_CONNECT, 'Connection refused — host unreachable or a firewall is blocking it'],
            28      => [self::CLASS_TIMEOUT, 'Request timed out — server too slow or packets dropped'],
            35, 58,
            59, 83  => [self::CLASS_SSL, 'TLS handshake failed — ' . $msg],
            51, 60  => [self::CLASS_SSL, 'SSL certificate verification failed — ' . $msg],
            47      => [self::CLASS_REDIRECT, 'Too many redirects — likely a redirect loop'],
            56      => [self::CLASS_CONNECT, 'Connection broken while receiving data — ' . $msg],
            97      => [self::CLASS_PROXY, 'Proxy handshake failed — ' . $msg],
            default => [self::CLASS_OTHER, "curl error $errno: $msg"],
        };
    }

    private function classifyHttp(int $status): array {
        if ($status === 404 || $status === 410) return [self::CLASS_NOT_FOUND, "HTTP $status — page does not exist (episode not covered by this source)"];
        if ($status === 401 || $status === 403) return [self::CLASS_BLOCKED, "HTTP $status — request blocked (bot filtering, IP or TLS reputation)"];
        if ($status === 429)                    return [self::CLASS_RATE_LIMITED, 'HTTP 429 — rate limited by the source'];
        if ($status >= 500)                     return [self::CLASS_HTTP_5XX, "HTTP $status — source server error"];
        if ($status >= 400)                     return [self::CLASS_HTTP_4XX, "HTTP $status — client error"];
        if ($status === 0)                      return [self::CLASS_OTHER, 'No HTTP response received'];
        return [self::CLASS_OTHER, "Unexpected HTTP $status"];
    }

    private function retryAfter(string $headers): ?int {
        if (preg_match('/^Retry-After:\s*(\d+)/mi', $headers, $m)) return max(1, (int)$m[1]);
        return null;
    }

    /** Per-host minimum spacing so no source ever sees a burst from us. */
    private function throttle(string $url, int $delayMs): void {
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        if ($host === '') return;
        $crawlDelay = $this->robotsCrawlDelay($host);
        if ($crawlDelay !== null) $delayMs = max($delayMs, $crawlDelay * 1000);
        $last = self::$lastHit[$host] ?? 0.0;
        $wait = ($last + $delayMs / 1000) - microtime(true);
        if ($wait > 0) usleep((int)min(5_000_000, $wait * 1_000_000));
        self::$lastHit[$host] = microtime(true);
    }

    // ── robots.txt ────────────────────────────────────────────────
    // Deliberately conservative and simple: only the `User-agent: *`
    // group is honoured, longest-matching Disallow wins, and an
    // unreachable robots.txt is treated as "allowed" (the same way
    // every mainstream crawler treats a 404 robots).
    private function robotsRules(string $host, string $scheme = 'https'): array {
        $key = "robots:$host";
        $cached = $this->cache->get($key);
        if (is_array($cached)) return $cached;

        $rules = ['allow' => [], 'disallow' => [], 'crawl_delay' => null];
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => "$scheme://$host/robots.txt",
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 2,
            CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT => (string)rmScrapeConfig('http.user_agent', RM_SCRAPER_UA),
        ]);
        $txt  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (is_string($txt) && $code === 200) {
            $inStar = false;
            foreach (preg_split('/\r?\n/', $txt) as $line) {
                $line = trim(preg_replace('/#.*$/', '', $line));
                if ($line === '' || !str_contains($line, ':')) continue;
                [$field, $value] = array_map('trim', explode(':', $line, 2));
                $field = strtolower($field);
                if ($field === 'user-agent')      { $inStar = ($value === '*'); continue; }
                if (!$inStar) continue;
                if ($field === 'disallow' && $value !== '') $rules['disallow'][] = $value;
                elseif ($field === 'allow' && $value !== '') $rules['allow'][] = $value;
                elseif ($field === 'crawl-delay' && is_numeric($value)) $rules['crawl_delay'] = min(10.0, (float)$value);
            }
        }
        $this->cache->set($key, $rules, (int)rmScrapeConfig('http.robots_ttl', 86400), 'robots');
        return $rules;
    }

    private function robotsCrawlDelay(string $host): ?float {
        if (!rmScrapeConfig('http.respect_robots', true)) return null;
        return $this->robotsRules($host)['crawl_delay'] ?? null;
    }

    public function robotsAllows(string $url): bool {
        $host   = strtolower((string)parse_url($url, PHP_URL_HOST));
        $scheme = (string)(parse_url($url, PHP_URL_SCHEME) ?: 'https');
        if ($host === '') return true;
        $path = (string)(parse_url($url, PHP_URL_PATH) ?: '/');
        $qs   = parse_url($url, PHP_URL_QUERY);
        if ($qs) $path .= '?' . $qs;

        $rules = $this->robotsRules($host, $scheme);
        $best = ['len' => -1, 'allow' => true];
        foreach (['allow' => true, 'disallow' => false] as $kind => $allowed) {
            foreach ($rules[$kind] as $rule) {
                if ($this->robotsMatch($rule, $path) && strlen($rule) > $best['len']) {
                    $best = ['len' => strlen($rule), 'allow' => $allowed];
                }
            }
        }
        return $best['allow'];
    }

    private function robotsMatch(string $rule, string $path): bool {
        $anchored = str_ends_with($rule, '$');
        if ($anchored) $rule = substr($rule, 0, -1);
        $re = '';
        foreach (str_split($rule) as $c) $re .= $c === '*' ? '.*' : preg_quote($c, '~');
        return (bool)preg_match('~^' . $re . ($anchored ? '$' : '') . '~', $path);
    }
}
