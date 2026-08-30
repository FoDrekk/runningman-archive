<?php
// AJAX must run before layout.php — see auto_sync.php for why.
$adminTitle = 'Import Data';
$adminPage  = 'import';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
adminCheck();

// ── XLSX reader (no library needed — XLSX = ZIP + XML) ────────
function rmReadXlsx(string $path): ?array {
    if (!class_exists('ZipArchive')) return null;
    $zip = new ZipArchive;
    if ($zip->open($path) !== true) return null;

    // Read shared strings
    $strings = [];
    $ss = $zip->getFromName('xl/sharedStrings.xml');
    if ($ss) {
        $xml = simplexml_load_string($ss);
        foreach ($xml->si as $si) {
            // Collect all text nodes (handles rich text)
            $text = '';
            foreach ($si->r as $r) $text .= (string)($r->t ?? '');
            if (!$si->r) $text = (string)($si->t ?? '');
            $strings[] = $text;
        }
    }

    // Read first sheet
    $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    if (!$sheet) return null;

    $xml  = simplexml_load_string($sheet);
    $rows = [];
    foreach ($xml->sheetData->row as $row) {
        $rowData = [];
        foreach ($row->c as $cell) {
            $v = (string)($cell->v ?? '');
            $t = (string)($cell->attributes()->t ?? '');
            if ($t === 's') $v = $strings[(int)$v] ?? '';
            $rowData[] = $v;
        }
        $rows[] = $rowData;
    }
    return $rows ?: null;
}

function rmReadCsv(string $path): ?array {
    $rows = [];
    if (($f = fopen($path, 'r')) === false) return null;
    while (($row = fgetcsv($f)) !== false) $rows[] = $row;
    fclose($f);
    return $rows ?: null;
}

function rmParseFile(string $path, string $ext): ?array {
    return strtolower($ext) === 'csv' ? rmReadCsv($path) : rmReadXlsx($path);
}

