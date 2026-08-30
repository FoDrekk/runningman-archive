<?php
// ============================================================
// Developer Diagnostics — permanent home for everything we built
// during the Wikipedia parser debug marathon (June 2026). See
// DEBUGGING_LESSONS.md for the full story. This replaces the
// throwaway _diagnostic.php / _network_diagnostic*.php /
// _trace_detection.php files with one reusable, general-purpose tool.
//
// Read-only against the database except for the explicit
// "clear cache" actions, which only ever delete temp files that
// regenerate automatically on next use — never touches real data.
// ============================================================
$adminTitle = 'Diagnostics';
$adminPage  = 'diagnostics';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/scraper.php';
require_once __DIR__ . '/../includes/system.php';
adminCheck();

// ── AJAX: network connectivity check (reuses system.php) ───────
if (isset($_GET['a']) && $_GET['a'] === 'netcheck') {
    header('Content-Type: application/json');
    set_time_limit(25);
    if (ob_get_level() > 0) ob_clean();
    try { echo json_encode(runHealthCheck()); }
    catch (Throwable $e) { echo json_encode([['name'=>'Health Check','ok'=>false,'error'=>$e->getMessage()]]); }
    exit;
}

// ── AJAX: test any arbitrary URL with real curl error capture ──
if (isset($_GET['a']) && $_GET['a'] === 'testurl') {
    header('Content-Type: application/json');
    $url = trim($_GET['url'] ?? '');
    if (!$url || !preg_match('~^https?://~i', $url)) {
        echo json_encode(['ok'=>false,'error'=>'Enter a valid http(s) URL']); exit;
    }
    $start = microtime(true);
    $body = rmFetch($url, 12);
    $ms   = round((microtime(true)-$start)*1000);
    echo json_encode([
        'ok'        => $body !== null,
        'length'    => $body ? strlen($body) : 0,
        'ms'        => $ms,
        'error'     => $body === null ? rmLastFetchError() : null,
        'preview'   => $body ? substr(strip_tags($body), 0, 300) : null,
    ]);
    exit;
}

// ── AJAX: live Wikipedia year-parse trace (debug flag ON) ──────
if (isset($_GET['a']) && $_GET['a'] === 'wikitrace') {
    header('Content-Type: application/json');
    set_time_limit(30);
    $year = (int)($_GET['year'] ?? date('Y'));

    @unlink(sys_get_temp_dir()."/rm_wiki_page_$year.html");
    @unlink(sys_get_temp_dir()."/rm_wiki_episodes_$year.json");

    ob_start();
    $GLOBALS['__rm_debug_parse'] = true;
    $t0 = microtime(true);
    $html = rmWikiFetchYearPage($year);
    $t1 = microtime(true);
    $episodes = $html ? rmWikiParseHtml($html) : [];
    $t2 = microtime(true);
    $GLOBALS['__rm_debug_parse'] = false;
    $trace = ob_get_clean();

    echo json_encode([
        'year'          => $year,
        'fetch_ok'      => $html !== null,
        'fetch_chars'   => $html ? strlen($html) : 0,
        'fetch_ms'      => round(($t1-$t0)*1000),
        'fetch_error'   => $html === null ? rmLastFetchError() : null,
        'parse_ms'      => round(($t2-$t1)*1000),
        'episodes_found'=> count($episodes),
        'max_episode'   => $episodes ? max(array_keys($episodes)) : null,
        'sample'        => $episodes ? array_slice($episodes, -2, 2, true) : null,
        'internal_trace'=> $trace,
    ]);
    exit;
}

