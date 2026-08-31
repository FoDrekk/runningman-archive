<?php
// ============================================================
// tests/resolution.php — conflict handling, source authority, guest
// identity and thumbnail acquisition.
//
// These are the decisions that determine whether the archive's data
// can be trusted: which source wins a contested field, what happens
// when trusted sources disagree, whether three spellings of one name
// become three people, and whether a broken image can displace a good
// one. Each is asserted directly rather than inferred from a run.
//
// Offline. The thumbnail section uses a local fixture server; the
// duplicate-image check needs a database and is skipped without one.
//
//   php tests/resolution.php
// ============================================================
// Hermetic by construction: outbound requests are disabled before the
// engine is loaded, so this suite can never reach a live source. Test
// fixtures are served from loopback, which stays permitted.
putenv('RM_SCRAPE_OFFLINE=1');
$_ENV['RM_SCRAPE_OFFLINE'] = '1';

require_once __DIR__ . '/../includes/scraping/bootstrap.php';

$pass = 0; $fail = 0; $skipped = [];
function check(string $name, $actual, $expected = true): void {
    global $pass, $fail;
    $ok = is_array($expected) ? ($actual == $expected) : ($actual === $expected);
    if ($ok) { $pass++; echo "  PASS $name\n"; }
    else { $fail++; echo "  FAIL $name\n       expected " . var_export($expected, true) . ", got " . var_export($actual, true) . "\n"; }
}
function section(string $s): void { echo "\n── " . strtoupper($s) . " ──\n"; }

$resolver = new RmFieldResolver();
$differ   = new RmDiffEngine();
$ctx      = ['episode_number' => 810, 'expected_year' => 2026];

// ============================================================
section('source classification');
$expectedClasses = [
    'sbs' => 'primary',
    'wikipedia' => 'secondary', 'kowiki' => 'secondary',
    'myrunningman' => 'secondary', 'myrm' => 'secondary',
    'mydramalist' => 'metadata', 'tmdb' => 'metadata',
    'wikidata' => 'identity',
];
foreach ($expectedClasses as $src => $cls) {
    check("$src is classified $cls", rmScrapeSourceClass($src), $cls);
}
check('primary outranks secondary', rmScrapeSourceRank('sbs') > rmScrapeSourceRank('wikipedia'), true);
check('secondary outranks metadata', rmScrapeSourceRank('wikipedia') > rmScrapeSourceRank('mydramalist'), true);
check('identity carries no episode authority', rmScrapeSourceRank('wikidata'), 0);
check('every registered source has a class',
      array_values(array_filter(array_keys((array)rmScrapeConfig('sources', [])),
          fn($s) => !in_array(rmScrapeSourceClass($s), ['primary','secondary','metadata','identity'], true))), []);

// ============================================================
section('conflict detection — every contested field');

$conflicts = [
    'air_date' => [
        ['sbs' => ['air_date' => '2026-08-23'], 'wikipedia' => ['air_date' => '2026-08-30']],
        'sbs', '2026-08-23',
    ],
    'title' => [
        ['sbs' => ['title' => 'Episode #810 - Jeju Island Race'],
         'wikipedia' => ['title' => 'Episode #810 - Busan Coastal Run']],
        'sbs', 'Episode #810 - Jeju Island Race',
    ],
    'location' => [
        ['myrunningman' => ['location' => 'Jeju'], 'wikipedia' => ['location' => 'Busan']],
        'myrunningman', 'Jeju',
    ],
    'synopsis' => [
        ['myrunningman' => ['synopsis' => 'The members race across Jeju Island for two days against the production team.'],
         'wikipedia'    => ['synopsis' => 'The cast competes in a series of indoor games at a Seoul studio complex.']],
        'myrunningman', null,
    ],
    'mission' => [
        ['wikipedia' => ['mission' => 'Name Tag Elimination'], 'myrunningman' => ['mission' => 'Hidden Identity Race']],
        'wikipedia', 'Name Tag Elimination',
    ],
];

foreach ($conflicts as $field => [$payloads, $wantSource, $wantValue]) {
    $r = $resolver->resolve($payloads, $ctx)[$field] ?? null;
    check("$field — resolved at all", $r !== null && $r['value'] !== null, true);
    if ($r === null) continue;
    check("$field — confidence is CONFLICT", $r['confidence'], RmFieldResolver::CONFLICT);
    check("$field — priority winner is $wantSource", $r['source'], $wantSource);
    if ($wantValue !== null) check("$field — winning value kept", $r['value'], $wantValue);
    check("$field — the loser is recorded, not discarded", count($r['conflicts']) >= 1, true);
    check("$field — the losing source is named", $r['conflicts'][0]['source'] ?? null,
          array_values(array_diff(array_keys($payloads), [$wantSource]))[0]);
}

