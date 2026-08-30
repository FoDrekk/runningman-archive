<?php
// ============================================================
// tests/migration.php — verifies database/scraping_engine.sql is a
// SAFE, ADDITIVE migration.
//
// The thing being proved is not "the tables appear" — it is that
// installing them changes nothing that already existed. So the test
// seeds a realistic archive first, fingerprints every pre-existing
// table, runs the migration, and fails if any fingerprint moved.
//
// It also runs the migration TWICE: a migration an admin can't safely
// re-run is a migration nobody will dare run at all.
//
//   php tests/migration.php            (uses config/db.php)
//   php tests/migration.php --fresh    (drops and rebuilds the test DB)
//
// Exits non-zero on failure. Skips cleanly when no database is reachable.
// ============================================================
require_once __DIR__ . '/../config/db.php';

$pass = 0; $fail = 0;
function check(string $name, $actual, $expected = true): void {
    global $pass, $fail;
    $ok = is_array($expected) ? ($actual == $expected) : ($actual === $expected);
    if ($ok) { $pass++; echo "  PASS $name\n"; }
    else { $fail++; echo "  FAIL $name\n       expected " . var_export($expected, true) . ", got " . var_export($actual, true) . "\n"; }
}
function section(string $s): void { echo "\n── " . strtoupper($s) . " ──\n"; }

$db = getDBSafe();
if ($db === null) {
    echo "SKIP: no database reachable (" . ($GLOBALS['__rm_db_error'] ?? 'unknown') . ")\n";
    echo "This test needs MySQL/MariaDB. Nothing was changed.\n";
    exit(0);
}

$root = dirname(__DIR__);

// ── Tables the migration must never touch ─────────────────────
const EXISTING_TABLES = ['episodes','guests','episode_guests','locations','themes',
                         'thumbnails','tags','episode_tags','years','activity_log','sync_state'];

// ── New tables the migration must create ──────────────────────
const NEW_TABLES = ['scrape_runs','scrape_log','episode_sources','episode_field_sources',
                    'scrape_changes','source_health','guest_aliases','review_flags',
                    'thumbnail_meta','episode_alt_titles'];

/** Structural + data fingerprint of one table. */
function fingerprint(PDO $db, string $table): ?array {
    try {
        $create = $db->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM)[1] ?? '';
        $rows   = (int)$db->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
        // CHECKSUM TABLE reads every row, so a single modified value moves it.
        $sum    = $db->query("CHECKSUM TABLE `$table`")->fetch(PDO::FETCH_NUM)[1] ?? null;
        return ['ddl' => sha1($create), 'rows' => $rows, 'checksum' => $sum];
    } catch (Throwable $e) { return null; }
}

// Deliberately the SAME runner the admin installer uses, so this test
// exercises the real install path rather than a lookalike.
require_once __DIR__ . '/../includes/scraping/bootstrap.php';
function runSqlFile(PDO $db, string $path): array { return rmRunSqlFile($db, $path); }

// ── Optionally rebuild a clean test database ──────────────────
if (in_array('--fresh', $argv, true)) {
    echo "Rebuilding " . DB_NAME . " from the base schema…\n";
    $db->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) $db->exec("DROP TABLE IF EXISTS `$t`");
    $db->exec('SET FOREIGN_KEY_CHECKS=1');
    foreach (['schema.sql','stability_patch.sql','add_teams_results.sql'] as $f) {
        $r = runSqlFile($db, "$root/database/$f");
        if (!$r['ok']) { echo "  base migration $f reported: " . implode('; ', $r['errors']) . "\n"; }
    }
}

section('preconditions');
$haveBase = true;
foreach (['episodes','guests','thumbnails','locations','years'] as $t) {
    try { $db->query("SELECT 1 FROM `$t` LIMIT 1"); }
    catch (Throwable $e) { $haveBase = false; echo "  base table `$t` missing — run with --fresh\n"; }
}
if (!$haveBase) { echo "\nSKIP: base schema not installed. Re-run with --fresh.\n"; exit(0); }
check('base application schema present', true);

