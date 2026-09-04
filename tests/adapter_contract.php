<?php
// ============================================================
// tests/adapter_contract.php — proves the scraping layer FAILS SAFELY.
//
// The rule every adapter must obey, and the reason this file exists:
//
//     HTTP 200 + nothing parsed  ==  PARSER WARNING
//     HTTP 200 + nothing parsed  !=  healthy source
//
// A source that answers 200 while its markup drifts is the most
// dangerous state in the system, because it looks like success to
// everything downstream. Every adapter is fed every way a response can
// be wrong — changed HTML, missing selectors, block pages, HTML where
// JSON was expected, invalid JSON, truncated bodies, 404/403/429/5xx,
// timeouts, redirect loops — and must classify each one correctly
// without throwing.
//
// Runs entirely offline: transport failures are exercised against a
// local fixture server, body-level failures via cache injection.
// No database and no external network required.
//
//   php tests/adapter_contract.php
// ============================================================
// Hermetic by construction: outbound requests are disabled before the
// engine is loaded, so this suite can never reach a live source. Test
// fixtures are served from loopback, which stays permitted.
putenv('RM_SCRAPE_OFFLINE=1');
$_ENV['RM_SCRAPE_OFFLINE'] = '1';

require_once __DIR__ . '/../includes/scraping/bootstrap.php';

$pass = 0; $fail = 0; $skipped = 0;
function check(string $name, $actual, $expected = true): void {
    global $pass, $fail;
    $ok = is_array($expected) ? ($actual == $expected) : ($actual === $expected);
    if ($ok) { $pass++; echo "  PASS $name\n"; }
    else { $fail++; echo "  FAIL $name\n       expected " . var_export($expected, true) . ", got " . var_export($actual, true) . "\n"; }
}
function section(string $s): void { echo "\n── " . strtoupper($s) . " ──\n"; }

$cache = RmCache::instance();
$cache->flush();

// ── Local fixture server ──────────────────────────────────────
$PORT = 8901;
$BASE = "http://127.0.0.1:$PORT";
$srv  = null;
$descriptors = [['pipe','r'], ['file','/tmp/rm_fixture_server.log','a'], ['file','/tmp/rm_fixture_server.log','a']];
$srv = @proc_open("php -S 127.0.0.1:$PORT " . escapeshellarg(__DIR__ . '/fixtures/server.php'), $descriptors, $pipes);
for ($i = 0; $i < 40; $i++) {
    $fp = @fsockopen('127.0.0.1', $PORT, $e, $s, 0.25);
    if ($fp) { fclose($fp); break; }
    usleep(250_000);
}
$serverUp = (bool)@fsockopen('127.0.0.1', $PORT, $e, $s, 0.5);
register_shutdown_function(function () use ($srv) {
    if (is_resource($srv)) { proc_terminate($srv); proc_close($srv); }
});

