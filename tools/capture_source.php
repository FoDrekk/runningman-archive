<?php
// ============================================================
// tools/capture_source.php — dump exactly what a source returns.
//
// Written because "SBS pages loaded but episode 813 was not found in
// them" is a symptom, not a diagnosis, and the difference between
//   · the page is server-rendered and our selectors drifted
//   · the page is a client-rendered shell with no episode text at all
//   · the data is in embedded JSON we are not reading
//   · we are fetching a listing, and the episode is simply not on it
// changes the fix completely. Guessing at another CSS selector without
// knowing which of those is true is how a scraper accumulates dead code.
//
// Run it on a machine that can actually reach the source:
//
//   php tools/capture_source.php --source=sbs --ep=813
//   php tools/capture_source.php --source=sbs --ep=813 --save=/tmp/sbs
//   php tools/capture_source.php --all --ep=813
//
// Read-only: it fetches and reports. It never writes to the database,
// and --save only writes the raw bodies you ask for.
// ============================================================
require_once __DIR__ . '/../includes/scraping/bootstrap.php';

$opt = ['source' => null, 'ep' => 0, 'save' => null, 'all' => false, 'fresh' => true];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--source=(.+)$/', $arg, $m))    $opt['source'] = $m[1];
    elseif (preg_match('/^--ep=(\d+)$/', $arg, $m))   $opt['ep'] = (int)$m[1];
    elseif (preg_match('/^--save=(.+)$/', $arg, $m))  $opt['save'] = rtrim($m[1], '/');
    elseif ($arg === '--all')                         $opt['all'] = true;
    elseif ($arg === '--cached')                      $opt['fresh'] = false;
}
if (!$opt['ep'] || (!$opt['source'] && !$opt['all'])) {
    fwrite(STDERR, "usage: php tools/capture_source.php --source=<name>|--all --ep=<n> [--save=<dir>] [--cached]\n");
    fwrite(STDERR, "sources: " . implode(', ', array_keys((array)rmScrapeConfig('sources', []))) . "\n");
    exit(2);
}

$ep = $opt['ep'];
$http = RmHttpClient::instance();

/** Every URL an adapter would request for this episode. */
function urlsFor(string $source, int $ep): array {
    $year = rmYear($ep);
    return match ($source) {
        'sbs' => [
            'https://static.apis.sbs.co.kr/program-api/1.0/menu/runningman',
            'https://static.apis.sbs.co.kr/program-api/1.0/board/runningman/vod',
            'https://programs.sbs.co.kr/enter/runningman/visualboard/54666',
            'https://programs.sbs.co.kr/enter/runningman',
        ],
        'wikipedia'    => ['https://en.wikipedia.org/w/api.php?' . http_build_query([
                              'action'=>'parse','page'=>"List of Running Man episodes ($year)",
                              'prop'=>'text','format'=>'json','disablelimitreport'=>1,'disableeditsection'=>1])],
        'kowiki'       => ['https://ko.wikipedia.org/w/api.php?' . http_build_query([
                              'action'=>'parse','page'=>"런닝맨의 에피소드 목록 ($year)",
                              'prop'=>'text','format'=>'json','disablelimitreport'=>1,'disableeditsection'=>1])],
        'myrunningman' => ["https://www.myrunningman.com/ep/$ep"],
        'myrm'         => ["https://myrm.tv/ep/$ep"],
        'mydramalist'  => ["https://mydramalist.com/25565-running-man/episode/$ep"],
        'wikidata'     => ['https://www.wikidata.org/wiki/Q485576'],
        'tmdb'         => ['https://api.themoviedb.org/3/tv/' . (int)rmScrapeConfig('sources.tmdb.tv_id', 33238)],
        default        => [],
    };
}

/** Selectors/markers the adapter actually relies on, for reference. */
function selectorsFor(string $source, int $ep): array {
    return match ($source) {
        'sbs' => [
            'episode marker'  => "/\\b0*$ep\\s*회/u   (Korean \"{$ep}회\")",
            'alt marker'      => "/\\bEp\\.?\\s*0*$ep\\b/i",
            'structured data' => '<script type="application/ld+json"> walked for a title-ish key',
            'list block'      => '<li|div|article> containing the episode marker',
            'title'           => 'class~=(tit|title|subject), else <h1-6>/<strong>, else img@alt',
            'date'            => 'YYYY.M.D or YYYY년 M월 D일 inside the matched block',
        ],
        'myrunningman' => [
            'thumbnail' => 'meta[property=og:image]',
            'location'  => '"Location:" label, then class~=location',
            'synopsis'  => 'div/p class~=(description|synopsis|summary|ep-desc), else og:description',
            'title'     => 'og:title (an <h1> only if it names the episode)',
            'air date'  => '"Broadcast Date"/"Aired", else <time datetime>',
            'tags'      => 'a[href*=/tags/]',
        ],
        'myrm' => [
            'title'    => 'og:title (an <h1> only if it names the episode)',
            'synopsis' => 'og:description / meta description',
            'air date' => '"Broadcast Date", else <time datetime>, else first YYYY-MM-DD',
            'guests'   => '"Guests:" label',
        ],
        'wikipedia', 'kowiki' => [
            'table'   => 'table.wikitable, column-aligned with rowspan/colspan carry-over',
            'columns' => 'header labels: Ep./회, Airdate/방송일, Title/제목, Guest(s)/게스트, Mission, Teams, Results',
        ],
        'mydramalist' => [
            'synopsis' => 'og:description, else div class~=(episode-synopsis|show-synopsis|description)',
            'location' => '"Landmark:"/"Site:"/"Location:" label',
            'guests'   => '"Guests:" label',
        ],
        default => [],
    };
}