// ── AJAX: full single-episode scrape trace ──────────────────────
if (isset($_GET['a']) && $_GET['a'] === 'eptrace') {
    header('Content-Type: application/json');
    set_time_limit(20);
    $ep = (int)($_GET['ep'] ?? 0);
    if ($ep < 1) { echo json_encode(['ok'=>false,'error'=>'Enter a valid episode number']); exit; }

    $t0 = microtime(true);
    $wiki = rmWikiEpisode($ep);
    $t1 = microtime(true);
    $myrm = rmMyrmTvEpisode($ep);
    $t2 = microtime(true);
    $extra = rmMyRunningManExtra($ep);
    $t3 = microtime(true);
    // Only call MyDramaList if rmScrapeEpisode() would actually call it too
    // (gated behind RM_ENABLE_MYDRAMALIST — confirmed dead end, see comment
    // above rmMyDramaListEpisode() in scraper.php: flat HTTP 403 from this
    // network even with full browser headers, almost certainly TLS-fingerprint
    // or IP-reputation blocking that no PHP curl header can work around).
    $wouldNeedMdl = RM_ENABLE_MYDRAMALIST && (
        (empty($wiki['synopsis']) && empty($myrm['synopsis']))
        || empty($extra['location']) || (empty($wiki['guests']) && empty($myrm['guests']))
    );
    $mdl = $wouldNeedMdl ? rmMyDramaListEpisode($ep) : null;
    $mdlErr = $wouldNeedMdl ? rmLastMdlError() : null;
    $t3b = microtime(true);
    $merged = rmScrapeEpisode($ep);
    $t4 = microtime(true);

    echo json_encode([
        'episode'  => $ep,
        'year_used'=> rmYear($ep),
        'wikipedia'=> ['data'=>$wiki, 'ms'=>round(($t1-$t0)*1000)],
        'myrm_tv'  => ['data'=>$myrm, 'ms'=>round(($t2-$t1)*1000)],
        'myrunningman'=> ['data'=>$extra, 'ms'=>round(($t3-$t2)*1000)],
        'mydramalist'=> $mdl !== null
            ? ['data'=>$mdl, 'ms'=>round(($t3b-$t3)*1000), 'error'=>$mdlErr]
            : ['data'=>null,'ms'=>0,'skipped'=> RM_ENABLE_MYDRAMALIST ? 'not needed — first 3 sources already had this data' : 'disabled — confirmed HTTP 403 from this network (TLS-fingerprint/IP blocking), see RM_ENABLE_MYDRAMALIST in scraper.php'],
        'merged'   => ['data'=>$merged, 'ms'=>round(($t4-$t3b)*1000)],
    ]);
    exit;
}

// ── AJAX: raw myrunningman.com HTML inspection for location/tags ──
// Location/tags extraction was written from a guess at the markup
// (never verified against real HTML — see DEBUGGING_LESSONS.md for
// why that's risky). This dumps the actual raw HTML around wherever
// "Location" and tag-link patterns appear, so the regex can be fixed
// from evidence instead of another guess.
if (isset($_GET['a']) && $_GET['a'] === 'mrminspect') {
    header('Content-Type: application/json');
    set_time_limit(15);
    $ep = (int)($_GET['ep'] ?? 0);
    if ($ep < 1) { echo json_encode(['ok'=>false,'error'=>'Enter a valid episode number']); exit; }

    // BUGFIX: was "/episodes/$ep" — that's the paginated INDEX route, not
    // a per-episode page (myrunningman.com and myrm.tv share the same
    // /ep/{n} route). Confirmed by live fetch: /episodes/300 returns
    // "Episodes - Page 300" with zero episode content; /ep/300 returns
    // the real episode page with Location/tags/etc.
    $url  = "https://www.myrunningman.com/ep/$ep";
    $html = rmFetch($url, 12);
    if (!$html) {
        echo json_encode(['ok'=>false,'error'=>rmLastFetchError() ?: 'fetch failed']);
        exit;
    }

    $result = ['ok'=>true, 'url'=>$url, 'html_length'=>strlen($html)];

    // Find raw context around an ACTUAL "Location:" label in the visible
    // page body — not just any occurrence of the word "location" (the
    // first match was a JS variable name, not real content, last time).
    if (preg_match('/Location\s*:?\s*<\/[^>]*>(.{0,300})/is', $html, $m, PREG_OFFSET_CAPTURE)) {
        $pos = $m[0][1];
        $result['location_context'] = substr($html, max(0,$pos-60), 400);
    } elseif (preg_match('/>\s*Location\s*:?\s*</i', $html, $m, PREG_OFFSET_CAPTURE)) {
        $pos = $m[0][1];
        $result['location_context'] = substr($html, max(0,$pos-60), 400);
    } else {
        $result['location_context'] = '(no "Location:" LABEL found in visible content — only generic occurrences of the word, if any)';
    }

    // Tags: look specifically for tag CHIPS within the episode detail
    // area, not the site's global nav link to /tags. Try a few distinct
    // patterns and show whichever finds something.
    $tagPatterns = [
        'badge/chip class' => '/<(?:span|a)[^>]+class=["\'][^"\']*\btag\b[^"\']*["\'][^>]*>(.{0,500})/i',
        'tag-list container' => '/<[^>]+(?:id|class)=["\'][^"\']*tag-?list[^"\']*["\'][^>]*>(.{0,500})/i',
        'data-tag attribute' => '/data-tag[^>]*>(.{0,500})/i',
    ];
    $result['tags_context'] = [];
    foreach ($tagPatterns as $label => $pat) {
        if (preg_match($pat, $html, $m)) $result['tags_context'][$label] = substr($m[0], 0, 400);
    }
    if (!$result['tags_context']) $result['tags_context']['none'] = '(none of the tested tag patterns matched anywhere in this page)';

    // Real per-episode thumbnail: look for <img> tags near "episode" or
    // with a src path that looks like a thumbnail/upload, NOT just the
    // sitewide og:image (which turned out to be a generic social.png).
    $result['img_tags'] = [];
    if (preg_match_all('/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $html, $im)) {
        foreach (array_slice($im[1], 0, 12) as $src) {
            if (preg_match('/logo|icon|avatar|favicon|sprite/i', $src)) continue;
            $result['img_tags'][] = $src;
        }
    }

    $result['current_extraction'] = rmMyRunningManExtra($ep);

    // Last resort: strip ALL script/style tags and dump the remaining
    // visible text. If "Location" and tags genuinely don't appear here
    // either, the data isn't in the server-rendered HTML at all — it's
    // either JS-rendered (same problem as myrm.tv) or simply not present
    // for this episode.
    $stripped = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html);
    $stripped = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $stripped);
    $visibleText = trim(preg_replace('/\s+/', ' ', strip_tags($stripped)));
    $result['visible_text_sample'] = mb_substr($visibleText, 0, 2500);

    echo json_encode($result);
    exit;
}