// ============================================================
// PART A — transport layer, over real sockets
// ============================================================
section('A. transport failure classification (live local server)');
if (!$serverUp) {
    echo "  SKIP: could not start the local fixture server on port $PORT\n";
    $skipped++;
} else {
    $http = new RmHttpClient(new RmCache(sys_get_temp_dir() . '/rm_contract_cache'));
    $u = fn(array $q) => $BASE . '/?' . http_build_query($q + ['pad' => 800]);

    $cases = [
        ['200 OK',                  ['code'=>200,'body'=>'ok'],          true,  RmHttpClient::CLASS_OK],
        ['404 → not_found',         ['code'=>404],                        false, RmHttpClient::CLASS_NOT_FOUND],
        ['410 → not_found',         ['code'=>410],                        false, RmHttpClient::CLASS_NOT_FOUND],
        ['403 → blocked',           ['code'=>403],                        false, RmHttpClient::CLASS_BLOCKED],
        ['401 → blocked',           ['code'=>401],                        false, RmHttpClient::CLASS_BLOCKED],
        ['429 → rate_limited',      ['code'=>429],                        false, RmHttpClient::CLASS_RATE_LIMITED],
        ['500 → server error',      ['code'=>500],                        false, RmHttpClient::CLASS_HTTP_5XX],
        ['503 → server error',      ['code'=>503],                        false, RmHttpClient::CLASS_HTTP_5XX],
        ['400 → client error',      ['code'=>400],                        false, RmHttpClient::CLASS_HTTP_4XX],
        ['200 empty body → empty',  ['code'=>200,'body'=>'zero','pad'=>0], false, RmHttpClient::CLASS_EMPTY],
    ];
    foreach ($cases as [$label, $q, $wantOk, $wantClass]) {
        $res = $http->get($u($q), ['timeout'=>6,'retries'=>0,'respect_robots'=>false,'min_bytes'=>1]);
        check("$label — ok flag",  $res->ok, $wantOk);
        check("$label — class",    $res->errorClass, $wantClass);
    }

    // Below min_bytes must read as "empty", not as a success with a stub body.
    $res = $http->get($u(['code'=>200,'body'=>'nav','pad'=>0]), ['timeout'=>6,'retries'=>0,'respect_robots'=>false,'min_bytes'=>100000]);
    check('body under min_bytes → empty', $res->errorClass, RmHttpClient::CLASS_EMPTY);

    // Timeout: the server stalls longer than the client will wait.
    $t0 = microtime(true);
    $res = $http->get($u(['sleep'=>4]), ['timeout'=>2,'retries'=>0,'respect_robots'=>false]);
    $elapsed = microtime(true) - $t0;
    check('slow response → timeout class', $res->errorClass, RmHttpClient::CLASS_TIMEOUT);
    check('timeout is actually enforced (<3.5s)', $elapsed < 3.5, true);

    // Redirect loop must terminate rather than spin.
    $res = $http->get($BASE . '/?redirect=1', ['timeout'=>8,'retries'=>0,'respect_robots'=>false]);
    check('redirect loop terminates', $res->ok, false);
    check('redirect loop classified',
          in_array($res->errorClass, [RmHttpClient::CLASS_REDIRECT, RmHttpClient::CLASS_OTHER, RmHttpClient::CLASS_HTTP_4XX], true), true);

    section('A2. retry policy — transient only, no storms');
    $hits = function (string $tag): int {
        $f = sys_get_temp_dir() . '/rm_test_hits_' . $tag;
        return (int)@file_get_contents($f);
    };
    $reset = function (string $tag): void { @unlink(sys_get_temp_dir() . '/rm_test_hits_' . $tag); };

    // A 404 is a fact, not a hiccup: retrying it triples load for the same answer.
    $reset('perm');
    $http->get($u(['code'=>404,'tag'=>'perm']), ['timeout'=>6,'retries'=>2,'respect_robots'=>false,'bypass_cache'=>true]);
    check('404 is requested exactly once (no retry)', $hits('perm'), 1);

    $reset('blocked');
    $http->get($u(['code'=>403,'tag'=>'blocked']), ['timeout'=>6,'retries'=>2,'respect_robots'=>false,'bypass_cache'=>true]);
    check('403 is requested exactly once (no retry)', $hits('blocked'), 1);

    // A 5xx is transient: 1 attempt + 2 retries = 3 requests, and no more.
    $reset('trans');
    $http->get($u(['code'=>500,'tag'=>'trans']), ['timeout'=>6,'retries'=>2,'respect_robots'=>false,'bypass_cache'=>true]);
    check('500 retries exactly twice (3 requests total)', $hits('trans'), 3);

    section('A3. negative cache suppresses repeat failures');
    $reset('sup');
    $key = 'contract-suppression-' . random_int(1000, 9999);
    for ($i = 0; $i < 4; $i++) {
        $r = $http->get($u(['code'=>403,'tag'=>'sup']), ['timeout'=>6,'retries'=>0,'respect_robots'=>false,'cache_key'=>$key]);
    }
    check('a blocking host is contacted once, not four times', $hits('sup'), 1);
    check('later attempts are served from the negative cache', $r->fromCache, true);
    check('and still report the original class', $r->errorClass, RmHttpClient::CLASS_BLOCKED);

    section('A4. JSON transport');
    [$data, $res] = $http->getJson($u(['body'=>'json','pad'=>0]), ['timeout'=>6,'retries'=>0,'respect_robots'=>false]);
    check('valid JSON parses', is_array($data) && isset($data['parse']), true);
    [$data, $res] = $http->getJson($u(['body'=>'html','pad'=>0]), ['timeout'=>6,'retries'=>0,'respect_robots'=>false]);
    check('HTML where JSON expected → not ok', $res->ok, false);
    check('HTML where JSON expected → empty class', $res->errorClass, RmHttpClient::CLASS_EMPTY);
    check('and returns no data rather than garbage', $data, null);
    [$data, $res] = $http->getJson($u(['body'=>'badjson','pad'=>0]), ['timeout'=>6,'retries'=>0,'respect_robots'=>false]);
    check('invalid JSON → not ok', $res->ok, false);
    check('invalid JSON → no data', $data, null);
}

