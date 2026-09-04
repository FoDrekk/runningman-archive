<?php
// ============================================================
// RmCache — TTL file cache for source pages, API responses and
// parsed source data, plus NEGATIVE caching (retry suppression).
//
// Why negative caching matters: without it, a bulk run over 800
// episodes hits a dead or blocking source 800 times and gets 800
// identical failures. One failure now suppresses that exact request
// for a short window, so the run stays fast and the source stays
// unprovoked.
//
// Entries are JSON envelopes ({v: value, e: expires, t: type}) so an
// empty array — a legitimately cacheable parse result — is never
// confused with "cache miss", the exact bug that used to freeze the
// Wikipedia year cache in a permanently-empty state.
// ============================================================
require_once __DIR__ . '/../../config/scraping.php';

class RmCache
{
    private string $dir;
    private bool   $enabled;
    /** @var array<string,mixed> in-request memo so one run never re-reads the same file */
    private array  $memo = [];
    /** Bumped by every flush. Parsers that memoise derived results for the
     *  life of the process (see rmWikiParseYear) compare against this so a
     *  cleared cache is actually cleared for them too — otherwise a
     *  long-running worker keeps serving listings the admin just discarded. */
    private static int $generation = 0;

    public function __construct(?string $dir = null) {
        $this->enabled = (bool)rmScrapeConfig('cache.enabled', true);
        $dir = $dir ?: rmScrapeConfig('cache.dir') ?: (sys_get_temp_dir() . '/rm_scrape_cache');
        $this->dir = rtrim($dir, '/');
        if ($this->enabled && !is_dir($this->dir)) @mkdir($this->dir, 0775, true);
    }

    public static function instance(): self {
        static $i = null;
        return $i ?: ($i = new self());
    }

    public function dir(): string { return $this->dir; }

    /** Changes whenever the cache is flushed. */
    public static function generation(): int { return self::$generation; }

    private function pathFor(string $key): string {
        return $this->dir . '/' . preg_replace('/[^a-z0-9_.-]/i', '_', substr($key, 0, 40))
             . '_' . sha1($key) . '.json';
    }

    /** Returns $default on miss/expiry. A cached NULL is still a hit. */
    public function get(string $key, $default = null) {
        if (!$this->enabled) return $default;
        if (array_key_exists($key, $this->memo)) return $this->memo[$key];

        $f = $this->pathFor($key);
        if (!is_file($f)) return $default;
        $raw = @file_get_contents($f);
        if ($raw === false || $raw === '') return $default;
        $env = json_decode($raw, true);
        if (!is_array($env) || !array_key_exists('v', $env)) return $default;
        if (isset($env['e']) && $env['e'] > 0 && $env['e'] < time()) { @unlink($f); return $default; }

        $this->memo[$key] = $env['v'];
        return $env['v'];
    }

    public function has(string $key): bool {
        $miss = "\0__rm_miss__\0";
        return $this->get($key, $miss) !== $miss;
    }

    public function set(string $key, $value, int $ttl, string $type = 'page'): bool {
        if (!$this->enabled || $ttl <= 0) return false;
        $this->memo[$key] = $value;
        $env = ['v' => $value, 'e' => time() + $ttl, 't' => $type, 'c' => time()];
        $json = json_encode($env, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) return false;
        $tmp = $this->pathFor($key) . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $json) === false) return false;
        return @rename($tmp, $this->pathFor($key));   // atomic — no half-written cache reads
    }

    public function forget(string $key): void {
        unset($this->memo[$key]);
        @unlink($this->pathFor($key));
    }

    /** Age in seconds of a cached entry, or null if absent. */
    public function age(string $key): ?int {
        $f = $this->pathFor($key);
        if (!is_file($f)) return null;
        $env = json_decode((string)@file_get_contents($f), true);
        return isset($env['c']) ? max(0, time() - (int)$env['c']) : null;
    }

    /** @return int number of files removed */
    public function flush(?string $type = null): int {
        $n = 0;
        foreach (glob($this->dir . '/*.json') ?: [] as $f) {
            if ($type !== null) {
                $env = json_decode((string)@file_get_contents($f), true);
                if (($env['t'] ?? null) !== $type) continue;
            }
            if (@unlink($f)) $n++;
        }
        $this->memo = [];
        self::$generation++;
        return $n;
    }

    /** Drop only entries whose TTL has already elapsed. */
    public function purgeExpired(): int {
        $n = 0;
        foreach (glob($this->dir . '/*.json') ?: [] as $f) {
            $env = json_decode((string)@file_get_contents($f), true);
            if (is_array($env) && isset($env['e']) && $env['e'] > 0 && $env['e'] < time() && @unlink($f)) $n++;
        }
        return $n;
    }

    public function stats(): array {
        $files = glob($this->dir . '/*.json') ?: [];
        $bytes = 0; $byType = []; $expired = 0;
        foreach ($files as $f) {
            $bytes += (int)@filesize($f);
            $env = json_decode((string)@file_get_contents($f), true);
            $t = $env['t'] ?? 'unknown';
            $byType[$t] = ($byType[$t] ?? 0) + 1;
            if (isset($env['e']) && $env['e'] > 0 && $env['e'] < time()) $expired++;
        }
        return ['dir'=>$this->dir,'files'=>count($files),'bytes'=>$bytes,'expired'=>$expired,'by_type'=>$byType,'enabled'=>$this->enabled];
    }

    /**
     * TTL for an episode-scoped fetch: the newest handful of episodes
     * change (thumbnail added, synopsis written) for days after airing,
     * while EP12 from 2010 has been settled for over a decade.
     */
    public static function episodeTtl(int $epNum, ?int $latestKnown = null, string $class = 'page'): int {
        $window = (int)rmScrapeConfig('cache.recent_window', 6);
        $isRecent = $latestKnown !== null && $epNum > ($latestKnown - $window);
        if ($isRecent) return (int)rmScrapeConfig('cache.ttl_recent', 900);
        if ($class === 'api')   return (int)rmScrapeConfig('cache.ttl_api', 43200);
        if ($class === 'index') return (int)rmScrapeConfig('cache.ttl_index', 1800);
        // Anything older than ~2 years is reference data and effectively frozen.
        return $epNum > 0 && $epNum < 700
            ? (int)rmScrapeConfig('cache.ttl_reference', 604800)
            : (int)rmScrapeConfig('cache.ttl_page', 21600);
    }
}
