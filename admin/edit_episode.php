<?php
// ============================================================
// Manual Episode Editor
// Edit / update / key-in any episode's data by hand — for the
// fields the scraper can't reliably fill (location, synopsis,
// special notes, fixing guest/tag lists, etc).
//
// AJAX/save handlers run BEFORE layout.php (same rule as every
// other admin page — layout.php emits the full HTML page the
// instant it's required, so any JSON response must be produced
// and exited before that include).
// ============================================================

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/scraping/bootstrap.php';
adminCheck();

$db = getDB();
$hasTeamsResults = rmTeamsResultsColumnsExist($db);

// ── SAVE handler (AJAX) ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    header('Content-Type: application/json');
    try {
        $epNum = (int)($_POST['episode_number'] ?? 0);
        if ($epNum < 1) throw new Exception('Invalid episode number');

        // Find the episode row (must already exist; creation is a
        // separate concern handled by sync/import).
        $st = $db->prepare("SELECT episode_id FROM episodes WHERE episode_number=?");
        $st->execute([$epNum]);
        $epId = $st->fetchColumn();
        if (!$epId) throw new Exception("Episode #$epNum not found in database");

        // ── Scalar fields ──
        $title    = trim($_POST['title'] ?? '');
        $airDate  = trim($_POST['air_date'] ?? '');
        $runtime  = (int)($_POST['runtime_minutes'] ?? 90);
        $synopsis = trim($_POST['synopsis'] ?? '');
        $mission  = trim($_POST['main_mission'] ?? '');
        $teams    = trim($_POST['teams'] ?? '');
        $results  = trim($_POST['results'] ?? '');
        $notes    = trim($_POST['special_notes'] ?? '');
        $isSpec   = isset($_POST['is_special']) && $_POST['is_special'] === '1' ? 1 : 0;
        $specType = trim($_POST['special_type'] ?? '');
        $verify   = isset($_POST['verification_required']) && $_POST['verification_required'] === '1' ? 1 : 0;

        // Validate air_date format (empty allowed → NULL)
        $airDateVal = null;
        if ($airDate !== '') {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $airDate)) throw new Exception('Air date must be YYYY-MM-DD');
            $airDateVal = $airDate;
        }

        // ── Year: derive from air_date year, link/create years row ──
        $yearId = null;
        if ($airDateVal) {
            $yr = substr($airDateVal, 0, 4);
            $ys = $db->prepare("SELECT year_id FROM years WHERE year_label=?");
            $ys->execute([$yr]);
            $yearId = $ys->fetchColumn();
            if (!$yearId) {
                $db->prepare("INSERT INTO years (year_label) VALUES (?)")->execute([$yr]);
                $yearId = (int)$db->lastInsertId();
            }
        }

        // ── Location: find or create by name (+country) ──
        $locId = null;
        $locName    = trim($_POST['location_name'] ?? '');
        $locCountry = trim($_POST['location_country'] ?? '');
        if ($locName !== '') {
            $ls = $db->prepare("SELECT location_id FROM locations WHERE name=? AND IFNULL(country,'')=?");
            $ls->execute([$locName, $locCountry]);
            $locId = $ls->fetchColumn();
            if (!$locId) {
                $overseas = ($locCountry !== '' && stripos($locCountry, 'korea') === false) ? 1 : 0;
                $db->prepare("INSERT INTO locations (name, country, is_overseas) VALUES (?,?,?)")
                   ->execute([$locName, $locCountry ?: null, $overseas]);
                $locId = (int)$db->lastInsertId();
            }
        }

        // ── Theme: optional, by id (0/empty = none) ──
        $themeId = (int)($_POST['theme_id'] ?? 0) ?: null;

        // ── Build UPDATE (teams/results only if columns exist) ──
        $sql = "UPDATE episodes SET
                    title=?, air_date=?, runtime_minutes=?, synopsis=?, main_mission=?,
                    special_notes=?, is_special=?, special_type=?, location_id=?, theme_id=?,
                    year_id=?, verification_required=?";
        $params = [
            $title, $airDateVal, $runtime, ($synopsis ?: null), ($mission ?: null),
            ($notes ?: null), $isSpec, ($specType ?: null), $locId, $themeId,
            $yearId, $verify,
        ];
        if ($hasTeamsResults) {
            $sql .= ", teams=?, results=?";
            $params[] = $teams ?: null;
            $params[] = $results ?: null;
        }
        $sql .= " WHERE episode_id=?";
        $params[] = $epId;
        $db->prepare($sql)->execute($params);

        // ── Guests: replace the whole set from the textarea ──
        // One name per line. Find-or-create each, relink from scratch.
        if (isset($_POST['guests'])) {
            $names = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $_POST['guests'])),
                fn($n) => $n !== ''));
            $db->prepare("DELETE FROM episode_guests WHERE episode_id=?")->execute([$epId]);
            foreach ($names as $gn) {
                $gs = $db->prepare("SELECT guest_id FROM guests WHERE name_romanized=?");
                $gs->execute([$gn]); $gid = $gs->fetchColumn();
                if (!$gid) {
                    $db->prepare("INSERT INTO guests (name_romanized) VALUES (?)")->execute([$gn]);
                    $gid = (int)$db->lastInsertId();
                }
                $db->prepare("INSERT IGNORE INTO episode_guests (episode_id,guest_id) VALUES (?,?)")
                   ->execute([$epId, $gid]);
            }
        }

        // ── Tags: replace the whole set from the comma/line input ──
        if (isset($_POST['tags'])) {
            $tagNames = array_values(array_unique(array_filter(array_map(
                fn($t) => trim(strtolower($t)),
                preg_split('/[,\r\n]+/', $_POST['tags'])
            ), fn($t) => $t !== '')));
            $db->prepare("DELETE FROM episode_tags WHERE episode_id=?")->execute([$epId]);
            foreach ($tagNames as $tn) {
                $ts = $db->prepare("SELECT tag_id FROM tags WHERE name=?");
                $ts->execute([$tn]); $tid = $ts->fetchColumn();
                if (!$tid) {
                    $db->prepare("INSERT INTO tags (name) VALUES (?)")->execute([$tn]);
                    $tid = (int)$db->lastInsertId();
                }
                $db->prepare("INSERT IGNORE INTO episode_tags (episode_id,tag_id) VALUES (?,?)")
                   ->execute([$epId, $tid]);
            }
        }

        echo json_encode(['ok' => true, 'ep' => $epNum]);
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ── Page render ─────────────────────────────────────────────
$reqEp = isset($_GET['ep']) ? (int)$_GET['ep'] : 0;
$ep = $reqEp > 0 ? getEpisode($reqEp) : null;

