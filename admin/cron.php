<?php
// ============================================================
// Running Man Archive — Automated Weekly Update (cron.php)
//
// Browser trigger:
//   http://localhost/runningman_archive/admin/cron.php
//
// Windows Task Scheduler (fully automatic):
//   Program:   C:\xampp\php\php.exe
//   Arguments: C:\xampp\htdocs\runningman_archive\admin\cron.php
//   Trigger:   Weekly, Sunday 23:00
//
// Stability: acquires a lock before running so it never collides
// with a manual Auto Sync running in the browser at the same time.
// ============================================================

define('IS_CLI', PHP_SAPI === 'cli');

if (!IS_CLI) {
    $adminTitle = 'Weekly Update';
    $adminPage  = 'cron';
    require_once __DIR__ . '/layout.php';
} else {
    define('BASE_PATH', '');
    require_once __DIR__ . '/../config/db.php';
    require_once __DIR__ . '/../includes/functions.php';
}
require_once __DIR__ . '/../includes/scraper.php';
require_once __DIR__ . '/../includes/system.php';

set_time_limit(3600);

$db     = getDB();
$dbMax  = (int)$db->query("SELECT MAX(episode_number) FROM episodes")->fetchColumn();
$log    = [];
$saved  = 0;
$errors = 0;
$skippedDueToLock = false;

function clog(string $msg): void {
    global $log;
    $ts   = '['.date('Y-m-d H:i:s').'] ';
    $line = $ts.$msg;
    $log[]= $line;
    if (IS_CLI) echo $line."\n";
    @file_put_contents(__DIR__.'/../cron.log', $line."\n", FILE_APPEND);
}

$shouldRun = IS_CLI || isset($_GET['run']);