function analyse(string $body, int $ep, string $contentType): array {
    $len   = strlen($body);
    $isJson = false;
    $decoded = json_decode($body, true);
    if (json_last_error() === JSON_ERROR_NONE && (is_array($decoded))) $isJson = true;

    // How much of this document is script rather than content? A shell
    // that renders client-side is mostly script and almost no text.
    $scriptBytes = 0;
    if (preg_match_all('~<script\b[^>]*>(.*?)</script>~is', $body, $sm)) {
        foreach ($sm[0] as $blk) $scriptBytes += strlen($blk);
    }
    $stripped = preg_replace('~<script\b[^>]*>.*?</script>~is', '', $body);
    $stripped = preg_replace('~<style\b[^>]*>.*?</style>~is', '', (string)$stripped);
    $visible  = trim(preg_replace('/\s+/u', ' ', strip_tags((string)$stripped)));

    // Does the episode appear at all, and in what form?
    $markers = [];
    foreach ([
        "{$ep}회"        => '/\b0*' . $ep . '\s*회/u',
        "Ep. {$ep}"      => '/\bEp\.?\s*0*' . $ep . '\b/i',
        "Episode {$ep}"  => '/Episode\s*#?\s*0*' . $ep . '\b/i',
        "bare {$ep}"     => '/(?<!\d)0*' . $ep . '(?!\d)/',
    ] as $label => $re) {
        $markers[$label] = preg_match_all($re, $body);
    }

    // SPA / client-render markers.
    $spa = [];
    foreach ([
        '__NEXT_DATA__' => '__NEXT_DATA__', 'window.__NUXT__' => '__NUXT__',
        'window.__INITIAL_STATE__' => '__INITIAL_STATE__', 'Vue' => 'new Vue(',
        'React root' => 'id="root"', 'ng-app' => 'ng-app', 'data-reactroot' => 'data-reactroot',
    ] as $label => $needle) {
        if (str_contains($body, $needle)) $spa[] = $label;
    }

    // Embedded JSON documents — the usual place a "JS-rendered" page
    // actually keeps its data.
    $embedded = [];
    if (preg_match_all('~<script[^>]*type=["\'](application/(?:ld\+)?json)["\'][^>]*>(.*?)</script>~is', $body, $jm, PREG_SET_ORDER)) {
        foreach ($jm as $i => $blk) {
            $d = json_decode(trim($blk[2]), true);
            $embedded[] = [
                'kind'  => $blk[1], 'index' => $i, 'bytes' => strlen($blk[2]),
                'valid' => json_last_error() === JSON_ERROR_NONE,
                'has_episode_marker' => (bool)preg_match('/(?<!\d)0*' . $ep . '(?!\d)/', $blk[2]),
                'top_keys' => is_array($d) ? array_slice(array_keys($d), 0, 8) : [],
            ];
        }
    }
    if (preg_match_all('~(?:window\.)?(__[A-Z_]+__|__NEXT_DATA__)\s*=\s*(\{.*?\})\s*[;<]~s', $body, $wm, PREG_SET_ORDER)) {
        foreach ($wm as $blk) {
            $d = json_decode($blk[2], true);
            $embedded[] = [
                'kind' => 'js-assignment ' . $blk[1], 'bytes' => strlen($blk[2]),
                'valid' => json_last_error() === JSON_ERROR_NONE,
                'has_episode_marker' => (bool)preg_match('/(?<!\d)0*' . $ep . '(?!\d)/', $blk[2]),
                'top_keys' => is_array($d) ? array_slice(array_keys($d), 0, 8) : [],
            ];
        }
    }

    // Is this a listing/search page rather than one episode?
    $looksListing = (bool)preg_match('/\b(회차|목록|전체보기|list|episodes?)\b/iu', $visible)
                 && preg_match_all('/\d{1,4}\s*회/u', $body) > 3;

    return [
        'bytes'            => $len,
        'content_type'     => $contentType,
        'is_json'          => $isJson,
        'script_bytes'     => $scriptBytes,
        'script_ratio'     => $len > 0 ? round($scriptBytes / $len, 2) : 0,
        'visible_text_len' => strlen($visible),
        'visible_sample'   => mb_substr($visible, 0, 300),
        'episode_markers'  => $markers,
        'spa_markers'      => $spa,
        'embedded_json'    => $embedded,
        'looks_like_listing' => $looksListing,
        'episode_count_on_page' => preg_match_all('/\d{1,4}\s*회/u', $body),
    ];
}