section('thumbnail conflict');
$r = $resolver->resolve([
    'myrunningman' => ['image_url' => 'https://www.myrunningman.com/thumbs/ep810.jpg'],
    'tmdb'         => ['image_url' => 'https://image.tmdb.org/t/p/w1280/other.jpg'],
], $ctx)['image_url'];
check('thumbnail — myrunningman wins by field priority', $r['source'], 'myrunningman');
check('thumbnail — the alternative is still recorded', count($r['conflicts']), 1);

section('agreement is not conflict');
$r = $resolver->resolve([
    'sbs' => ['air_date' => '2026-08-23'], 'wikipedia' => ['air_date' => '2026-08-23'],
    'kowiki' => ['air_date' => '2026-08-23'],
], $ctx)['air_date'];
check('three agreeing sources → HIGH', $r['confidence'], RmFieldResolver::HIGH);
check('no conflict recorded', $r['conflicts'], []);
check('all three credited', count($r['sources']), 3);

section('a weak source contradicting a strong one is noise, not a conflict');
$r = $resolver->resolve([
    'wikipedia'   => ['synopsis' => 'The members race across Jeju Island for two days against the production team.'],
    'mydramalist' => ['synopsis' => 'A completely different description of some other episode entirely here.'],
], $ctx)['synopsis'];
check('metadata vs secondary is not escalated to CONFLICT', $r['confidence'] === RmFieldResolver::CONFLICT, false);
check('but the disagreement is still recorded', count($r['conflicts']) >= 1, true);

// ============================================================
section('existing data is protected from conflicts');

$existing = [
    'title'    => 'Episode #810 - Jeju Island Race',
    'air_date' => '2026-08-23',
    'synopsis' => 'The members travel to Jeju Island for a two-day race against the production team, with a penalty for the losers.',
    'location' => 'Jeju',
    'guests'   => ['Yoo Jae-suk', 'Kim Jong-kook'],
];

// A CONFLICT on the air date must not be written silently.
$d = $differ->diff($existing, ['air_date' => [
    'value' => '2026-08-30', 'source' => 'wikipedia', 'sources' => ['wikipedia'],
    'confidence' => RmFieldResolver::CONFLICT, 'conflicts' => [['source'=>'sbs','tier'=>3,'value'=>'2026-08-23']],
]]);
check('conflicted air date is not applied', isset($d['apply']['air_date']), false);
$rejected = array_values(array_filter($d['changes'], fn($c) => $c['type'] === RmDiffEngine::REJECTED));
check('and the refusal is logged with a reason', !empty($rejected[0]['reason']), true);

// A METADATA source must not overwrite a PRIMARY source's recorded value.
$d = $differ->diff($existing,
    ['air_date' => ['value' => '2026-09-06', 'source' => 'mydramalist', 'sources' => ['mydramalist'],
                    'confidence' => RmFieldResolver::MEDIUM, 'conflicts' => []]],
    ['existing_sources' => ['air_date' => 'sbs']]);
check('metadata cannot overwrite a primary-sourced air date', isset($d['apply']['air_date']), false);
$why = array_values(array_filter($d['changes'], fn($c) => $c['field'] === 'air_date'))[0]['reason'] ?? '';
check('and the reason names both classes', str_contains($why, 'metadata') && str_contains($why, 'primary'), true);

// The same source correcting itself IS allowed.
$d = $differ->diff($existing,
    ['synopsis' => ['value' => 'The members travel to Jeju Island for a two-day race against the production team, now with an extended penalty round described in full.',
                    'source' => 'myrunningman', 'sources' => ['myrunningman'], 'confidence' => RmFieldResolver::MEDIUM, 'conflicts' => []]],
    ['existing_sources' => ['synopsis' => 'myrunningman']]);
check('a source may correct its own earlier value', isset($d['apply']['synopsis']), true);

// A stronger class may overwrite a weaker one.
$d = $differ->diff($existing,
    ['title' => ['value' => 'Episode #810 - Jeju Island Grand Race', 'source' => 'sbs', 'sources' => ['sbs'],
                 'confidence' => RmFieldResolver::MEDIUM, 'conflicts' => []]],
    ['existing_sources' => ['title' => 'mydramalist']]);
check('primary may overwrite a metadata-sourced title', isset($d['apply']['title']), true);

