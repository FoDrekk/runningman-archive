<?php
// ============================================================
// RmResearchRun — a run that exists in the database, not in a tab.
//
// The old Auto Sync loop lived in JavaScript. The browser held the
// queue, the browser counted the progress, and the browser decided it
// was finished — so closing the tab lost the run, and refreshing after
// it finished showed a page that looked like nothing had ever
// happened. "Did the sync actually finish?" was unanswerable because
// nothing had written the answer down.
//
// Here the server owns the run. The queue is a table. Progress is a
// query. The browser's only job is to ask for the next step and to
// render what comes back, so a refresh, a crash or a closed laptop
// costs at most the episode that was in flight.
//
// Run states: created → queued → running ⇄ paused
//                              → completed | completed_with_warnings
//                              | failed | cancelled
// ============================================================
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/ResearchState.php';

class RmResearchRun
{
    public const CREATED   = 'created';
    public const QUEUED    = 'queued';
    public const RUNNING   = 'running';
    public const PAUSED    = 'paused';
    public const COMPLETED = 'completed';
    public const WARNINGS  = 'completed_with_warnings';
    public const FAILED    = 'failed';
    public const CANCELLED = 'cancelled';

    /** States in which a run still owns the queue and may be resumed. */
    public const ACTIVE = [self::CREATED, self::QUEUED, self::RUNNING, self::PAUSED];
    /** States in which a run is over and its numbers are final. */
    public const FINAL  = [self::COMPLETED, self::WARNINGS, self::FAILED, self::CANCELLED];

    private ?PDO $db;
    private int  $id;
    private array $row;

    private function __construct(?PDO $db, int $id, array $row)
    {
        $this->db = $db; $this->id = $id; $this->row = $row;
    }

    public function id(): int { return $this->id; }
    public function ref(): string { return (string)($this->row['run_ref'] ?? ('RUN-' . $this->id)); }
    public function row(): array { return $this->row; }
    public function status(): string { return (string)($this->row['status'] ?? self::RUNNING); }
    public function isDryRun(): bool { return !empty($this->row['dry_run']); }
    public function researchMode(): string { return (string)($this->row['research_mode'] ?? 'balanced'); }

    private static function db(): ?PDO
    {
        try { return getDBSafe(); } catch (Throwable $e) { return null; }
    }

