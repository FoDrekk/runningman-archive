<?php
// ============================================================
// RmDiagnosticReport — "TEST -> ERROR -> EXPORT REPORT -> USER UPLOADS
// REPORT -> REPORT CAN BE USED TO DIAGNOSE THE PROBLEM."
//
// Assembles what already happened — a completed research run, or a
// single-episode trace — into one structured report covering system
// info, the operation, every source's result, field-by-field analysis,
// what the AI layer decided (if anything), the database state before
// and after, and every error encountered. Exportable as JSON (for a
// developer) or HTML (for anyone else to read and attach to a bug
// report).
//
// EVERY value that passes through here is sanitised first. This file
// must never be the reason a database password, an API key or a
// session cookie ends up in a file someone attaches to a public issue.
// ============================================================
require_once __DIR__ . '/../../config/scraping.php';

class RmDiagnosticReport
{
    // ────────────────────────────────────────────────────────────
    // Building
    // ────────────────────────────────────────────────────────────
    /** Everything known about one completed (or in-progress) research run. */
    public static function forRun(?PDO $db, int $runId): array
    {
        $report = [
            'report_type'   => 'run',
            'generated_at'  => date('c'),
            'system'        => self::systemInfo($db),
            'operation'     => null,
            'source_results'=> [],
            'field_analysis'=> [],
            'ai'            => [],
            'database_state'=> [],
            'errors'        => [],
        ];
        if ($db === null) {
            $report['errors'][] = ['type' => 'no_database', 'message' => 'No database connection was available to build this report'];
            return self::sanitize($report);
        }

        try {
            $run = $db->prepare('SELECT * FROM scrape_runs WHERE run_id = ?');
            $run->execute([$runId]);
            $runRow = $run->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) { $runRow = null; }

        $report['operation'] = $runRow ? [
            'run_id'          => (int)$runRow['run_id'],
            'run_ref'         => $runRow['run_ref'] ?? null,
            'mode'            => $runRow['mode'] ?? null,
            'research_mode'   => $runRow['research_mode'] ?? null,
            'status'          => $runRow['status'] ?? null,
            'started_at'      => $runRow['started_at'] ?? null,
            'finished_at'     => $runRow['finished_at'] ?? null,
            'duration_ms'     => $runRow['duration_ms'] ?? null,
            'episodes_requested' => $runRow['episodes_requested'] ?? null,
            'episodes_checked'   => $runRow['episodes_checked'] ?? null,
            'episodes_added'     => $runRow['episodes_added'] ?? null,
            'episodes_updated'   => $runRow['episodes_updated'] ?? null,
            'episodes_review'    => $runRow['episodes_review'] ?? null,
            'episodes_failed'    => $runRow['episodes_failed'] ?? null,
            'summary'         => $runRow['summary'] ?? null,
            'error_summary'   => $runRow['error_summary'] ?? null,
            'dry_run'         => !empty($runRow['dry_run'] ?? false),
        ] : ['run_id' => $runId, 'note' => 'No run row found — the report reflects only what could still be queried'];

        $report['source_results'] = self::sourceResultsForRun($db, $runId);
        $report['field_analysis'] = self::fieldAnalysisForRun($db, $runId);
        $report['ai']             = self::aiForRun($db, $runId);
        $report['database_state'] = self::databaseStateForRun($db, $runId);
        $report['errors']         = self::errorsForRun($db, $runId);

        return self::sanitize($report);
    }

    /** A single episode's live trace (Admin -> Diagnostics -> Single Episode Scrape Trace). */
    public static function forTrace(array $trace): array
    {
        $report = [
            'report_type'    => 'single_episode_trace',
            'generated_at'   => date('c'),
            'system'         => self::systemInfo(null),
            'operation'      => [
                'episode'  => $trace['episode'] ?? null,
                'dry_run'  => true,
                'total_ms' => $trace['total_ms'] ?? null,
                'note'     => $trace['note'] ?? null,
            ],
            'source_results' => array_map(fn($s) => [
                'status' => $s['status'] ?? null, 'http' => $s['http'] ?? null, 'ms' => $s['ms'] ?? null,
                'fields' => $s['fields'] ?? [], 'error' => $s['error'] ?? null, 'parser' => $s['parser'] ?? null,
            ], (array)($trace['sources'] ?? [])),
            'field_analysis' => array_map(fn($r) => [
                'value' => $r['value'] ?? null, 'source' => $r['source'] ?? null,
                'confidence' => $r['confidence'] ?? null, 'conflicts' => $r['conflicts'] ?? [],
            ], (array)($trace['resolution'] ?? [])),
            'ai'             => [],
            'database_state' => ['changes' => (array)($trace['changes'] ?? [])],
            'errors'         => array_map(fn($w) => ['type' => 'warning', 'message' => $w], (array)($trace['warnings'] ?? [])),
        ];
        return self::sanitize($report);
    }

