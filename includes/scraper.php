<?php
// ============================================================
// Running Man Archive — Scraper (compatibility facade)
//
// The scraping system now lives in includes/scraping/ as a set of
// independent components:
//
//   scraping/scrapers/*.php   one adapter per source (SBS, Wikipedia
//                             EN + KO, myrunningman, myrm.tv, TheTVDB,
//                             KShow123, IMDb — diagnostics/verification
//                             only, see config/scraping.php)
//   scraping/ScrapingEngine   collect → resolve → diff → apply
//   scraping/FieldResolver    per-FIELD source priority + confidence
//   scraping/DataNormalizer   guest/location/date/title normalisation
//   scraping/DataValidator    rejects garbage before it reaches the DB
//   scraping/DiffEngine       change detection + data-safety guards
//   scraping/Provenance       where every value came from
//   scraping/SourceHealth     which sources are broken, and how
//   scraping/ThumbnailEngine  validated image acquisition + fallback
//
// THIS FILE IS A FACADE. Every function the rest of the project has
// always called still exists here with the same name, arguments and
// return shape — admin/fetch.php, admin/cron.php, admin/auto_sync.php,
// admin/thumbnails.php, admin/diagnostics.php and batch_synopsis.php
// all keep working unchanged — but each one now delegates into the
// engine, so those callers silently gain multi-source resolution,
// validation, provenance and data-safety guards.
//
// The Wikipedia table parser (th/td alignment, rowspan/colspan
// carry-over, nested tables) was moved VERBATIM into
// scraping/scrapers/WikipediaScraper.php, which also still defines
// rmWikiFetchYearPage(), rmWikiParseHtml() and rmWikiParseYear() as
// global functions for the diagnostics pages.
// ============================================================

require_once __DIR__ . '/scraping/bootstrap.php';

// ── Legacy error accessors ────────────────────────────────────
$GLOBALS['__rm_last_fetch_error'] = $GLOBALS['__rm_last_fetch_error'] ?? null;

function rmLastFetchError(): ?string {
    return $GLOBALS['__rm_last_fetch_error'] ?? RmHttpClient::instance()->lastError();
}

// ── rmFetch — now backed by RmHttpClient ──────────────────────
// Same signature and same "null on failure" contract as before, but
// with classified errors, transient-only retries, per-host rate
// limiting and robots.txt compliance underneath.
function rmFetch(string $url, int $timeout = 15, int $retries = 2): ?string {
    $res = RmHttpClient::instance()->get($url, ['timeout' => $timeout, 'retries' => $retries]);
    $GLOBALS['__rm_last_fetch_error'] = $res->ok ? null : $res->error;
    return $res->ok ? $res->body : null;
}

// ── Per-source accessors (used by the diagnostics trace pages) ──
function rmWikiEpisode(int $epNum): array {
    $d = RmSourceRegistry::instance()->get('wikipedia')->episode($epNum);
    $out = ['episode_number' => $epNum, 'source' => 'wikipedia', 'guests' => [], 'image_url' => null];
    foreach (['title','air_date','synopsis','guests','mission','teams','results'] as $f) {
        if (!empty($d[$f])) $out[$f] = $d[$f];
    }
    // Legacy contract: this function always returns SOME title.
    if (empty($out['title'])) $out['title'] = 'Episode #' . str_pad((string)$epNum, 3, '0', STR_PAD_LEFT);
    $out['_status'] = $d['_status'] ?? null;
    $out['_error']  = $d['_error'] ?? null;
    return $out;
}

function rmMyrmTvEpisode(int $epNum): array {
    $d = RmSourceRegistry::instance()->get('myrm')->episode($epNum);
    $out = ['episode_number' => $epNum, 'source' => 'myrm.tv', 'guests' => [], 'image_url' => null];
    foreach (['title','synopsis','air_date','guests'] as $f) if (!empty($d[$f])) $out[$f] = $d[$f];
    $out['_status'] = $d['_status'] ?? null;
    $out['_error']  = $d['_error'] ?? null;
    return $out;
}

function rmMyRunningManExtra(int $epNum): array {
    $d = RmSourceRegistry::instance()->get('myrunningman')->episode($epNum);
    return [
        'image_url' => $d['image_url'] ?? null,
        'location'  => $d['location']  ?? null,
        'tags'      => $d['tags']      ?? [],
        'synopsis'  => $d['synopsis']  ?? null,
        '_status'   => $d['_status']   ?? null,
        '_error'    => $d['_error']    ?? null,
    ];
}

