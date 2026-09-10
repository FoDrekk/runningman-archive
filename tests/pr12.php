<?php
// ============================================================
// tests/pr12.php — Source Expansion & Benchmarking (Fandom + TVmaze).
//
// Covers what PR12 actually added: two new adapters, registered
// cautiously (tier 1, LAST in every field_priority list they appear
// in — see config/scraping.php) rather than assumed trustworthy on
// day one.
//
//   - FandomScraper: the MediaWiki `action=parse` transport, the
//     resolved-title identity check (redirect/mismatch → missing_episode,
//     not a wrong-episode value), and the generic portable-infobox
//     label reader — proven here against a realistic action=parse
//     JSON envelope, not just the universal adapter invariant.
//   - TvMazeScraper: the absolute-episode-number extraction rule as its
//     own pure function (including the reject-a-2000+-number sanity
//     clamp and the "no boundary inside a 7-digit run" case), plus the
//     index-lookup/status behaviour of episode() against a seeded index.
//
// Offline. No live source is ever contacted — see tests/adapter_contract.php
// for the shared "200 + nothing parsed is never ok" contract both of
// these already satisfy automatically via the registry-driven B5 sweep.
//
//   php tests/pr12.php
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

$cache = RmCache::instance();

// ============================================================
section('registry wiring — cautious rollout, not a free-for-all');

$registry = RmSourceRegistry::instance();
check('fandom is registered',  $registry->has('fandom'), true);
check('tvmaze is registered',  $registry->has('tvmaze'), true);
check('fandom starts at tier 1 (unproven track record)', rmScrapeConfig('sources.fandom.tier'), 1);
check('tvmaze starts at tier 1 (unproven track record)', rmScrapeConfig('sources.tvmaze.tier'), 1);
check('fandom is class=secondary (can fill/corroborate, never overwrite a stronger class)',
    rmScrapeConfig('sources.fandom.class'), 'secondary');
check('tvmaze is class=secondary', rmScrapeConfig('sources.tvmaze.class'), 'secondary');
check('tvmaze needs no API key to be usable', rmScrapeSourceEnabled('tvmaze'), true);

foreach (['title', 'air_date', 'guests', 'location', 'mission'] as $field) {
    $order = (array)rmScrapeConfig("field_priority.$field", []);
    if (in_array('fandom', $order, true)) {
        check("fandom is LAST in field_priority[$field], not first",
            end($order) === 'fandom' || array_search('fandom', $order, true) === count($order) - 1, true);
    }
}
foreach (['title', 'air_date'] as $field) {
    $order = (array)rmScrapeConfig("field_priority.$field", []);
    check("tvmaze is present in field_priority[$field]", in_array('tvmaze', $order, true), true);
    check("tvmaze comes after tvdb in field_priority[$field], never ahead of it",
        array_search('tvmaze', $order, true) > array_search('tvdb', $order, true), true);
}
check('tvmaze never appears in field_priority[guests] (it has no such field)',
    in_array('tvmaze', (array)rmScrapeConfig('field_priority.guests', []), true), false);

// ============================================================
section('TvMazeScraper::absoluteEpisodeNumber() — the matching rule in isolation');

check('"Episode 813" → 813',        TvMazeScraper::absoluteEpisodeNumber('Episode 813'), 813);
check('"Ep. 1" → 1',                TvMazeScraper::absoluteEpisodeNumber('Ep. 1'), 1);
check('bare "813" → 813',           TvMazeScraper::absoluteEpisodeNumber('813'), 813);
check('Korean counter "813회" → 813', TvMazeScraper::absoluteEpisodeNumber('813회'), 813);
check('no digits at all → null',    TvMazeScraper::absoluteEpisodeNumber('Race to the Top'), null);
check('out-of-range "Episode 2001" → null (sanity clamp)', TvMazeScraper::absoluteEpisodeNumber('Episode 2001'), null);
check('a contiguous 7-digit run has no internal boundary → null',
    TvMazeScraper::absoluteEpisodeNumber('1000000'), null);

// ============================================================
section('TvMazeScraper::episode() — lookup + status against a seeded index');

$cache->flush();
$cache->set('tvmaze:index', [
    813 => ['name' => 'Episode 813', 'airdate' => '2026-08-23'],
], 3600, 'api');
$tvmaze = new TvMazeScraper();

