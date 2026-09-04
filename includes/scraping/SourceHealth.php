<?php
// ============================================================
// RmSourceHealth — the scraper must know when a source is broken,
// and in WHAT WAY it is broken.
//
// "Scraper failed" is not a diagnosis. A DNS failure means the
// machine lost its network. A 403 means the site started blocking
// us. A 200 with zero parsed fields means THEIR HTML CHANGED and
// our selectors are stale — the most dangerous case, because it
// looks like success to everything upstream and, without this
// class, would quietly overwrite good data with nothing.
//
// Status ladder:
//   ok               recent successes, nothing unusual
//   parser_warning   reachable, but returning no fields where it used to
//   rate_limited     429s / Retry-After
//   blocked          401/403 — bot filtering
//   degraded         intermittent failures
//   down             consecutive hard failures (DNS/TLS/timeout/5xx)
//   unknown          never attempted since install
// ============================================================
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/scraping.php';
require_once __DIR__ . '/Http.php';
require_once __DIR__ . '/Provenance.php';

class RmSourceHealth
{
    const OK             = 'ok';
    const PARSER_WARNING = 'parser_warning';
    const RATE_LIMITED   = 'rate_limited';
    const BLOCKED        = 'blocked';
    const DEGRADED       = 'degraded';
    const DOWN           = 'down';
    const UNKNOWN        = 'unknown';

    private ?PDO $db;

    public function __construct(?PDO $db = null) {
        try { $this->db = $db ?: getDBSafe(); } catch (Throwable $e) { $this->db = null; }
    }

    public static function instance(): self {
        static $i = null;
        return $i ?: ($i = new self());
    }

    private function ready(): bool {
        if ($this->db === null) return false;
        try { $this->db->query('SELECT 1 FROM source_health LIMIT 1'); return true; }
        catch (Throwable $e) { return false; }
    }

    /**
     * Record one source attempt.
     * @param string $outcome  'success' | 'empty' | 'parser_warning' | 'failure'
     */
    public function record(string $source, string $outcome, array $info = []): void
    {
        if (!$this->ready()) return;
        $class = (string)($info['error_class'] ?? ($outcome === 'success' ? RmHttpClient::CLASS_OK : RmHttpClient::CLASS_OTHER));
        $err   = $info['error'] ?? null;
        $ms    = (int)($info['ms'] ?? 0);
        $pv    = $info['parser_version'] ?? null;

        try {
            // Seed the row so the UPDATE below always has something to hit.
            $this->db->prepare("INSERT IGNORE INTO source_health (source_name, status) VALUES (?, 'unknown')")
                     ->execute([mb_substr($source, 0, 40)]);

            $sets = [
                'last_attempt_at = NOW()',
                'last_status_class = ?',
                'parser_version = COALESCE(?, parser_version)',
                // Running average that survives a restart without a history table.
                'avg_ms = IF(avg_ms = 0, ?, ROUND((avg_ms * 3 + ?) / 4))',
            ];
            $params = [mb_substr($class, 0, 30), $pv, $ms, $ms];

            if ($outcome === 'success') {
                $sets[] = 'success_count = success_count + 1';
                $sets[] = 'consecutive_failures = 0';
                $sets[] = 'last_success_at = NOW()';
                $sets[] = 'last_error = NULL';
            } elseif ($outcome === 'empty') {
                $sets[] = 'empty_count = empty_count + 1';
                // "This episode is not on the page" is a fact about our
                // coverage, not a fault in the source: the fetch worked and
                // the parser worked. Recording it as last_error paints a
                // healthy source red in the control centre for the rest of
                // the day. The per-episode detail is kept where it belongs,
                // in episode_sources and the run log.
                if (!in_array($class, ['missing_episode', 'not_applicable'], true)) {
                    $sets[] = 'last_error = ?';
                    $params[] = mb_substr((string)($err ?: 'Source returned no usable fields'), 0, 300);
                }
            } elseif ($outcome === 'parser_warning') {
                $sets[] = 'parser_warnings = parser_warnings + 1';
                $sets[] = 'empty_count = empty_count + 1';
                $sets[] = 'last_error = ?';
                $params[] = mb_substr((string)($err ?: 'Reachable but produced no fields — page structure may have changed'), 0, 300);
            } else {
                $sets[] = 'failure_count = failure_count + 1';
                $sets[] = 'consecutive_failures = consecutive_failures + 1';
                $sets[] = 'last_failure_at = NOW()';
                $sets[] = 'last_error = ?';
                $params[] = mb_substr((string)($err ?: 'Unknown failure'), 0, 300);
            }

            $params[] = mb_substr($source, 0, 40);
            $this->db->prepare('UPDATE source_health SET ' . implode(', ', $sets) . ' WHERE source_name = ?')
                     ->execute($params);

            $this->refreshStatus($source);
        } catch (Throwable $e) { }
    }