// Gaps may still be filled by anyone.
$d = $differ->diff(['title' => 'Episode #810 - Jeju Island Race'],
    ['synopsis' => ['value' => 'A brand new synopsis for a field that was previously empty entirely.',
                    'source' => 'mydramalist', 'sources' => ['mydramalist'], 'confidence' => RmFieldResolver::LOW, 'conflicts' => []]],
    ['existing_sources' => ['title' => 'sbs']]);
check('metadata may still FILL an empty field', isset($d['apply']['synopsis']), true);

// ============================================================
section('guest identity — variations must not become separate people');
$k = fn($s) => RmNormalizer::guestKey($s);
$variants = ['Lee Kwang-soo', 'Lee Kwang Soo', 'Lee Kwangsoo', 'lee kwang-soo', 'LEE KWANG SOO', '  Lee  Kwang-Soo  '];
$keys = array_unique(array_map($k, $variants));
check('all six spellings collapse to one identity', count($keys), 1);

$merged = RmNormalizer::mergeGuests([
    'wikipedia'    => ['Lee Kwang-soo', 'Song Ji-hyo', 'Yoo Jae-suk'],
    'mydramalist'  => ['Lee Kwang Soo', 'Song Ji Hyo', 'Ji Suk-jin'],
    'myrunningman' => ['Lee Kwangsoo', 'Yoo Jae Suk'],
]);
check('union across three sources yields four people', count($merged['names']), 4);
check('canonical hyphenated spelling is kept', in_array('Lee Kwang-soo', $merged['names'], true), true);
check('no duplicate of the same person', count($merged['names']) === count(array_unique(array_map($k, $merged['names']))), true);

section('guest identity — genuinely different people stay separate');
$distinct = [
    ['Kim Jong-kook', 'Kim Jong-min'],
    ['Lee Kwang-soo', 'Lee Kwang-hee'],
    ['Song Ji-hyo',   'Song Joong-ki'],
    ['Yoo Jae-suk',   'Yoo Ah-in'],
    ['Ji Suk-jin',    'Ji Chang-wook'],
];
foreach ($distinct as [$a, $b]) {
    check("\"$a\" and \"$b\" are not merged", $k($a) === $k($b), false);
    $m = RmNormalizer::mergeGuests(['a' => [$a], 'b' => [$b]]);
    check("  → both survive the merge", count($m['names']), 2);
}

section('guest identity — near misses are flagged, never merged');
$near = RmNormalizer::mergeGuests(['a' => ['Kim Jong-kook'], 'b' => ['Kim Jong-kuk']]);
check('two spellings one edit-pair apart stay separate', count($near['names']), 2);
check('and are raised for review', count($near['review']), 1);
check('the review names both spellings',
      isset($near['review'][0]['a'], $near['review'][0]['b']), true);

$far = RmNormalizer::mergeGuests(['a' => ['Kim Jong-kook'], 'b' => ['Kim Jong-min']]);
check('clearly different names are not even flagged', count($far['review']), 0);

section('guest validation rejects non-people');
foreach (['Guests', 'N/A', 'TBD', '', '   ', '<div></div>', '2026', '---', 'https://example.test/x'] as $junk) {
    check('rejects ' . var_export($junk, true), RmNormalizer::guestName($junk), null);
}
check('a cast-list-sized guest array is refused',
      RmValidator::guests(array_map(fn($i) => "Person Number $i", range(1, 40)))['valid'], false);

// ============================================================
section('thumbnails — validation of the bytes, not the status code');
$PORT = 8902;
$srv = @proc_open("php -S 127.0.0.1:$PORT " . escapeshellarg(__DIR__ . '/fixtures/server.php'),
                  [['pipe','r'], ['file','/tmp/rm_fixture_server.log','a'], ['file','/tmp/rm_fixture_server.log','a']], $pipes);
for ($i = 0; $i < 40; $i++) { $fp = @fsockopen('127.0.0.1', $PORT, $e, $s, 0.25); if ($fp) { fclose($fp); break; } usleep(250_000); }
$up = (bool)@fsockopen('127.0.0.1', $PORT, $e, $s, 0.5);
register_shutdown_function(function () use ($srv) { if (is_resource($srv)) { proc_terminate($srv); proc_close($srv); } });

