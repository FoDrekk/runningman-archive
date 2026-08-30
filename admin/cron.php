<?php
// ============================================================
// Running Man Archive — Automated Weekly Update (cron.php)
//
// Browser trigger:
//   http://localhost/runningman_archive/admin/cron.php?run=1
//
// Windows Task Scheduler (fully automatic):
//   Program:   C:\xampp\php\php.exe
//   Arguments: C:\xampp\htdocs\runningman_archive\admin\cron.php
//   Trigger:   Weekly, Sunday 23:00
//
// Now driven by the scraping engine, so an unattended run:
//   · never overlaps another run (lock, held by Auto Sync too)
//   · RESUMES an interrupted previous run before starting a new one
//   · logs every episode AND every source, with per-source timings
//   · counts added / updated / skipped / failed separately
//   · records total execution time and reports PARTIAL failures
//     honestly instead of claiming success
//   · re-checks only the fields recent episodes are still missing,
//     rather than re-scraping complete episodes
//
// CLI flags: --mode=latest|missing|failed --limit=N --seconds=N --dry
// ============================================================

define('IS_CLI', PHP_SAPI === 'cli');

if (!IS_CLI) {
    $adminTitle = 'Weekly Update';
    $adminPage  = 'cron';
    require_once __DIR__ . '/layout.php';
} else {
    if (!defined('BASE_PATH')) define('BASE_PATH', '');
    require_once __DIR__ . '/../config/db.php';
    require_once __DIR__ . '/../includes/functions.php';
}
require_once __DIR__ . '/../includes/scraper.php';
require_once __DIR__ . '/../includes/system.php';

set_time_limit(3600);

$db     = getDB();
$dbMax  = (int)$db->query('SELECT COALESCE(MAX(episode_number),0) FROM episodes')->fetchColumn();
$log    = [];
$runSummary = null;
$skippedDueToLock = false;

function clog(string $msg): void {
    global $log;
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    $log[] = $line;
    if (IS_CLI) echo $line . "\n";
    // The plain-text log is kept as a familiar tail; scrape_log holds the
    // structured, queryable version of the same events.
    @file_put_contents(__DIR__ . '/../cron.log', $line . "\n", FILE_APPEND);
}

// ── CLI/browser options ───────────────────────────────────────
$opt = ['mode' => 'latest', 'limit' => 8, 'seconds' => 900, 'dry' => false];
if (IS_CLI) {
    foreach (array_slice($argv, 1) as $arg) {
        if (preg_match('/^--mode=(\w+)$/', $arg, $m))    $opt['mode']    = $m[1];
        elseif (preg_match('/^--limit=(\d+)$/', $arg, $m)) $opt['limit']  = (int)$m[1];
        elseif (preg_match('/^--seconds=(\d+)$/', $arg, $m)) $opt['seconds'] = (int)$m[1];
        elseif ($arg === '--dry')                        $opt['dry']     = true;
    }
} else {
    if (!empty($_GET['mode']))    $opt['mode']    = preg_replace('/[^a-z]/', '', (string)$_GET['mode']) ?: 'latest';
    if (!empty($_GET['limit']))   $opt['limit']   = max(1, min(200, (int)$_GET['limit']));
    if (!empty($_GET['seconds'])) $opt['seconds'] = max(30, min(3000, (int)$_GET['seconds']));
    $opt['dry'] = !empty($_GET['dry']);
}

$shouldRun = IS_CLI || isset($_GET['run']);