// ============================================================
// PART B — every adapter against every malformed body
// ============================================================
section('B. adapter contract: 200 + nothing parsed is never "ok"');

/** Cache keys each adapter reads for one episode. */
function seedFor(string $source, int $ep, string $body, RmCache $cache): void {
    $year = rmYear($ep);
    $keys = match ($source) {
        'myrunningman' => ["http:mrm:ep:$ep"],
        'myrm'         => ["http:myrm:ep:$ep"],
        'mydramalist'  => ["http:mdl:ep:$ep"],
        'wikipedia'    => ['http:wiki:year:' . $year . ':' . md5("List of Running Man episodes ($year)")],
        'kowiki'       => ['http:kowiki:page:' . $year . ':' . md5("런닝맨의 에피소드 목록 ($year)")],
        default        => [],
    };
    foreach ($keys as $k) $cache->set($k, $body, 300, 'page');
}

$bodies = [
    'changed HTML (nav only)'      => '<html><body><nav>Home | Episodes | Guests | Sign in</nav></body></html>',
    'missing selectors'            => '<html><body><div class="totally-new-layout"><span>810</span></div></body></html>',
    'unexpected page'              => '<html><body><h1>Site under maintenance</h1></body></html>',
    'empty-ish page'               => '<html><body></body></html>',
    'cloudflare interstitial'      => '<html><head><title>Just a moment...</title></head><body><div class="cf-browser-verification">Checking your browser</div></body></html>',
    'truncated HTML'               => '<html><head><meta property="og:title" content="Episode #810 - Trunc',
    'JSON where HTML expected'     => '{"error":{"code":"missingtitle"}}',
    'binary noise'                 => "\x00\x01\x02\xff\xfe garbage \x00 bytes",
];

$htmlAdapters = ['myrunningman' => new MyRunningManScraper(), 'myrm' => new MyRMtvScraper(), 'mydramalist' => new MyDramaListScraper()];
$testEp = 810;

foreach ($htmlAdapters as $name => $adapter) {
    foreach ($bodies as $label => $raw) {
        $cache->flush();
        // Pad so the body clears min_bytes: the point is that the PARSER
        // rejects the content, not that the transport rejected its size.
        seedFor($name, $testEp, $raw . str_repeat(' ', 900), $cache);
        try {
            $r = $adapter->episode($testEp);
            $thrown = null;
        } catch (Throwable $e) {
            $r = null; $thrown = get_class($e) . ': ' . $e->getMessage();
        }
        check("$name / $label — no exception escapes", $thrown, null);
        if ($r === null) continue;

        $fields = array_values(array_filter(array_keys($r), fn($k) => !str_starts_with($k, '_')));
        $status = $r['_status'] ?? 'missing';
        check("$name / $label — not reported as ok", $status === 'ok', false);
        check("$name / $label — yields no fields", $fields, []);
        check("$name / $label — carries a reason", !empty($r['_error']), true);
    }
}