// ── AJAX: process import one episode (create OR update) ──────
if (isset($_POST['action']) && $_POST['action'] === 'import_row') {
    header('Content-Type: application/json');
    $ep     = (int)($_POST['ep'] ?? 0);
    $fields = json_decode($_POST['fields'] ?? '{}', true);
    $mode   = $_POST['mode'] ?? 'update'; // 'update' or 'create'
    if (!$ep || !$fields) { echo json_encode(['ok'=>false,'msg'=>'Invalid']); exit; }

    require_once __DIR__ . '/../includes/scraper.php';

    try {
        $db     = getDB();
        $padded = str_pad($ep, 3, '0', STR_PAD_LEFT);

        // Clean title
        $title = '';
        if (!empty($fields['title'])) {
            $raw = trim($fields['title']);
            if (!preg_match('/^Episode\s*#\d+/i', $raw)) {
                $raw = strlen($raw) > 3 ? "Episode #$padded - $raw" : "Episode #$padded";
            }
            if (!preg_match('/Episodes?\s*[-–]\s*Page\s*\d+/i', $raw)) $title = $raw;
        }
        if (!$title) $title = "Episode #$padded";

        // Check if episode exists
        $exists = $db->prepare("SELECT episode_id FROM episodes WHERE episode_number=?");
        $exists->execute([$ep]);
        $eid = (int)$exists->fetchColumn();

        $action = '';

        if (!$eid && $mode === 'create') {
            // CREATE new episode
            $yr    = rmYear($ep);
            $yrStmt= $db->prepare("SELECT year_id FROM years WHERE year_label=?");
            $yrStmt->execute([$yr]);
            $yrId  = (int)$yrStmt->fetchColumn();
            if (!$yrId) {
                $db->prepare("INSERT INTO years (year_label,total_eps) VALUES (?,0)")->execute([$yr]);
                $yrId = (int)$db->lastInsertId();
            }

            $airDate = null;
            if (!empty($fields['air_date'])) {
                $d = date('Y-m-d', strtotime($fields['air_date']));
                if ($d > '2009-01-01' && $d < '2030-01-01') $airDate = $d;
            }

            $db->prepare("INSERT INTO episodes (episode_number,year_id,title,air_date,runtime_minutes,synopsis,verification_required) VALUES (?,?,?,?,90,?,1)")
               ->execute([$ep,$yrId,$title,$airDate,$fields['synopsis']??null]);
            $eid = (int)$db->lastInsertId();

            // Thumbnail placeholder
            $db->prepare("INSERT IGNORE INTO thumbnails (episode_number,local_path,verified) VALUES (?,?,0)")
               ->execute([$ep, bp()."/thumbnails/$yr/ep$padded.jpg"]);
            $tRow = $db->prepare("SELECT thumbnail_id FROM thumbnails WHERE episode_number=? LIMIT 1");
            $tRow->execute([$ep]); $tid = (int)$tRow->fetchColumn();
            if ($tid) $db->prepare("UPDATE episodes SET thumbnail_id=? WHERE episode_id=?")->execute([$tid,$eid]);

            // Update year total
            $db->prepare("UPDATE years SET total_eps=(SELECT COUNT(*) FROM episodes WHERE year_id=?) WHERE year_id=?")->execute([$yrId,$yrId]);
            $action = 'created';

        } elseif ($eid) {
            // UPDATE existing episode
            $hasTR = rmTeamsResultsColumnsExist($db);
            $updates=[]; $params=[];
            if ($title && $title !== "Episode #$padded") { $updates[]='title=?'; $params[]=$title; }
            if (!empty($fields['air_date'])) {
                $d=date('Y-m-d',strtotime($fields['air_date']));
                if ($d>'2009-01-01'&&$d<'2030-01-01'){ $updates[]='air_date=?'; $params[]=$d; }
            }
            if (!empty($fields['synopsis']))     { $updates[]='synopsis=?';     $params[]=$fields['synopsis']; }
            if (!empty($fields['main_mission'])) { $updates[]='main_mission=?'; $params[]=$fields['main_mission']; }
            if ($hasTR && !empty($fields['teams']))   { $updates[]='teams=?';   $params[]=$fields['teams']; }
            if ($hasTR && !empty($fields['results'])) { $updates[]='results=?'; $params[]=$fields['results']; }
            if (isset($fields['is_special']))    { $updates[]='is_special=?';   $params[]=$fields['is_special']?1:0; }
            if (!empty($fields['special_type'])) { $updates[]='special_type=?'; $params[]=$fields['special_type']; }

            // Location: find-or-create by name (+country), then link.
            if (!empty($fields['location'])) {
                $locName = trim($fields['location']);
                $locCountry = trim($fields['location_country'] ?? '');
                $ls = $db->prepare("SELECT location_id FROM locations WHERE name=? AND IFNULL(country,'')=?");
                $ls->execute([$locName, $locCountry]);
                $locId = (int)$ls->fetchColumn();
                if (!$locId) {
                    $overseas = ($locCountry !== '' && stripos($locCountry,'korea')===false) ? 1 : 0;
                    $db->prepare("INSERT INTO locations (name,country,is_overseas) VALUES (?,?,?)")
                       ->execute([$locName, $locCountry ?: null, $overseas]);
                    $locId = (int)$db->lastInsertId();
                }
                $updates[]='location_id=?'; $params[]=$locId;
            }

            if ($updates) {
                $updates[]='verification_required=0'; $params[]=$ep;
                $db->prepare("UPDATE episodes SET ".implode(',',$updates)." WHERE episode_number=?")->execute($params);
            }
            $action = 'updated';
        } else {
            // Episode doesn't exist and mode=update only
            echo json_encode(['ok'=>true,'ep'=>$ep,'action'=>'skipped','msg'=>'Not in DB']);
            exit;
        }

        // Guests — REPLACE the whole set (delete existing links, then add).
        // The user explicitly wants replace semantics, and this also clears
        // out any earlier garbage guest rows for the episode.
        if ($eid && isset($fields['guests'])) {
            $db->prepare("DELETE FROM episode_guests WHERE episode_id=?")->execute([$eid]);
            foreach (array_filter(array_map('trim', explode(',', $fields['guests']))) as $gname) {
                if ($gname === '') continue;
                $g=$db->prepare("SELECT guest_id FROM guests WHERE name_romanized=?"); $g->execute([$gname]); $gid=$g->fetchColumn();
                if (!$gid){$db->prepare("INSERT INTO guests (name_romanized) VALUES (?)")->execute([$gname]);$gid=(int)$db->lastInsertId();}
                $db->prepare("INSERT IGNORE INTO episode_guests (episode_id,guest_id) VALUES (?,?)")->execute([$eid,$gid]);
            }
        }

        // Tags — REPLACE the whole set (delete existing links, then add).
        if ($eid && isset($fields['tags'])) {
            $db->prepare("DELETE FROM episode_tags WHERE episode_id=?")->execute([$eid]);
            $tagNames = array_unique(array_filter(array_map(
                fn($t)=>trim(strtolower($t)),
                preg_split('/[,\r\n]+/', $fields['tags'])
            ), fn($t)=>$t!==''));
            foreach ($tagNames as $tn) {
                $ts=$db->prepare("SELECT tag_id FROM tags WHERE name=?"); $ts->execute([$tn]); $tid=$ts->fetchColumn();
                if (!$tid){$db->prepare("INSERT INTO tags (name) VALUES (?)")->execute([$tn]);$tid=(int)$db->lastInsertId();}
                $db->prepare("INSERT IGNORE INTO episode_tags (episode_id,tag_id) VALUES (?,?)")->execute([$eid,$tid]);
            }
        }

        @unlink(sys_get_temp_dir().'/rm_stats.json');
        echo json_encode(['ok'=>true,'ep'=>$ep,'action'=>$action,'title'=>$title]);
    } catch(Exception $e) {
        echo json_encode(['ok'=>false,'ep'=>$ep,'msg'=>$e->getMessage()]);
    }
    exit;
}