// ── AJAX: cache file listing + clear ─────────────────────────────
function rmListCacheFiles(): array {
    $dir = sys_get_temp_dir();
    $files = [];
    foreach (glob("$dir/rm_*") as $f) {
        $files[] = [
            'name' => basename($f),
            'size' => filesize($f),
            'age_min' => round((time()-filemtime($f))/60, 1),
        ];
    }
    usort($files, fn($a,$b) => strcmp($a['name'],$b['name']));
    return $files;
}
if (isset($_GET['a']) && $_GET['a'] === 'cachelist') {
    header('Content-Type: application/json');
    echo json_encode(rmListCacheFiles());
    exit;
}
if (isset($_GET['a']) && $_GET['a'] === 'cacheclear') {
    header('Content-Type: application/json');
    $target = $_GET['file'] ?? 'all';
    $dir = sys_get_temp_dir();
    $cleared = [];
    foreach (glob("$dir/rm_*") as $f) {
        if ($target === 'all' || basename($f) === $target) {
            @unlink($f);
            $cleared[] = basename($f);
        }
    }
    echo json_encode(['cleared'=>$cleared]);
    exit;
}

// ── Normal page render ───────────────────────────────────────────
require_once __DIR__ . '/layout.php';

$dbMax = (int)getDB()->query("SELECT MAX(episode_number) FROM episodes")->fetchColumn();
$currentYear = (int)date('Y');
$expectedFunctions = ['rmFetch','rmWikiFetchYearPage','rmWikiParseHtml','rmWikiParseYear',
    'rmWikiEpisode','rmMyrmTvEpisode','rmScrapeEpisode','rmGetLatestEpNumber',
    'rmEpisodeExistsOnMRM','rmCleanTitle','rmDownloadThumb','rmYear','rmLastFetchError'];
$missingFunctions = array_values(array_filter($expectedFunctions, fn($f) => !function_exists($f)));
?>

<div class="at">
  <h1>🛠️ Diagnostics</h1>
  <p>Permanent debugging toolkit — system identity, connectivity, and live trace tools for when something doesn't work as expected. See <code>DEBUGGING_LESSONS.md</code> for the methodology behind this page.</p>
</div>

