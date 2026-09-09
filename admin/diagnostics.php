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
// Now traces the whole engine, not just three hardcoded sources: what
// EVERY registered source returned (with its status, timing and real
// failure reason), then how the resolver chose each field, with the
// confidence and any disagreement it found. This is the tool for
// answering "why does EP809 have that synopsis?".
if (isset($_GET['a']) && $_GET['a'] === 'eptrace') {
    header('Content-Type: application/json');
    set_time_limit(180);
    if (ob_get_level() > 0) ob_clean();
    $ep = (int)($_GET['ep'] ?? 0);
    if ($ep < 1) { echo json_encode(['ok'=>false,'error'=>'Enter a valid episode number']); exit; }

    try {
        $engine = new RmScrapingEngine();
        $t0 = microtime(true);
        // Read-only: contacts every source and computes the full plan,
        // then writes nothing at all — no episode data, no provenance,
        // no source health, no log rows.
        $plan = $engine->trace($ep, ['bypass_cache' => !empty($_GET['fresh'])]);
        $ms = (int)round((microtime(true) - $t0) * 1000);

        $sources = [];
        foreach ((array)($plan['meta'] ?? []) as $name => $m) {
            $payload = (array)($plan['payloads'][$name] ?? []);

            // How many values each field yielded — "Guests: 8" is the
            // number that tells you at a glance whether a parser is
            // still working, in a way "fields: guests" does not.
            $counts = [];
            foreach ($m['fields'] as $f) {
                $v = $payload[$f] ?? null;
                $counts[$f] = is_array($v) ? count($v) : ($v === null || $v === '' ? 0 : 1);
            }

            // Fetch and parse are separate verdicts on purpose: a source
            // can fetch perfectly and parse nothing, and that combination
            // is the one worth noticing.
            $fetched = !in_array($m['status'], ['fetch_failed','blocked','rate_limited','adapter_error','suppressed','disabled'], true);
            $parser  = match (true) {
                $m['status'] === 'ok' && $m['fields'] !== [] => 'OK',
                $m['status'] === 'parser_warning'            => 'WARNING',
                $m['status'] === 'missing_episode'           => 'N/A — episode not listed',
                in_array($m['status'], ['disabled','not_applicable','suppressed','skipped'], true) => 'not run',
                $fetched                                     => 'WARNING',
                default                                      => 'not reached',
            };

            $sources[$name] = [
                'source_class' => rmScrapeSourceClass($name),
                'tier'   => (int)rmScrapeConfig("sources.$name.tier", 1),
                'status' => $m['status'], 'ms' => $m['ms'], 'http' => $m['http'],
                'fields' => $m['fields'], 'field_counts' => $counts,
                'error'  => $m['error'], 'class' => $m['class'],
                'cached' => $m['cached'], 'suppressed' => !empty($m['suppressed']),
                'url'    => $m['url'], 'parser_version' => $m['version'],
                'fetch'  => $fetched ? 'OK' : 'FAILED',
                'parser' => $parser,
                'data'   => array_intersect_key($payload, array_flip($m['fields'])),
            ];
        }

        $resolution = [];
        foreach ((array)($plan['resolved'] ?? []) as $field => $r) {
            $value = $r['value'] ?? null;
            // What each source OFFERED, before resolution — so the
            // normalisation step is visible: "Seoul, South Korea" and
            // "Seoul City" arriving as two strings and agreeing as one
            // value is the interesting part.
            $offered = [];
            foreach ((array)($plan['payloads'] ?? []) as $src => $payload) {
                if (!array_key_exists($field, $payload)) continue;
                $raw = $payload[$field];
                $offered[$src] = is_array($raw) ? implode(', ', array_map('strval', $raw)) : (string)$raw;
            }
            $resolution[$field] = [
                'value'      => is_array($value) ? implode(', ', array_map('strval', $value)) : $value,
                'count'      => is_array($value) ? count($value) : ($value === null || $value === '' ? 0 : 1),
                'source'     => $r['source'] ?? null,
                'agreed_by'  => $r['sources'] ?? [],
                'confidence' => $r['confidence'] ?? null,
                'conflicts'  => $r['conflicts'] ?? [],
                'rejected'   => $r['rejected'] ?? [],   // validation refusals, with reasons
                'offered'    => $offered,               // pre-normalisation input
            ];
        }

        echo json_encode([
            'ok'         => true,
            'episode'    => $ep,
            'year_used'  => rmYear($ep),
            'total_ms'   => $ms,
            'is_new'     => !empty($plan['is_new']),
            'sources'    => $sources,
            'resolution' => $resolution,
            'changes'    => array_map([RmDiffEngine::class, 'renderLine'],
                                array_values(array_filter((array)($plan['changes'] ?? []), fn($c) => $c['type'] !== 'unchanged'))),
            'warnings'   => array_column((array)($plan['warnings'] ?? []), 'message'),
            'applied'    => (int)($plan['summary']['total_applied'] ?? 0),
            'dry_run'    => true,
            'note'       => 'Dry run — no database changes were made.',
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    } catch (Throwable $e) {
        echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
    }
    exit;
}

// ── AJAX: offline engine self-test ──────────────────────────────
// Exercises the rules that must never silently regress — guest identity
// collapsing, location normalisation, garbage rejection, field priority,
// confidence scoring and the data-safety guards. No network, no writes.
if (isset($_GET['a']) && $_GET['a'] === 'selftest') {
    header('Content-Type: application/json');
    if (ob_get_level() > 0) ob_clean();
    try {
        require_once __DIR__ . '/../includes/scraping/selftest.php';
        echo json_encode(['ok' => true] + rmScrapingSelfTest());
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ── AJAX: source health snapshot ────────────────────────────────
if (isset($_GET['a']) && $_GET['a'] === 'srchealth') {
    header('Content-Type: application/json');
    set_time_limit(90);
    if (ob_get_level() > 0) ob_clean();
    try {
        $h = RmSourceHealth::instance();
        echo json_encode(['ok'=>true, 'sources'=>$h->all(), 'probe'=>!empty($_GET['probe']) ? $h->probeAll() : []]);
    } catch (Throwable $e) {
        echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
    }
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

// ── Diagnostic report (section 23): a completed run, exportable ──
// JSON for a developer, HTML for anyone else — either way, every
// secret is redacted before the report leaves RmDiagnosticReport at all.
if (isset($_GET['a']) && $_GET['a'] === 'report') {
    $format = ($_GET['format'] ?? 'json') === 'html' ? 'html' : 'json';
    $runId  = isset($_GET['run']) ? (int)$_GET['run'] : null;
    if ($runId === null) {
        $latest = RmScrapeRun::latest();
        $runId = $latest ? (int)$latest['run_id'] : null;
    }
    if ($runId === null) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'No research run found yet — run something first.']);
        exit;
    }
    $report = RmDiagnosticReport::forRun(getDBSafe(), $runId);
    $filename = "diagnostic-report-run-$runId." . ($format === 'html' ? 'html' : 'json');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    if ($format === 'html') {
        header('Content-Type: text/html; charset=utf-8');
        echo RmDiagnosticReport::toHtml($report);
    } else {
        header('Content-Type: application/json');
        echo RmDiagnosticReport::toJson($report);
    }
    exit;
}

// ── AJAX: cache file listing + clear ─────────────────────────────
function rmListCacheFiles(): array {
    $files = [];
    // Legacy temp files plus the scraping engine's own cache directory,
    // so "clear cache" here really does clear everything the scraper reads.
    foreach (glob(sys_get_temp_dir() . '/rm_*') as $f) {
        if (is_dir($f)) continue;
        $files[] = ['name' => basename($f), 'size' => (int)filesize($f),
                    'age_min' => round((time() - filemtime($f)) / 60, 1), 'engine' => false];
    }
    $engineDir = RmCache::instance()->dir();
    foreach (glob($engineDir . '/*.json') as $f) {
        $files[] = ['name' => 'engine/' . basename($f), 'size' => (int)filesize($f),
                    'age_min' => round((time() - filemtime($f)) / 60, 1), 'engine' => true];
    }
    usort($files, fn($a, $b) => strcmp($a['name'], $b['name']));
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
    // Engine cache entries are namespaced "engine/<file>" in the listing.
    if ($target === 'all' || str_starts_with($target, 'engine/')) {
        $engineDir = RmCache::instance()->dir();
        foreach (glob($engineDir . '/*.json') as $f) {
            if ($target === 'all' || basename($f) === substr($target, 7)) {
                if (@unlink($f)) $cleared[] = 'engine/' . basename($f);
            }
        }
        // A manual cache clear is also the operator saying "try the failing
        // sources again now", so lift any automatic cool-downs with it.
        if ($target === 'all') RmSourceHealth::instance()->clearSuppression();
    }
    foreach (glob("$dir/rm_*") as $f) {
        if (is_dir($f)) continue;
        if ($target === 'all' || basename($f) === $target) {
            @unlink($f);
            $cleared[] = basename($f);
        }
    }
    echo json_encode(['cleared'=>$cleared]);
    exit;
}

// ── Normal page render ───────────────────────────────────────────
// A ?a= request that reached this point matched no handler above.
// Rendering the page would hand a JSON caller an HTML document.
rmJsonRejectUnknownAction();

require_once __DIR__ . '/layout.php';

$dbMax = (int)getDB()->query("SELECT MAX(episode_number) FROM episodes")->fetchColumn();
$currentYear = (int)date('Y');
$expectedFunctions = ['rmFetch','rmWikiFetchYearPage','rmWikiParseHtml','rmWikiParseYear',
    'rmWikiEpisode','rmMyrmTvEpisode','rmScrapeEpisode','rmGetLatestEpNumber',
    'rmEpisodeExistsOnMRM','rmCleanTitle','rmDownloadThumb','rmYear','rmLastFetchError'];
$missingFunctions = array_values(array_filter($expectedFunctions, fn($f) => !function_exists($f)));

// Engine components — a missing class here means an incomplete deployment,
// which looks identical to "the scraper stopped working" from the outside.
$expectedClasses = ['RmScrapingEngine','RmSourceRegistry','RmFieldResolver','RmDiffEngine',
    'RmNormalizer','RmValidator','RmProvenance','RmSourceHealth','RmThumbnailEngine',
    'RmCache','RmHttpClient','RmMissingData','RmScrapeRun'];
$missingClasses = array_values(array_filter($expectedClasses, fn($c) => !class_exists($c)));
$engineTablesReady = rmScrapingTablesExist();
$registeredSources = array_keys(RmSourceRegistry::instance()->all());
$activeSources     = array_keys(RmSourceRegistry::instance()->active(true));
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
    <tr><td style="color:rgba(255,255,255,.4)">Engine components</td><td>
      <?= $missingClasses ? '<span style="color:#fca5a5">MISSING: '.h(implode(', ',$missingClasses)).' — includes/scraping/ is incomplete</span>' : '<span style="color:#86efac">all '.count($expectedClasses).' loaded</span>' ?>
    </td></tr>
    <tr><td style="color:rgba(255,255,255,.4)">Registered sources</td><td>
      <?= h(implode(', ', $registeredSources)) ?>
      <span style="color:rgba(255,255,255,.3)">· active: <?= h(implode(', ', $activeSources)) ?></span>
    </td></tr>
    <tr><td style="color:rgba(255,255,255,.4)">Engine tables</td><td>
      <?php if ($engineTablesReady): ?><span style="color:#86efac">installed</span>
      <?php else: ?><span style="color:#fcd34d">not installed — provenance, change tracking and run history are disabled.
        Install from <a href="<?= bp() ?>/admin/scraper.php" style="color:inherit;text-decoration:underline">Scraper Control Centre</a></span><?php endif; ?>
    </td></tr>
    <tr><td style="color:rgba(255,255,255,.4)">DB latest episode</td><td>EP<?= str_pad($dbMax,3,'0',STR_PAD_LEFT) ?></td></tr>
  </table>
  <div style="margin-top:.8rem">
    <button class="btn btn-dark btn-sm" onclick="runSelfTest()">🧪 Run engine self-test (offline)</button>
    <span style="font-size:.74rem;color:rgba(255,255,255,.3);margin-left:.4rem">Checks normalisation, validation, resolution and data-safety rules. No network, no writes.</span>
    <div id="selfTestResult" style="margin-top:.7rem"></div>
  </div>
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

<!-- Section C2: Source Health -->
<div class="ap">
  <div class="sh">Source Health</div>
  <p style="font-size:.78rem;color:rgba(255,255,255,.35);margin-bottom:.8rem">
    What each source has actually been doing, and <em>how</em> it is failing when it fails — a DNS failure, a 403,
    and "reachable but returning nothing" are three different problems with three different fixes. Full controls
    live in the <a href="<?= bp() ?>/admin/scraper.php">Scraper Control Centre</a>.
  </p>
  <div style="display:flex;gap:.5rem;align-items:center;margin-bottom:.8rem">
    <button class="btn btn-sm" onclick="loadSrcHealth(false)">↻ Show recorded health</button>
    <button class="btn btn-dark btn-sm" onclick="loadSrcHealth(true)">📡 Probe every source now</button>
  </div>
  <div id="srcHealthResult"></div>
</div>

<!-- Section D: Single Episode Scrape Trace -->
<div class="ap">
  <div class="sh">Single Episode Scrape Trace</div>
  <p style="font-size:.78rem;color:rgba(255,255,255,.35);margin-bottom:.8rem">
    Runs the full engine for one episode as a <strong>dry run</strong> — every registered source is contacted,
    then the resolver picks each field and reports its confidence. Nothing is written to the database.
    This answers both "what did each source return?" and "why did this value win?".
  </p>
  <div style="display:flex;gap:.5rem;align-items:center">
    <span style="font-size:.8rem;color:rgba(255,255,255,.4)">Episode #:</span>
    <input type="number" id="traceEp" value="<?= $dbMax+1 ?>" style="width:90px;padding:.4rem .7rem;background:#141c2c;border:1px solid rgba(41,171,226,.14);border-radius:7px;color:#eef2f8;font-size:.82rem;outline:none">
    <label style="font-size:.76rem;color:rgba(255,255,255,.4);display:flex;align-items:center;gap:.3rem">
      <input type="checkbox" id="traceFresh"> bypass cache
    </label>
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

<!-- Section D3: Diagnostic Report Export -->
<div class="ap">
  <div class="sh">Diagnostic Report</div>
  <p style="font-size:.78rem;color:rgba(255,255,255,.35);margin-bottom:.8rem">
    A complete, sanitised snapshot of one research run — system info, every source's result,
    field-by-field decisions, AI activity, database changes and errors. JSON for debugging,
    HTML to read or attach to a bug report. Every secret is redacted automatically.
  </p>
  <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">
    <span style="font-size:.8rem;color:rgba(255,255,255,.4)">Run ID (blank = latest):</span>
    <input type="number" id="reportRunId" placeholder="latest" style="width:110px;padding:.4rem .7rem;background:#141c2c;border:1px solid rgba(41,171,226,.14);border-radius:7px;color:#eef2f8;font-size:.82rem;outline:none">
    <a class="btn btn-sm" id="btnReportJson" href="#" onclick="return downloadReport('json')">⬇ Download JSON</a>
    <a class="btn btn-dark btn-sm" id="btnReportHtml" href="#" onclick="return downloadReport('html')">⬇ Download HTML</a>
  </div>
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
  var fresh=document.getElementById('traceFresh').checked?'&fresh=1':'';
  var out=document.getElementById('epTraceResult');
  out.innerHTML='<span class="spin"></span> Running the full engine for EP'+ep+' (dry run)…';
  try{
    var r=await fetch(BP+'/admin/diagnostics.php?a=eptrace&ep='+ep+fresh);
    var d=await r.json();
    if(!d.ok){ out.innerHTML='<span class="diag-fail">'+h(d.error||'trace failed')+'</span>'; return; }

    var confColour={high:'#4ade80',medium:'#facc15',low:'#fb923c',conflict:'#f87171'};
    var statusColour=function(st){
      if(st==='ok') return '#86efac';
      if(st==='skipped'||st==='missing_episode'||st==='not_applicable'||st==='disabled') return 'rgba(255,255,255,.35)';
      if(st==='parser_warning'||st==='empty') return '#fcd34d';
      return '#fca5a5';
    };

    var html='<div style="font-size:.78rem;color:rgba(255,255,255,.4)">Episode '+d.episode+' → year '+d.year_used
      +' · '+d.total_ms+'ms total · '+(d.is_new?'not yet in the database':'already in the database')
      +' · <span style="color:#86efac">'+h(d.note)+'</span>'
      +' <span style="color:rgba(255,255,255,.3)">(would apply '+(d.applied|0)+' field'+((d.applied|0)===1?'':'s')+' if run for real)</span></div>';

    var parserColour=function(p){
      if(p==='OK') return '#86efac';
      if(p==='WARNING') return '#fcd34d';
      if(p==='not run'||p==='not reached') return 'rgba(255,255,255,.35)';
      return '#fca5a5';
    };

    html+='<div style="font-size:.7rem;font-weight:800;letter-spacing:.09em;text-transform:uppercase;color:rgba(41,171,226,.45);margin:1rem 0 .4rem">'
      +'Source → Fetch → HTTP → Parser → Fields found</div>';
    Object.keys(d.sources||{}).forEach(function(name){
      var o=d.sources[name];
      var counts=Object.keys(o.field_counts||{}).map(function(f){
        return '<span style="display:inline-block;margin-right:.9rem">'+h(f)+': <strong>'+o.field_counts[f]+'</strong></span>';
      }).join('');
      html+='<div style="background:#141c2c;border:1px solid rgba(41,171,226,.1);border-radius:8px;padding:.7rem;margin-top:.5rem">'
        +'<div style="display:flex;justify-content:space-between;align-items:baseline;gap:.6rem">'
        +'<strong style="font-size:.82rem">'+h(name)
        +' <span style="font-weight:400;font-size:.68rem;color:rgba(255,255,255,.3)">'+h(o.source_class)+' · tier '+o.tier+'</span></strong>'
        +'<span style="font-size:.7rem;color:'+statusColour(o.status)+'">'+h(o.status)
        +(o.cached?' [cache]':'')+(o.suppressed?' [cooling down]':'')+'</span></div>'
        +'<div style="font-size:.75rem;margin-top:.4rem;line-height:1.9">'
        +'Fetch: <span style="color:'+(o.fetch==='OK'?'#86efac':'#fca5a5')+'">'+h(o.fetch)+'</span>'
        +' &nbsp;·&nbsp; HTTP '+(o.http?o.http:'—')+(o.ms?' ('+o.ms+'ms)':'')
        +' &nbsp;·&nbsp; Parser: <span style="color:'+parserColour(o.parser)+';font-weight:700">'+h(o.parser)+'</span>'
        +' <span style="color:rgba(255,255,255,.25)">'+h(o.parser_version||'')+'</span>'
        +'</div>'
        +(counts?'<div style="font-size:.75rem;margin-top:.25rem;color:rgba(41,171,226,.8)">'+counts+'</div>'
                :'<div style="font-size:.75rem;margin-top:.25rem;color:rgba(255,255,255,.3)">no fields parsed</div>')
        +(o.error?'<div style="font-size:.73rem;color:#fca5a5;margin-top:.35rem">⚠ '+h(o.error)+'</div>':'')
        +(o.data&&Object.keys(o.data).length?'<details style="margin-top:.4rem"><summary style="font-size:.72rem;color:rgba(255,255,255,.35);cursor:pointer">raw parsed values</summary>'
          +'<div class="diag-pre" style="margin-top:.35rem;max-height:180px">'+h(JSON.stringify(o.data,null,2))+'</div></details>':'')
        +'</div>';
    });

    html+='<div style="font-size:.7rem;font-weight:800;letter-spacing:.09em;text-transform:uppercase;color:rgba(41,171,226,.45);margin:1.1rem 0 .4rem">Field resolution — who won, and how sure</div>';
    html+='<table class="atable"><thead><tr><th style="width:110px">Field</th><th>Offered by (normalisation input)</th>'
      +'<th>Merge result</th><th>Confidence</th><th>Validation &amp; disagreement</th></tr></thead><tbody>';
    Object.keys(d.resolution||{}).forEach(function(f){
      var x=d.resolution[f];
      var offered=Object.keys(x.offered||{}).map(function(src){
        return '<span style="color:rgba(255,255,255,.6)">'+h(src)+'</span>: '+h(String(x.offered[src]).slice(0,70));
      }).join('<br>')||'—';
      html+='<tr><td style="font-weight:700">'+h(f)+'</td>'
        +'<td style="font-size:.72rem;color:rgba(255,255,255,.45);max-width:250px">'+offered+'</td>'
        +'<td style="max-width:260px">'+h(String(x.value==null?'':x.value).slice(0,140))
        +'<br><span style="font-size:.68rem;color:rgba(255,255,255,.35)">via '+h(x.source||'—')
        +(x.count>1?' · '+x.count+' values':'')
        +(x.agreed_by&&x.agreed_by.length>1?' · agreed by '+h(x.agreed_by.join(', ')):'')+'</span></td>'
        +'<td style="color:'+(confColour[x.confidence]||'rgba(255,255,255,.35)')+';font-weight:700">'+h(String(x.confidence||'—').toUpperCase())+'</td>'
        +'<td style="font-size:.72rem;color:rgba(255,255,255,.4)">'
        +((x.conflicts||[]).map(function(c){return '<span style="color:#fca5a5">conflict</span> '+h(c.source)+': '+h(String(c.value).slice(0,45));}).join('<br>')||'')
        +((x.rejected||[]).length?'<div style="color:#fcd34d;margin-top:.2rem">rejected: '+(x.rejected||[]).map(function(rj){return h(rj.source)+' — '+h(String(rj.reason).slice(0,70));}).join('<br>')+'</div>':'')
        +(!(x.conflicts||[]).length&&!(x.rejected||[]).length?'passed validation':'')
        +'</td></tr>';
    });
    html+='</tbody></table>';

    if(d.changes&&d.changes.length){
      html+='<div style="font-size:.7rem;font-weight:800;letter-spacing:.09em;text-transform:uppercase;color:rgba(41,171,226,.45);margin:1.1rem 0 .4rem">What a real sync would change</div>'
        +'<div class="diag-pre">'+d.changes.map(h).join('\n')+'</div>';
    }
    if(d.warnings&&d.warnings.length){
      html+='<div style="margin-top:.7rem;color:#fcd34d;font-size:.76rem">'+d.warnings.map(h).join('<br>')+'</div>';
    }
    out.innerHTML=html;
  }catch(e){ out.innerHTML='<span class="diag-fail">Request failed: '+h(e.message)+'</span>'; }
}

async function runSelfTest(){
  var out=document.getElementById('selfTestResult');
  out.innerHTML='<span class="spin"></span> Running offline checks…';
  try{
    var r=await fetch(BP+'/admin/diagnostics.php?a=selftest');
    var d=await r.json();
    if(!d.ok){ out.innerHTML='<span class="diag-fail">'+h(d.error||'failed')+'</span>'; return; }
    var fails=(d.results||[]).filter(function(x){return !x.pass;});
    out.innerHTML='<div style="font-weight:700;color:'+(fails.length?'#fca5a5':'#86efac')+'">'
      +d.passed+'/'+d.total+' checks passed'+(fails.length?' — '+fails.length+' FAILING':'')+'</div>'
      +(fails.length?'<div class="diag-pre" style="margin-top:.5rem">'+fails.map(function(f){
          return '['+h(f.group)+'] '+h(f.name)+'\n    expected '+h(f.expected)+', got '+h(f.actual);
        }).join('\n')+'</div>':'');
  }catch(e){ out.innerHTML='<span class="diag-fail">Request failed: '+h(e.message)+'</span>'; }
}

async function loadSrcHealth(probe){
  var out=document.getElementById('srcHealthResult');
  out.innerHTML='<span class="spin"></span> '+(probe?'Probing every source…':'Loading recorded health…');
  try{
    var r=await fetch(BP+'/admin/diagnostics.php?a=srchealth'+(probe?'&probe=1':''));
    var d=await r.json();
    if(!d.ok){ out.innerHTML='<span class="diag-fail">'+h(d.error||'failed')+'</span>'; return; }
    var colour={ok:'#4ade80',parser_warning:'#facc15',degraded:'#facc15',rate_limited:'#fb923c',blocked:'#f87171',down:'#f87171',disabled:'#64748b',unknown:'#64748b'};
    var html='<table class="atable"><thead><tr><th>Source</th><th>Status</th><th>Success rate</th><th>Last success</th><th>Avg</th><th>Diagnosis</th></tr></thead><tbody>';
    Object.keys(d.sources).forEach(function(k){
      var s=d.sources[k], p=(d.probe||{})[k];
      html+='<tr><td style="font-weight:700">'+h(s.label||k)+'<br><span style="font-size:.68rem;color:rgba(255,255,255,.3)">tier '+s.tier+'</span></td>'
        +'<td style="color:'+(colour[s.status]||'#64748b')+';font-weight:700">'+h(String(s.status).replace(/_/g,' '))
        +(p?'<br><span style="font-size:.68rem;color:'+(p.ok?'#86efac':'#fca5a5')+'">probe: '+(p.ok?'reachable '+p.ms+'ms':h(String(p.status)))+'</span>':'')+'</td>'
        +'<td>'+(s.success_rate==null?'—':s.success_rate+'%')+'<span style="font-size:.68rem;color:rgba(255,255,255,.3)"> ('+s.success_count+'/'+(s.success_count+s.failure_count)+')</span></td>'
        +'<td style="font-size:.74rem">'+h(s.last_success_at?String(s.last_success_at).slice(0,16):'never')+'</td>'
        +'<td style="font-size:.74rem">'+(s.avg_ms?s.avg_ms+'ms':'—')+'</td>'
        +'<td style="font-size:.72rem;color:rgba(255,255,255,.45);max-width:320px">'
        +(s.parser_warnings?'<span style="color:#fcd34d">'+s.parser_warnings+' parser warning(s)</span><br>':'')
        +(s.suppressed_until?'<span style="color:#fb923c">cooling down until '+h(String(s.suppressed_until).slice(11,16))+'</span><br>':'')
        +h(s.last_error||(p&&p.error?String(p.error):'—'))+'</td></tr>';
    });
    out.innerHTML=html+'</tbody></table>';
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
function downloadReport(format){
  var run = document.getElementById('reportRunId').value.trim();
  var url = BP + '/admin/diagnostics.php?a=report&format=' + format + (run ? '&run=' + encodeURIComponent(run) : '');
  window.location.href = url;
  return false;
}
window.addEventListener('load', function(){ runNetCheck(); loadCacheList(); });
</script>
</body></html>