$hit = $tvmaze->episode(813);
check('indexed episode reports ok',         $hit['_status'] ?? null, 'ok');
check('indexed episode yields air_date',    $hit['air_date'] ?? null, '2026-08-23');
check('indexed episode yields a title',     !empty($hit['title']), true);
check('indexed episode carries no error',   $hit['_error'] ?? null, null);

$miss = $tvmaze->episode(814);
check('un-indexed episode → missing_episode, not a guess', $miss['_status'] ?? null, 'missing_episode');
check('un-indexed episode yields no fields',
    array_values(array_filter(array_keys($miss), fn($k) => !str_starts_with($k, '_'))), []);

// ============================================================
section('FandomScraper — action=parse envelope, portable-infobox extraction');

function seedFandom(int $ep, string $body): void {
    global $cache;
    $cache->set('http:fandom:ep:' . $ep, $body, 300, 'page');
}

$infobox = '<aside class="portable-infobox">'
    . '<div class="pi-item"><h3 class="pi-data-label">Air Date</h3><div class="pi-data-value">August 23, 2026</div></div>'
    . '<div class="pi-item"><h3 class="pi-data-label">Guest(s)</h3><div class="pi-data-value"><a href="/wiki/A">Lee Somin</a><a href="/wiki/B">Kim Junho</a></div></div>'
    . '<div class="pi-item"><h3 class="pi-data-label">Location</h3><div class="pi-data-value">Jeju Island</div></div>'
    . '<div class="pi-item"><h3 class="pi-data-label">Mission</h3><div class="pi-data-value">Find the golden bell hidden across the island</div></div>'
    . '</aside>';
$goodEnvelope = json_encode(['parse' => ['title' => 'Episode/810', 'text' => ['*' => $infobox]]]);

$cache->flush();
seedFandom(810, $goodEnvelope . str_repeat(' ', 900));
$fandom = new FandomScraper();
$good = $fandom->episode(810);
check('good page reports ok',        $good['_status'] ?? null, 'ok');
check('good page yields air_date',   $good['air_date'] ?? null, '2026-08-23');
check('good page yields guests',     $good['guests'] ?? null, ['Lee Somin', 'Kim Junho']);
check('good page yields location',   $good['location'] ?? null, 'Jeju Island');
check('good page yields mission',    !empty($good['mission']), true);
check('good page carries no error',  $good['_error'] ?? null, null);

// Redirected/mismatched resolved title — must never adopt another episode's data.
$cache->flush();
$wrongEpisodeEnvelope = json_encode(['parse' => ['title' => 'Episode/811', 'text' => ['*' => $infobox]]]);
seedFandom(810, $wrongEpisodeEnvelope . str_repeat(' ', 900));
$mismatch = (new FandomScraper())->episode(810);
check('resolved title for a different episode → missing_episode', $mismatch['_status'] ?? null, 'missing_episode');
check('mismatched episode yields no fields',
    array_values(array_filter(array_keys($mismatch), fn($k) => !str_starts_with($k, '_'))), []);

// No article for this episode.
$cache->flush();
seedFandom(810, json_encode(['error' => ['code' => 'missingtitle', 'info' => "The page you specified doesn't exist."]]) . str_repeat(' ', 900));
$noArticle = (new FandomScraper())->episode(810);
check('missingtitle → missing_episode', $noArticle['_status'] ?? null, 'missing_episode');

// Page resolves but the infobox is gone/redesigned.
$cache->flush();
seedFandom(810, json_encode(['parse' => ['title' => 'Episode/810', 'text' => ['*' => '<p>Under construction.</p>']]]) . str_repeat(' ', 900));
$noInfobox = (new FandomScraper())->episode(810);
check('page with no portable-infobox → parser_warning, not ok', $noInfobox['_status'] ?? null, 'parser_warning');
check('page with no portable-infobox yields no fields',
    array_values(array_filter(array_keys($noInfobox), fn($k) => !str_starts_with($k, '_'))), []);

// Garbage where JSON was expected.
$cache->flush();
seedFandom(810, "\x00\x01\x02\xff\xfe garbage \x00 bytes" . str_repeat(' ', 900));
$garbage = (new FandomScraper())->episode(810);
check('non-JSON body never claims ok', ($garbage['_status'] ?? '') === 'ok', false);

echo "\n" . str_repeat('─', 62) . "\n";
printf("%s — %d passed, %d failed\n", $fail === 0 ? 'PR12 VERIFIED' : 'PR12 FAILURES', $pass, $fail);
exit($fail === 0 ? 0 : 1);