if ($shouldRun) {
    // ── Stability: don't run if Auto Sync is actively writing ──
    if (lockIsHeld('sync')) {
        clog("SKIPPED: Auto Sync is currently running — avoiding concurrent writes.");
        logActivity('cron', null, 'skipped', 'Skipped — auto_sync lock held');
        $skippedDueToLock = true;
    } elseif (!lockAcquire('cron')) {
        clog("SKIPPED: Another cron run is already in progress.");
        logActivity('cron', null, 'skipped', 'Skipped — cron already locked');
        $skippedDueToLock = true;
    } else {
        clog("=== Running Man Archive — Weekly Update Start ===");
        clog("Database max episode: EP$dbMax");

        clog("Detecting latest episode via Wikipedia/myrm.tv…");
        $latest = rmGetLatestEpNumber();

        if (!$latest) {
            $reason = function_exists('rmLastFetchError') ? rmLastFetchError() : null;
            clog("ERROR: Could not reach Wikipedia or myrm.tv. Aborting.");
            if ($reason) clog("  Reason: $reason");
            logActivity('cron', null, 'failed', 'Could not reach any data source'.($reason?" — $reason":''));
        } elseif ($latest > 1500) {
            clog("WARNING: Detected EP$latest — seems too high (SPA false positive). Aborting auto-import.");
            clog("Current expected range: EP700-EP900. Use Fetch Latest with manual EP entry.");
            logActivity('cron', null, 'failed', "Rejected false-positive EP$latest");
            $latest = $dbMax;
        } elseif ($latest <= $dbMax) {
            clog("Database is up to date (EP$dbMax).");
            logActivity('cron', null, 'success', "Up to date at EP$dbMax");
        } else {
            clog("Latest detected: EP$latest — ".($latest-$dbMax)." new episode(s).");
        }

        $maxPerRun = 5;
        $newEps    = [];
        if ($latest && $latest > $dbMax) {
            $cap = min($latest, $dbMax + $maxPerRun);
            for ($i = $dbMax+1; $i <= $cap; $i++) $newEps[] = $i;
            if ($latest > $cap)
                clog("Note: ".($latest-$cap)." more episodes available. Will import next run.");
        }

        foreach ($newEps as $epNum) {
            clog("Scraping EP$epNum…");
            $startMs = microtime(true);
            sleep(2);

            $data = rmScrapeEpisode($epNum);
            if (!$data) {
                clog("  SKIP: No data for EP$epNum");
                logActivity('cron', $epNum, 'failed', 'No data from any source');
                $errors++; continue;
            }

            $yr     = rmYear($epNum);
            $padded = str_pad($epNum, 3, '0', STR_PAD_LEFT);
            $yStmt  = $db->prepare("SELECT year_id FROM years WHERE year_label=?");
            $yStmt->execute([$yr]);
            $yrId   = (int)$yStmt->fetchColumn();
            if (!$yrId) {
                $db->prepare("INSERT INTO years (year_label,total_eps) VALUES (?,0)")->execute([$yr]);
                $yrId = (int)$db->lastInsertId();
            }

            $exists = $db->prepare("SELECT COUNT(*) FROM episodes WHERE episode_number=?");
            $exists->execute([$epNum]);
            if ($exists->fetchColumn()>0) {
                clog("  SKIP: EP$epNum already in DB");
                logActivity('cron', $epNum, 'skipped', 'Already in DB');
                continue;
            }

            try {
                $db->beginTransaction();
                $title  = $data['title'] ?? "Episode #$padded";
                $imgUrl = $data['image_url'] ?? null;
                $wp     = $imgUrl ? rmDownloadThumb($imgUrl, $epNum, $yr) : null;
                $dbPath = $wp ?: bp()."/thumbnails/$yr/ep$padded.jpg";

                $db->prepare("INSERT IGNORE INTO thumbnails (episode_number,local_path,thumbnail_url,verified) VALUES (?,?,?,?)")
                   ->execute([$epNum,$dbPath,$imgUrl,$wp?1:0]);
                $tStmt = $db->prepare("SELECT thumbnail_id FROM thumbnails WHERE episode_number=? LIMIT 1");
                $tStmt->execute([$epNum]);
                $tid   = (int)$tStmt->fetchColumn();

                // Location — look up by name, create if new (same approach as Auto Sync)
                $locId = null;
                if (!empty($data['location'])) {
                    $lf = $db->prepare("SELECT location_id FROM locations WHERE name=? LIMIT 1");
                    $lf->execute([trim($data['location'])]);
                    $locId = $lf->fetchColumn();
                    if (!$locId) {
                        $db->prepare("INSERT INTO locations (name) VALUES (?)")->execute([trim($data['location'])]);
                        $locId = (int)$db->lastInsertId();
                    }
                }

                // Synopsis: store ONLY a real scraped description. If none
                // was scraped, leave it null — do NOT synthesize one into
                // the column here. episode.php generates a non-redundant
                // summary live at display time (excluding mission/teams/
                // results/location, which have their own rows), so an empty
                // synopsis still displays sensibly. Synthesizing into the
                // column would (a) duplicate the Main Mission row verbatim
                // (see EP807) and (b) make a description-less episode look
                // "complete" to the incomplete-detector, hiding it from
                // future re-syncs.
                $synopsis = $data['synopsis'] ?? null;

                if (rmTeamsResultsColumnsExist($db)) {
                    $db->prepare("INSERT INTO episodes (episode_number,year_id,title,air_date,runtime_minutes,synopsis,main_mission,teams,results,location_id,thumbnail_id,verification_required) VALUES (?,?,?,?,90,?,?,?,?,?,?,1)")
                       ->execute([$epNum,$yrId,$title,$data['air_date']??null,$synopsis,$data['mission']??null,$data['teams']??null,$data['results']??null,$locId,$tid?:null]);
                } else {
                    $db->prepare("INSERT INTO episodes (episode_number,year_id,title,air_date,runtime_minutes,synopsis,main_mission,location_id,thumbnail_id,verification_required) VALUES (?,?,?,?,90,?,?,?,?,1)")
                       ->execute([$epNum,$yrId,$title,$data['air_date']??null,$synopsis,$data['mission']??null,$locId,$tid?:null]);
                }
                $eid = (int)$db->lastInsertId();
                if ($tid) $db->prepare("UPDATE episodes SET thumbnail_id=? WHERE episode_id=?")->execute([$tid,$eid]);

                foreach ($data['guests']??[] as $gn) {
                    $g=$db->prepare("SELECT guest_id FROM guests WHERE name_romanized=?"); $g->execute([$gn]); $gid=$g->fetchColumn();
                    if (!$gid){$db->prepare("INSERT INTO guests (name_romanized) VALUES (?)")->execute([$gn]);$gid=(int)$db->lastInsertId();}
                    $db->prepare("INSERT IGNORE INTO episode_guests (episode_id,guest_id) VALUES (?,?)")->execute([$eid,$gid]);
                }
                foreach ($data['tags']??[] as $tagName) {
                    $tagName = trim($tagName);
                    if ($tagName === '') continue;
                    $tg=$db->prepare("SELECT tag_id FROM tags WHERE name=?"); $tg->execute([$tagName]); $tgid=$tg->fetchColumn();
                    if (!$tgid){$db->prepare("INSERT INTO tags (name) VALUES (?)")->execute([$tagName]);$tgid=(int)$db->lastInsertId();}
                    $db->prepare("INSERT IGNORE INTO episode_tags (episode_id,tag_id) VALUES (?,?)")->execute([$eid,$tgid]);
                }
                $db->prepare("UPDATE years SET total_eps=(SELECT COUNT(*) FROM episodes WHERE year_id=?) WHERE year_id=?")->execute([$yrId,$yrId]);
                $db->commit();
                @unlink(sys_get_temp_dir().'/rm_stats.json');
                $saved++;
                $durMs = (int)((microtime(true)-$startMs)*1000);
                clog("  ✓ EP$epNum saved: $title".($wp?" [thumb]":""));
                logActivity('cron', $epNum, 'success', $title.($wp?' [thumb]':''), $durMs);
            } catch(Exception $e) {
                $db->rollBack();
                clog("  ERROR EP$epNum: ".$e->getMessage());
                logActivity('cron', $epNum, 'failed', $e->getMessage());
                $errors++;
            }
        }

        clog("=== Complete: $saved new, $errors errors ===");
        lockRelease('cron');
    }
}