section('B2. JSON-backed adapters reject non-JSON and bad JSON');
$jsonCases = [
    'HTML instead of JSON' => '<html><body><h1>Error page</h1></body></html>',
    'invalid JSON'         => '{"parse": {"text": {"*": "unterminated',
    'valid JSON, no data'  => '{"error":{"code":"missingtitle","info":"The page you specified doesn\'t exist."}}',
    'valid JSON, stub page'=> '{"parse":{"text":{"*":"<p>redirect</p>"}}}',
];
foreach (['wikipedia' => new WikipediaScraper(), 'kowiki' => new KoWikipediaScraper()] as $name => $adapter) {
    foreach ($jsonCases as $label => $raw) {
        $cache->flush();
        seedFor($name, $testEp, $raw . str_repeat(' ', 2500), $cache);
        try { $r = $adapter->episode($testEp); $thrown = null; }
        catch (Throwable $e) { $r = null; $thrown = get_class($e) . ': ' . $e->getMessage(); }
        check("$name / $label — no exception escapes", $thrown, null);
        if ($r === null) continue;
        $fields = array_values(array_filter(array_keys($r), fn($k) => !str_starts_with($k, '_')));
        check("$name / $label — not reported as ok", ($r['_status'] ?? '') === 'ok', false);
        check("$name / $label — yields no fields", $fields, []);
    }
}

section('B3. a GOOD page still parses (the control case)');
$cache->flush();
seedFor('myrunningman', $testEp,
    '<html><head><meta property="og:image" content="https://www.myrunningman.com/thumbs/ep810.jpg">'
  . '<meta property="og:description" content="The members travel to Jeju Island for a two-day race against the production team.">'
  . '</head><body><div>Location: Jeju, South Korea</div><div>Broadcast Date: 2026-08-23</div>'
  . '<a href="/tags/travel">Travel</a></body></html>' . str_repeat(' ', 600), $cache);
$good = (new MyRunningManScraper())->episode($testEp);
check('good page reports ok',        $good['_status'] ?? null, 'ok');
check('good page yields synopsis',   !empty($good['synopsis']), true);
check('good page yields location',   !empty($good['location']), true);
check('good page yields image',      !empty($good['image_url']), true);
check('good page yields air date',   $good['air_date'] ?? null, '2026-08-23');
check('good page carries no error',  $good['_error'] ?? null, null);

section('B4. keyed and non-episode sources declare themselves honestly');
$tmdb = (new TmdbScraper())->episode($testEp);
check('TMDB without a key reports disabled', $tmdb['_status'] ?? null, 'disabled');
check('TMDB without a key yields no fields',
      array_values(array_filter(array_keys($tmdb), fn($k) => !str_starts_with($k, '_'))), []);
check('TMDB explains why',                    str_contains((string)($tmdb['_error'] ?? ''), 'API key'), true);

$wd = (new WikidataScraper())->episode($testEp);
check('Wikidata declares itself not-applicable for episodes', $wd['_status'] ?? null, 'not_applicable');
check('Wikidata contributes no episode fields', (new WikidataScraper())->fields(), []);

section('B4b. adapters must not raise PHP diagnostics');
// The bug this catches: SbsScraper carried a pattern with two bounded
// negative-lookahead repetitions that PCRE could not compile. Every call
// returned false — so the extraction silently never ran — AND raised
//     preg_match(): Compilation failed: regular expression is too large
// which printed into whatever admin AJAX response was in flight and made
// the control centre report `Unexpected token '<'`. A warning from a
// scraper is never cosmetic: it is either broken logic or a corrupted
// response, and usually both.
$raised = [];
set_error_handler(function (int $no, string $msg, string $file = '', int $line = 0) use (&$raised): bool {
    $raised[] = sprintf('%s in %s:%d', $msg, basename($file), $line);
    return true;
});

// Pages that genuinely exercise the extraction paths, not just the
// early-outs: each carries the episode so the parsers run to completion.
$realistic = [
    'myrunningman' => '<html><head><meta property="og:image" content="https://x.test/ep813.jpg">'
        . '<meta property="og:title" content="Running Man Episode #813 - Jeju Race">'
        . '<meta property="og:description" content="The members travel to Jeju Island for a two-day race against the production team.">'
        . '</head><body><div>Location: Jeju, South Korea</div><div>Broadcast Date: 2026-08-23</div>'
        . '<a href="/tags/travel">Travel</a></body></html>',
    'myrm' => '<html><head><meta property="og:title" content="Episode #813 - Jeju Race">'
        . '<meta property="og:description" content="The members race across Jeju Island for two days.">'
        . '</head><body><div>Broadcast Date: 2026-08-23</div><div>Guests: Lee Kwang-soo, Jeon So-min</div></body></html>',
    'mydramalist' => '<html><head><meta property="og:description" content="A specific episode description written by an editor for this episode.">'
        . '</head><body>Landmark: Jeju Island<br>Guests: Lee Kwang-soo</body></html>',
];
foreach ($realistic as $src => $page) {
    $cache->flush();
    seedFor($src, 813, $page . str_repeat(' ', 900), $cache);
    $before = count($raised);
    RmSourceRegistry::instance()->get($src)->episode(813, ['latest' => 813]);
    check("$src raises no PHP diagnostics on a realistic page", array_slice($raised, $before), []);
}