// ── Session + upload handling (MUST run before layout.php prints HTML,
//    because the reset path sends a Location header, and session_start()
//    also emits headers). Same rule as the AJAX handler above.
if (session_status() === PHP_SESSION_NONE) session_start();

// Reset: clear any in-progress import and return to the upload step.
// Without this, an uploaded file persists in the session indefinitely,
// so every visit to Import jumps straight back to the mapping step with
// no way to choose a different file.
if (isset($_GET['reset'])) {
    if (!empty($_SESSION['rm_import_file']) && file_exists($_SESSION['rm_import_file'])) {
        @unlink($_SESSION['rm_import_file']);
    }
    unset($_SESSION['rm_import_file'], $_SESSION['rm_import_ext'], $_SESSION['rm_import_cols']);
    header('Location: ' . bp() . '/admin/import.php');
    exit;
}

$uploadedRows  = null;
$uploadedCols  = null;
$uploadError   = '';
$uploadedFile  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['datafile'])) {
    $file = $_FILES['datafile'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $uploadError = 'Upload failed: error code '.$file['error'];
    } else {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx','csv','xls'])) {
            $uploadError = 'Only .xlsx and .csv files supported.';
        } else {
            // Clear any previous import file first so we never mix two uploads.
            if (!empty($_SESSION['rm_import_file']) && file_exists($_SESSION['rm_import_file'])) {
                @unlink($_SESSION['rm_import_file']);
            }
            $tmp  = sys_get_temp_dir().'/rm_import_'.time().'.'.$ext;
            move_uploaded_file($file['tmp_name'], $tmp);
            $rows = rmParseFile($tmp, $ext);
            if (!$rows) {
                $uploadError = 'Could not read file. Make sure it\'s a valid Excel or CSV file.';
            } else {
                $uploadedCols = $rows[0];  // First row = headers
                $uploadedRows = array_slice($rows, 1); // Data rows
                $uploadedFile = $tmp;
                // Save to session for mapping step
                $_SESSION['rm_import_file'] = $tmp;
                $_SESSION['rm_import_ext']  = $ext;
                $_SESSION['rm_import_cols'] = $uploadedCols;
            }
        }
    }
}

