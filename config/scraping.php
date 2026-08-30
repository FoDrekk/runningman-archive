<?php
// ============================================================
// Running Man Archive — Scraping Engine Configuration
//
// Everything tunable about data acquisition lives here: which
// sources exist, how much each is trusted, which source wins for
// which FIELD, cache lifetimes, rate limits and optional API keys.
//
// NOTHING SECRET BELONGS IN THIS FILE. Optional API keys are read
// from the environment or from config/scraping.local.php (which is
// git-ignored). Every source that needs a key stays disabled and
// silently skipped when no key is present — the engine is fully
// functional without any of them.
// ============================================================

if (!defined('RM_SCRAPER_UA')) {
    // Identifiable but browser-compatible. Several sources reject
    // non-browser agents outright, which is why the browser token is
    // kept — the archive suffix makes the traffic attributable.
    define('RM_SCRAPER_UA', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36 RunningManArchive/2.0');
}

function rmScrapeDefaultConfig(): array {
    return [
        // ── HTTP behaviour ────────────────────────────────────────
        'http' => [
            'timeout'          => 15,
            'connect_timeout'  => 8,
            'max_retries'      => 2,       // total attempts = 1 + max_retries
            'backoff_base_ms'  => 400,     // 0.4s → 0.8s → 1.6s (+ jitter)
            'backoff_max_ms'   => 8000,
            'user_agent'       => RM_SCRAPER_UA,
            'respect_robots'   => true,
            'robots_ttl'       => 86400,
            'default_delay_ms' => 900,     // polite gap between hits on one host
        ],

        // ── Cache TTLs, in seconds, by cache class ────────────────
        // Stable reference data is cached hard; anything describing the
        // newest episode is cached briefly so a fresh airing is picked
        // up quickly. Failures get a short suppression window so a dead
        // source is not hammered once per episode in a bulk run.
        'cache' => [
            'enabled'          => true,
            'dir'              => null,    // null → system temp dir/rm_scrape_cache
            'ttl_reference'    => 604800,  // 7d  — old, settled episodes
            'ttl_page'         => 21600,   // 6h  — generic source page
            'ttl_index'        => 1800,    // 30m — episode listings / year tables
            'ttl_recent'       => 900,     // 15m — anything about the latest episode
            'ttl_api'          => 43200,   // 12h — structured API responses
            'ttl_negative'     => 600,     // 10m — retry suppression after failure
            'ttl_blocked'      => 3600,    // 1h  — suppression after 403/429
            'recent_window'    => 6,       // last N episodes count as "recent"
        ],

        // ── Source registry ───────────────────────────────────────
        // tier = trust weight used by the confidence model:
        //   3 official/editorially reviewed · 2 solid secondary
        //   1 community-contributed / best-effort
        // enabled=false sources are never contacted at all.
        'sources' => [
            'sbs' => [
                'label'   => 'SBS (Official)',
                'tier'    => 3,
                'enabled' => true,
                'delay_ms'=> 1200,
                'base'    => 'https://programs.sbs.co.kr/enter/runningman',
            ],
            'wikipedia' => [
                'label'   => 'Wikipedia (EN)',
                'tier'    => 3,
                'enabled' => true,
                'delay_ms'=> 600,
                'base'    => 'https://en.wikipedia.org/',
            ],
            'kowiki' => [
                'label'   => 'Wikipedia (KO)',
                'tier'    => 2,
                'enabled' => true,
                'delay_ms'=> 600,
                'base'    => 'https://ko.wikipedia.org/',
            ],
            'myrunningman' => [
                'label'   => 'myrunningman.com',
                'tier'    => 2,
                'enabled' => true,
                'delay_ms'=> 1000,
                'base'    => 'https://www.myrunningman.com/',
            ],
            'myrm' => [
                'label'   => 'myrm.tv',
                'tier'    => 1,
                'enabled' => true,
                'delay_ms'=> 1000,
                'base'    => 'https://myrm.tv/',
            ],
            'mydramalist' => [
                'label'   => 'MyDramaList',
                'tier'    => 1,
                // Historically a flat HTTP 403 from this host (TLS/IP
                // reputation blocking, not headers). Left enabled so the
                // engine can prove that for itself and mark the source
                // BLOCKED in health rather than pretending it works —
                // the blocked-suppression window means it costs one
                // request per hour, not one per episode.
                'enabled' => true,
                'delay_ms'=> 2500,
                'base'    => 'https://mydramalist.com/',
            ],
            'tmdb' => [
                'label'    => 'TMDB',
                'tier'     => 2,
                'enabled'  => true,      // still needs a key — see api_keys
                'delay_ms' => 300,
                'base'     => 'https://api.themoviedb.org/3/',
                'tv_id'    => 33238,     // "Running Man" (SBS, 2010) on TMDB
            ],
            'wikidata' => [
                'label'   => 'Wikidata',
                'tier'    => 2,
                'enabled' => true,
                'delay_ms'=> 500,
                'base'    => 'https://www.wikidata.org/',
            ],
        ],

        // ── FIELD-LEVEL source priority ───────────────────────────
        // First source in a list that supplies a VALID value wins the
        // field. This is deliberately per-field: the site with the best
        // air dates is not the site with the best synopses.
        'field_priority' => [
            'title'        => ['sbs','wikipedia','myrm','mydramalist','tmdb','kowiki','myrunningman'],
            'title_ko'     => ['sbs','kowiki','wikidata'],
            'air_date'     => ['sbs','wikipedia','kowiki','tmdb','mydramalist','myrm','myrunningman'],
            'synopsis'     => ['myrunningman','myrm','mydramalist','wikipedia','tmdb','sbs','kowiki'],
            'guests'       => ['wikipedia','kowiki','mydramalist','sbs','myrm','myrunningman'],
            'mission'      => ['wikipedia','myrunningman','kowiki'],
            'teams'        => ['wikipedia'],
            'results'      => ['wikipedia'],
            'location'     => ['myrunningman','wikipedia','mydramalist','sbs','kowiki'],
            'theme'        => ['myrunningman','wikipedia'],
            'tags'         => ['myrunningman','wikipedia'],
            'special_notes'=> ['wikipedia','sbs','myrunningman'],
            'image_url'    => ['myrunningman','tmdb','sbs','myrm','wikipedia'],
        ],

        // Fields merged as sets (union + dedup) instead of "one winner".
        'array_fields' => ['guests','tags'],

        // ── Confidence thresholds ─────────────────────────────────
        'confidence' => [
            'high_weight'      => 4,  // combined tier weight of agreeing sources
            'reliable_tier'    => 2,  // tier at/above which a lone source is MEDIUM
            'conflict_tier'    => 2,  // disagreement between sources ≥ this = CONFLICT
        ],

        // ── Data-safety guards ────────────────────────────────────
        'safety' => [
            // Never let a new synopsis shrink an existing one by more
            // than this ratio without flagging — a source that suddenly
            // returns a stub is usually a parser break, not an edit.
            'synopsis_shrink_ratio' => 0.5,
            // A source that returns HTTP 200 but zero fields when it has
            // historically produced data is a parser warning, not "no data".
            'parser_warning_after'  => 3,
            'max_guests_per_ep'     => 30,
            'max_synopsis_chars'    => 4000,
            'min_synopsis_chars'    => 15,
        ],

        // ── Optional API keys — env or config/scraping.local.php ──
        'api_keys' => [
            'tmdb' => null,
            'tvdb' => null,
        ],

        // ── Thumbnails ────────────────────────────────────────────
        'thumbnail' => [
            'min_bytes'   => 2000,
            'min_width'   => 200,
            'min_height'  => 112,
            'target_w'    => 1280,
            'target_h'    => 720,
            'jpeg_quality'=> 92,
            'recheck_days'=> 45,
        ],
    ];
}

// Merge order: defaults → config/scraping.local.php → environment.
// Local file and env are for secrets/host-specific tuning only.
function rmScrapeConfig(?string $path = null, $default = null) {
    static $cfg = null;
    if ($cfg === null) {
        $cfg = rmScrapeDefaultConfig();

        $localFile = __DIR__ . '/scraping.local.php';
        if (is_readable($localFile)) {
            $local = @include $localFile;
            if (is_array($local)) $cfg = rmScrapeMergeConfig($cfg, $local);
        }

        foreach (['tmdb' => 'RM_TMDB_API_KEY', 'tvdb' => 'RM_TVDB_API_KEY'] as $k => $env) {
            $v = getenv($env);
            if ($v !== false && trim($v) !== '') $cfg['api_keys'][$k] = trim($v);
        }
    }

    if ($path === null) return $cfg;

    $node = $cfg;
    foreach (explode('.', $path) as $seg) {
        if (!is_array($node) || !array_key_exists($seg, $node)) return $default;
        $node = $node[$seg];
    }
    return $node;
}

function rmScrapeMergeConfig(array $base, array $over): array {
    foreach ($over as $k => $v) {
        $base[$k] = (is_array($v) && isset($base[$k]) && is_array($base[$k]))
            ? rmScrapeMergeConfig($base[$k], $v) : $v;
    }
    return $base;
}

// A source is usable only if enabled AND (if it needs one) keyed.
function rmScrapeSourceEnabled(string $name): bool {
    if (!rmScrapeConfig("sources.$name.enabled", false)) return false;
    if (in_array($name, ['tmdb','tvdb'], true)) {
        $key = rmScrapeConfig("api_keys.$name");
        return is_string($key) && trim($key) !== '';
    }
    return true;
}