if ($shouldRun) {
    // ── Overlap protection ────────────────────────────────────
    if (lockIsHeld('sync')) {
        clog('SKIPPED: Auto Sync is currently running — avoiding concurrent writes.');
        logActivity('cron', null, 'skipped', 'Skipped — auto_sync lock held');
        $skippedDueToLock = true;
    } elseif (lockIsHeld('scrape')) {
        clog('SKIPPED: A scraping run from the control centre is in progress.');
        logActivity('cron', null, 'skipped', 'Skipped — scrape lock held');
        $skippedDueToLock = true;
    } elseif (!lockAcquire('cron', 3600)) {
        clog('SKIPPED: Another cron run is already in progress.');
        logActivity('cron', null, 'skipped', 'Skipped — cron already locked');
        $skippedDueToLock = true;
    } else {
        $startedAt = microtime(true);
        clog('=== Running Man Archive — Weekly Update Start ===');
        clog("Database max episode: EP$dbMax · mode={$opt['mode']}" . ($opt['dry'] ? ' · DRY RUN' : ''));

        try {
            $engine = new RmScrapingEngine();

            // ── Resume anything a previous run left unfinished ──
            $stale = RmScrapeRun::resumable(30);
            if ($stale) {
                clog('Resuming interrupted run #' . $stale['run_id'] . ' before starting a new one…');
                $res = $engine->resume($stale, ['dry_run' => $opt['dry']]);
                if (!empty($res['summary'])) {
                    $rc = $res['summary']['counts'];
                    clog("  Resume complete: {$rc['checked']} checked, {$rc['added']} added, {$rc['updated']} updated, {$rc['failed']} failed");
                }
            }

            $out = $engine->runMode($opt['mode'], [
                'dry_run'    => $opt['dry'],
                'limit'      => $opt['limit'],
                'max_new'    => $opt['limit'],
                'time_limit' => $opt['seconds'],
                'scope'      => IS_CLI ? 'scheduled task' : 'browser trigger',
            ]);
            $runSummary = $out['summary'];

            // Mirror the structured log into the plain-text tail so the
            // familiar cron.log still tells the whole story.
            foreach ($engine->run()?->logLines() ?? [] as $l) {
                $bits = [];
                if ($l['ep'])     $bits[] = 'EP' . $l['ep'];
                if ($l['source']) $bits[] = $l['source'];
                $bits[] = $l['event'];
                if ($l['message'] !== '') $bits[] = '— ' . $l['message'];
                if ($l['ms'])     $bits[] = '(' . $l['ms'] . 'ms)';
                clog('  ' . strtoupper(substr($l['level'], 0, 1)) . ' ' . implode(' ', $bits));
            }

            $c = $runSummary['counts'];
            $status = $runSummary['status'];
            clog("=== {$out['targets']['label']} — {$c['added']} added, {$c['updated']} updated, {$c['skipped']} skipped, {$c['failed']} failed ===");
            clog('    Sources: ' . $c['src_ok'] . ' ok, ' . $c['src_failed'] . ' failed, ' . $c['src_skipped'] . ' skipped'
                 . ' · total ' . round((microtime(true) - $startedAt), 1) . 's'
                 . ($out['paused'] ? ' · PAUSED at the time budget — the next run resumes automatically' : ''));

            logActivity('cron', null,
                $c['failed'] > 0 ? ($c['added'] + $c['updated'] > 0 ? 'success' : 'failed') : 'success',
                ($status === 'partial' ? 'PARTIAL: ' : '') .
                "{$opt['mode']}: {$c['added']} added, {$c['updated']} updated, {$c['skipped']} skipped, {$c['failed']} failed",
                (int)$runSummary['duration_ms']);

        } catch (Throwable $e) {
            clog('FATAL: ' . $e->getMessage());
            logActivity('cron', null, 'failed', 'Cron aborted: ' . $e->getMessage());
        } finally {
            lockRelease('cron');
        }
    }
}

if (IS_CLI) {
    exit(($runSummary && ($runSummary['counts']['failed'] ?? 0) > 0) ? 1 : 0);
}

$cronLog     = @file_get_contents(__DIR__ . '/../cron.log') ?: '';
$logLines    = array_filter(array_slice(explode("\n", trim($cronLog)), -25));
$syncLocked  = lockIsHeld('sync');
$tablesReady = stabilityTablesExist();
$engineReady = rmScrapingTablesExist();
$recentRuns  = RmScrapeRun::recent(5);
$resumable   = RmScrapeRun::resumable(30);
?>

<div class="at"><h1>⏱️ Weekly Update</h1><p>Unattended import of new episodes, driven by the multi-source scraping engine.</p></div>

