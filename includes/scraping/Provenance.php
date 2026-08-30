<?php
// ============================================================
// RmProvenance — "where did this episode's data come from?"
//
// Two levels, because both questions get asked:
//   episode_sources        one row per (episode, source): was it
//                          reached, what did it yield, when, with
//                          which parser version, content hash
//   episode_field_sources  one row per (episode, field): which source
//                          actually won that field, who agreed, what
//                          the confidence was, what disagreed
//
// Every method degrades to a no-op when the scraping tables have not
// been installed yet — exactly like includes/system.php does for the
// stability tables. A missing migration must never fatal a page.
// ============================================================
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/DataNormalizer.php';

function rmScrapingTablesExist(bool $recheck = false): bool {
    static $exists = null;
    if ($recheck) $exists = null;
    if ($exists !== null) return $exists;
    try {
        $db = getDBSafe();
            if ($db === null) throw new RuntimeException('Database unavailable');
        $db->query('SELECT 1 FROM episode_sources LIMIT 1');
        $db->query('SELECT 1 FROM episode_field_sources LIMIT 1');
        $db->query('SELECT 1 FROM scrape_runs LIMIT 1');
        $exists = true;
    } catch (Throwable $e) {
        $exists = false;
    }
    return $exists;
}

/** Which of the optional scraping tables are actually present. */
function rmScrapingTableStatus(): array {
    $tables = ['scrape_runs','scrape_log','episode_sources','episode_field_sources',
               'scrape_changes','source_health','guest_aliases','review_flags',
               'thumbnail_meta','episode_alt_titles'];
    $out = [];
    foreach ($tables as $t) {
        try { getDBSafe()?->query("SELECT 1 FROM `$t` LIMIT 1"); $out[$t] = true; }
        catch (Throwable $e) { $out[$t] = false; }
    }
    return $out;
}

class RmProvenance
{
    private ?PDO $db;

    public function __construct(?PDO $db = null) {
        try { $this->db = $db ?: getDBSafe(); } catch (Throwable $e) { $this->db = null; }
    }

    private function ready(): bool { return $this->db !== null && rmScrapingTablesExist(); }

    // ── Source-level provenance ──────────────────────────────────
    public function recordSource(int $epNum, string $source, array $info): void
    {
        if (!$this->ready()) return;
        try {
            $this->db->prepare(
                "INSERT INTO episode_sources
                   (episode_number, source_name, source_url, status, http_status,
                    parser_version, fields_provided, content_hash, duration_ms, fetched_at)
                 VALUES (?,?,?,?,?,?,?,?,?,NOW())
                 ON DUPLICATE KEY UPDATE
                    source_url=VALUES(source_url), status=VALUES(status),
                    http_status=VALUES(http_status), parser_version=VALUES(parser_version),
                    fields_provided=VALUES(fields_provided), content_hash=VALUES(content_hash),
                    duration_ms=VALUES(duration_ms), fetched_at=NOW()"
            )->execute([
                $epNum,
                mb_substr($source, 0, 40),
                mb_substr((string)($info['url'] ?? ''), 0, 500) ?: null,
                mb_substr((string)($info['status'] ?? 'unknown'), 0, 30),
                isset($info['http_status']) ? (int)$info['http_status'] : null,
                mb_substr((string)($info['parser_version'] ?? ''), 0, 20) ?: null,
                mb_substr(implode(',', (array)($info['fields'] ?? [])), 0, 300) ?: null,
                $info['content_hash'] ?? null,
                isset($info['ms']) ? (int)$info['ms'] : null,
            ]);
        } catch (Throwable $e) { /* provenance must never break a scrape */ }
    }