$sources = $opt['all'] ? array_keys((array)rmScrapeConfig('sources', [])) : [$opt['source']];

foreach ($sources as $source) {
    $urls = urlsFor($source, $ep);
    echo "\n" . str_repeat('═', 74) . "\n";
    printf("SOURCE: %s   (%s, tier %d)   EPISODE: %d\n", $source,
           rmScrapeSourceClass($source), (int)rmScrapeConfig("sources.$source.tier", 1), $ep);
    echo str_repeat('═', 74) . "\n";

    if (!rmScrapeSourceEnabled($source)) {
        echo "  DISABLED in config (or missing its API key) — not contacted.\n";
        continue;
    }
    if (!$urls) { echo "  No URL mapping for this source in the capture tool.\n"; continue; }

    foreach ($urls as $url) {
        echo "\n── REQUEST ──\n";
        echo "  requested URL : $url\n";
        $res = $http->get($url, [
            'timeout' => 25, 'retries' => 1, 'bypass_cache' => $opt['fresh'],
            'cache_ttl' => 0, 'respect_robots' => true,
        ]);
        printf("  HTTP status   : %s\n", $res->status ?: '(none)');
        printf("  final URL     : %s%s\n", $res->url, $res->url !== $url ? '   ← REDIRECTED' : '');
        printf("  content type  : %s\n", $res->contentType ?: '(none)');
        printf("  response size : %s bytes\n", number_format($res->length()));
        printf("  outcome       : %s\n", $res->ok ? 'OK' : $res->errorClass . ' — ' . $res->error);
        if (!$res->ok) continue;

        $a = analyse((string)$res->body, $ep, $res->contentType);
        echo "\n── ANALYSIS ──\n";
        printf("  looks like JSON        : %s\n", $a['is_json'] ? 'yes' : 'no');
        printf("  script bytes / ratio   : %s (%s of the document)\n", number_format($a['script_bytes']), ($a['script_ratio'] * 100) . '%');
        printf("  visible text length    : %s bytes\n", number_format($a['visible_text_len']));
        printf("  JS-rendered indicators : %s\n", $a['spa_markers'] ? implode(', ', $a['spa_markers']) : 'none found');
        printf("  looks like a listing   : %s (%d \"N회\" markers on the page)\n",
               $a['looks_like_listing'] ? 'YES' : 'no', $a['episode_count_on_page']);
        echo   "  episode $ep appears as :\n";
        foreach ($a['episode_markers'] as $label => $n) printf("      %-14s %s\n", $label, $n ? "$n match(es)" : 'not present');
        if ($a['embedded_json']) {
            echo "  embedded JSON documents:\n";
            foreach ($a['embedded_json'] as $e) {
                printf("      %-28s %7s bytes  valid=%-3s episode-marker=%-3s keys=%s\n",
                    $e['kind'], number_format($e['bytes']), $e['valid'] ? 'yes' : 'no',
                    $e['has_episode_marker'] ? 'YES' : 'no', implode(',', $e['top_keys']) ?: '—');
            }
        } else {
            echo "  embedded JSON documents: none\n";
        }
        echo "  visible text sample    : " . $a['visible_sample'] . "\n";

        if ($opt['save']) {
            @mkdir($opt['save'], 0775, true);
            $file = $opt['save'] . '/' . $source . '_ep' . $ep . '_' . substr(sha1($url), 0, 8)
                  . ($a['is_json'] ? '.json' : '.html');
            file_put_contents($file, (string)$res->body);
            echo "  raw body saved to      : $file\n";
        }
    }

    $sel = selectorsFor($source, $ep);
    if ($sel) {
        echo "\n── SELECTORS THIS ADAPTER CURRENTLY RELIES ON ──\n";
        foreach ($sel as $what => $how) printf("  %-16s %s\n", $what, $how);
    }

    echo "\n── WHAT THE ADAPTER ACTUALLY RETURNS ──\n";
    $adapter = RmSourceRegistry::instance()->get($source);
    if ($adapter) {
        $out = $adapter->episode($ep, ['bypass_cache' => $opt['fresh']]);
        $fields = array_values(array_filter(array_keys($out), fn($k) => !str_starts_with($k, '_')));
        printf("  status : %s\n", $out['_status'] ?? '?');
        printf("  fields : %s\n", $fields ? implode(', ', $fields) : '(none)');
        if (!empty($out['_error'])) printf("  reason : %s\n", $out['_error']);
        foreach ($fields as $f) {
            $v = is_array($out[$f]) ? implode(' | ', array_map('strval', $out[$f])) : (string)$out[$f];
            printf("      %-12s %s\n", $f, mb_substr($v, 0, 90));
        }
    }
}

echo "\n" . str_repeat('═', 74) . "\n";
echo "Nothing above was written to the database.\n";
if (!$opt['save']) echo "Re-run with --save=/tmp/capture to keep the raw bodies for inspection.\n";