    private static function systemInfo(?PDO $db): array
    {
        $dbVersion = null;
        if ($db !== null) {
            try { $dbVersion = (string)$db->query('SELECT VERSION()')->fetchColumn(); } catch (Throwable $e) {}
        }
        return [
            'application'  => 'Running Man Archive',
            'php_version'  => PHP_VERSION,
            'db_version'   => $dbVersion,
            'os'           => PHP_OS_FAMILY ?? PHP_OS,
            'offline_mode' => (bool)rmScrapeConfig('http.offline', false),
        ];
    }

    private static function sourceResultsForRun(PDO $db, int $runId): array
    {
        try {
            $rows = $db->prepare(
                'SELECT source_name, source_status, COUNT(*) n, MIN(created_at) first_seen, MAX(created_at) last_seen
                   FROM research_evidence WHERE run_id = ? GROUP BY source_name, source_status'
            );
            $rows->execute([$runId]);
            $out = [];
            foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[$r['source_name']]['statuses'][$r['source_status']] = (int)$r['n'];
            }
            return $out;
        } catch (Throwable $e) { return []; }
    }

    private static function fieldAnalysisForRun(PDO $db, int $runId): array
    {
        try {
            $rows = $db->prepare(
                'SELECT episode_number, field_name, decision, confidence, reason, applied
                   FROM research_decisions WHERE run_id = ? ORDER BY episode_number, field_name LIMIT 2000'
            );
            $rows->execute([$runId]);
            return $rows->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { return []; }
    }

    private static function aiForRun(PDO $db, int $runId): array
    {
        try {
            $rows = $db->prepare(
                'SELECT episode_number, field_name, model, decision, confidence, grounding_status, grounding_issues
                   FROM ai_generation_log WHERE run_id = ? ORDER BY episode_number LIMIT 1000'
            );
            $rows->execute([$runId]);
            return $rows->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { return []; }
    }

    private static function databaseStateForRun(PDO $db, int $runId): array
    {
        try {
            $rows = $db->prepare(
                'SELECT episode_number, field_name, old_value, new_value, reason, decision
                   FROM scrape_changes WHERE run_id = ? ORDER BY episode_number, field_name LIMIT 2000'
            );
            $rows->execute([$runId]);
            return $rows->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { return []; }
    }

    private static function errorsForRun(PDO $db, int $runId): array
    {
        try {
            $rows = $db->prepare(
                "SELECT event, message, level, created_at FROM scrape_log
                  WHERE run_id = ? AND level IN ('error','warning') ORDER BY created_at LIMIT 500"
            );
            $rows->execute([$runId]);
            return $rows->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { return []; }
    }

    // ────────────────────────────────────────────────────────────
    // Sanitisation — never optional, never skippable
    // ────────────────────────────────────────────────────────────
    /** Key names whose VALUE is redacted outright, wherever they appear. */
    private const SECRET_KEYS = [
        'password', 'passwd', 'pass', 'db_pass', 'secret', 'api_key', 'apikey',
        'access_token', 'auth_token', 'token', 'authorization', 'cookie',
        'session_id', 'sessionid', 'private_key', 'client_secret', 'dsn',
    ];

    /** Patterns that look like a credential even in ordinary text/strings. */
    private const SECRET_PATTERNS = [
        '/\bBearer\s+[A-Za-z0-9\-._~+\/]+=*/i',
        '/\bsk-[A-Za-z0-9]{16,}/',
        '/\b[Aa]pi[_-]?[Kk]ey["\']?\s*[:=]\s*["\']?[A-Za-z0-9\-_]{12,}/',
        '/([?&](?:api_key|apikey|key|token|access_token|auth)=)[^&\s]+/i',
        '/mysql:\/\/[^:]+:[^@]+@/i',
    ];

    /** Recursively redact anything that looks like a secret. Never skippable. */
    public static function sanitize(mixed $data): mixed
    {
        if (is_array($data)) {
            $out = [];
            foreach ($data as $k => $v) {
                $out[$k] = is_string($k) && in_array(mb_strtolower($k), self::SECRET_KEYS, true)
                    ? '***REDACTED***' : self::sanitize($v);
            }
            return $out;
        }
        if (is_string($data)) return self::sanitizeString($data);
        return $data;
    }

    private static function sanitizeString(string $s): string
    {
        foreach (self::SECRET_PATTERNS as $p) $s = preg_replace($p, '***REDACTED***', $s) ?? $s;
        // Defence in depth: the literal configured DB password/API keys,
        // if one somehow ended up embedded in a message or stack trace.
        foreach ([defined('DB_PASS') ? DB_PASS : null, rmScrapeConfig('ai.api_key'), rmScrapeConfig('api_keys.tvdb')] as $secret) {
            if (is_string($secret) && $secret !== '' && str_contains($s, $secret)) {
                $s = str_replace($secret, '***REDACTED***', $s);
            }
        }
        return $s;
    }

    // ────────────────────────────────────────────────────────────
    // Rendering
    // ────────────────────────────────────────────────────────────
    public static function toJson(array $report): string
    {
        return json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    public static function toHtml(array $report): string
    {
        $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $section = function (string $title, string $body) use ($h): string {
            return "<section><h2>{$h($title)}</h2>$body</section>";
        };
        $table = function (array $rows) use ($h): string {
            $first = reset($rows);
            if ($first === false || !is_array($first)) return '<p class="muted">Nothing to show.</p>';
            $cols = array_keys($first);
            $html = '<table><thead><tr>' . implode('', array_map(fn($c) => '<th>' . $h($c) . '</th>', $cols)) . '</tr></thead><tbody>';
            foreach ($rows as $row) {
                $html .= '<tr>' . implode('', array_map(function ($c) use ($row, $h) {
                    $v = $row[$c] ?? '';
                    return '<td>' . $h(is_array($v) ? implode(', ', array_map('strval', $v)) : $v) . '</td>';
                }, $cols)) . '</tr>';
            }
            return $html . '</tbody></table>';
        };
        $kv = function (array $data) use ($h): string {
            $html = '<table class="kv">';
            foreach ($data as $k => $v) {
                $html .= '<tr><td>' . $h($k) . '</td><td>' . $h(is_array($v) ? json_encode($v) : ($v ?? '—')) . '</td></tr>';
            }
            return $html . '</table>';
        };

        $body = $section('System', $kv((array)$report['system']))
            . $section('Operation', $kv((array)$report['operation']))
            . $section('Source Results', $table(self::flattenSourceResults((array)$report['source_results'])))
            . $section('Field Analysis', $table((array)$report['field_analysis']))
            . $section('AI', $table((array)$report['ai']))
            . $section('Database Changes', $table((array)$report['database_state']))
            . $section('Errors & Warnings', $table((array)$report['errors']));

        return <<<HTML
<!doctype html><html><head><meta charset="utf-8"><title>Diagnostic Report</title>
<style>
body{font-family:-apple-system,Segoe UI,Roboto,sans-serif;background:#0b0f16;color:#e6edf3;margin:0;padding:2rem;line-height:1.5}
h1{color:#29ABE2} h2{color:#7dd3f0;font-size:1rem;text-transform:uppercase;letter-spacing:.05em;border-bottom:1px solid #223;padding-bottom:.4rem;margin-top:2rem}
table{width:100%;border-collapse:collapse;font-size:.85rem;margin-top:.5rem}
th,td{text-align:left;padding:.4rem .6rem;border-bottom:1px solid #1c2432}
th{color:#8fa3bf;font-weight:600} tr:hover td{background:#111826}
table.kv td:first-child{color:#8fa3bf;width:220px}
.muted{color:#5b6b82} section{margin-bottom:1rem}
</style></head><body>
<h1>Diagnostic Report</h1>
<p class="muted">Generated {$h($report['generated_at'])} — secrets are redacted automatically; safe to attach to a bug report.</p>
$body
</body></html>
HTML;
    }

    private static function flattenSourceResults(array $sourceResults): array
    {
        $out = [];
        foreach ($sourceResults as $source => $info) {
            foreach ((array)($info['statuses'] ?? []) as $status => $n) {
                $out[] = ['source' => $source, 'status' => $status, 'count' => $n];
            }
        }
        return $out;
    }
}