// Load from session if mapping step
if (!$uploadedRows && isset($_SESSION['rm_import_file'])) {
    if (file_exists($_SESSION['rm_import_file'])) {
        $rows = rmParseFile($_SESSION['rm_import_file'], $_SESSION['rm_import_ext']);
        if ($rows) {
            $uploadedCols = $_SESSION['rm_import_cols'];
            $uploadedRows = array_slice($rows, 1);
            $uploadedFile = $_SESSION['rm_import_file'];
        }
    } else {
        // Stale session pointing at a temp file that's already gone — clear it.
        unset($_SESSION['rm_import_file'], $_SESSION['rm_import_ext'], $_SESSION['rm_import_cols']);
    }
}

require_once __DIR__ . '/layout.php';

$db     = getDB();
$dbMax  = (int)$db->query("SELECT MAX(episode_number) FROM episodes")->fetchColumn();
$total  = (int)$db->query("SELECT COUNT(*) FROM episodes")->fetchColumn();

// DB fields for mapping
$dbFields = [
    ''                 => '— Skip this column —',
    'ep_number'        => 'Episode Number (required)',
    'title'            => 'Episode Title',
    'air_date'         => 'Air Date',
    'synopsis'         => 'Synopsis / Description',
    'main_mission'     => 'Main Mission',
    'teams'            => 'Teams',
    'results'          => 'Result',
    'location'         => 'Location Name',
    'location_country' => 'Location Country',
    'tags'             => 'Tags (comma-separated)',
    'guests'           => 'Guests (comma-separated)',
    'is_special'       => 'Is Special (1/0)',
    'special_type'     => 'Special Type',
];
?>

<div class="at">
  <h1>📥 Import Episode Data</h1>
  <p>Upload Excel (.xlsx) or CSV to bulk-update episode details.</p>
</div>

<?php if ($uploadError): ?>
<div class="aerr">⚠ <?= h($uploadError) ?></div>
<?php endif; ?>

<!-- ── STEP 1: Upload ─────────────────────────────────────── -->
<?php if (!$uploadedRows): ?>
<div class="ap">
  <div class="sh">Step 1 — Upload File</div>
  <form method="POST" enctype="multipart/form-data">
    <div style="border:2px dashed rgba(41,171,226,.25);border-radius:12px;padding:2.5rem;text-align:center;cursor:pointer;transition:.2s;position:relative"
         id="dropZone"
         ondragover="event.preventDefault();this.style.borderColor='#29ABE2'"
         ondragleave="this.style.borderColor='rgba(41,171,226,.25)'"
         ondrop="handleDrop(event)">
      <div style="font-size:2.5rem;margin-bottom:.75rem">📥</div>
      <div style="font-weight:700;font-size:.95rem;color:#eef2f8;margin-bottom:.35rem">
        Drop your Excel or CSV file here
      </div>
      <div style="font-size:.8rem;color:rgba(255,255,255,.35);margin-bottom:1.2rem">
        Supports: .xlsx, .csv — from myrunningman.com, your own spreadsheet, or any format
      </div>
      <label class="btn" style="cursor:pointer">
        📂 Choose File
        <input type="file" name="datafile" id="fileInput" accept=".xlsx,.csv,.xls" style="display:none"
               onchange="this.closest('form').submit()">
      </label>
      <div id="fileName" style="font-size:.78rem;color:rgba(41,171,226,.7);margin-top:.75rem"></div>
    </div>
  </form>

  <div style="margin-top:1.5rem">
    <div class="sh">Supported Formats</div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem">
      <?php foreach ([
        ['myrunningman.com export', 'Excel with columns: Ep #, Episode Name, Watched, Favorited'],
        ['Custom spreadsheet', 'Any Excel/CSV with Episode Number + other fields'],
        ['Air dates list', 'CSV with Ep # and Date columns'],
        ['Synopsis batch', 'Excel with Ep # and Description/Synopsis columns'],
      ] as [$t,$d]): ?>
      <div style="background:#141c2c;border:1px solid rgba(41,171,226,.1);border-radius:8px;padding:.85rem 1rem">
        <div style="font-size:.82rem;font-weight:700;color:#eef2f8;margin-bottom:.2rem"><?= $t ?></div>
        <div style="font-size:.73rem;color:rgba(255,255,255,.35)"><?= $d ?></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<?php else: ?>

