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
            // Offline mode refuses every non-loopback request outright.
            // Set RM_SCRAPE_OFFLINE=1 for test runs and CI, so the suite
            // can never reach a real source — deterministic for us, and
            // no unsolicited traffic for them.
            'offline'          => false,
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
        // tier  = trust weight used by the confidence model:
        //         3 official/editorially reviewed · 2 solid secondary
        //         1 community-contributed / best-effort
        //
        // class = what the source IS, which decides what it may OVERWRITE:
        //   primary    the broadcaster itself — the record of what aired
        //   secondary  substantial, edited episode coverage
        //   metadata   supplementary detail; may FILL gaps but may not
        //              overwrite a value a stronger class already supplied
        //   identity   not an episode source at all (person/entity data)
        //
        // enabled=false sources are never contacted at all.
        'sources' => [
            'sbs' => [
                'label'   => 'SBS (Official)',
                'tier'    => 3,
                'class'   => 'primary',
                'enabled' => true,
                'delay_ms'=> 1200,
                'base'    => 'https://programs.sbs.co.kr/enter/runningman',
            ],
            'wikipedia' => [
                'label'   => 'Wikipedia (EN)',
                'tier'    => 3,
                'class'   => 'secondary',
                'enabled' => true,
                'delay_ms'=> 600,
                'base'    => 'https://en.wikipedia.org/',
            ],
            'kowiki' => [
                'label'   => 'Wikipedia (KO)',
                'tier'    => 2,
                'class'   => 'secondary',
                'enabled' => true,
                'delay_ms'=> 600,
                'base'    => 'https://ko.wikipedia.org/',
            ],
            'myrunningman' => [
                'label'   => 'myrunningman.com',
                'tier'    => 2,
                'class'   => 'secondary',
                'enabled' => true,
                'delay_ms'=> 1000,
                'base'    => 'https://www.myrunningman.com/',
            ],
            'myrm' => [
                'label'   => 'myrm.tv',
                'tier'    => 1,
                'class'   => 'secondary',
                'enabled' => true,
                'delay_ms'=> 1000,
                'base'    => 'https://myrm.tv/',
            ],
            'tvdb' => [
                'label'   => 'TheTVDB',
                'tier'    => 2,
                'class'   => 'secondary',
                // Independent episode/date verification. Needs a key —
                // see api_keys — and stays disabled and skipped without
                // one, exactly like every other keyed source here.
                'enabled' => true,
                'delay_ms'=> 500,
                'base'    => 'https://api4.thetvdb.com/v4/',
                'series'  => 79086,      // "Running Man" on TheTVDB
            ],
            'kshow123' => [
                'label'   => 'KShow123',
                'tier'    => 1,
                'class'   => 'metadata',
                // Secondary episode/availability verification and a
                // thumbnail fallback. Community-run mirror, so it earns
                // only a FILL role — see field_priority — never a source
                // that overwrites a stronger class.
                'enabled' => true,
                'delay_ms'=> 1500,
                'base'    => 'https://kshow123.tv/',
            ],
            'imdb' => [
                'label'   => 'IMDb',
                'tier'    => 2,
                'class'   => 'metadata',
                // Read strictly through the public JSON-LD its episode
                // pages already publish. No login, no API key, and a 403
                // is recorded as ACCESS_RESTRICTED rather than worked
                // around.
                'enabled' => true,
                'delay_ms'=> 2000,
                'base'    => 'https://www.imdb.com/',
                'series'  => 'tt1587289',      // Running Man (SBS, 2010)
                // Diagnostics/cross-check only (PR #4 source policy): IMDb
                // may corroborate or contest another source's value, but a
                // candidate whose ONLY support is a verification_only
                // source is never picked by RmDecisionEngine to FILL or
                // UPDATE canonical episode metadata. See Decision.php.
                'verification_only' => true,
            ],
        ],

        // ── Publisher lineage ─────────────────────────────────────
        // Sources that share an upstream are ONE witness, not several.
        // Wikidata is populated from Wikipedia; the language editions
        // copy each other routinely. Counting them separately would
        // manufacture agreement that nobody independently checked.
        'source_lineage' => [
            'wikipedia' => 'wikimedia',
            'kowiki'    => 'wikimedia',
        ],

        // ── FIELD-LEVEL source priority ───────────────────────────
        // First source in a list that supplies a VALID value wins the
        // field. This is deliberately per-field: the site with the best
        // air dates is not the site with the best synopses.
        //
        // Wikipedia EN is canonical for episode metadata (PR #4 source
        // policy) — it leads every field it can supply. SBS stays ahead
        // of it only where SBS is definitionally authoritative (it IS
        // the broadcast record). Secondary sources fill what Wikipedia
        // doesn't have; they do not silently replace it — see
        // RmDecisionEngine's class-rank and overwrite-margin guards.
        // `imdb` is intentionally absent from every list here: it is
        // diagnostics/verification only (see sources.imdb.verification_only)
        // and must never win a field.
        'field_priority' => [
            'title'        => ['wikipedia','sbs','myrm','tvdb','kowiki','myrunningman','kshow123'],
            'title_ko'     => ['sbs','kowiki'],
            'air_date'     => ['sbs','wikipedia','tvdb','kowiki','myrm','myrunningman'],
            'synopsis'     => ['wikipedia','myrunningman','myrm','sbs','kowiki'],
            'guests'       => ['wikipedia','kowiki','sbs','myrm','myrunningman'],
            'mission'      => ['wikipedia','myrunningman','kowiki'],
            'teams'        => ['wikipedia'],
            'results'      => ['wikipedia'],
            'location'     => ['myrunningman','wikipedia','sbs','kowiki'],
            'theme'        => ['myrunningman','wikipedia'],
            'tags'         => ['myrunningman','wikipedia'],
            'special_notes'=> ['wikipedia','sbs','myrunningman'],
            // Thumbnail priority mirrors the Thumbnail Service spec
            // exactly: SBS → MyRunningMan/MyRM → KShow123 → Wikipedia →
            // (generated fallback, handled outside this list).
            'image_url'    => ['sbs','myrunningman','myrm','kshow123','wikipedia'],
        ],

        // Fields merged as sets (union + dedup) instead of "one winner".
        'array_fields' => ['guests','tags'],

        // How much authority each class carries. A source may not
        // overwrite a value recorded from a strictly stronger class.
        'class_rank' => [
            'primary'   => 3,
            'secondary' => 2,
            'metadata'  => 1,
            'identity'  => 0,
        ],

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

        // ── FIELD-LEVEL source reputation ─────────────────────────
        // Editorial overrides where a source's standing on one field
        // differs sharply from its standing overall. Anything not named
        // here is derived from tier, class and field-priority rank.
        'field_reputation' => [
            'air_date' => ['sbs' => 95, 'wikipedia' => 88, 'tvdb' => 80],
            'title_ko' => ['sbs' => 92, 'kowiki' => 88],
            'guests'   => ['wikipedia' => 90, 'kowiki' => 82],
            'synopsis' => ['sbs' => 55, 'myrunningman' => 80],
        ],

        // ── Research behaviour ────────────────────────────────────
        'research' => [
            // Which sources each mode is willing to spend a request on.
            // Quick asks only the sources that usually answer; Maximum
            // asks everything that could conceivably hold the field;
            // Deep additionally runs public discovery and re-checks
            // fields that came back weak.
            'modes' => [
                'quick'    => ['tiers' => [3],       'discovery' => false, 'recheck_weak' => false,
                               'label' => 'Quick',            'hint' => 'High-value sources only'],
                'balanced' => ['tiers' => [3, 2],    'discovery' => false, 'recheck_weak' => false,
                               'label' => 'Balanced',         'hint' => 'The normal, reliable source set'],
                'maximum'  => ['tiers' => [3, 2, 1], 'discovery' => true,  'recheck_weak' => false,
                               'label' => 'Maximum coverage', 'hint' => 'Every enabled source, plus public discovery'],
                'deep'     => ['tiers' => [3, 2, 1], 'discovery' => true,  'recheck_weak' => true,
                               'label' => 'Deep research',    'hint' => 'Maximum, plus cross-verification of weak and contested fields'],
            ],
            'default_mode' => 'balanced',

            // How long before an episode is worth looking at again. The
            // point of these numbers is that a source which had nothing
            // yesterday will still have nothing today, and asking it
            // anyway is what made the same 386 episodes reappear on
            // every page load.
            'cooldown' => [
                'updated'    => 259200,      // 3d  — something changed; check back soonish
                'no_new'     => 172800,      // 2d  — reached everything, nothing there
                'failed'     => 21600,       // 6h  — could not check; the obstacle may pass
                'conflict'   => 604800,      // 7d  — waiting on more evidence, not more requests
                'default'    => 86400,
                'recent_cap' => 86400,       // a recent episode never rests longer than a day
                'cap'        => 5184000,     // 60d ceiling for everything else
            ],

            'stale_days'            => 180,  // not verified in this long → worth re-checking
            'low_confidence_below'  => 75,
            'overwrite_margin'      => 8,    // new evidence must beat old by this to overwrite
            'contested_within'      => 12,   // two candidates this close are a conflict, not a winner
            'max_per_run'           => 200,
            'step_seconds'          => 20,   // wall-clock budget for one browser-driven step
            'copyable_fields'       => ['synopsis', 'mission', 'teams', 'results', 'special_notes'],

            // How sure the engine must be before writing unattended.
            'criticality' => [
                'episode_number' => 'critical', 'air_date' => 'critical', 'title' => 'critical',
                'guests' => 'important', 'location' => 'important', 'synopsis' => 'important',
                'title_ko' => 'important', 'mission' => 'important',
                'tags' => 'secondary', 'theme' => 'secondary', 'image_url' => 'secondary',
                'teams' => 'secondary', 'results' => 'secondary', 'special_notes' => 'secondary',
            ],
            'thresholds' => [
                'critical'  => ['update' => 90, 'fill' => 85, 'review' => 70],
                'important' => ['update' => 82, 'fill' => 75, 'review' => 60],
                'secondary' => ['update' => 75, 'fill' => 65, 'review' => 50],
            ],
        ],

        // ── Public discovery ──────────────────────────────────────
        // Finding public pages the way a site publishes them: its own
        // sitemap, its own feed, its own canonical links. Never by
        // scraping a search engine's results, and never past anything
        // that asks for a login.
        'discovery' => [
            'enabled'        => true,
            'sitemap_ttl'    => 86400,
            'max_candidates' => 6,
            'timeout'        => 12,
        ],

        // ── Optional API keys — env or config/scraping.local.php ──
        'api_keys' => [
            'tvdb' => null,
        ],

        // ── AI reasoning / synopsis generation (PR #4) ────────────
        // The AI layer is a REASONING service, not a chatbot: it is only
        // ever consulted for cases the deterministic rules leave open
        // (RmDecisionEngine's REVIEW outcome) or to draft a synopsis when
        // evidence exists but no source has written one. It never runs
        // against a complete, trusted record.
        //
        // mode: auto     — apply GENERATE/USE_SOURCE_DATA decisions that
        //                   clear the confidence threshold automatically
        //       review   — every AI decision lands in Needs Review instead
        //                   of being applied automatically (the default —
        //                   safest for a fresh install)
        //       disabled — the provider is never consulted at all
        //
        // No key configured (RM_AI_API_KEY / ANTHROPIC_API_KEY) behaves
        // exactly like every other optional source: the feature reports
        // itself unavailable and the rest of the engine works unaffected.
        'ai' => [
            'mode'       => 'review',
            'provider'   => 'anthropic',
            'model'      => 'claude-sonnet-5',
            'api_base'   => 'https://api.anthropic.com/v1/messages',
            'timeout'    => 30,
            'max_tokens' => 400,
            'thresholds' => [
                'high'   => 90,   // >= high        → HIGH CONFIDENCE, may auto-apply in 'auto' mode
                'review' => 70,   // [review, high)  → REVIEW
                                  // <  review       → REJECT / INSUFFICIENT_EVIDENCE
            ],
            'synopsis' => [
                'min_words'          => 50,
                'max_words'          => 100,
                'min_evidence_fields'=> 2,   // need at least this many usable facts to attempt one
                'banned_openers'     => [
                    'in this episode', 'in this exciting episode', 'in today\'s episode',
                    'this episode features', 'join the running man members as',
                ],
            ],
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

        foreach (['tvdb' => 'RM_TVDB_API_KEY'] as $k => $env) {
            $v = getenv($env);
            if ($v !== false && trim($v) !== '') $cfg['api_keys'][$k] = trim($v);
        }

        $aiKey = getenv('RM_AI_API_KEY') ?: getenv('ANTHROPIC_API_KEY');
        if ($aiKey !== false && trim((string)$aiKey) !== '') $cfg['ai']['api_key'] = trim($aiKey);

        $offline = getenv('RM_SCRAPE_OFFLINE');
        if ($offline !== false && $offline !== '' && $offline !== '0') $cfg['http']['offline'] = true;
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

/** What kind of source this is: primary | secondary | metadata | identity. */
function rmScrapeSourceClass(string $name): string {
    return (string)rmScrapeConfig("sources.$name.class", 'metadata');
}

/** Authority ranking of a source's class — higher may overwrite lower. */
function rmScrapeSourceRank(?string $name): int {
    if ($name === null || $name === '') return 0;
    return (int)rmScrapeConfig('class_rank.' . rmScrapeSourceClass($name), 1);
}

// A source is usable only if enabled AND (if it needs one) keyed.
function rmScrapeSourceEnabled(string $name): bool {
    if (!rmScrapeConfig("sources.$name.enabled", false)) return false;
    if (in_array($name, ['tvdb'], true)) {
        $key = rmScrapeConfig("api_keys.$name");
        return is_string($key) && trim($key) !== '';
    }
    return true;
}

/**
 * Diagnostics/verification-only source (PR #4 source policy): may
 * corroborate or contest a value but must never be the sole support for
 * a FILL or UPDATE of canonical episode metadata. See Decision.php.
 */
function rmScrapeSourceVerificationOnly(string $name): bool {
    return (bool)rmScrapeConfig("sources.$name.verification_only", false);
}