// Themes + min/max episode bounds for the jump UI
$themes = $db->query("SELECT theme_id, name FROM themes ORDER BY name")->fetchAll();
$bounds = $db->query("SELECT MIN(episode_number) lo, MAX(episode_number) hi FROM episodes")->fetch();

$adminTitle = 'Edit Episode';
$adminPage  = 'editep';
require_once __DIR__ . '/layout.php';
?>

<div class="at">
  <h1>Edit Episode</h1>
  <p>Manually edit, update, or key-in data for any episode. Useful for fields the scraper can't fill (location, synopsis, special notes) or to fix bad data.</p>
</div>

<!-- Jump / search -->
<div class="ap">
  <div class="sh">Open an Episode</div>
  <div style="display:flex;gap:.6rem;align-items:flex-end;flex-wrap:wrap">
    <div class="fld" style="margin-bottom:0">
      <label>Episode # <?= $bounds ? "({$bounds['lo']}–{$bounds['hi']})" : '' ?></label>
      <input type="number" id="jumpEp" value="<?= $reqEp ?: '' ?>" min="1" placeholder="e.g. 272" style="width:130px" onkeydown="if(event.key==='Enter')openEp()">
    </div>
    <button class="btn" onclick="openEp()" style="height:fit-content">Open</button>
    <?php if ($ep): ?>
      <a class="btn btn-dark" href="<?= bp() ?>/episode.php?ep=<?= $reqEp ?>" target="_blank" style="height:fit-content;text-decoration:none">View on Site ↗</a>
    <?php endif; ?>
  </div>
</div>

<?php if ($reqEp > 0 && !$ep): ?>
  <div class="aerr">Episode #<?= $reqEp ?> not found in the database. Sync or import it first, then edit here.</div>
<?php endif; ?>

<?php if ($ep): ?>
<div id="saveMsg"></div>

