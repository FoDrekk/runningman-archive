<?php
// ============================================================
// OPTIONAL host-specific scraping configuration.
//
// Copy this file to config/scraping.local.php (which is git-ignored)
// and fill in only what you want to change. It is merged OVER the
// defaults in config/scraping.php, so anything you leave out keeps
// its default value.
//
// The archive is fully functional without this file. Its only
// required job is holding API keys, which must never be committed.
// You can also supply them as environment variables instead:
//   RM_TMDB_API_KEY=...
// ============================================================

return [
    // ── Optional API keys ─────────────────────────────────────
    // TMDB is a genuinely useful extra source for episode stills,
    // English overviews and air dates — but it is OPTIONAL. With no
    // key the source reports itself disabled, is skipped entirely,
    // and costs nothing. Get a free key at themoviedb.org.
    'api_keys' => [
        'tmdb' => null,   // 'your-tmdb-v3-key' or a v4 read token
    ],

    // ── Turn individual sources off ───────────────────────────
    // Useful if a source is permanently unreachable from your network:
    // disabling it stops the engine contacting it at all.
    // 'sources' => [
    //     'mydramalist' => ['enabled' => false],
    // ],

    // ── Adjust which source wins which field ──────────────────
    // These lists replace the default entirely, so include every
    // source you want considered for that field, best first.
    // 'field_priority' => [
    //     'synopsis' => ['myrunningman', 'mydramalist', 'wikipedia'],
    // ],

    // ── Be gentler (or less gentle) with request pacing ────────
    // 'http' => [
    //     'default_delay_ms' => 1500,   // minimum gap between hits on one host
    //     'timeout'          => 20,
    //     'max_retries'      => 1,
    // ],

    // ── Cache lifetimes, in seconds ───────────────────────────
    // 'cache' => [
    //     'ttl_recent'    => 600,      // anything about the newest episodes
    //     'ttl_reference' => 1209600,  // old, settled episodes
    // ],
];