<!-- ── STEP 2: Map columns ─────────────────────────────────── -->
<div class="ap" id="mapPanel">
  <div class="sh" style="display:flex;justify-content:space-between;align-items:center">
    <span>Step 2 — Map Columns to Fields</span>
    <a href="<?= bp() ?>/admin/import.php?reset=1" class="btn btn-dark btn-sm" style="text-decoration:none">Upload Different File</a>
  </div>
  <p style="font-size:.82rem;color:rgba(255,255,255,.4);margin-bottom:1.2rem">
    <?= count($uploadedRows) ?> rows detected. Map each column to the right database field.
    <strong style="color:#FFD700">Episode Number column is required.</strong>
  </p>

  <!-- Column mapping -->
  <div style="display:grid;gap:.55rem;margin-bottom:1.5rem" id="mappingGrid">
    <?php foreach ($uploadedCols as $i => $col): ?>
    <div style="display:grid;grid-template-columns:180px 24px 1fr;gap:.75rem;align-items:center">
      <div style="background:#141c2c;border:1px solid rgba(41,171,226,.12);border-radius:6px;padding:.45rem .75rem;font-size:.82rem;color:rgba(255,255,255,.6);white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
        <?= h($col) ?>
      </div>
      <div style="color:rgba(255,255,255,.25);text-align:center;font-size:.9rem">→</div>
      <select id="map<?= $i ?>" data-col="<?= $i ?>"
        style="padding:.42rem .7rem;background:#141c2c;border:1px solid rgba(41,171,226,.15);border-radius:7px;color:#eef2f8;font-size:.82rem;outline:none">
        <?php
        // Auto-detect best mapping
        $colLower = strtolower(trim($col));
        $autoMap  = '';
        if (preg_match('/^ep|episode.*(num|#|no)|^#$/i', $colLower))    $autoMap = 'ep_number';
        elseif (preg_match('/name|title/i', $colLower))                  $autoMap = 'title';
        elseif (preg_match('/date|aired?|broadcast/i', $colLower))       $autoMap = 'air_date';
        elseif (preg_match('/syn|desc|summary/i', $colLower))            $autoMap = 'synopsis';
        elseif (preg_match('/mission/i', $colLower))                     $autoMap = 'main_mission';
        elseif (preg_match('/team/i', $colLower))                        $autoMap = 'teams';
        elseif (preg_match('/result/i', $colLower))                      $autoMap = 'results';
        elseif (preg_match('/country/i', $colLower))                     $autoMap = 'location_country';
        elseif (preg_match('/location|landmark|venue|place/i', $colLower)) $autoMap = 'location';
        elseif (preg_match('/^tags?$/i', $colLower))                     $autoMap = 'tags';
        elseif (preg_match('/special.*type|type.*special/i', $colLower)) $autoMap = 'special_type';
        elseif (preg_match('/^is.?special|special$/i', $colLower))       $autoMap = 'is_special';
        elseif (preg_match('/guest|appear|cast/i', $colLower))           $autoMap = 'guests';
        foreach ($dbFields as $v => $l):
          $sel = ($autoMap === $v) ? 'selected' : '';
        ?>
        <option value="<?= h($v) ?>" <?= $sel ?>><?= h($l) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Data preview -->
  <div class="sh">Data Preview (first 5 rows)</div>
  <div style="overflow-x:auto;margin-bottom:1.5rem">
    <table class="atable">
      <thead>
        <tr>
          <?php foreach ($uploadedCols as $col): ?>
          <th><?= h($col) ?></th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach (array_slice($uploadedRows, 0, 5) as $row): ?>
        <tr>
          <?php foreach ($uploadedCols as $i => $_): ?>
          <td style="max-width:200px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
            <?= h($row[$i] ?? '') ?>
          </td>
          <?php endforeach; ?>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Import mode toggle -->
  <div style="background:#141c2c;border:1px solid rgba(41,171,226,.12);border-radius:10px;padding:1rem 1.2rem;margin-bottom:1.2rem">
    <div style="font-size:.7rem;font-weight:800;text-transform:uppercase;letter-spacing:.09em;color:rgba(41,171,226,.45);margin-bottom:.75rem">Import Mode</div>
    <div style="display:flex;gap:.5rem">
      <label style="display:flex;align-items:center;gap:.45rem;cursor:pointer;background:rgba(41,171,226,.08);border:1.5px solid #29ABE2;border-radius:7px;padding:.5rem .9rem">
        <input type="radio" name="importMode" value="create" id="modeCreate" checked style="accent-color:#29ABE2">
        <div>
          <div style="font-size:.82rem;font-weight:700;color:#eef2f8">Create + Update</div>
          <div style="font-size:.68rem;color:rgba(255,255,255,.35)">Creates missing episodes, updates existing ones</div>
        </div>
      </label>
      <label style="display:flex;align-items:center;gap:.45rem;cursor:pointer;background:var(--s2);border:1.5px solid rgba(41,171,226,.15);border-radius:7px;padding:.5rem .9rem">
        <input type="radio" name="importMode" value="update" id="modeUpdate" style="accent-color:#29ABE2">
        <div>
          <div style="font-size:.82rem;font-weight:700;color:#eef2f8">Update Only</div>
          <div style="font-size:.68rem;color:rgba(255,255,255,.35)">Only updates episodes already in database</div>
        </div>
      </label>
    </div>
  </div>

  <div style="display:flex;gap:.6rem;align-items:center">
    <button class="btn" onclick="startImport()" id="btnImport">
      📥 Import <?= count($uploadedRows) ?> Rows
    </button>
    <button class="btn btn-dark btn-sm" onclick="location.reload()">✕ Cancel</button>
    <span style="font-size:.78rem;color:rgba(255,255,255,.3)" id="modeHint">
      Will create missing episodes + update existing ones.
    </span>
  </div>