    // ── Field-level provenance ───────────────────────────────────
    public function recordField(int $epNum, string $field, array $r): void
    {
        if (!$this->ready()) return;
        try {
            $conflicts = [];
            foreach ((array)($r['conflicts'] ?? []) as $c) {
                $conflicts[] = ($c['source'] ?? '?') . '=' . mb_substr((string)($c['value'] ?? ''), 0, 60);
            }
            $this->db->prepare(
                "INSERT INTO episode_field_sources
                   (episode_number, field_name, source_name, source_url, confidence,
                    agreeing_sources, conflicting, value_hash, updated_at)
                 VALUES (?,?,?,?,?,?,?,?,NOW())
                 ON DUPLICATE KEY UPDATE
                    source_name=VALUES(source_name), source_url=VALUES(source_url),
                    confidence=VALUES(confidence), agreeing_sources=VALUES(agreeing_sources),
                    conflicting=VALUES(conflicting), value_hash=VALUES(value_hash), updated_at=NOW()"
            )->execute([
                $epNum,
                mb_substr($field, 0, 40),
                mb_substr((string)($r['source'] ?? 'unknown'), 0, 40),
                mb_substr((string)($r['url'] ?? ''), 0, 500) ?: null,
                in_array($r['confidence'] ?? '', ['high','medium','low','conflict'], true) ? $r['confidence'] : 'medium',
                mb_substr(implode(',', (array)($r['sources'] ?? [])), 0, 200) ?: null,
                mb_substr(implode(' | ', $conflicts), 0, 400) ?: null,
                RmNormalizer::hash($r['value'] ?? ''),
            ]);
        } catch (Throwable $e) { }
    }