    /** Recompute the ladder position from the counters we just updated. */
    private function refreshStatus(string $source): void
    {
        try {
            $row = $this->db->prepare('SELECT * FROM source_health WHERE source_name=?');
            $row->execute([$source]);
            $h = $row->fetch();
            if (!$h) return;

            $status = self::UNKNOWN;
            $class  = (string)($h['last_status_class'] ?? '');
            $consec = (int)$h['consecutive_failures'];
            $succ   = (int)$h['success_count'];
            $fail   = (int)$h['failure_count'];

            if ($class === RmHttpClient::CLASS_OFFLINE)            $status = self::UNKNOWN;
            elseif ($class === RmHttpClient::CLASS_BLOCKED
                || $class === RmHttpClient::CLASS_PROXY)           $status = self::BLOCKED;
            elseif ($class === RmHttpClient::CLASS_RATE_LIMITED)   $status = self::RATE_LIMITED;
            elseif ($consec >= 3)                                  $status = self::DOWN;
            elseif ((int)$h['parser_warnings'] >= (int)rmScrapeConfig('safety.parser_warning_after', 3)
                    && $succ > 0 && $consec === 0)                 $status = self::PARSER_WARNING;
            elseif ($consec > 0)                                   $status = self::DEGRADED;
            elseif ($succ > 0)                                     $status = self::OK;

            // Back off automatically from a source that is actively refusing
            // us: the engine skips it until this timestamp passes.
            $disabledUntil = null;
            if ($status === self::BLOCKED)          $disabledUntil = date('Y-m-d H:i:s', time() + 3600);
            elseif ($status === self::RATE_LIMITED) $disabledUntil = date('Y-m-d H:i:s', time() + 900);
            elseif ($status === self::DOWN)         $disabledUntil = date('Y-m-d H:i:s', time() + 600);

            $this->db->prepare('UPDATE source_health SET status=?, disabled_until=? WHERE source_name=?')
                     ->execute([$status, $disabledUntil, $source]);
        } catch (Throwable $e) { }
    }

    /** True when a source is in an automatic cool-down. */
    public function isSuppressed(string $source): bool
    {
        if (!$this->ready()) return false;
        try {
            $s = $this->db->prepare('SELECT disabled_until FROM source_health WHERE source_name=?');
            $s->execute([$source]);
            $until = $s->fetchColumn();
            return $until && strtotime((string)$until) > time();
        } catch (Throwable $e) { return false; }
    }

    public function clearSuppression(?string $source = null): void
    {
        if (!$this->ready()) return;
        try {
            if ($source) $this->db->prepare('UPDATE source_health SET disabled_until=NULL, consecutive_failures=0 WHERE source_name=?')->execute([$source]);
            else $this->db->query('UPDATE source_health SET disabled_until=NULL, consecutive_failures=0');
        } catch (Throwable $e) { }
    }