if (IS_CLI) exit(0);

$cronLog     = @file_get_contents(__DIR__.'/../cron.log') ?: '';
$logLines    = array_filter(array_slice(explode("\n", trim($cronLog)), -20));
$syncLocked  = lockIsHeld('sync');
$tablesReady = stabilityTablesExist();
?>

<div class="at"><h1>⏱️ Weekly Update</h1><p>Automated import of new episodes via Wikipedia &amp; myrm.tv.</p></div>

<?php if (!$tablesReady): ?>
<div class="alert alert-warn" style="margin-bottom:1rem">
  ⚠ Stability tables not installed yet — collision-protection with Auto Sync is disabled, but updates still run normally.
  Run <code style="background:rgba(0,0,0,.2);padding:1px 6px;border-radius:3px">database/stability_patch.sql</code> in phpMyAdmin to enable them.
</div>
<?php endif; ?>

<?php if ($skippedDueToLock): ?>
<div class="alert alert-warn" style="margin-bottom:1.5rem">
  ⏸ This run was skipped because Auto Sync was active at the time — preventing conflicting database writes. Try again once it finishes.
</div>
<?php elseif ($shouldRun): ?>
<div style="background:rgba(34,197,94,.09);border:1px solid rgba(34,197,94,.2);border-radius:8px;padding:.75rem 1rem;color:#86efac;font-size:.85rem;margin-bottom:1.5rem">
  ✅ Update ran: <?= $saved ?> new episode<?= $saved!==1?'s':'' ?> imported<?= $errors?" ($errors errors)":'' ?>.
</div>
<?php endif; ?>

<?php if ($syncLocked && !$shouldRun): ?>
<div class="alert alert-info" style="margin-bottom:1.5rem">
  ℹ️ Auto Sync is currently running. "Run Now" will safely skip rather than collide with it.
</div>
<?php endif; ?>

<!-- How to set up -->
<div class="ap">
  <div class="sh">Setup — Windows Task Scheduler</div>
  <div style="font-size:.82rem;line-height:2.1;color:rgba(255,255,255,.55)">
    <p style="margin-bottom:.65rem"><strong style="color:#FFD700">Option 1 — Browser (manual weekly trigger):</strong></p>
    <p style="margin-bottom:.25rem">Bookmark and visit every Sunday night after the episode airs:</p>
    <code style="background:rgba(41,171,226,.1);padding:3px 9px;border-radius:4px;color:#29ABE2;font-size:.82rem">
      <?= (isset($_SERVER["HTTP_HOST"]) ? "http://".$_SERVER["HTTP_HOST"] : "http://localhost") . bp() ?>/admin/cron.php?run=1
    </code>
    <p style="margin-top:.9rem;margin-bottom:.65rem"><strong style="color:#FFD700">Option 2 — Fully automated (Task Scheduler):</strong></p>
    <div style="display:grid;gap:.3rem">
      <div><span style="color:rgba(255,255,255,.3)">Program/script:</span> <code style="background:rgba(41,171,226,.08);padding:2px 7px;border-radius:3px;color:#29ABE2">C:\xampp\php\php.exe</code></div>
      <div><span style="color:rgba(255,255,255,.3)">Arguments:</span> <code style="background:rgba(41,171,226,.08);padding:2px 7px;border-radius:3px;color:#29ABE2">C:\xampp\htdocs\runningman_archive\admin\cron.php</code></div>
      <div><span style="color:rgba(255,255,255,.3)">Trigger:</span> <code style="background:rgba(41,171,226,.08);padding:2px 7px;border-radius:3px;color:#29ABE2">Weekly, Sunday 23:00</code></div>
    </div>
    <p style="margin-top:.85rem;color:rgba(255,255,255,.35);font-size:.78rem">
      Safety: max 5 new episodes per run · rejects detected EP &gt; 1500 · auto-retries on network errors ·
      skips if Auto Sync is already writing to the database
    </p>
  </div>
  <div style="margin-top:1rem">
    <a href="?run=1" class="btn" onclick="this.textContent='Running…'">▶ Run Now</a>
    <a href="health.php" class="btn btn-dark btn-sm">🩺 View System Health</a>
  </div>
</div>

<!-- Log -->
<?php if ($logLines): ?>
<div class="ap">
  <div class="sh">Update Log (last 20 lines)</div>
  <div style="background:#06090f;border-radius:8px;padding:.85rem;font-size:.72rem;font-family:monospace;line-height:1.85;max-height:300px;overflow-y:auto">
    <?php foreach ($logLines as $l): ?>
    <div style="color:<?= str_contains($l,'ERROR')?'#fca5a5':(str_contains($l,'✓')?'#86efac':'rgba(255,255,255,.38)') ?>">
      <?= htmlspecialchars($l) ?>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

</main></div>
</body></html>