</div>

<!-- ── STEP 3: Import progress ─────────────────────────────── -->
<div class="ap" id="progPanel" style="display:none">
  <div class="sh">Step 3 — Importing…</div>
  <div style="display:flex;justify-content:space-between;font-size:.78rem;margin-bottom:.45rem">
    <span id="progLbl" style="color:rgba(255,255,255,.4)">Starting…</span>
    <span>
      <span style="color:#86efac">✓ <span id="cOk">0</span> updated</span> &nbsp;
      <span style="color:rgba(255,255,255,.3)">– <span id="cSkip">0</span> skipped</span> &nbsp;
      <span style="color:#fca5a5">✗ <span id="cErr">0</span> errors</span>
    </span>
  </div>
  <div style="background:#141c2c;border-radius:99px;height:5px;overflow:hidden;margin-bottom:1rem">
    <div id="progBar" style="background:linear-gradient(90deg,#1a82b0,#29ABE2);height:100%;border-radius:99px;width:0;transition:width .3s"></div>
  </div>
  <div id="impLog" style="max-height:280px;overflow-y:auto;font-size:.72rem;font-family:monospace"></div>
</div>

<!-- ── STEP 4: Results ─────────────────────────────────────── -->
<div class="ap" id="resultsPanel" style="display:none">
  <div class="sh">Import Complete</div>
  <div id="resultsSummary"></div>
  <div style="margin-top:1.2rem;display:flex;gap:.6rem">
    <a href="<?= bp() ?>/admin/import.php?reset=1" class="btn btn-sm">Import Another File</a>
    <a href="<?= bp() ?>/search.php" class="btn btn-dark btn-sm" target="_blank">View Episodes →</a>
  </div>