// ── Seed representative existing data, so "untouched" is meaningful ──
section('seed representative existing data');
$db->exec("INSERT IGNORE INTO years (year_label, total_eps) VALUES (2015, 0)");
$yrId = (int)$db->query("SELECT year_id FROM years WHERE year_label=2015")->fetchColumn();
$db->exec("INSERT IGNORE INTO locations (name, country, is_overseas) VALUES ('Seoul', 'South Korea', 0)");
$locId = (int)$db->query("SELECT location_id FROM locations WHERE name='Seoul'")->fetchColumn();

$seedEp = 700001 % 2000;   // an episode number no scrape will ever target
$seedEp = 1999;
$db->prepare("INSERT IGNORE INTO episodes (episode_number, year_id, title, air_date, synopsis, main_mission, location_id, verification_required)
              VALUES (?,?,?,?,?,?,?,0)")
   ->execute([$seedEp, $yrId, "Episode #$seedEp - Migration Canary",
              '2015-06-07', 'A pre-existing synopsis that the migration must not touch.',
              'Canary mission', $locId]);
$epId = (int)$db->query("SELECT episode_id FROM episodes WHERE episode_number=$seedEp")->fetchColumn();
$db->exec("INSERT IGNORE INTO guests (name_romanized, name_korean) VALUES ('Migration Canary', '테스트')");
$gid = (int)$db->query("SELECT guest_id FROM guests WHERE name_romanized='Migration Canary'")->fetchColumn();
$db->prepare("INSERT IGNORE INTO episode_guests (episode_id, guest_id) VALUES (?,?)")->execute([$epId, $gid]);
$db->prepare("INSERT IGNORE INTO thumbnails (episode_number, local_path, thumbnail_url, verified) VALUES (?,?,?,1)")
   ->execute([$seedEp, '/thumbnails/2015/ep1999.jpg', 'https://example.test/ep1999.jpg']);
check('canary episode seeded', (int)$db->query("SELECT COUNT(*) FROM episodes WHERE episode_number=$seedEp")->fetchColumn(), 1);

// ── Fingerprint every pre-existing table ──────────────────────
$before = [];
foreach (EXISTING_TABLES as $t) { $fp = fingerprint($db, $t); if ($fp !== null) $before[$t] = $fp; }
check('fingerprinted ' . count($before) . ' existing tables', count($before) > 0);

// ── Install ───────────────────────────────────────────────────
section('install database/scraping_engine.sql');
$run1 = runSqlFile($db, "$root/database/scraping_engine.sql");
check('migration ran without errors', $run1['ok'], true);
if (!$run1['ok']) foreach ($run1['errors'] as $e) echo "       $e\n";

section('new tables');
foreach (NEW_TABLES as $t) {
    $exists = false;
    try { $db->query("SELECT 1 FROM `$t` LIMIT 1"); $exists = true; } catch (Throwable $e) {}
    check("table `$t` exists", $exists, true);
}
check('rmScrapingTablesExist() agrees', rmScrapingTablesExist(true), true);

section('indexes');
// Every column these tables are queried by must be indexed, or a bulk run
// degrades into full scans on tables that grow one row per episode-source.
$requiredIndexes = [
    'scrape_runs'           => ['idx_started','idx_status','idx_mode'],
    'scrape_log'            => ['idx_run','idx_ep','idx_created','idx_level'],
    'episode_sources'       => ['uq_ep_source','idx_source','idx_status'],
    'episode_field_sources' => ['uq_ep_field','idx_conf','idx_source'],
    'scrape_changes'        => ['idx_ep','idx_run','idx_created','idx_type'],
    'source_health'         => ['PRIMARY','idx_status'],
    'guest_aliases'         => ['uq_alias','idx_key','idx_guest'],
    'review_flags'          => ['idx_type','idx_status','idx_ep'],
    'thumbnail_meta'        => ['PRIMARY','idx_hash','idx_status'],
    'episode_alt_titles'    => ['uq_alt','idx_ep'],
];
foreach ($requiredIndexes as $table => $names) {
    $have = [];
    foreach ($db->query("SHOW INDEX FROM `$table`")->fetchAll() as $r) $have[$r['Key_name']] = true;
    foreach ($names as $n) check("`$table`.$n index present", isset($have[$n]), true);
}

section('constraints and column types');
// The migration deliberately adds NO foreign keys to episodes: a scrape
// records provenance for an episode number that may not exist yet, and an
// FK would either block that or cascade-delete provenance behind the
// admin's back. Assert that absence, so it stays a decision, not a drift.
$fkCount = (int)$db->query(
    "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
      WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_TYPE='FOREIGN KEY'
        AND TABLE_NAME IN ('" . implode("','", NEW_TABLES) . "')"
)->fetchColumn();
check('new tables add no foreign keys (deliberate)', $fkCount, 0);

$existingFk = (int)$db->query(
    "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
      WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_TYPE='FOREIGN KEY'
        AND TABLE_NAME IN ('episodes','episode_guests','episode_tags')"
)->fetchColumn();
check('existing foreign keys still present', $existingFk > 0, true);

$charsetOk = (int)$db->query(
    "SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('" . implode("','", NEW_TABLES) . "')
        AND TABLE_COLLATION NOT LIKE 'utf8mb4%'"
)->fetchColumn();
check('all new tables are utf8mb4', $charsetOk, 0);

section('existing data untouched');
foreach ($before as $t => $fp) {
    $after = fingerprint($db, $t);
    check("`$t` structure unchanged", $after['ddl'] ?? null, $fp['ddl']);
    check("`$t` row count unchanged ({$fp['rows']})", $after['rows'] ?? null, $fp['rows']);
    check("`$t` data checksum unchanged", $after['checksum'] ?? null, $fp['checksum']);
}
$canary = $db->query("SELECT title, synopsis, air_date FROM episodes WHERE episode_number=$seedEp")->fetch();
check('canary title intact',    $canary['title'] ?? null, "Episode #$seedEp - Migration Canary");
check('canary synopsis intact', $canary['synopsis'] ?? null, 'A pre-existing synopsis that the migration must not touch.');
check('canary air date intact', $canary['air_date'] ?? null, '2015-06-07');

section('idempotency');
// An admin who runs the file twice — or clicks Install twice — must not
// be punished for it.
$before2 = [];
foreach (array_merge(EXISTING_TABLES, NEW_TABLES) as $t) { $fp = fingerprint($db, $t); if ($fp !== null) $before2[$t] = $fp; }
$run2 = runSqlFile($db, "$root/database/scraping_engine.sql");
check('second run completes without errors', $run2['ok'], true);
if (!$run2['ok']) foreach ($run2['errors'] as $e) echo "       $e\n";
$drift = [];
foreach ($before2 as $t => $fp) {
    $after = fingerprint($db, $t);
    if (($after['ddl'] ?? null) !== $fp['ddl'] || ($after['rows'] ?? null) !== $fp['rows']) $drift[] = $t;
}
check('re-running changes nothing', $drift, []);

section('seed rows');
check('source_health seeded for every registered source',
      (int)$db->query("SELECT COUNT(*) FROM source_health")->fetchColumn() >= 8, true);
$registered = array_keys((array)rmScrapeConfig('sources', []));
$seeded = $db->query("SELECT source_name FROM source_health")->fetchAll(PDO::FETCH_COLUMN);
check('no registered source is missing a health row', array_values(array_diff($registered, $seeded)), []);

// ── Clean up only what this test itself created ───────────────
$db->exec("DELETE FROM episode_guests WHERE episode_id = $epId");
$db->exec("DELETE FROM episodes WHERE episode_number = $seedEp");
$db->exec("DELETE FROM guests WHERE name_romanized = 'Migration Canary'");
$db->exec("DELETE FROM thumbnails WHERE episode_number = $seedEp");

echo "\n" . str_repeat('─', 62) . "\n";
printf("%s — %d passed, %d failed\n", $fail === 0 ? 'MIGRATION VERIFIED' : 'MIGRATION PROBLEMS', $pass, $fail);
exit($fail === 0 ? 0 : 1);