    // ────────────────────────────────────────────────────────────
    // Creating
    // ────────────────────────────────────────────────────────────
    /**
     * Persist a new run and its queue in one transaction. Either the
     * whole run exists and is resumable, or none of it does — a
     * half-written queue is worse than no run at all.
     *
     * @param array $queue rows from RmResearchState::buildQueue()
     */
    public static function create(string $mode, array $queue, array $opt = []): ?self
    {
        $db = self::db();
        if ($db === null || !rmResearchTablesExist()) return null;

        $ref = self::nextRef($db);
        try {
            $db->beginTransaction();
            $db->prepare(
                'INSERT INTO scrape_runs (run_ref, mode, research_mode, scope, dry_run, status,
                                          episodes_requested, episodes_remaining, heartbeat_at)
                 VALUES (?,?,?,?,?,?,?,?,NOW())'
            )->execute([
                $ref, mb_substr($mode, 0, 30),
                mb_substr((string)($opt['research_mode'] ?? 'balanced'), 0, 20),
                isset($opt['scope']) ? mb_substr((string)$opt['scope'], 0, 120) : null,
                !empty($opt['dry_run']) ? 1 : 0,
                $queue ? self::QUEUED : self::COMPLETED,
                count($queue), count($queue),
            ]);
            $id = (int)$db->lastInsertId();

            if ($queue) {
                $ins = $db->prepare(
                    'INSERT INTO research_queue (run_id, episode_number, position, priority, reason, fields_wanted)
                     VALUES (?,?,?,?,?,?)'
                );
                foreach (array_values($queue) as $pos => $q) {
                    $ins->execute([
                        $id, (int)$q['episode'], $pos,
                        max(1, min(9, (int)($q['priority'] ?? 5))),
                        mb_substr(implode(' · ', (array)($q['reasons'] ?? [])), 0, 300) ?: null,
                        mb_substr(implode(',', (array)($q['fields'] ?? [])), 0, 300) ?: null,
                    ]);
                }
            }
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            return null;
        }
        return self::load($id);
    }

    /** RUN-2026-09-04-003 — the third run started today. */
    private static function nextRef(PDO $db): string
    {
        $today = date('Y-m-d');
        try {
            $n = (int)$db->query(
                "SELECT COUNT(*) FROM scrape_runs WHERE DATE(started_at) = CURDATE()")->fetchColumn();
        } catch (Throwable $e) { $n = 0; }
        return sprintf('RUN-%s-%03d', $today, $n + 1);
    }

    // ────────────────────────────────────────────────────────────
    // Loading
    // ────────────────────────────────────────────────────────────
    public static function load(int $id): ?self
    {
        $db = self::db();
        if ($db === null || !rmResearchTablesExist()) return null;
        try {
            $s = $db->prepare('SELECT * FROM scrape_runs WHERE run_id = ?');
            $s->execute([$id]);
            $row = $s->fetch(PDO::FETCH_ASSOC);
            return $row ? new self($db, $id, $row) : null;
        } catch (Throwable $e) { return null; }
    }

    /**
     * The run that still owns a queue, if any. This is what makes the
     * page able to say "a run is in progress" after a refresh instead
     * of quietly starting a second one alongside it.
     */
    public static function current(): ?self
    {
        $db = self::db();
        if ($db === null || !rmResearchTablesExist()) return null;
        try {
            $in = "'" . implode("','", self::ACTIVE) . "'";
            $row = $db->query(
                "SELECT * FROM scrape_runs WHERE status IN ($in) ORDER BY run_id DESC LIMIT 1"
            )->fetch(PDO::FETCH_ASSOC);
            return $row ? new self($db, (int)$row['run_id'], $row) : null;
        } catch (Throwable $e) { return null; }
    }

    /** The most recent finished run — the one the page reports after a refresh. */
    public static function last(): ?self
    {
        $db = self::db();
        if ($db === null || !rmResearchTablesExist()) return null;
        try {
            $in = "'" . implode("','", array_merge(self::FINAL, ['partial', 'aborted'])) . "'";
            $row = $db->query(
                "SELECT * FROM scrape_runs WHERE status IN ($in) ORDER BY run_id DESC LIMIT 1"
            )->fetch(PDO::FETCH_ASSOC);
            return $row ? new self($db, (int)$row['run_id'], $row) : null;
        } catch (Throwable $e) { return null; }
    }

    private function reload(): void
    {
        try {
            $s = $this->db->prepare('SELECT * FROM scrape_runs WHERE run_id = ?');
            $s->execute([$this->id]);
            $row = $s->fetch(PDO::FETCH_ASSOC);
            if ($row) $this->row = $row;
        } catch (Throwable $e) { }
    }

    // ────────────────────────────────────────────────────────────
    // The queue
    // ────────────────────────────────────────────────────────────
    /**
     * Take the next episode off the queue and mark it in progress.
     * Returns null when the queue is empty, the run is paused, or a
     * cancel has been requested — the three reasons to stop stepping.
     */
    public function claimNext(): ?array
    {
        if (!in_array($this->status(), [self::QUEUED, self::RUNNING], true)) return null;
        $this->reload();
        if (!empty($this->row['cancel_requested'])) return null;
        if ($this->status() === self::PAUSED) return null;

        try {
            $row = $this->db->prepare(
                'SELECT * FROM research_queue WHERE run_id = ? AND state = ? ORDER BY position ASC LIMIT 1'
            );
            $row->execute([$this->id, 'queued']);
            $item = $row->fetch(PDO::FETCH_ASSOC);
            if (!$item) return null;

            $this->db->prepare(
                'UPDATE research_queue SET state = ?, started_at = NOW(), attempts = attempts + 1
                  WHERE queue_id = ? AND state = ?'
            )->execute(['researching', (int)$item['queue_id'], 'queued']);

            if ($this->status() !== self::RUNNING) $this->setStatus(self::RUNNING);
            $this->heartbeat();
            return $item;
        } catch (Throwable $e) { return null; }
    }

    /** Record the outcome of one queue item. */
    public function finishItem(int $queueId, string $state, string $summary = '', ?int $confidence = null): void
    {
        try {
            $this->db->prepare(
                'UPDATE research_queue SET state = ?, finished_at = NOW(), result_summary = ?, confidence = ?
                  WHERE queue_id = ?'
            )->execute([$state, mb_substr($summary, 0, 300) ?: null, $confidence, $queueId]);
        } catch (Throwable $e) { }
    }

    /** Put an in-flight item back so a resumed run picks it up again. */
    public function releaseInFlight(): void
    {
        try {
            $this->db->prepare("UPDATE research_queue SET state='queued', started_at=NULL
                                 WHERE run_id = ? AND state='researching'")->execute([$this->id]);
        } catch (Throwable $e) { }
    }

    /** @return array{queued:int,researching:int,completed:int,no_data:int,needs_review:int,failed:int,skipped:int,cancelled:int,total:int,done:int} */
    public function progress(): array
    {
        $out = ['queued'=>0,'researching'=>0,'completed'=>0,'no_data'=>0,'needs_review'=>0,
                'failed'=>0,'skipped'=>0,'cancelled'=>0];
        try {
            $s = $this->db->prepare('SELECT state, COUNT(*) n FROM research_queue WHERE run_id = ? GROUP BY state');
            $s->execute([$this->id]);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $out[$r['state']] = (int)$r['n'];
        } catch (Throwable $e) { }
        $out['total'] = array_sum($out);
        $out['done']  = $out['total'] - $out['queued'] - $out['researching'];
        $out['remaining'] = $out['queued'] + $out['researching'];
        $out['percent'] = $out['total'] > 0 ? (int)round($out['done'] / $out['total'] * 100) : 100;
        return $out;
    }

    /** @return array<int,array> queue rows, for the UI's episode list */
    public function items(int $limit = 500, ?string $state = null): array
    {
        try {
            $sql = 'SELECT * FROM research_queue WHERE run_id = ?'
                 . ($state ? ' AND state = ?' : '')
                 . ' ORDER BY position ASC LIMIT ' . max(1, min(2000, $limit));
            $s = $this->db->prepare($sql);
            $s->execute($state ? [$this->id, $state] : [$this->id]);
            return $s->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { return []; }
    }

    // ────────────────────────────────────────────────────────────
    // Lifecycle
    // ────────────────────────────────────────────────────────────
    private function setStatus(string $status): void
    {
        try {
            $this->db->prepare('UPDATE scrape_runs SET status = ? WHERE run_id = ?')->execute([$status, $this->id]);
            $this->row['status'] = $status;
        } catch (Throwable $e) { }
    }

    public function heartbeat(): void
    {
        try { $this->db->prepare('UPDATE scrape_runs SET heartbeat_at = NOW() WHERE run_id = ?')->execute([$this->id]); }
        catch (Throwable $e) { }
    }

    public function pause(): void
    {
        if (!in_array($this->status(), [self::QUEUED, self::RUNNING], true)) return;
        $this->releaseInFlight();
        $this->setStatus(self::PAUSED);
        $this->syncCounts();
    }

    public function resume(): void
    {
        if ($this->status() !== self::PAUSED) return;
        $this->setStatus(self::RUNNING);
        $this->heartbeat();
    }

    /**
     * Ask the run to stop. The flag is checked by claimNext(), so an
     * in-flight episode is allowed to finish and be recorded rather
     * than being abandoned half-written.
     */
    public function requestCancel(): void
    {
        try { $this->db->prepare('UPDATE scrape_runs SET cancel_requested = 1 WHERE run_id = ?')->execute([$this->id]); }
        catch (Throwable $e) { }
        $this->row['cancel_requested'] = 1;
    }

    public function cancelNow(): void
    {
        $this->requestCancel();
        try {
            $this->db->prepare("UPDATE research_queue SET state='cancelled', finished_at=NOW()
                                 WHERE run_id = ? AND state IN ('queued','researching')")->execute([$this->id]);
        } catch (Throwable $e) { }
        $this->finish(self::CANCELLED, 'Cancelled by the operator');
    }

    /**
     * Close the run out and freeze its numbers. Chooses the status
     * itself when not told one, because the difference between
     * "completed" and "completed with warnings" is exactly what the
     * operator wants to see and should not depend on the caller
     * remembering to work it out.
     */
    public function finish(?string $status = null, string $summary = ''): array
    {
        $p = $this->progress();
        if ($status === null) {
            $status = match (true) {
                $p['remaining'] > 0                              => self::PAUSED,
                $p['failed'] > 0 || $p['needs_review'] > 0        => self::WARNINGS,
                $p['completed'] === 0 && $p['no_data'] === 0      => self::FAILED,
                default                                          => self::COMPLETED,
            };
        }
        $this->syncCounts($status, $summary);
        return $this->summary();
    }

    /** Copy the queue's tallies onto the run row so the numbers survive. */
    public function syncCounts(?string $status = null, string $summary = ''): void
    {
        $p = $this->progress();
        try {
            $sets = ['episodes_checked = ?', 'episodes_no_data = ?', 'episodes_review = ?',
                     'episodes_failed = ?', 'episodes_remaining = ?', 'heartbeat_at = NOW()'];
            $params = [$p['completed'] + $p['no_data'] + $p['needs_review'] + $p['failed'],
                       $p['no_data'], $p['needs_review'], $p['failed'], $p['remaining']];

            if ($status !== null) {
                $sets[] = 'status = ?';           $params[] = $status;
                $sets[] = 'finished_at = NOW()';
                $sets[] = 'duration_ms = TIMESTAMPDIFF(MICROSECOND, started_at, NOW()) / 1000';
                if ($summary !== '') { $sets[] = 'summary = ?'; $params[] = mb_substr($summary, 0, 500); }
                $this->row['status'] = $status;
            }
            $params[] = $this->id;
            $this->db->prepare('UPDATE scrape_runs SET ' . implode(', ', $sets) . ' WHERE run_id = ?')
                     ->execute($params);
        } catch (Throwable $e) { }
        $this->reload();
    }

    /** Add to one of the run's own counters (updated / added / evidence). */
    public function bump(string $column, int $n = 1): void
    {
        static $allowed = ['episodes_added','episodes_updated','episodes_skipped','evidence_count',
                           'sources_ok','sources_empty','sources_warned','sources_failed','sources_skipped'];
        if (!in_array($column, $allowed, true)) return;
        try {
            $this->db->prepare("UPDATE scrape_runs SET `$column` = `$column` + ? WHERE run_id = ?")
                     ->execute([$n, $this->id]);
        } catch (Throwable $e) { }
    }

    public function setAverageConfidence(?int $pct): void
    {
        if ($pct === null) return;
        try {
            $this->db->prepare('UPDATE scrape_runs SET avg_confidence = ? WHERE run_id = ?')
                     ->execute([max(0, min(100, $pct)), $this->id]);
        } catch (Throwable $e) { }
    }

    // ────────────────────────────────────────────────────────────
    // Reporting
    // ────────────────────────────────────────────────────────────
    /**
     * Everything the page needs to state the outcome without hedging:
     * what was asked for, what happened to it, and how long it took.
     */
    public function summary(): array
    {
        $this->reload();
        $r = $this->row;
        $p = $this->progress();
        $status = (string)$r['status'];

        return [
            'run_id'      => $this->id,
            'ref'         => $this->ref(),
            'mode'        => (string)$r['mode'],
            'research_mode' => (string)($r['research_mode'] ?? 'balanced'),
            'scope'       => $r['scope'],
            'dry_run'     => !empty($r['dry_run']),
            'status'      => $status,
            'active'      => in_array($status, self::ACTIVE, true),
            'final'       => in_array($status, array_merge(self::FINAL, ['partial','aborted']), true),
            'label'       => self::statusLabel($status),
            'started_at'  => $r['started_at'],
            'finished_at' => $r['finished_at'],
            'duration_ms' => $r['duration_ms'] !== null ? (int)$r['duration_ms'] : null,
            'requested'   => (int)($r['episodes_requested'] ?? $p['total']),
            'processed'   => $p['done'],
            'updated'     => (int)($r['episodes_updated'] ?? 0),
            'added'       => (int)($r['episodes_added'] ?? 0),
            'no_data'     => $p['no_data'],
            'review'      => $p['needs_review'],
            'failed'      => $p['failed'],
            'remaining'   => $p['remaining'],
            'percent'     => $p['percent'],
            'evidence'    => (int)($r['evidence_count'] ?? 0),
            'avg_confidence' => $r['avg_confidence'] !== null ? (int)$r['avg_confidence'] : null,
            'summary'     => $r['summary'],
            'error_summary' => $r['error_summary'],
            'cancel_requested' => !empty($r['cancel_requested']),
            'counts'      => $p,
        ];
    }

    /** ✓ / ● / Ⅱ / ■ / ✕ plus a plain-language name, for the status chip. */
    public static function statusLabel(string $status): array
    {
        return match ($status) {
            self::CREATED, self::QUEUED => ['icon' => '○', 'text' => 'Queued',                  'tone' => 'idle'],
            self::RUNNING               => ['icon' => '●', 'text' => 'Running',                 'tone' => 'busy'],
            self::PAUSED                => ['icon' => 'Ⅱ', 'text' => 'Paused',                  'tone' => 'warn'],
            self::COMPLETED             => ['icon' => '✓', 'text' => 'Completed',               'tone' => 'ok'],
            self::WARNINGS              => ['icon' => '✓', 'text' => 'Completed with warnings', 'tone' => 'warn'],
            self::CANCELLED             => ['icon' => '■', 'text' => 'Cancelled',               'tone' => 'idle'],
            self::FAILED                => ['icon' => '✕', 'text' => 'Failed',                  'tone' => 'bad'],
            'partial'                   => ['icon' => '✓', 'text' => 'Completed with warnings', 'tone' => 'warn'],
            'aborted'                   => ['icon' => '■', 'text' => 'Abandoned',               'tone' => 'idle'],
            default                     => ['icon' => '?', 'text' => ucfirst($status),          'tone' => 'idle'],
        };
    }

    /**
     * Runs whose browser walked away. A run left "running" with no
     * heartbeat is not lost — its queue is intact — but it should stop
     * blocking a new one, so it is marked paused and offered for
     * resume rather than silently occupying the page forever.
     */
    public static function reapStalled(int $staleSeconds = 900): int
    {
        $db = self::db();
        if ($db === null || !rmResearchTablesExist()) return 0;
        try {
            $s = $db->prepare(
                "UPDATE scrape_runs SET status = ?
                  WHERE status IN ('running','queued')
                    AND (heartbeat_at IS NULL OR heartbeat_at < (NOW() - INTERVAL ? SECOND))"
            );
            $s->execute([self::PAUSED, max(60, $staleSeconds)]);
            $n = $s->rowCount();
            if ($n > 0) {
                $db->query("UPDATE research_queue q JOIN scrape_runs r ON r.run_id = q.run_id
                               SET q.state='queued', q.started_at=NULL
                             WHERE r.status='paused' AND q.state='researching'");
            }
            return $n;
        } catch (Throwable $e) { return 0; }
    }

    /** Recent finished runs, for the history list. */
    public static function recent(int $limit = 10): array
    {
        $db = self::db();
        if ($db === null || !rmResearchTablesExist()) return [];
        try {
            return $db->query('SELECT * FROM scrape_runs ORDER BY run_id DESC LIMIT ' . max(1, min(50, $limit)))
                      ->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { return []; }
    }
}