</div>

<!-- Embedded data for JS -->
<script id="rowData" type="application/json">
<?= json_encode($uploadedRows, JSON_UNESCAPED_UNICODE) ?>
</script>
<script id="colData" type="application/json">
<?= json_encode($uploadedCols, JSON_UNESCAPED_UNICODE) ?>
</script>

<?php endif; ?>

</main></div>
<script>
var BP = '<?= bp() ?>';

// Mode toggle hint
document.querySelectorAll('input[name="importMode"]').forEach(function(r){
  r.addEventListener('change', function(){
    var hint = document.getElementById('modeHint');
    if (!hint) return;
    if (this.value === 'create')
      hint.textContent = 'Will create missing episodes + update existing ones.';
    else
      hint.textContent = 'Only updates episodes already in database. Skips missing ones.';
    // Update label styles
    document.querySelectorAll('input[name="importMode"]').forEach(function(rb){
      var lbl = rb.closest('label');
      lbl.style.background = rb.checked ? 'rgba(41,171,226,.08)' : 'var(--s2,#141c2c)';
      lbl.style.borderColor = rb.checked ? '#29ABE2' : 'rgba(41,171,226,.15)';
    });
  });
});

// Drag & drop
function handleDrop(e) {
  e.preventDefault();
  document.getElementById('dropZone').style.borderColor = 'rgba(41,171,226,.25)';
  var file = e.dataTransfer.files[0];
  if (!file) return;
  document.getElementById('fileName').textContent = '📄 ' + file.name;
  var dt = new DataTransfer();
  dt.items.add(file);
  document.getElementById('fileInput').files = dt.files;
  document.getElementById('fileInput').closest('form').submit();
}

document.getElementById('fileInput')?.addEventListener('change', function(){
  if (this.files[0]) document.getElementById('fileName').textContent = '📄 ' + this.files[0].name;
});