    // ── Change log ───────────────────────────────────────────────
    public function recordChanges(int $epNum, array $changes, ?int $runId = null): int
    {
        if (!$this->ready()) return 0;
        $keep = array_filter($changes, fn($c) => ($c['type'] ?? '') !== 'unchanged');
        if (!$keep) return 0;
        $n = 0;
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO scrape_changes
                   (run_id, episode_number, field_name, change_type, old_value, new_value,
                    source_name, confidence, applied, reason)
                 VALUES (?,?,?,?,?,?,?,?,?,?)"
            );
            foreach ($keep as $c) {
                $stmt->execute([
                    $runId, $epNum,
                    mb_substr((string)$c['field'], 0, 40),
                    (string)$c['type'],
                    $c['old'] !== null ? mb_substr((string)$c['old'], 0, 2000) : null,
                    $c['new'] !== null ? mb_substr((string)$c['new'], 0, 2000) : null,
                    mb_substr((string)($c['source'] ?? ''), 0, 40) ?: null,
                    mb_substr((string)($c['confidence'] ?? ''), 0, 10) ?: null,
                    !empty($c['applied']) ? 1 : 0,
                    $c['reason'] !== null ? mb_substr((string)$c['reason'], 0, 200) : null,
                ]);
                $n++;
            }
        } catch (Throwable $e) { }
        return $n;
    }

    // ── Alternate titles (Korean/original) ───────────────────────
    public function recordAltTitle(int $epNum, string $title, string $lang = 'ko', ?string $source = null): void
    {
        if (!$this->ready()) return;
        $t = RmNormalizer::text($title);
        if ($t === null || mb_strlen($t) < 2) return;
        try {
            $this->db->prepare(
                "INSERT INTO episode_alt_titles (episode_number, title, lang, source_name)
                 VALUES (?,?,?,?)
                 ON DUPLICATE KEY UPDATE lang=VALUES(lang), source_name=VALUES(source_name)"
            )->execute([$epNum, mb_substr($t, 0, 300), mb_substr($lang, 0, 10), $source ? mb_substr($source, 0, 40) : null]);
        } catch (Throwable $e) { }
    }

    // ── Review flags (never auto-delete, always surface) ─────────
    public function flag(string $type, string $entityType, ?int $entityId, ?int $epNum, string $detail): void
    {
        if (!$this->ready()) return;
        try {
            // Don't pile up identical open flags for the same thing.
            $dup = $this->db->prepare(
                "SELECT flag_id FROM review_flags
                  WHERE flag_type=? AND entity_type=? AND status='open'
                    AND (episode_number <=> ?) AND (entity_id <=> ?) AND detail=? LIMIT 1"
            );
            $dup->execute([$type, $entityType, $epNum, $entityId, mb_substr($detail, 0, 1000)]);
            if ($dup->fetchColumn()) return;

            $this->db->prepare(
                "INSERT INTO review_flags (flag_type, entity_type, entity_id, episode_number, detail)
                 VALUES (?,?,?,?,?)"
            )->execute([mb_substr($type,0,40), mb_substr($entityType,0,20), $entityId, $epNum, mb_substr($detail, 0, 1000)]);
        } catch (Throwable $e) { }
    }

    public function openFlags(?string $type = null, int $limit = 100): array
    {
        if (!$this->ready()) return [];
        try {
            $sql = "SELECT * FROM review_flags WHERE status='open'" . ($type ? ' AND flag_type=?' : '') . ' ORDER BY flag_id DESC LIMIT ' . max(1, min(500, $limit));
            $stmt = $this->db->prepare($sql);
            $stmt->execute($type ? [$type] : []);
            return $stmt->fetchAll();
        } catch (Throwable $e) { return []; }
    }

    public function resolveFlag(int $flagId, string $status = 'resolved'): bool
    {
        if (!$this->ready()) return false;
        try {
            $this->db->prepare("UPDATE review_flags SET status=?, resolved_at=NOW() WHERE flag_id=?")
                     ->execute([in_array($status, ['resolved','ignored'], true) ? $status : 'resolved', $flagId]);
            return true;
        } catch (Throwable $e) { return false; }
    }

    // ── Read side: "where did this episode's data come from?" ────
    public function forEpisode(int $epNum): array
    {
        if (!$this->ready()) return ['sources' => [], 'fields' => [], 'changes' => [], 'alt_titles' => []];
        try {
            $s = $this->db->prepare("SELECT * FROM episode_sources WHERE episode_number=? ORDER BY source_name");
            $s->execute([$epNum]);
            $f = $this->db->prepare("SELECT * FROM episode_field_sources WHERE episode_number=? ORDER BY field_name");
            $f->execute([$epNum]);
            $c = $this->db->prepare("SELECT * FROM scrape_changes WHERE episode_number=? ORDER BY change_id DESC LIMIT 60");
            $c->execute([$epNum]);
            $a = $this->db->prepare("SELECT * FROM episode_alt_titles WHERE episode_number=? ORDER BY lang");
            $a->execute([$epNum]);
            return ['sources'=>$s->fetchAll(), 'fields'=>$f->fetchAll(), 'changes'=>$c->fetchAll(), 'alt_titles'=>$a->fetchAll()];
        } catch (Throwable $e) {
            return ['sources' => [], 'fields' => [], 'changes' => [], 'alt_titles' => []];
        }
    }

    public function recentChanges(int $limit = 100, ?int $runId = null): array
    {
        if (!$this->ready()) return [];
        try {
            $sql = "SELECT * FROM scrape_changes" . ($runId ? " WHERE run_id=?" : "") . " ORDER BY change_id DESC LIMIT " . max(1, min(500, $limit));
            $stmt = $this->db->prepare($sql);
            $stmt->execute($runId ? [$runId] : []);
            return $stmt->fetchAll();
        } catch (Throwable $e) { return []; }
    }

    /** Episodes whose data carries a CONFLICT on any field. */
    public function conflictedEpisodes(int $limit = 100): array
    {
        if (!$this->ready()) return [];
        try {
            $stmt = $this->db->prepare(
                "SELECT episode_number, GROUP_CONCAT(field_name) AS fields, MAX(updated_at) AS updated_at
                   FROM episode_field_sources WHERE confidence='conflict'
                  GROUP BY episode_number ORDER BY episode_number DESC LIMIT " . max(1, min(500, $limit))
            );
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (Throwable $e) { return []; }
    }
}