// SBS: both a server-rendered listing and a client-rendered shell.
$sbsPages = [
    'server-rendered listing' => '<html><body><ul>'
        . '<li class="item"><strong class="tit">제주도 레이스</strong><span>2026.08.23</span><em>813회</em></li>'
        . '</ul></body></html>',
    'client-rendered shell'   => '<html><head><title>런닝맨</title></head><body><div id="root"></div>'
        . '<script>window.__NEXT_DATA__={"props":{}}</script></body></html>',
    'JSON-LD only'            => '<html><head><script type="application/ld+json">'
        . '{"@type":"TVEpisode","name":"런닝맨 813회 제주도","datePublished":"2026-08-23"}</script></head><body></body></html>',
];
foreach ($sbsPages as $label => $page) {
    $cache->flush();
    $padded = $page . str_repeat(' ', 1200);
    foreach ([md5('https://programs.sbs.co.kr/enter/runningman/visualboard/54666'),
              md5('https://programs.sbs.co.kr/enter/runningman')] as $h) {
        $cache->set("http:sbs:page:$h", $padded, 300, 'page');
    }
    $before = count($raised);
    $out = (new SbsScraper())->episode(813, ['latest' => 813]);
    check("sbs raises no PHP diagnostics — $label", array_slice($raised, $before), []);
    if ($label === 'server-rendered listing') {
        check('sbs extracts from a server-rendered listing', $out['_status'] ?? null, 'ok');
        check('  → and finds the Korean title', !empty($out['title_ko']), true);
        check('  → and the air date', $out['air_date'] ?? null, '2026-08-23');
    }
    if ($label === 'client-rendered shell') {
        check('sbs names client rendering as the obstacle', $out['_status'] ?? null, 'needs_javascript');
        check('  → and says the content is not in the served HTML',
              str_contains(strtolower((string)($out['_error'] ?? '')), 'client-rendered'), true);
    }
}
restore_error_handler();
check('no PHP diagnostic was raised by any adapter', $raised, []);

section('B5. universal invariant across every registered adapter');
// Whatever a source does, it may never claim "ok" while supplying nothing.
foreach (RmSourceRegistry::instance()->all() as $name => $adapter) {
    $cache->flush();
    if (in_array($name, array_keys($htmlAdapters), true) || in_array($name, ['wikipedia','kowiki'], true)) {
        seedFor($name, 999123, '<html><body>nothing useful here at all</body></html>' . str_repeat(' ', 3000), $cache);
    }
    try { $r = $adapter->episode(999123); $thrown = null; }
    catch (Throwable $e) { $r = null; $thrown = get_class($e); }
    check("$name — never throws", $thrown, null);
    if ($r === null) continue;
    $fields = array_values(array_filter(array_keys($r), fn($k) => !str_starts_with($k, '_')));
    $claimsOk = ($r['_status'] ?? '') === 'ok';
    check("$name — never claims ok with zero fields", $claimsOk && !$fields, false);
    check("$name — always reports a status", !empty($r['_status']), true);
}

echo "\n" . str_repeat('─', 62) . "\n";
printf("%s — %d passed, %d failed%s\n",
    $fail === 0 ? 'ADAPTER CONTRACT HELD' : 'ADAPTER CONTRACT VIOLATIONS',
    $pass, $fail, $skipped ? " ($skipped section(s) skipped)" : '');
exit($fail === 0 ? 0 : 1);
