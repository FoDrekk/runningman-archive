<?php
// ============================================================
// RmScrapeRun — one row per scraping run, plus a structured,
// queryable activity log underneath it.
//
// The old cron wrote lines to a text file. That answers "what
// happened?" only if a human reads it. This records the run itself
// (mode, scope, counts, duration, status, resume cursor) so the
// admin can answer "did last night's run work, how long did it
// take, what did it change, and can I resume it?" — and so an
// interrupted run can pick up exactly where it stopped instead of
// starting over.
// ============================================================
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/Provenance.php';

class RmScrapeRun
{
    public ?int   $id = null;
    public string $mode;
    public bool   $dryRun;
    private ?PDO  $db;
    private float $started;
    private array $counts = ['checked'=>0,'added'=>0,'updated'=>0,'skipped'=>0,'failed'=>0,
                             'src_ok'=>0,'src_failed'=>0,'src_skipped'=>0];
    private array $memoryLog = [];      // always kept, even without tables
    private int   $maxMemoryLog = 400;

    public function __construct(string $mode, array $opt = [])
    {
        $this->mode    = $mode;
        $this->dryRun  = !empty($opt['dry_run']);
        $this->started = microtime(true);
        $this->db = getDBSafe();

        if ($this->ready()) {
            try {
                $this->db->prepare(
                    "INSERT INTO scrape_runs (mode, scope, dry_run, status, started_at)
                     VALUES (?,?,?, 'running', NOW())"
                )->execute([mb_substr($mode,0,30), mb_substr((string)($opt['scope'] ?? ''),0,120) ?: null, $this->dryRun ? 1 : 0]);
                $this->id = (int)$this->db->lastInsertId();
            } catch (Throwable $e) { $this->id = null; }
        }
    }

    private function ready(): bool {
        if ($this->db === null) return false;
        try { $this->db->query('SELECT 1 FROM scrape_runs LIMIT 1'); return true; }
        catch (Throwable $e) { return false; }
    }

    /** Structured log line. Never logs credentials — callers pass messages only. */
    public function log(string $event, string $message = '', array $ctx = []): void
    {
        $line = [
            'ts'      => date('H:i:s'),
            'event'   => $event,
            'ep'      => $ctx['episode'] ?? null,
            'source'  => $ctx['source'] ?? null,
            'level'   => $ctx['level'] ?? 'info',
            'message' => $message,
            'ms'      => $ctx['ms'] ?? null,
        ];
        $this->memoryLog[] = $line;
        if (count($this->memoryLog) > $this->maxMemoryLog) array_shift($this->memoryLog);

        if ($this->db === null) return;
        try {
            $this->db->prepare(
                "INSERT INTO scrape_log (run_id, episode_number, source_name, level, event, message, duration_ms)
                 VALUES (?,?,?,?,?,?,?)"
            )->execute([
                $this->id,
                isset($ctx['episode']) ? (int)$ctx['episode'] : null,
                isset($ctx['source']) ? mb_substr((string)$ctx['source'], 0, 40) : null,
                in_array($line['level'], ['debug','info','warning','error'], true) ? $line['level'] : 'info',
                mb_substr($event, 0, 60),
                mb_substr(self::redact($message), 0, 500) ?: null,
                isset($ctx['ms']) ? (int)$ctx['ms'] : null,
            ]);
        } catch (Throwable $e) { }
    }

    public function warn(string $event, string $message, array $ctx = []): void { $this->log($event, $message, $ctx + ['level'=>'warning']); }
    public function error(string $event, string $message, array $ctx = []): void { $this->log($event, $message, $ctx + ['level'=>'error']); }

    /** Defence in depth: an API key must never reach a log row. */
    public static function redact(string $s): string
    {
        $s = preg_replace('/([?&](?:api_key|apikey|key|token|access_token|auth)=)[^&\s]+/i', '$1***', $s);
        return preg_replace('/\b(?:Bearer|Authorization:)\s+\S+/i', 'Bearer ***', $s);
    }

    public function count(string $key, int $n = 1): void
    {
        if (isset($this->counts[$key])) $this->counts[$key] += $n;
    }

    public function counts(): array { return $this->counts; }

    /** Persist the resume point so an interrupted run can continue. */
    public function checkpoint(?int $lastEpisode, array $remaining = []): void
    {
        if (!$this->ready() || !$this->id) return;
        try {
            $this->db->prepare("UPDATE scrape_runs SET last_episode=?, cursor_state=?, episodes_checked=?, episodes_added=?, episodes_updated=?, episodes_skipped=?, episodes_failed=? WHERE run_id=?")
                     ->execute([
                        $lastEpisode,
                        $remaining ? json_encode(array_slice(array_values($remaining), 0, 2000)) : null,
                        $this->counts['checked'], $this->counts['added'], $this->counts['updated'],
                        $this->counts['skipped'], $this->counts['failed'], $this->id,
                     ]);
        } catch (Throwable $e) { }
    }