<form id="epForm" onsubmit="return false">
  <input type="hidden" name="action" value="save">
  <input type="hidden" name="episode_number" value="<?= (int)$ep['episode_number'] ?>">

  <!-- Core -->
  <div class="ap">
    <div class="sh">Core</div>
    <div class="fld">
      <label>Title</label>
      <input type="text" name="title" value="<?= h($ep['title'] ?? '') ?>" placeholder="Episode #XXX - Descriptive Title">
    </div>
    <div class="g3">
      <div class="fld">
        <label>Air Date (YYYY-MM-DD)</label>
        <input type="text" name="air_date" value="<?= h($ep['air_date'] && $ep['air_date'] !== '0000-00-00' ? $ep['air_date'] : '') ?>" placeholder="2015-11-01">
      </div>
      <div class="fld">
        <label>Runtime (min)</label>
        <input type="number" name="runtime_minutes" value="<?= (int)($ep['runtime_minutes'] ?? 90) ?>">
      </div>
      <div class="fld">
        <label>Theme</label>
        <select name="theme_id">
          <option value="0">— none —</option>
          <?php foreach ($themes as $t): ?>
            <option value="<?= (int)$t['theme_id'] ?>" <?= ((int)($ep['theme_id'] ?? 0) === (int)$t['theme_id']) ? 'selected' : '' ?>><?= h($t['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
  </div>

  <!-- Location -->
  <div class="ap">
    <div class="sh">Location</div>
    <div class="g2">
      <div class="fld">
        <label>Location Name</label>
        <input type="text" name="location_name" value="<?= h($ep['location_name'] ?? '') ?>" placeholder="Korea University Gymnasium (Anam-dong, Seoul)">
      </div>
      <div class="fld">
        <label>Country</label>
        <input type="text" name="location_country" value="<?= h($ep['location_country'] ?? '') ?>" placeholder="South Korea">
      </div>
    </div>
    <p style="font-size:.72rem;color:rgba(255,255,255,.3)">Leave both blank to clear location. A new location is created automatically if it doesn't exist.</p>
  </div>

  <!-- Content -->
  <div class="ap">
    <div class="sh">Content</div>
    <div class="fld">
      <label>Synopsis / Description</label>
      <textarea name="synopsis" rows="4" placeholder="Real episode description. Leave blank if none — the site auto-generates a summary from the other fields."><?= h($ep['synopsis'] ?? '') ?></textarea>
    </div>
    <div class="fld">
      <label>Main Mission</label>
      <textarea name="main_mission" rows="2" placeholder="Defeat the Heroes Team in the 100 vs 100 name tag elimination"><?= h($ep['main_mission'] ?? '') ?></textarea>
    </div>
    <?php if ($hasTeamsResults): ?>
    <div class="g2">
      <div class="fld">
        <label>Teams</label>
        <textarea name="teams" rows="2" placeholder="Heroes Team(...) Jong-kook Team(...)"><?= h($ep['teams'] ?? '') ?></textarea>
      </div>
      <div class="fld">
        <label>Result</label>
        <textarea name="results" rows="2" placeholder="Heroes Team Wins"><?= h($ep['results'] ?? '') ?></textarea>
      </div>
    </div>
    <?php else: ?>
      <p style="font-size:.72rem;color:#fca5a5">Teams/Result fields hidden — run <code>database/add_teams_results.sql</code> to enable them.</p>
    <?php endif; ?>
    <div class="fld">
      <label>Special Notes</label>
      <textarea name="special_notes" rows="2"><?= h($ep['special_notes'] ?? '') ?></textarea>
    </div>
  </div>

  <!-- Guests & Tags -->
  <div class="ap">
    <div class="sh">Guests &amp; Tags</div>
    <div class="g2">
      <div class="fld">
        <label>Guests (one per line)</label>
        <textarea name="guests" rows="6" placeholder="Jung Doo-hong&#10;Kim Ki-tae&#10;Lee Won-hee"><?php
          echo h(implode("\n", array_map(fn($g) => $g['name_romanized'], $ep['guests'] ?? [])));
        ?></textarea>
      </div>
      <div class="fld">
        <label>Tags (comma or newline separated)</label>
        <textarea name="tags" rows="6" placeholder="athletes, competition, teamwork"><?= h(implode(', ', $ep['tags'] ?? [])) ?></textarea>
      </div>
    </div>
    <p style="font-size:.72rem;color:rgba(255,255,255,.3)">Saving replaces the entire guest/tag list for this episode with exactly what's in these boxes.</p>
  </div>

  <!-- Flags -->
  <div class="ap">
    <div class="sh">Flags</div>
    <div class="g2">
      <div class="fld">
        <label><input type="checkbox" name="is_special" value="1" id="isSpec" <?= !empty($ep['is_special']) ? 'checked' : '' ?> style="width:auto;margin-right:.4rem"> Mark as Special</label>
        <input type="text" name="special_type" value="<?= h($ep['special_type'] ?? '') ?>" placeholder="Special type (e.g. Year-End, Fan Meeting)" style="margin-top:.4rem">
      </div>
      <div class="fld">
        <label><input type="checkbox" name="verification_required" value="1" <?= !empty($ep['verification_required']) ? 'checked' : '' ?> style="width:auto;margin-right:.4rem"> Needs verification</label>
        <p style="font-size:.72rem;color:rgba(255,255,255,.3);margin-top:.4rem">Uncheck once you've confirmed the data is correct, so it stops showing as "incomplete".</p>
      </div>
    </div>
  </div>

  <div style="display:flex;gap:.6rem;align-items:center">
    <button class="btn" onclick="saveEp()" id="btnSave">Save Changes</button>
    <span id="saveStatus" style="font-size:.82rem;color:rgba(255,255,255,.4)"></span>
  </div>
</form>

<!-- ── Where this episode's data came from ─────────────────────
     Field-level provenance: which source won each value, who agreed,
     how confident the engine was, and what disagreed. This is the
     answer to "why does this episode say that?" — and the place a
     wrong value gets traced back to the source that supplied it. -->
<?php
$prov      = new RmProvenance();
$provData  = $prov->forEpisode((int)$ep['episode_number']);
$provReady = rmScrapingTablesExist();
$confColour = ['high'=>'#4ade80','medium'=>'#facc15','low'=>'#fb923c','conflict'=>'#f87171'];
?>
<div class="ap">
  <div class="sh">Data Provenance</div>
  <?php if (!$provReady): ?>
    <p style="font-size:.78rem;color:rgba(255,255,255,.35)">
      Provenance tracking is not installed yet. Install the engine tables from
      <a href="<?= bp() ?>/admin/scraper.php">Scraper Control Centre</a> to record where each value came from.
    </p>
  <?php elseif (!$provData['fields'] && !$provData['sources']): ?>
    <p style="font-size:.78rem;color:rgba(255,255,255,.35)">
      No scrape has been recorded for this episode yet — its data predates provenance tracking, or was entered by hand.
      Run a sync from the <a href="<?= bp() ?>/admin/scraper.php">Scraper Control Centre</a> to populate it.
    </p>
  <?php else: ?>
    <?php if ($provData['fields']): ?>
    <table class="atable" style="margin-bottom:1rem">
      <thead><tr><th style="width:130px">Field</th><th>Source</th><th>Confidence</th><th>Agreed by</th><th>Disagreement</th><th>Updated</th></tr></thead>
      <tbody>
      <?php foreach ($provData['fields'] as $f): ?>
        <tr>
          <td style="font-weight:700"><?= h((string)$f['field_name']) ?></td>
          <td>
            <?php if (!empty($f['source_url'])): ?>
              <a href="<?= h((string)$f['source_url']) ?>" target="_blank" rel="noopener"><?= h((string)$f['source_name']) ?></a>
            <?php else: ?><?= h((string)$f['source_name']) ?><?php endif; ?>
          </td>
          <td style="color:<?= $confColour[(string)$f['confidence']] ?? 'rgba(255,255,255,.4)' ?>;font-weight:700">
            <?= h(strtoupper((string)$f['confidence'])) ?>
          </td>
          <td style="font-size:.75rem;color:rgba(255,255,255,.45)"><?= h((string)($f['agreeing_sources'] ?: '—')) ?></td>
          <td style="font-size:.72rem;color:<?= !empty($f['conflicting']) ? '#fca5a5' : 'rgba(255,255,255,.3)' ?>;max-width:260px">
            <?= h((string)($f['conflicting'] ?: '—')) ?>
          </td>
          <td style="font-size:.72rem;color:rgba(255,255,255,.3)"><?= h(substr((string)$f['updated_at'], 0, 16)) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>

    <?php if ($provData['sources']): ?>
    <div class="sh" style="margin-top:.4rem">Sources consulted</div>
    <table class="atable">
      <thead><tr><th style="width:130px">Source</th><th>Result</th><th>Fields provided</th><th>Parser</th><th>Fetched</th></tr></thead>
      <tbody>
      <?php foreach ($provData['sources'] as $sRow):
        $st = (string)$sRow['status'];
        $col = $st === 'ok' ? '#86efac' : (in_array($st, ['empty','missing_episode','not_applicable','disabled'], true) ? 'rgba(255,255,255,.35)' : ($st === 'parser_warning' ? '#fcd34d' : '#fca5a5')); ?>
        <tr>
          <td><?php if (!empty($sRow['source_url'])): ?>
            <a href="<?= h((string)$sRow['source_url']) ?>" target="_blank" rel="noopener"><?= h((string)$sRow['source_name']) ?></a>
          <?php else: ?><?= h((string)$sRow['source_name']) ?><?php endif; ?></td>
          <td style="color:<?= $col ?>"><?= h(str_replace('_', ' ', $st)) ?><?= $sRow['http_status'] ? ' <span style="opacity:.6">(HTTP ' . (int)$sRow['http_status'] . ')</span>' : '' ?></td>
          <td style="font-size:.74rem;color:rgba(255,255,255,.45)"><?= h((string)($sRow['fields_provided'] ?: '—')) ?></td>
          <td style="font-size:.72rem;color:rgba(255,255,255,.3)"><?= h((string)($sRow['parser_version'] ?: '—')) ?></td>
          <td style="font-size:.72rem;color:rgba(255,255,255,.3)"><?= h(substr((string)$sRow['fetched_at'], 0, 16)) ?><?= $sRow['duration_ms'] ? ' · ' . (int)$sRow['duration_ms'] . 'ms' : '' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>

    <?php if ($provData['alt_titles']): ?>
    <div style="margin-top:.9rem;font-size:.8rem;color:rgba(255,255,255,.5)">
      <strong style="color:rgba(41,171,226,.7)">Alternate titles kept:</strong>
      <?= h(implode(' · ', array_map(fn($a) => $a['title'] . ' (' . $a['lang'] . ')', $provData['alt_titles']))) ?>
    </div>
    <?php endif; ?>

    <?php if ($provData['changes']): ?>
    <div class="sh" style="margin-top:1.1rem">Change History</div>
    <div style="background:#06090f;border-radius:8px;padding:.8rem;font-size:.73rem;font-family:ui-monospace,monospace;line-height:1.9;max-height:260px;overflow-y:auto">
      <?php foreach ($provData['changes'] as $c):
        $applied = !empty($c['applied']); ?>
        <div style="color:<?= $c['change_type'] === 'rejected' ? '#fca5a5' : ($applied ? '#86efac' : 'rgba(255,255,255,.35)') ?>">
          [<?= h(substr((string)$c['created_at'], 5, 11)) ?>]
          <?= h((string)$c['field_name']) ?>
          <?= h(str_replace('_', ' ', (string)$c['change_type'])) ?>
          <?= $applied ? '' : '(not applied)' ?>
          <?= $c['source_name'] ? ' via ' . h((string)$c['source_name']) : '' ?>
          <?= $c['reason'] ? ' — ' . h(mb_substr((string)$c['reason'], 0, 100)) : '' ?>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  <?php endif; ?>
</div>

<?php endif; ?>

<script>
var BP = <?= json_encode(bp()) ?>;
function openEp(){
  var n = parseInt(document.getElementById('jumpEp').value);
  if(!n||n<1){ return; }
  window.location.href = BP + '/admin/edit_episode.php?ep=' + n;
}
function saveEp(){
  var form = document.getElementById('epForm');
  var btn = document.getElementById('btnSave');
  var status = document.getElementById('saveStatus');
  var msg = document.getElementById('saveMsg');
  var fd = new FormData(form);
  // checkboxes: ensure unchecked sends nothing (handled server-side via isset+value check)
  btn.disabled = true; status.textContent = 'Saving…';
  fetch(BP + '/admin/edit_episode.php', { method:'POST', body: fd })
    .then(r => r.json())
    .then(d => {
      btn.disabled = false;
      if(d.ok){
        status.textContent = '';
        msg.innerHTML = '<div class="aok">Saved episode #' + d.ep + ' successfully.</div>';
        msg.scrollIntoView({behavior:'smooth', block:'center'});
      } else {
        status.textContent = '';
        msg.innerHTML = '<div class="aerr">Save failed: ' + (d.error||'unknown error') + '</div>';
      }
    })
    .catch(e => {
      btn.disabled = false; status.textContent = '';
      msg.innerHTML = '<div class="aerr">Request failed: ' + e.message + '</div>';
    });
}
</script>

</main>
</div>
</body>
</html>