<!-- Section A: System Identity -->
<div class="ap">
  <div class="sh">System Identity</div>
  <table class="atable">
    <tr><td style="width:240px;color:rgba(255,255,255,.4)">scraper.php path</td><td><?= h(realpath(__DIR__.'/../includes/scraper.php')) ?></td></tr>
    <tr><td style="color:rgba(255,255,255,.4)">Last modified</td><td><?= h(date('Y-m-d H:i:s', filemtime(__DIR__.'/../includes/scraper.php'))) ?></td></tr>
    <tr><td style="color:rgba(255,255,255,.4)">PHP version</td><td><?= phpversion() ?></td></tr>
    <tr><td style="color:rgba(255,255,255,.4)">opcache enabled</td><td><?= (function_exists('opcache_get_status') && opcache_get_status()!==false) ? '<span style="color:#fcd34d">yes — restart Apache after replacing PHP files</span>' : 'no' ?></td></tr>
    <tr><td style="color:rgba(255,255,255,.4)">DOM extension</td><td><?= extension_loaded('dom') ? '<span style="color:#86efac">loaded</span>' : '<span style="color:#fca5a5">MISSING — Wikipedia parsing will not work</span>' ?></td></tr>
    <tr><td style="color:rgba(255,255,255,.4)">Required functions</td><td>
      <?= $missingFunctions ? '<span style="color:#fca5a5">MISSING: '.h(implode(', ',$missingFunctions)).' — old scraper.php is likely still loaded</span>' : '<span style="color:#86efac">all '.count($expectedFunctions).' present</span>' ?>
    </td></tr>
    <tr><td style="color:rgba(255,255,255,.4)">DB latest episode</td><td>EP<?= str_pad($dbMax,3,'0',STR_PAD_LEFT) ?></td></tr>
  </table>
</div>

<!-- Section B: Network Connectivity -->
<div class="ap">
  <div class="sh">Network Connectivity</div>
  <button class="btn btn-sm" onclick="runNetCheck()" id="btnNet">🔄 Test Connections</button>
  <div id="netResults" style="margin-top:1rem;display:grid;grid-template-columns:repeat(2,1fr);gap:.6rem"></div>

  <div style="margin-top:1.2rem;padding-top:1rem;border-top:1px solid rgba(41,171,226,.08)">
    <div style="font-size:.78rem;color:rgba(255,255,255,.4);margin-bottom:.5rem">Test any specific URL (uses the same rmFetch() the real scraper uses, including retry + real curl error capture):</div>
    <div style="display:flex;gap:.5rem">
      <input type="text" id="testUrlInput" placeholder="https://en.wikipedia.org/w/api.php?..." style="flex:1;padding:.5rem .8rem;background:#141c2c;border:1px solid rgba(41,171,226,.14);border-radius:7px;color:#eef2f8;font-size:.82rem;outline:none">
      <button class="btn btn-dark btn-sm" onclick="testUrl()">Test</button>
    </div>
    <div id="urlResult" style="margin-top:.8rem"></div>
  </div>
</div>

<!-- Section C: Wikipedia Year-Parse Trace -->
<div class="ap">
  <div class="sh">Wikipedia Year-Parse Trace</div>
  <p style="font-size:.78rem;color:rgba(255,255,255,.35);margin-bottom:.8rem">
    Fetches a year page fresh (bypassing cache) and parses it with internal step-by-step
    tracing switched on — shows exactly what the real <code>rmWikiParseHtml()</code> sees and does.
  </p>
  <div style="display:flex;gap:.5rem;align-items:center;margin-bottom:.8rem">
    <span style="font-size:.8rem;color:rgba(255,255,255,.4)">Year:</span>
    <select id="traceYear" style="padding:.4rem .7rem;background:#141c2c;border:1px solid rgba(41,171,226,.14);border-radius:7px;color:#eef2f8;font-size:.82rem">
      <?php for ($y=$currentYear; $y>=2010; $y--): ?>
        <option value="<?= $y ?>" <?= $y===$currentYear?'selected':'' ?>><?= $y ?></option>
      <?php endfor; ?>
    </select>
    <button class="btn btn-sm" onclick="runWikiTrace()">▶ Trace This Year</button>
  </div>
  <div id="wikiTraceResult"></div>
</div>