if (!$up) {
    echo "  SKIP: fixture server would not start on port $PORT\n";
    $skipped[] = 'thumbnails';
} else {
    $B  = "http://127.0.0.1:$PORT";
    $te = new RmThumbnailEngine(getDBSafe());
    $ep = 999810; $year = 2026;
    $thumbFile = __DIR__ . "/../thumbnails/$year/ep" . str_pad((string)$ep, 3, '0', STR_PAD_LEFT) . '.jpg';
    @unlink($thumbFile);

    // Each candidate below is a way a "thumbnail" can be wrong.
    $bad = [
        '404'                    => "$B/?code=404&body=real_image",
        'HTML pretending to be an image' => "$B/?body=html_image",
        'zero-byte response'     => "$B/?body=zero&pad=0",
        'not an image at all'    => "$B/?body=nav&type=image%2Fjpeg&pad=0",
        '1x1 tracking pixel'     => "$B/?body=tiny_image",
        'server error'           => "$B/?code=500&body=real_image",
        'placeholder asset name' => "$B/no-image_default.png",
    ];
    foreach ($bad as $label => $url) {
        $res = $te->acquire($ep, $year, [['url' => $url, 'source' => 'fixture']], ['force' => true]);
        check("rejects $label", $res['ok'], false);
        check("  → and explains why", !empty($res['reason']), true);
        check("  → and writes no file", is_file($thumbFile), false);
    }

    $res = $te->acquire($ep, $year, [['url' => "$B/?body=real_image", 'source' => 'fixture']], ['force' => true]);
    check('accepts a genuine image', $res['ok'], true);
    check('  → records real dimensions', ($res['width'] ?? 0) >= 200 && ($res['height'] ?? 0) >= 112, true);
    check('  → writes the file to disk', is_file($thumbFile), true);
    $goodHash = $res['hash'];
    $goodSize = is_file($thumbFile) ? filesize($thumbFile) : 0;

    section('thumbnails — fallback across sources');
    $res = $te->acquire($ep, $year, [
        ['url' => "$B/?code=404",          'source' => 'myrunningman'],
        ['url' => "$B/?body=html_image",   'source' => 'sbs'],
        ['url' => "$B/?body=real_image",   'source' => 'tmdb'],
    ], ['force' => true]);
    check('falls through two broken sources to a working one', $res['ok'], true);
    check('  → credits the source that actually worked', $res['source'], 'tmdb');
    check('  → and reports what it tried', count($res['attempts']), 3);

    section('thumbnails — a bad image never displaces a good one');
    $before = @file_get_contents($thumbFile);
    $res = $te->acquire($ep, $year, [['url' => "$B/?body=html_image", 'source' => 'fixture']]);
    $after = @file_get_contents($thumbFile);
    check('the stored image is unchanged after a failed fetch', $after === $before, true);
    check('the stored file still has real size', filesize($thumbFile) > 1000, true);
    check('and the failure is reported, not swallowed', $res['ok'], false);

    section('thumbnails — identical bytes are not re-downloaded');
    $res = $te->acquire($ep, $year, [['url' => "$B/?body=real_image", 'source' => 'fixture']]);
    check('an identical image is skipped', $res['skipped'] ?? false, true);
    check('  → and says so', str_contains(strtolower((string)$res['reason']), 'identical')
                          || str_contains(strtolower((string)$res['reason']), 'unchanged'), true);

    if (getDBSafe() !== null && rmScrapingTablesExist()) {
        section('thumbnails — duplicate detection across episodes');
        $ep2 = 999811;
        $f2 = __DIR__ . "/../thumbnails/$year/ep" . str_pad((string)$ep2, 3, '0', STR_PAD_LEFT) . '.jpg';
        @unlink($f2);
        $res = $te->acquire($ep2, $year, [['url' => "$B/?body=real_image", 'source' => 'fixture']], ['force' => true]);
        check('a byte-identical image on another episode is still saved', $res['ok'], true);
        check('  → but flagged rather than silently accepted',
              str_contains(strtolower((string)($res['reason'] ?? '')), 'identical'), true);
        $flag = (int)getDBSafe()->query(
            "SELECT COUNT(*) FROM review_flags WHERE flag_type='duplicate_thumbnail' AND status='open'")->fetchColumn();
        check('  → and raised for review', $flag > 0, true);
        @unlink($f2);
        getDBSafe()->exec("DELETE FROM thumbnail_meta WHERE episode_number IN ($ep, $ep2)");
        getDBSafe()->exec("DELETE FROM review_flags WHERE flag_type='duplicate_thumbnail'");
    } else {
        $skipped[] = 'thumbnail duplicate detection (no database)';
    }
    @unlink($thumbFile);
    @rmdir(dirname($thumbFile));
}

echo "\n" . str_repeat('─', 62) . "\n";
foreach ($skipped as $s) echo "SKIPPED: $s\n";
printf("%s — %d passed, %d failed\n", $fail === 0 ? 'RESOLUTION VERIFIED' : 'RESOLUTION PROBLEMS', $pass, $fail);
exit($fail === 0 ? 0 : 1);