<?php if (!$tablesReady): ?>
<div class="alert alert-warn" style="margin-bottom:1rem">
  ⚠ Stability tables not installed yet — collision-protection with Auto Sync is disabled, but updates still run normally.
  Run <code style="background:rgba(0,0,0,.2);padding:1px 6px;border-radius:3px">database/stability_patch.sql</code> in phpMyAdmin to enable them.
</div>
<?php endif; ?>

<?php if (!$engineReady): ?>
<div class="alert alert-warn" style="margin-bottom:1rem">
  ⚠ Scraping engine tables not installed — runs still work, but per-run history, provenance and change tracking will not be recorded.
  Install them from <a href="<?= bp() ?>/admin/scraper.php">Scraper Control Centre</a>.
</div>
<?php endif; ?>

<?php if ($skippedDueToLock): ?>
<div class="alert alert-warn" style="margin-bottom:1.5rem">
  ⏸ This run was skipped because another job was writing to the database at the time. Try again once it finishes.
</div>
<?php elseif ($shouldRun && $runSummary): ?>
<?php $c = $runSummary['counts']; ?>
<div class="alert <?= $c['failed'] > 0 ? 'alert-warn' : 'alert-ok' ?>" style="margin-bottom:1.5rem">
  <div>
    <strong><?= $runSummary['status'] === 'partial' ? 'Completed with failures' : 'Update complete' ?></strong>
    <?= !empty($runSummary['dry_run']) ? ' — DRY RUN, nothing was written' : '' ?><br>
    <?= (int)$c['checked'] ?> checked · <?= (int)$c['added'] ?> added · <?= (int)$c['updated'] ?> updated ·
    <?= (int)$c['skipped'] ?> skipped · <?= (int)$c['failed'] ?> failed
    · <?= round((int)$runSummary['duration_ms'] / 1000, 1) ?>s
    <br><span style="opacity:.7;font-size:.78rem">Sources: <?= (int)$c['src_ok'] ?> ok, <?= (int)$c['src_failed'] ?> failed,
      <?= (int)$c['src_skipped'] ?> skipped because the data was already complete</span>
  </div>
</div>
<?php elseif ($shouldRun): ?>
<div class="alert alert-warn" style="margin-bottom:1.5rem">The run did not complete — see the log below.</div>
<?php endif; ?>

<?php if ($resumable && !$shouldRun): ?>
<div class="alert alert-warn" style="margin-bottom:1.5rem">
  ⏯ Run #<?= (int)$resumable['run_id'] ?> was interrupted and still has queued episodes.
  The next run resumes it automatically, or resume it now from the
  <a href="<?= bp() ?>/admin/scraper.php">Scraper Control Centre</a>.
</div>
<?php endif; ?>

<?php if ($syncLocked && !$shouldRun): ?>
<div class="alert alert-info" style="margin-bottom:1.5rem">
  ℹ️ Auto Sync is currently running. "Run Now" will safely skip rather than collide with it.
</div>
<?php endif; ?>