<!-- Section D: Single Episode Scrape Trace -->
<div class="ap">
  <div class="sh">Single Episode Scrape Trace</div>
  <p style="font-size:.78rem;color:rgba(255,255,255,.35);margin-bottom:.8rem">
    Runs Wikipedia + myrm.tv + myrunningman.com + MyDramaList (4th fallback) + the merge logic for one episode, showing exactly what each source contributed.
  </p>
  <div style="display:flex;gap:.5rem;align-items:center">
    <span style="font-size:.8rem;color:rgba(255,255,255,.4)">Episode #:</span>
    <input type="number" id="traceEp" value="<?= $dbMax+1 ?>" style="width:90px;padding:.4rem .7rem;background:#141c2c;border:1px solid rgba(41,171,226,.14);border-radius:7px;color:#eef2f8;font-size:.82rem;outline:none">
    <button class="btn btn-sm" onclick="runEpTrace()">▶ Trace This Episode</button>
  </div>
  <div id="epTraceResult" style="margin-top:.8rem"></div>
</div>

<!-- Section D2: myrunningman.com Location/Tags raw inspector -->
<div class="ap">
  <div class="sh">myrunningman.com Raw HTML Inspector (Location / Tags)</div>
  <p style="font-size:.78rem;color:rgba(255,255,255,.35);margin-bottom:.8rem">
    Location/tags extraction was written without verifying real markup first. Use this to see the
    ACTUAL raw HTML around "Location" and tag links for a real episode, so the regex can be fixed
    from evidence — the same approach that found and fixed all the Wikipedia parser bugs.
    <strong style="color:#fcd34d">Tip: test an older, well-established episode (e.g. EP4) rather than
    a brand-new one — very recent episodes often don't have community-added location/tags yet.</strong>
  </p>
  <div style="display:flex;gap:.5rem;align-items:center">
    <span style="font-size:.8rem;color:rgba(255,255,255,.4)">Episode #:</span>
    <input type="number" id="mrmEp" value="4" style="width:90px;padding:.4rem .7rem;background:#141c2c;border:1px solid rgba(41,171,226,.14);border-radius:7px;color:#eef2f8;font-size:.82rem;outline:none">
    <button class="btn btn-sm" onclick="runMrmInspect()">▶ Inspect Raw HTML</button>
  </div>
  <div id="mrmInspectResult" style="margin-top:.8rem"></div>
</div>

<!-- Section E: Cache Management -->
<div class="ap">
  <div class="sh">Cache Files</div>
  <button class="btn btn-dark btn-sm" onclick="loadCacheList()">🔄 Refresh List</button>
  <button class="btn btn-ghost btn-sm" onclick="clearCache('all')">🗑️ Clear All</button>
  <div id="cacheList" style="margin-top:.8rem;font-size:.78rem"></div>
</div>

