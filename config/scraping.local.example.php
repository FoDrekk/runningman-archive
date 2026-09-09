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
//   RM_TVDB_API_KEY=...
//   RM_AI_API_KEY=...   (or ANTHROPIC_API_KEY)
// ============================================================

return [
    // ── Optional API keys ─────────────────────────────────────
    // TheTVDB is an independent verification source for episode dates —
    // but it is OPTIONAL. With no key the source reports itself
    // disabled, is skipped entirely, and costs nothing. Get a free key
    // at thetvdb.com/api-information.
    'api_keys' => [
        'tvdb' => null,   // your TheTVDB v4 API key
    ],

    // ── AI synopsis generation / reasoning (PR #4) ─────────────
    // Also optional. With no key the AI layer reports itself
    // unavailable and every decision it would have made falls back to
    // INSUFFICIENT_EVIDENCE / REVIEW — nothing is ever blocked on it.
    // 'ai' => [
    //     'mode' => 'auto',   // 'review' (default, safest) | 'auto' | 'disabled'
    // ],

    // ── Turn individual sources off ───────────────────────────
    // Useful if a source is permanently unreachable from your network:
    // disabling it stops the engine contacting it at all.
    // 'sources' => [
    //     'kshow123' => ['enabled' => false],
    // ],

    // ── Adjust which source wins which field ──────────────────
    // These lists replace the default entirely, so include every
    // source you want considered for that field, best first.
    // 'field_priority' => [
    //     'synopsis' => ['myrunningman', 'wikipedia'],
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