// Import logic
async function startImport() {
  var rows  = JSON.parse(document.getElementById('rowData')?.textContent || '[]');
  var cols  = JSON.parse(document.getElementById('colData')?.textContent || '[]');
  if (!rows.length) { alert('No data to import'); return; }

  // Build mapping: colIndex → dbField
  var mapping = {};
  document.querySelectorAll('[id^="map"]').forEach(function(sel) {
    if (sel.value) mapping[parseInt(sel.dataset.col)] = sel.value;
  });

  // Validate: must have ep_number column
  var hasEp = Object.values(mapping).includes('ep_number');
  if (!hasEp) { alert('You must map an Episode Number column!'); return; }

  document.getElementById('mapPanel').style.display   = 'none';
  document.getElementById('progPanel').style.display  = 'block';

  var total = rows.length, done = 0, ok = 0, skip = 0, err = 0;
  var stopImport = false;

  // Add stop button
  document.getElementById('impLog').insertAdjacentHTML('beforebegin',
    '<button class="btn btn-ghost btn-sm" id="btnStop" onclick="stopImport=true" style="margin-bottom:.75rem">⏹ Stop</button>');
  window.stopImport = false;

  for (var i = 0; i < rows.length; i++) {
    if (window.stopImport) break;
    var row = rows[i];

    // Extract fields from row
    var fields = {};
    for (var colIdx in mapping) {
      var dbField = mapping[colIdx];
      var val     = row[colIdx] || '';
      if (val !== '') fields[dbField] = val;
    }

    var epNum = parseInt(fields['ep_number']);
    if (!epNum || isNaN(epNum)) { skip++; done++; continue; }
    delete fields['ep_number'];
    if (!Object.keys(fields).length) { skip++; done++; continue; }

    document.getElementById('progLbl').textContent = 'EP' + String(epNum).padStart(3,'0') + ' (' + done + '/' + total + ')';
    document.getElementById('progBar').style.width  = (done/total*100) + '%';

    try {
      var mode = document.querySelector('input[name="importMode"]:checked')?.value || 'create';
      var fd = new FormData();
      fd.append('action', 'import_row');
      fd.append('ep', epNum);
      fd.append('fields', JSON.stringify(fields));
      fd.append('mode', mode);
      var r = await fetch(BP + '/admin/import.php', {method:'POST', body:fd});
      var d = await r.json();

      var li  = document.createElement('div');
      li.style.cssText = 'padding:.12rem 0;border-bottom:1px solid rgba(41,171,226,.04)';
      if (d.ok && (d.action === 'created' || d.action === 'updated')) {
        ok++;
        var badge = d.action==='created'
          ? '<span style="background:rgba(255,215,0,.12);color:#FFD700;font-size:.65rem;padding:1px 6px;border-radius:3px;font-weight:700;margin-left:.35rem">NEW</span>'
          : '<span style="background:rgba(41,171,226,.1);color:#29ABE2;font-size:.65rem;padding:1px 6px;border-radius:3px;font-weight:700;margin-left:.35rem">UPD</span>';
        li.innerHTML = '<span style="color:#86efac;font-weight:700">✓</span> EP' + String(epNum).padStart(3,'0') + badge +
          (d.title ? ' <span style="color:rgba(255,255,255,.3);font-size:.68rem">'+d.title.substring(0,45)+'</span>' : '');
      } else if (d.ok) {
        skip++;
        li.innerHTML = '<span style="color:rgba(255,255,255,.3)">–</span> EP' + String(epNum).padStart(3,'0') + ' <span style="color:rgba(255,255,255,.2)">skipped</span>';
      } else {
        err++;
        li.innerHTML = '<span style="color:#fca5a5;font-weight:700">✗</span> EP' + String(epNum).padStart(3,'0') + ' <span style="color:#fca5a5">' + (d.msg || 'error') + '</span>';
      }
      var log = document.getElementById('impLog');
      log.appendChild(li);
      log.scrollTop = log.scrollHeight;
    } catch(e) { err++; }

    done++;
    document.getElementById('cOk').textContent   = ok;
    document.getElementById('cSkip').textContent = skip;
    document.getElementById('cErr').textContent  = err;
    await new Promise(r => setTimeout(r, 50)); // Small delay to keep UI responsive
  }

  // Done
  document.getElementById('progBar').style.width     = '100%';
  document.getElementById('progBar').style.background = ok > 0 ? '#22c55e' : '#29ABE2';
  document.getElementById('progLbl').textContent = (window.stopImport ? 'Stopped' : 'Complete') + ' — ' + ok + ' updated';

  document.getElementById('progPanel').style.display  = 'block';
  document.getElementById('resultsPanel').style.display = 'block';
  document.getElementById('resultsSummary').innerHTML =
    '<div style="display:flex;gap:1.5rem;flex-wrap:wrap">' +
    '<div style="text-align:center"><div style="font-size:2rem;font-weight:900;color:#86efac">' + ok + '</div><div style="font-size:.7rem;color:rgba(255,255,255,.3);text-transform:uppercase;letter-spacing:.1em">Updated</div></div>' +
    '<div style="text-align:center"><div style="font-size:2rem;font-weight:900;color:rgba(255,255,255,.3)">' + skip + '</div><div style="font-size:.7rem;color:rgba(255,255,255,.3);text-transform:uppercase;letter-spacing:.1em">Skipped</div></div>' +
    (err ? '<div style="text-align:center"><div style="font-size:2rem;font-weight:900;color:#fca5a5">' + err + '</div><div style="font-size:.7rem;color:rgba(255,255,255,.3);text-transform:uppercase;letter-spacing:.1em">Errors</div></div>' : '') +
    '</div>';
}
</script>
</body></html>