    public function finish(string $status = 'completed', string $notes = ''): array
    {
        $durMs = (int)round((microtime(true) - $this->started) * 1000);
        if ($this->counts['failed'] > 0 && $this->counts['checked'] > $this->counts['failed'] && $status === 'completed') {
            $status = 'partial';   // some episodes worked, some didn't — say so honestly
        }
        if ($this->ready() && $this->id) {
            try {
                $this->db->prepare(
                    "UPDATE scrape_runs SET status=?, finished_at=NOW(), duration_ms=?,
                        episodes_checked=?, episodes_added=?, episodes_updated=?, episodes_skipped=?,
                        episodes_failed=?, sources_ok=?, sources_failed=?, sources_skipped=?,
                        notes=?, cursor_state=IF(?='completed', NULL, cursor_state)
                      WHERE run_id=?"
                )->execute([
                    $status, $durMs,
                    $this->counts['checked'], $this->counts['added'], $this->counts['updated'],
                    $this->counts['skipped'], $this->counts['failed'],
                    $this->counts['src_ok'], $this->counts['src_failed'], $this->counts['src_skipped'],
                    mb_substr($notes, 0, 2000) ?: null, $status, $this->id,
                ]);
            } catch (Throwable $e) { }
        }
        return $this->summary($status, $durMs);
    }

    public function summary(string $status = 'running', ?int $durMs = null): array
    {
        return [
            'run_id'      => $this->id,
            'mode'        => $this->mode,
            'dry_run'     => $this->dryRun,
            'status'      => $status,
            'duration_ms' => $durMs ?? (int)round((microtime(true) - $this->started) * 1000),
            'counts'      => $this->counts,
            'log'         => $this->memoryLog,
        ];
    }

    public function logLines(): array { return $this->memoryLog; }

    /**
     * Log a line that belongs to no particular run — a standalone
     * episode sync from the control centre or Auto Sync. Written with a
     * NULL run_id so it still appears in the activity log without
     * fabricating a run row per episode.
     */
    public static function note(string $event, string $message = '', array $ctx = []): void
    {
        $db = getDBSafe();
        if ($db === null) return;
        $level = (string)($ctx['level'] ?? 'info');
        if (!in_array($level, ['debug','info','warning','error'], true)) $level = 'info';
        try {
            $db->prepare(
                "INSERT INTO scrape_log (run_id, episode_number, source_name, level, event, message, duration_ms)
                 VALUES (NULL,?,?,?,?,?,?)"
            )->execute([
                isset($ctx['episode']) ? (int)$ctx['episode'] : null,
                isset($ctx['source']) ? mb_substr((string)$ctx['source'], 0, 40) : null,
                $level,
                mb_substr($event, 0, 60),
                mb_substr(self::redact($message), 0, 500) ?: null,
                isset($ctx['ms']) ? (int)$ctx['ms'] : null,
            ]);
        } catch (Throwable $e) { }
    }

    // ── Static readers for Admin ─────────────────────────────────
    public static function recent(int $limit = 15): array
    {
        try {
            $db = getDBSafe();
            if ($db === null) throw new RuntimeException('Database unavailable');
            $stmt = $db->prepare("SELECT * FROM scrape_runs ORDER BY run_id DESC LIMIT " . max(1, min(100, $limit)));
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (Throwable $e) { return []; }
    }

    public static function latest(): ?array
    {
        $r = self::recent(1);
        return $r[0] ?? null;
    }

    public static function logFor(?int $runId, int $limit = 200): array
    {
        try {
            $db = getDBSafe();
            if ($db === null) throw new RuntimeException('Database unavailable');
            $sql = "SELECT * FROM scrape_log " . ($runId ? "WHERE run_id=? " : "") . "ORDER BY log_id DESC LIMIT " . max(1, min(1000, $limit));
            $stmt = $db->prepare($sql);
            $stmt->execute($runId ? [$runId] : []);
            return array_reverse($stmt->fetchAll());
        } catch (Throwable $e) { return []; }
    }

    /** A run left "running" for longer than $staleMinutes was interrupted. */
    public static function resumable(int $staleMinutes = 30): ?array
    {
        try {
            $db = getDBSafe();
            if ($db === null) throw new RuntimeException('Database unavailable');
            $stmt = $db->prepare(
                "SELECT * FROM scrape_runs
                  WHERE status='running' AND cursor_state IS NOT NULL
                    AND started_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)
                  ORDER BY run_id DESC LIMIT 1"
            );
            $stmt->execute([$staleMinutes]);
            $row = $stmt->fetch();
            return $row ?: null;
        } catch (Throwable $e) { return null; }
    }

    public static function markAborted(int $runId, string $reason = 'Interrupted'): void
    {
        try {
            getDBSafe()?->prepare("UPDATE scrape_runs SET status='aborted', finished_at=NOW(), notes=CONCAT(COALESCE(notes,''),' ',?) WHERE run_id=? AND status='running'")
                   ->execute([mb_substr($reason,0,200), $runId]);
        } catch (Throwable $e) { }
    }

    /** Aggregate stats for the control centre header. */
    public static function stats(int $days = 7): array
    {
        try {
            $db = getDBSafe();
            if ($db === null) throw new RuntimeException('Database unavailable');
            $stmt = $db->prepare(
                "SELECT COUNT(*) runs, SUM(episodes_added) added, SUM(episodes_updated) updated,
                        SUM(episodes_failed) failed, SUM(episodes_skipped) skipped, AVG(duration_ms) avg_ms
                   FROM scrape_runs WHERE started_at > DATE_SUB(NOW(), INTERVAL ? DAY)"
            );
            $stmt->execute([$days]);
            return $stmt->fetch() ?: [];
        } catch (Throwable $e) { return []; }
    }
}