<div class="ap">
  <div class="sh">Setup — Windows Task Scheduler</div>
  <div style="font-size:.82rem;line-height:2.1;color:rgba(255,255,255,.55)">
    <p style="margin-bottom:.65rem"><strong style="color:#FFD700">Option 1 — Browser (manual weekly trigger):</strong></p>
    <p style="margin-bottom:.25rem">Bookmark and visit every Sunday night after the episode airs:</p>
    <code style="background:rgba(41,171,226,.1);padding:3px 9px;border-radius:4px;color:#29ABE2;font-size:.82rem">
      <?= (isset($_SERVER['HTTP_HOST']) ? 'http://' . htmlspecialchars($_SERVER['HTTP_HOST']) : 'http://localhost') . bp() ?>/admin/cron.php?run=1
    </code>
    <p style="margin-top:.9rem;margin-bottom:.65rem"><strong style="color:#FFD700">Option 2 — Fully automated (Task Scheduler):</strong></p>
    <div style="display:grid;gap:.3rem">
      <div><span style="color:rgba(255,255,255,.3)">Program/script:</span> <code style="background:rgba(41,171,226,.08);padding:2px 7px;border-radius:3px;color:#29ABE2">C:\xampp\php\php.exe</code></div>
      <div><span style="color:rgba(255,255,255,.3)">Arguments:</span> <code style="background:rgba(41,171,226,.08);padding:2px 7px;border-radius:3px;color:#29ABE2">C:\xampp\htdocs\runningman_archive\admin\cron.php --mode=latest --limit=8</code></div>
      <div><span style="color:rgba(255,255,255,.3)">Trigger:</span> <code style="background:rgba(41,171,226,.08);padding:2px 7px;border-radius:3px;color:#29ABE2">Weekly, Sunday 23:00</code></div>
    </div>
    <p style="margin-top:.85rem;color:rgba(255,255,255,.35);font-size:.78rem">
      CLI flags: <code>--mode=latest|missing|failed</code> · <code>--limit=N</code> · <code>--seconds=N</code> (time budget) · <code>--dry</code> (preview only).<br>
      Safety: skips while Auto Sync or the control centre is writing · resumes an interrupted run first ·
      rejects an implausible latest-episode jump · never overwrites valid data with an empty scrape ·
      exits non-zero when episodes failed, so Task Scheduler can surface it.
    </p>
  </div>
  <div style="margin-top:1rem">
    <a href="?run=1" class="btn" onclick="this.textContent='Running…'">▶ Run Now</a>
    <a href="?run=1&amp;dry=1" class="btn btn-outline btn-sm" onclick="this.textContent='Running…'">👁 Dry Run</a>
    <a href="?run=1&amp;mode=missing&amp;limit=25" class="btn btn-dark btn-sm" onclick="this.textContent='Running…'">🧩 Fill Missing</a>
    <a href="scraper.php" class="btn btn-dark btn-sm">🛰️ Scraper Control Centre</a>
    <a href="health.php" class="btn btn-dark btn-sm">🩺 System Health</a>
  </div>
</div>

<?php if ($recentRuns): ?>
<div class="ap">
  <div class="sh">Recent Runs</div>
  <table class="atable">
    <thead><tr><th>#</th><th>Mode</th><th>Started</th><th>Status</th><th>Added</th><th>Updated</th><th>Skipped</th><th>Failed</th><th>Time</th></tr></thead>
    <tbody>
    <?php foreach ($recentRuns as $r): ?>
      <tr>
        <td><?= (int)$r['run_id'] ?></td>
        <td><?= htmlspecialchars((string)$r['mode']) ?><?= !empty($r['dry_run']) ? ' (dry)' : '' ?></td>
        <td><?= htmlspecialchars((string)$r['started_at']) ?></td>
        <td style="color:<?= $r['status'] === 'completed' ? '#86efac' : ($r['status'] === 'running' ? '#fcd34d' : '#fca5a5') ?>"><?= htmlspecialchars((string)$r['status']) ?></td>
        <td><?= (int)$r['episodes_added'] ?></td>
        <td><?= (int)$r['episodes_updated'] ?></td>
        <td><?= (int)$r['episodes_skipped'] ?></td>
        <td><?= (int)$r['episodes_failed'] ?></td>
        <td><?= $r['duration_ms'] ? round((int)$r['duration_ms'] / 1000, 1) . 's' : '—' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php if ($logLines): ?>
<div class="ap">
  <div class="sh">Update Log (last 25 lines)</div>
  <div style="background:#06090f;border-radius:8px;padding:.85rem;font-size:.72rem;font-family:monospace;line-height:1.85;max-height:340px;overflow-y:auto">
    <?php foreach ($logLines as $l): ?>
    <div style="color:<?= str_contains($l, 'ERROR') || str_contains($l, 'FATAL') || str_contains($l, ' E ') ? '#fca5a5' : (str_contains($l, ' W ') ? '#fcd34d' : (str_contains($l, 'added') || str_contains($l, 'saved') ? '#86efac' : 'rgba(255,255,255,.38)')) ?>">
      <?= htmlspecialchars($l) ?>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

</main></div>
</body></html>