// ── MAIN: scrape one episode across every usable source ────────
// Return shape is byte-for-byte what callers already expect. What
// changed is everything underneath: all sources are consulted (not a
// fixed 3 + conditional 4th), each field is won by the source that is
// best for THAT field rather than by one global ranking, every value
// is validated before it is offered, and the merge is a union for
// guests/tags rather than first-writer-wins.
function rmScrapeEpisode(int $epNum): array {
    $engine    = rmEngine();
    $collected = $engine->collect($epNum, [], []);
    $payloads  = $collected['payloads'];

    $merged = [
        'episode_number' => $epNum,
        'source'         => '',
        'guests'         => [],
        'tags'           => [],
        'image_url'      => null,
        'location'       => null,
    ];

    if (!$payloads) {
        $merged['title']  = 'Episode #' . str_pad((string)$epNum, 3, '0', STR_PAD_LEFT);
        $merged['source'] = 'none';
        $merged['_meta']  = $collected['meta'];
        return $merged;
    }

    $resolved = (new RmFieldResolver())->resolve($payloads, [
        'episode_number' => $epNum,
        'expected_year'  => rmYear($epNum),
    ]);

    foreach (['title','air_date','synopsis','mission','teams','results','location','image_url','guests','tags'] as $f) {
        if (!empty($resolved[$f]['value'])) $merged[$f] = $resolved[$f]['value'];
    }
    if (is_array($merged['location'])) $merged['location'] = $merged['location']['name'] ?? null;
    if (empty($merged['title'])) $merged['title'] = 'Episode #' . str_pad((string)$epNum, 3, '0', STR_PAD_LEFT);

    // Legacy 'source' string: which sources actually contributed.
    $contributors = [];
    foreach ($resolved as $r) foreach ((array)($r['sources'] ?? []) as $s) $contributors[$s] = true;
    $merged['source'] = $contributors ? implode('+', array_keys($contributors)) : 'none';

    // Extra keys are additive — existing callers ignore them, the new
    // admin pages use them for confidence badges and provenance.
    $merged['_resolved'] = $resolved;
    $merged['_meta']     = $collected['meta'];
    return $merged;
}

// ── Latest episode detection ──────────────────────────────────
function rmEpisodeExistsOnMRM(int $epNum): bool {
    $s = RmSourceRegistry::instance()->get('myrunningman');
    return $s instanceof MyRunningManScraper ? $s->episodeExists($epNum) : false;
}

// Multi-signal detection (archive max + every source that can report
// its own maximum + a probe past the newest known episode). Disagreement
// between signals is flagged for review rather than silently resolved.
function rmGetLatestEpNumber(): ?int {
    $d = RmLatestEpisode::detect();
    $GLOBALS['__rm_latest_detection'] = $d;
    return $d['latest'];
}

/** Full detection detail — signals, confidence, conflict note. */
function rmGetLatestEpDetail(): array {
    return $GLOBALS['__rm_latest_detection'] ?? RmLatestEpisode::detect();
}

/**
 * Detection detail PLUS aired/upcoming classification of any candidate
 * episode past the archive's current maximum — see
 * RmLatestEpisode::detectDetailed() for what distinguishes this from
 * rmGetLatestEpDetail() above. This is what the Maintenance "Fetch
 * Latest Episode" page uses; the plain detail is kept for callers that
 * only need the raw multi-signal max.
 */
function rmGetLatestEpDetection(): array {
    return RmLatestEpisode::detectDetailed();
}

// ── Clean/validate title ──────────────────────────────────────
function rmCleanTitle(string $raw, int $epNum): string {
    return RmNormalizer::title($raw, $epNum);
}

// ── Download thumbnail ────────────────────────────────────────
// Same signature and same "web path or null" return. Now validates
// the actual bytes (real image, real dimensions, not HTML, not a
// placeholder), hashes them so an identical image is not re-encoded,
// and records verification state in thumbnail_meta.
function rmDownloadThumb(string $imgUrl, int $epNum, int $year): ?string {
    $res = (new RmThumbnailEngine())->acquire($epNum, $year, [['url' => $imgUrl, 'source' => 'legacy']]);
    if (!$res['ok']) {
        $GLOBALS['__rm_last_fetch_error'] = $res['reason'];
        return null;
    }
    return $res['path'];
}
