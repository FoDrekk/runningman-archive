<?php
// ============================================================
// tests/diagnostic_report.php — the diagnostic export pipeline never
// leaks a secret, and produces something a human or a debugger can
// actually use.
//
// Hermetic: no network. The DB-backed forRun() path skips cleanly
// without MySQL/MariaDB, exactly like resolution.php's duplicate-image
// check.
//
//   php tests/diagnostic_report.php
// ============================================================
putenv('RM_SCRAPE_OFFLINE=1');
$_ENV['RM_SCRAPE_OFFLINE'] = '1';

require_once __DIR__ . '/../includes/scraping/bootstrap.php';

$pass = 0; $fail = 0;
function check(string $name, $actual, $expected = true): void {
    global $pass, $fail;
    $ok = is_array($expected) ? ($actual == $expected) : ($actual === $expected);
    if ($ok) { $pass++; echo "  PASS $name\n"; }
    else { $fail++; echo "  FAIL $name\n       expected " . var_export($expected, true) . ", got " . var_export($actual, true) . "\n"; }
}
function section(string $s): void { echo "\n── " . strtoupper($s) . " ──\n"; }

// ============================================================
section('sanitisation — secrets never survive, by key name');
$dirty = [
    'password' => 'hunter2', 'DB_PASS' => 'not-a-real-secret-but-should-still-go',
    'api_key' => 'sk-abcdef1234567890', 'nested' => ['token' => 'abc.def.ghi', 'safe' => 'Episode 810'],
];
$clean = RmDiagnosticReport::sanitize($dirty);
check('a top-level password is redacted', $clean['password'], '***REDACTED***');
check('a differently-cased secret key is still caught', $clean['DB_PASS'], '***REDACTED***');
check('a nested secret key is still caught', $clean['nested']['token'], '***REDACTED***');
check('an ordinary value is left untouched', $clean['nested']['safe'], 'Episode 810');
check('the raw secret value does not appear anywhere in the JSON output',
      str_contains(json_encode($clean), 'hunter2'), false);

section('sanitisation — secrets caught by shape, even under an innocent key');
$dirty2 = [
    'note' => 'auth failed: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.abc.def',
    'url'  => 'https://api.example.test/v1/data?api_key=abcd1234efgh5678&other=1',
    'dsn'  => 'mysql://root:supersecret@localhost/db',
];
$clean2 = RmDiagnosticReport::sanitize($dirty2);
check('a bearer token embedded in free text is redacted', str_contains($clean2['note'], 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9'), false);
check('an api_key query parameter is redacted', str_contains($clean2['url'], 'abcd1234efgh5678'), false);
check('  → but the rest of the URL survives', str_contains($clean2['url'], 'api.example.test'), true);
check('a credential embedded in a DSN is redacted', str_contains($clean2['dsn'], 'supersecret'), false);

section('sanitisation — the configured DB password and AI key never survive verbatim, even in prose');
if (defined('DB_PASS') && DB_PASS !== '') {
    $withRealPass = RmDiagnosticReport::sanitize(['msg' => 'connection failed with password ' . DB_PASS]);
    check('the literal configured DB_PASS is redacted', str_contains($withRealPass['msg'], DB_PASS), false);
} else {
    echo "  SKIP: DB_PASS is empty in this environment (nothing to prove).\n";
}

// ============================================================
section('rendering — JSON and HTML both come back usable');
$report = RmDiagnosticReport::forTrace([
    'episode' => 810, 'total_ms' => 1234, 'note' => 'Dry run — no database changes were made.',
    'sources' => ['sbs' => ['status' => 'ok', 'http' => 200, 'ms' => 300, 'fields' => ['title'], 'parser' => 'OK']],
    'resolution' => ['title' => ['value' => 'Jeju Race', 'source' => 'sbs', 'confidence' => 'high', 'conflicts' => []]],
    'changes' => ['title: (empty) -> Jeju Race'],
    'warnings' => [],
]);
check('report_type is recorded', $report['report_type'], 'single_episode_trace');
check('system info is present', isset($report['system']['php_version']), true);

$json = RmDiagnosticReport::toJson($report);
$decoded = json_decode($json, true);
check('the JSON report parses back losslessly', is_array($decoded) && $decoded['report_type'] === 'single_episode_trace', true);
check('the JSON is pretty-printed (readable by a human)', str_contains($json, "\n"), true);

$html = RmDiagnosticReport::toHtml($report);
check('the HTML report is a real document', str_starts_with(trim($html), '<!doctype html>'), true);
check('  → and includes the episode data', str_contains($html, 'Jeju Race'), true);
check('  → with no leftover template placeholders', !str_contains($html, '{$'), true);

// ============================================================
section('forRun — degrades cleanly without a database');
$noDb = RmDiagnosticReport::forRun(null, 1);
check('a missing database is reported as an error, not a crash', $noDb['errors'][0]['type'] ?? null, 'no_database');
check('the report is still valid, sanitised JSON', is_string(RmDiagnosticReport::toJson($noDb)), true);

$db = getDBSafe();
if ($db === null) {
    echo "\n  SKIP: no database reachable — forRun() against a real run needs MySQL/MariaDB.\n";
} else {
    $hasResearchTables = true;
    try { $db->query('SELECT 1 FROM research_decisions LIMIT 1'); } catch (Throwable $e) { $hasResearchTables = false; }
    if (!$hasResearchTables) {
        echo "\n  SKIP: research tables not installed — run database/research_engine.sql.\n";
    } else {
        section('forRun — a real run assembles into a coherent report');
        $db->exec("INSERT INTO scrape_runs (mode, status, started_at) VALUES ('test', 'completed', NOW())");
        $runId = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO research_decisions (run_id, episode_number, field_name, decision, confidence, reason)
                      VALUES (?,?,?,?,?,?)")
           ->execute([$runId, 999888, 'title', 'FILL', 90, 'Strong agreement']);
        $db->prepare("INSERT INTO research_evidence (run_id, episode_number, field_name, source_name, source_status, reliability)
                      VALUES (?,?,?,?,?,?)")
           ->execute([$runId, 999888, 'title', 'sbs', 'FOUND', 90]);

        $report = RmDiagnosticReport::forRun($db, $runId);
        check('the operation section names the right run', $report['operation']['run_id'] ?? null, $runId);
        check('field analysis includes the decision just inserted',
              (bool)array_filter($report['field_analysis'], fn($r) => $r['episode_number'] == 999888), true);
        check('source results include the evidence just inserted', isset($report['source_results']['sbs']), true);

        $db->exec("DELETE FROM research_decisions WHERE run_id = $runId");
        $db->exec("DELETE FROM research_evidence WHERE run_id = $runId");
        $db->exec("DELETE FROM scrape_runs WHERE run_id = $runId");
    }
}

echo "\n" . str_repeat('─', 62) . "\n";
printf("%s — %d passed, %d failed\n", $fail === 0 ? 'DIAGNOSTIC REPORT VERIFIED' : 'DIAGNOSTIC REPORT PROBLEMS', $pass, $fail);
exit($fail === 0 ? 0 : 1);