    public function all(): array
    {
        $registry = (array)rmScrapeConfig('sources', []);
        $rows = [];
        if ($this->ready()) {
            try {
                foreach ($this->db->query('SELECT * FROM source_health')->fetchAll() as $r) $rows[$r['source_name']] = $r;
            } catch (Throwable $e) { }
        }

        $out = [];
        foreach ($registry as $name => $cfg) {
            $h = $rows[$name] ?? [];
            $succ = (int)($h['success_count'] ?? 0);
            $fail = (int)($h['failure_count'] ?? 0);
            $tot  = $succ + $fail;
            $enabled = rmScrapeSourceEnabled($name);
            $out[$name] = [
                'name'            => $name,
                'label'           => $cfg['label'] ?? $name,
                'tier'            => (int)($cfg['tier'] ?? 1),
                'class'           => rmScrapeSourceClass($name),
                'rank'            => rmScrapeSourceRank($name),
                'enabled'         => $enabled,
                'needs_key'       => in_array($name, ['tmdb','tvdb'], true),
                'status'          => $enabled ? (string)($h['status'] ?? self::UNKNOWN) : 'disabled',
                'last_class'      => $h['last_status_class'] ?? null,
                'last_error'      => $h['last_error'] ?? null,
                'last_success_at' => $h['last_success_at'] ?? null,
                'last_failure_at' => $h['last_failure_at'] ?? null,
                'last_attempt_at' => $h['last_attempt_at'] ?? null,
                'success_count'   => $succ,
                'failure_count'   => $fail,
                'empty_count'     => (int)($h['empty_count'] ?? 0),
                'parser_warnings' => (int)($h['parser_warnings'] ?? 0),
                'success_rate'    => $tot > 0 ? round($succ / $tot * 100) : null,
                'avg_ms'          => (int)($h['avg_ms'] ?? 0),
                'parser_version'  => $h['parser_version'] ?? null,
                'suppressed_until'=> $h['disabled_until'] ?? null,
                'fields'          => [],
            ];
        }
        return $out;
    }

    public function get(string $source): array
    {
        return $this->all()[$source] ?? ['name'=>$source,'status'=>self::UNKNOWN,'enabled'=>false];
    }

    /** Human label + colour hint for Admin. */
    public static function present(string $status): array
    {
        return match ($status) {
            self::OK             => ['Online',          '#4ade80', '●'],
            self::PARSER_WARNING => ['Parser warning',  '#facc15', '▲'],
            self::DEGRADED       => ['Degraded',        '#facc15', '▲'],
            self::RATE_LIMITED   => ['Rate limited',    '#fb923c', '▲'],
            self::BLOCKED        => ['Blocked',         '#f87171', '■'],
            self::DOWN           => ['Down',            '#f87171', '■'],
            'disabled'           => ['Disabled',        '#64748b', '○'],
            default              => ['Unknown',         '#64748b', '○'],
        };
    }

    /**
     * Live reachability probe for every registered source. Cheap HEAD-ish
     * request; used by Admin → Diagnostics and the control centre, not by
     * the scraping loop (which learns health from real work instead).
     */
    public function probeAll(): array
    {
        $http = RmHttpClient::instance();
        $out = [];
        foreach ((array)rmScrapeConfig('sources', []) as $name => $cfg) {
            if (!rmScrapeSourceEnabled($name)) {
                $out[$name] = ['name'=>$name,'label'=>$cfg['label'] ?? $name,'ok'=>null,
                               'status'=>'disabled','ms'=>0,
                               'error'=> in_array($name,['tmdb','tvdb'],true) ? 'No API key configured (optional source)' : 'Disabled in config'];
                continue;
            }
            $url = (string)($cfg['base'] ?? '');
            if ($url === '') { $out[$name] = ['name'=>$name,'label'=>$cfg['label'] ?? $name,'ok'=>null,'status'=>'unknown','ms'=>0,'error'=>'No base URL configured']; continue; }
            $res = $http->get($url, ['timeout'=>8,'retries'=>0,'cache_ttl'=>300,'cache_key'=>"probe:$name",'min_bytes'=>200]);
            $out[$name] = [
                'name'   => $name,
                'label'  => $cfg['label'] ?? $name,
                'ok'     => $res->ok,
                'status' => $res->ok ? self::OK : $res->errorClass,
                'ms'     => $res->ms,
                'cached' => $res->fromCache,
                'error'  => $res->ok ? null : $res->error,
            ];
            $this->record($name, $res->ok ? 'success' : 'failure',
                          ['error_class'=>$res->errorClass,'error'=>$res->error,'ms'=>$res->ms]);
        }
        return $out;
    }
}