</main></div>
<style>
.diag-ok{color:#86efac}.diag-fail{color:#fca5a5}.diag-warn{color:#fcd34d}
.diag-pre{background:#06090f;padding:.8rem;border-radius:6px;overflow-x:auto;font-size:.72rem;color:#94a3b8;max-height:350px;white-space:pre-wrap;font-family:Consolas,monospace}
</style>
<script>
const BP='<?= bp() ?>';
function h(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')}

async function runNetCheck(){
  var btn=document.getElementById('btnNet');
  btn.disabled=true; btn.innerHTML='<span class="spin"></span> Testing…';
  try{
    var ctrl=new AbortController(); var kill=setTimeout(()=>ctrl.abort(),28000);
    var r=await fetch(BP+'/admin/diagnostics.php?a=netcheck',{signal:ctrl.signal});
    clearTimeout(kill);
    var sources=await r.json();
    document.getElementById('netResults').innerHTML = sources.map(function(s){
      var color=s.ok?'#86efac':'#fca5a5', bg=s.ok?'rgba(34,197,94,.08)':'rgba(239,68,68,.08)', bd=s.ok?'rgba(34,197,94,.2)':'rgba(239,68,68,.25)';
      return '<div style="background:'+bg+';border:1px solid '+bd+';border-radius:10px;padding:.85rem">'
        +'<div style="display:flex;justify-content:space-between"><strong style="font-size:.85rem">'+h(s.name)+'</strong><span style="color:'+color+';font-size:.75rem;font-weight:700">'+(s.ok?'✓ OK':'✗ DOWN')+'</span></div>'
        +'<div style="font-size:.7rem;color:rgba(255,255,255,.35);margin-top:.25rem">'+(s.ok?s.ms+'ms':h(s.error||'unreachable'))+'</div></div>';
    }).join('');
  }catch(e){ document.getElementById('netResults').innerHTML='<div class="diag-fail">Request failed: '+h(e.message)+'</div>'; }
  btn.disabled=false; btn.innerHTML='🔄 Test Connections';
}

async function testUrl(){
  var url=document.getElementById('testUrlInput').value.trim();
  var out=document.getElementById('urlResult');
  if(!url){out.innerHTML='<span class="diag-warn">Enter a URL first</span>';return}
  out.innerHTML='<span class="spin"></span> Testing…';
  try{
    var r=await fetch(BP+'/admin/diagnostics.php?a=testurl&url='+encodeURIComponent(url));
    var d=await r.json();
    var html = d.ok
      ? '<span class="diag-ok">✓ '+d.length+' bytes in '+d.ms+'ms</span>'
      : '<span class="diag-fail">✗ '+h(d.error||'failed')+'</span>';
    if(d.preview) html += '<div class="diag-pre" style="margin-top:.5rem;max-height:150px">'+h(d.preview)+'</div>';
    out.innerHTML = html;
  }catch(e){ out.innerHTML='<span class="diag-fail">Request failed: '+h(e.message)+'</span>'; }
}

async function runWikiTrace(){
  var year=document.getElementById('traceYear').value;
  var out=document.getElementById('wikiTraceResult');
  out.innerHTML='<span class="spin"></span> Fetching + parsing '+year+' (cache cleared first)…';
  try{
    var r=await fetch(BP+'/admin/diagnostics.php?a=wikitrace&year='+year);
    var d=await r.json();
    var html = '<table class="atable" style="margin-top:.6rem">'
      +'<tr><td style="width:220px;color:rgba(255,255,255,.4)">Fetch result</td><td>'+(d.fetch_ok?'<span class="diag-ok">✓ '+d.fetch_chars.toLocaleString()+' chars</span>':'<span class="diag-fail">✗ '+h(d.fetch_error||'failed')+'</span>')+' ('+d.fetch_ms+'ms)</td></tr>'
      +'<tr><td style="color:rgba(255,255,255,.4)">Episodes parsed</td><td>'+(d.episodes_found>0?'<span class="diag-ok">'+d.episodes_found+'</span>':'<span class="diag-fail">0</span>')+(d.max_episode?' (max EP'+d.max_episode+')':'')+' ('+d.parse_ms+'ms)</td></tr>'
      +'</table>';
    if(d.internal_trace) html += '<div class="diag-pre" style="margin-top:.6rem">'+h(d.internal_trace)+'</div>';
    out.innerHTML = html;
  }catch(e){ out.innerHTML='<span class="diag-fail">Request failed: '+h(e.message)+'</span>'; }
}

async function runEpTrace(){
  var ep=document.getElementById('traceEp').value;
  var out=document.getElementById('epTraceResult');
  out.innerHTML='<span class="spin"></span> Scraping EP'+ep+' from all sources…';
  try{
    var r=await fetch(BP+'/admin/diagnostics.php?a=eptrace&ep='+ep);
    var d=await r.json();
    var fmt=function(label,obj){
      if (!obj.data) {
        return '<div style="background:#141c2c;border:1px solid rgba(41,171,226,.1);border-radius:8px;padding:.7rem;margin-top:.5rem">'
          +'<div style="display:flex;justify-content:space-between"><strong style="font-size:.8rem">'+label+'</strong><span style="font-size:.7rem;color:rgba(255,255,255,.3)">skipped</span></div>'
          +'<div style="font-size:.74rem;color:rgba(255,255,255,.35);margin-top:.3rem">'+h(obj.skipped||'no data')+'</div></div>';
      }
      return '<div style="background:#141c2c;border:1px solid rgba(41,171,226,.1);border-radius:8px;padding:.7rem;margin-top:.5rem">'
        +'<div style="display:flex;justify-content:space-between"><strong style="font-size:.8rem">'+label+'</strong><span style="font-size:.7rem;color:rgba(255,255,255,.3)">'+obj.ms+'ms</span></div>'
        +(obj.error ? '<div style="font-size:.74rem;color:#fca5a5;margin-top:.3rem">⚠ '+h(obj.error)+'</div>' : '')
        +'<div class="diag-pre" style="margin-top:.4rem;max-height:160px">'+h(JSON.stringify(obj.data,null,2))+'</div></div>';
    };
    out.innerHTML = '<div style="font-size:.78rem;color:rgba(255,255,255,.4)">Episode '+d.episode+' → year '+d.year_used+'</div>'
      + fmt('Wikipedia', d.wikipedia) + fmt('myrm.tv', d.myrm_tv) + fmt('myrunningman.com', d.myrunningman) + fmt('MyDramaList (4th fallback)', d.mydramalist) + fmt('Merged (final result)', d.merged);
  }catch(e){ out.innerHTML='<span class="diag-fail">Request failed: '+h(e.message)+'</span>'; }
}

async function runMrmInspect(){
  var ep=document.getElementById('mrmEp').value;
  var out=document.getElementById('mrmInspectResult');
  out.innerHTML='<span class="spin"></span> Fetching raw HTML for EP'+ep+'…';
  try{
    var r=await fetch(BP+'/admin/diagnostics.php?a=mrminspect&ep='+ep);
    var d=await r.json();
    if(!d.ok){ out.innerHTML='<span class="diag-fail">✗ '+h(d.error||'failed')+'</span>'; return; }
    var html = '<table class="atable" style="margin-bottom:.6rem"><tr><td style="width:180px;color:rgba(255,255,255,.4)">Page length</td><td>'+d.html_length.toLocaleString()+' chars</td></tr></table>';
    html += '<div style="font-size:.75rem;color:#7dd3f0;margin-bottom:.3rem">Raw context around "Location":</div>';
    html += '<div class="diag-pre">'+h(d.location_context)+'</div>';
    html += '<div style="font-size:.75rem;color:#7dd3f0;margin:.6rem 0 .3rem">Raw context around tags (each pattern tested separately):</div>';
    html += '<div class="diag-pre">'+h(JSON.stringify(d.tags_context,null,2))+'</div>';
    html += '<div style="font-size:.75rem;color:#7dd3f0;margin:.6rem 0 .3rem">Real &lt;img&gt; tags found on the page (logo/icon/favicon already filtered out):</div>';
    html += '<div class="diag-pre">'+(d.img_tags && d.img_tags.length ? h(JSON.stringify(d.img_tags,null,2)) : '(no non-logo/icon img tags found)')+'</div>';
    html += '<div style="font-size:.75rem;color:#7dd3f0;margin:.6rem 0 .3rem">What the current regex extracts right now:</div>';
    html += '<div class="diag-pre">'+h(JSON.stringify(d.current_extraction,null,2))+'</div>';
    html += '<div style="font-size:.75rem;color:#a855f7;margin:.6rem 0 .3rem">Full visible page text (scripts/styles stripped) — look here for the actual wording used:</div>';
    html += '<div class="diag-pre" style="max-height:300px">'+h(d.visible_text_sample)+'</div>';
    out.innerHTML = html;
  }catch(e){ out.innerHTML='<span class="diag-fail">Request failed: '+h(e.message)+'</span>'; }
}

async function loadCacheList(){
  var out=document.getElementById('cacheList');
  out.innerHTML='<span class="spin"></span> Loading…';
  try{
    var r=await fetch(BP+'/admin/diagnostics.php?a=cachelist');
    var files=await r.json();
    if(!files.length){ out.innerHTML='<span style="color:rgba(255,255,255,.3)">No cache files currently.</span>'; return; }
    out.innerHTML = files.map(function(f){
      return '<div style="display:flex;justify-content:space-between;padding:.3rem 0;border-bottom:1px solid rgba(41,171,226,.05)">'
        +'<span>'+h(f.name)+'</span><span style="color:rgba(255,255,255,.3)">'+f.size+' bytes · '+f.age_min+'m old</span>'
        +'<button class="btn btn-ghost btn-sm" style="padding:1px 8px;font-size:.65rem" onclick="clearCache(\''+h(f.name)+'\')">clear</button></div>';
    }).join('');
  }catch(e){ out.innerHTML='<span class="diag-fail">Failed: '+h(e.message)+'</span>'; }
}
async function clearCache(file){
  await fetch(BP+'/admin/diagnostics.php?a=cacheclear&file='+encodeURIComponent(file));
  loadCacheList();
}
window.addEventListener('load', function(){ runNetCheck(); loadCacheList(); });
</script>
</body></html>
