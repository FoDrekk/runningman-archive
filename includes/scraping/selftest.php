<?php
// ============================================================
// rmScrapingSelfTest() — offline checks for the pure-logic parts of
// the engine: normalisation, validation, field resolution, diffing.
//
// No network, no database. It exists because these are exactly the
// rules that must not silently regress: if the guest normaliser stops
// collapsing "Lee Kwang Soo" and "Lee Kwang-soo", the archive starts
// growing duplicate people, and nothing else in the system will
// notice. Run it from Admin → Diagnostics, or on the CLI:
//
//   php includes/scraping/selftest.php
// ============================================================
require_once __DIR__ . '/bootstrap.php';

function rmScrapingSelfTest(): array
{
    $results = [];
    $check = function (string $group, string $name, $actual, $expected) use (&$results) {
        $pass = is_array($expected) ? ($actual == $expected) : ($actual === $expected);
        $results[] = [
            'group' => $group, 'name' => $name, 'pass' => $pass,
            'expected' => is_array($expected) ? json_encode($expected, JSON_UNESCAPED_UNICODE) : var_export($expected, true),
            'actual'   => is_array($actual)   ? json_encode($actual, JSON_UNESCAPED_UNICODE)   : var_export($actual, true),
        ];
    };

    // ── Guest identity ────────────────────────────────────────
    $k = fn($s) => RmNormalizer::guestKey($s);
    $check('guests', 'spaced and hyphenated spellings collapse',      $k('Lee Kwang Soo') === $k('Lee Kwang-soo'), true);
    $check('guests', 'unspaced spelling collapses too',               $k('Lee Kwangsoo') === $k('Lee Kwang-soo'), true);
    $check('guests', 'different people stay different',               $k('Kim Jong-kook') === $k('Kim Jong-min'), false);
    $check('guests', 'trailing role annotation stripped',             RmNormalizer::guestName('Jeon So-min (actress)'), 'Jeon So-min');
    $check('guests', 'the literal word "Guests" is rejected',         RmNormalizer::guestName('Guests'), null);
    $check('guests', 'empty markup is rejected',                      RmNormalizer::guestName('<div></div>'), null);
    $check('guests', 'list numbering stripped',                       RmNormalizer::guestName('1. Yoo Jae-suk'), 'Yoo Jae-suk');

    $merged = RmNormalizer::mergeGuests([
        'wikipedia' => ['Yoo Jae-suk', 'Kim Jong-kook', 'HaHa'],
        'mdl'       => ['Yoo Jae Suk', 'Kim Jongkook', 'HaHa', 'Song Ji-hyo'],
    ]);
    $check('guests', 'union merge deduplicates by identity', count($merged['names']), 4);
    $check('guests', 'best-formatted spelling wins',         in_array('Yoo Jae-suk', $merged['names'], true), true);

    $near = RmNormalizer::mergeGuests(['a' => ['Kim Jong-kook'], 'b' => ['Kim Jong-kuk']]);
    $check('guests', 'one-character difference is flagged, not merged', count($near['names']) === 2 && count($near['review']) === 1, true);

    // ── Locations ─────────────────────────────────────────────
    $lk = fn($s) => RmNormalizer::locationKey($s);
    $check('location', '"Seoul" ≡ "Seoul, South Korea"', $lk('Seoul') === $lk('Seoul, South Korea'), true);
    $check('location', '"Seoul City" normalises to Seoul', $lk('Seoul City') === $lk('Seoul'), true);
    $loc = RmNormalizer::location('Seoul, South Korea');
    $check('location', 'country extracted',    $loc['country'], 'South Korea');
    $check('location', 'overseas flag correct', $loc['is_overseas'], 0);
    $osea = RmNormalizer::location('Sydney, Australia');
    $check('location', 'overseas detected',    $osea['is_overseas'], 1);
    $check('location', '"Unknown" rejected',   RmNormalizer::location('Unknown'), null);

    // ── Dates ─────────────────────────────────────────────────
    foreach ([
        '2026-08-23' => '2026-08-23',
        '2026.08.23' => '2026-08-23',
        '23 August 2026' => '2026-08-23',
        'August 23, 2026' => '2026-08-23',
        '2026년 8월 23일' => '2026-08-23',
        '2026-08-23 (filmed on 2026-08-01)' => '2026-08-23',
    ] as $in => $want) {
        $check('dates', "parses \"$in\"", RmNormalizer::date($in), $want);
    }
    $check('dates', 'rejects prose', RmNormalizer::date('Lorem ipsum'), null);

    // ── Validation: the garbage that must never reach the DB ──
    $check('validate', 'episode number "Home" rejected',   RmValidator::episodeNumber('Home')['valid'], false);
    $check('validate', 'episode number 810 accepted',      RmValidator::episodeNumber('810')['value'], 810);
    $check('validate', 'episode number 99999 rejected',    RmValidator::episodeNumber(99999)['valid'], false);
    $check('validate', 'air date "Lorem ipsum" rejected',  RmValidator::airDate('Lorem ipsum')['valid'], false);
    $check('validate', 'air date before the show rejected',RmValidator::airDate('2005-01-01')['valid'], false);
    $check('validate', 'far-future air date rejected',     RmValidator::airDate('2099-01-01')['valid'], false);
    $check('validate', 'nav-text synopsis rejected',       RmValidator::synopsis('Home | Episodes | Guests | Sign in | Privacy Policy | Terms of Service')['valid'], false);
    $check('validate', 'MDL generic blurb rejected',       RmValidator::synopsis('In each episode, the members must compete in a series of games and missions to win the race.')['valid'], false);
    $check('validate', 'real synopsis accepted',           RmValidator::synopsis('The members travel to Jeju Island for a two-day race against the production team.')['valid'], true);
    $check('validate', 'paginated index title rejected',   RmValidator::title('Episodes - Page 3', 810)['valid'], false);
    $check('validate', 'title with descriptor accepted',   RmValidator::title('Episode #810 - Jeju Trip', 810)['value'], 'Episode #810 - Jeju Trip');
    $check('validate', 'HTML disguised as an image rejected', RmValidator::imageBytes('<!DOCTYPE html><html><body>404</body></html>' . str_repeat('x', 3000), 'image/jpeg')['valid'], false);
    $check('validate', 'placeholder image URL rejected',   RmValidator::imageUrl('https://x.test/img/no-image_default.png')['valid'], false);

    // ── Field resolution + confidence ─────────────────────────
    $resolver = new RmFieldResolver();
    $r = $resolver->resolve([
        'wikipedia' => ['air_date' => '2026-08-23', '_url' => 'https://en.wikipedia.org/x'],
        'sbs'       => ['air_date' => '2026-08-23', '_url' => 'https://sbs.test/x'],
    ], ['episode_number' => 810]);
    $check('resolve', 'two trusted sources agreeing → HIGH', $r['air_date']['confidence'], RmFieldResolver::HIGH);
    $check('resolve', 'official SBS wins the air date',      $r['air_date']['source'], 'sbs');

    $r2 = $resolver->resolve([
        'wikipedia' => ['air_date' => '2026-08-23'],
        'sbs'       => ['air_date' => '2026-08-30'],
    ], ['episode_number' => 810]);
    $check('resolve', 'trusted sources disagreeing → CONFLICT', $r2['air_date']['confidence'], RmFieldResolver::CONFLICT);
    $check('resolve', 'conflict still records the loser',        count($r2['air_date']['conflicts']), 1);

    $r3 = $resolver->resolve(['myrm' => ['synopsis' => 'The members race across Seoul in a hidden-identity mission.']], ['episode_number' => 810]);
    $check('resolve', 'lone weak source → LOW', $r3['synopsis']['confidence'], RmFieldResolver::LOW);

    $r4 = $resolver->resolve(['wikipedia' => ['mission' => 'Name Tag Elimination']], ['episode_number' => 810]);
    $check('resolve', 'lone trusted source → MEDIUM', $r4['mission']['confidence'], RmFieldResolver::MEDIUM);

    $r5 = $resolver->resolve([
        'wikipedia'    => ['location' => 'Seoul'],
        'myrunningman' => ['location' => 'Seoul, South Korea'],
    ], ['episode_number' => 810]);
    $check('resolve', 'location priority: myrunningman first', $r5['location']['source'], 'myrunningman');
    $check('resolve', 'equivalent locations count as agreement', $r5['location']['confidence'], RmFieldResolver::HIGH);

    // ── Diff + data safety ────────────────────────────────────
    $differ = new RmDiffEngine();
    $existing = [
        'title'    => 'Episode #810 - Jeju Trip',
        'synopsis' => 'The members travel to Jeju Island for a two-day race against the production team, with the losing team facing a penalty.',
        'air_date' => '2026-08-23',
        'guests'   => ['Yoo Jae-suk', 'Kim Jong-kook'],
        'location' => 'Jeju',
    ];

    $d1 = $differ->diff($existing, ['synopsis' => ['value' => null, 'source' => 'wikipedia', 'confidence' => 'medium', 'sources' => [], 'conflicts' => []]]);
    $check('safety', 'empty new value never overwrites a good one', isset($d1['apply']['synopsis']), false);
    $check('safety', 'and it is reported as a warning',             count($d1['warnings']), 1);

    $d2 = $differ->diff($existing, ['synopsis' => ['value' => 'Short.', 'source' => 'myrm', 'confidence' => 'low', 'sources' => ['myrm'], 'conflicts' => []]]);
    $check('safety', 'suspicious synopsis shrink refused', isset($d2['apply']['synopsis']), false);

    $d3 = $differ->diff($existing, ['title' => ['value' => 'Episode #810', 'source' => 'myrm', 'confidence' => 'low', 'sources' => ['myrm'], 'conflicts' => []]]);
    $check('safety', 'descriptive title not traded for a placeholder', isset($d3['apply']['title']), false);

    $d4 = $differ->diff($existing, ['air_date' => ['value' => '2026-09-01', 'source' => 'myrm', 'confidence' => RmFieldResolver::LOW, 'sources' => ['myrm'], 'conflicts' => []]]);
    $check('safety', 'low-trust air-date change refused', isset($d4['apply']['air_date']), false);

    $d5 = $differ->diff($existing, ['guests' => ['value' => ['Yoo Jae-suk', 'Song Ji-hyo'], 'source' => 'wikipedia', 'confidence' => 'high', 'sources' => ['wikipedia'], 'conflicts' => []]]);
    $check('safety', 'guest sets merge additively',   count($d5['apply']['guests'] ?? []), 3);
    $check('safety', 'a missing guest is kept, not deleted',
           in_array('Kim Jong-kook', $d5['apply']['guests'] ?? [], true), true);
    $removals = array_filter($d5['changes'], fn($c) => $c['type'] === RmDiffEngine::ITEM_REMOVED);
    $check('safety', 'the absent guest is reported for review', count($removals), 1);

    $d6 = $differ->diff([], ['synopsis' => ['value' => 'A brand new synopsis for an episode that had none at all.', 'source' => 'myrunningman', 'confidence' => 'medium', 'sources' => ['myrunningman'], 'conflicts' => []]]);
    $check('safety', 'genuinely new data is applied', isset($d6['apply']['synopsis']), true);

    // ── Titles ────────────────────────────────────────────────
    $check('titles', 'canonical form built',        RmNormalizer::title('Running Man Episode 810 - Jeju Trip', 810), 'Episode #810 - Jeju Trip');
    $check('titles', 'site suffix stripped',        RmNormalizer::title('Episode #810 - Jeju Trip - Wikipedia', 810), 'Episode #810 - Jeju Trip');
    $check('titles', 'index page becomes placeholder', RmNormalizer::title('Episodes - Page 4', 810), 'Episode #810');
    $check('titles', 'equivalent titles share a key', RmNormalizer::titleKey('Episode #810 - Jeju Trip') === RmNormalizer::titleKey('Episode #810 - jeju trip'), true);

    // ── Registry / config wiring ──────────────────────────────
    $reg = RmSourceRegistry::instance();
    $check('registry', 'every configured source has an adapter',
           count(array_diff(array_keys((array)rmScrapeConfig('sources', [])), array_keys($reg->all()))), 0);
    $check('registry', 'keyless TMDB is not usable', $reg->usable('tmdb', true) === (rmScrapeConfig('api_keys.tmdb') !== null), true);
    $check('registry', 'field targeting narrows the source list',
           count($reg->sourcesForFields(['teams'])) < count($reg->active(true)), true);
    foreach ((array)rmScrapeConfig('field_priority', []) as $field => $order) {
        $unknown = array_diff($order, array_keys((array)rmScrapeConfig('sources', [])));
        $check('registry', "field_priority[$field] names only real sources", count($unknown), 0);
    }

    $passed = count(array_filter($results, fn($r) => $r['pass']));
    return ['results' => $results, 'passed' => $passed, 'total' => count($results), 'ok' => $passed === count($results)];
}

// CLI entry point
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    $t = rmScrapingSelfTest();
    $group = null;
    foreach ($t['results'] as $r) {
        if ($r['group'] !== $group) { $group = $r['group']; echo "\n── " . strtoupper($group) . " ──\n"; }
        printf("  %s %s\n", $r['pass'] ? 'PASS' : 'FAIL', $r['name']);
        if (!$r['pass']) printf("       expected %s, got %s\n", $r['expected'], $r['actual']);
    }
    printf("\n%s — %d/%d checks passed\n", $t['ok'] ? 'ALL GREEN' : 'FAILURES PRESENT', $t['passed'], $t['total']);
    exit($t['ok'] ? 0 : 1);
}
