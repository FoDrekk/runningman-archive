# Scraping Engine

How data gets into the archive, and why it can be trusted.

## The pipeline

```
detect gaps  →  pick only the sources that fill them
             →  fetch each source independently
             →  normalise  →  validate
             →  resolve field-by-field (priority + confidence)
             →  diff against the database  →  apply safety guards
             →  write in a transaction (or, in dry-run, write nothing)
             →  record provenance, changes, health, thumbnails, flags
```

Each stage is a separate class, so a failure is attributable to a stage
and a source rather than to "the scraper".

## Files

| File | Responsibility |
|---|---|
| `scrapers/*.php` | One adapter per source. Fetch and parse only — no merging, no ranking, no writes. |
| `ScrapingEngine.php` | Orchestrates the pipeline; owns the run loop and the write path. |
| `SourceRegistry.php` | The adapter list, and "which sources can fill these fields?" |
| `FieldResolver.php` | Per-**field** source priority; scores HIGH / MEDIUM / LOW / CONFLICT. |
| `DataNormalizer.php` | Guest, location, date, title and URL normalisation. |
| `DataValidator.php` | Rejects garbage before it can reach the database. |
| `DiffEngine.php` | Old vs new, plus the guards that refuse unsafe writes. |
| `Provenance.php` | Where every value came from; change log; review flags. |
| `SourceHealth.php` | Which sources are broken, and *how*. |
| `ThumbnailEngine.php` | Validated image acquisition with cross-source fallback. |
| `MissingData.php` | Gap detection and target selection per scraping mode. |
| `Integrity.php` | Duplicate suspicion, guest identity, latest-episode detection. |
| `Http.php` / `Cache.php` | Classified failures, retry policy, rate limiting, robots, TTL cache. |
| `RunLog.php` | Run records and the structured activity log. |
| `selftest.php` | Offline checks for the rules that must not silently regress. |

`includes/scraper.php` is a compatibility facade: the function names the
rest of the project has always called still exist and now delegate here.

## The rules that matter

**An existing valid value beats a new invalid one.** If a source's HTML
changes and its parser starts returning nothing, the outcome is a
`PARSER_WARNING` — never a synopsis replaced with NULL. `DiffEngine`
also refuses a title that loses its descriptor, a synopsis that shrinks
by more than half, and an air-date change proposed by a single weak
source.

**Priority is per field, not global.** The best source for air dates is
not the best source for synopses, and the only source with filming
locations has no episode titles. See `field_priority` in
`config/scraping.php`.

**Sets merge, they don't overwrite.** Guests and tags are unioned across
sources and deduplicated by identity. A guest no source mentioned this
run is *kept* and reported, not deleted.

**When unsure, flag — never destroy.** Possible duplicate episodes,
near-identical guest names and source conflicts all land in
`review_flags` for a human. Nothing is merged or deleted automatically.

**Failures are diagnosed, not just counted.** DNS, TLS, 403-blocked,
429-rate-limited, 404-missing, proxy-refused, "200 but empty" and
"reachable but parsed nothing" are distinct classes with distinct retry
policies, because they need distinct fixes.

## Configuration

`config/scraping.php` holds the defaults: sources and their trust tiers,
field priorities, cache TTLs, rate limits and safety thresholds.

Host-specific settings and API keys go in `config/scraping.local.php`
(git-ignored, merged over the defaults) — copy
`config/scraping.local.example.php` to start. Keys may also come from the
environment (`RM_TMDB_API_KEY`). **No key is ever hardcoded, and every
keyed source stays disabled and skipped without one.**

## Adding a source

1. Create `scrapers/YourScraper.php` extending `RmScraper`; implement
   `name()`, `fields()` and `episode()`.
2. Return only fields you actually found. Absent is fine; empty is not.
3. Register it in `config/scraping.php` (`sources`) with a trust tier,
   add it to the relevant `field_priority` lists, and instantiate it in
   `SourceRegistry::__construct()`.
4. Use `firstMatch()` with several selectors rather than one, and report
   `parser_warning` when a page loads but yields nothing.

A source only earns its place if it supplies information the others
don't, or independently corroborates them. More sources is not better
data.

## Sources (PR #4 policy, extended by PR12)

Ten sources, each with an explicit role. Wikipedia EN is canonical
for episode metadata; nothing silently falls back to a weaker source to
replace it, and nothing outside this list gets contacted at all —
MyDramaList, TMDB, Wikidata, AsianWiki and every other unreliable/unused
adapter discovered during the PR #4 audit were removed, not disabled.
PR12 benchmarked ~10-20 further candidates against this same bar (see
that PR's description for the full matrix) and added exactly the two
that survived it — Namuwiki, Trakt.tv, OMDb, Korean streaming platforms
and a Wikipedia-derived Kaggle dataset were all researched and rejected,
for reasons ranging from no public API and an incompatible license
(Namuwiki) to shared upstream lineage with a source already on this list
(Trakt.tv/TVDB, OMDb/IMDb) to ToS/paywall risk (streaming platforms).
"More sources is not better data" — see below — held for PR12 too.

| Source | Role | Class |
|---|---|---|
| Wikipedia (EN) | **Canonical** episode metadata | secondary |
| SBS (Official) | Broadcast verification, air dates, official thumbnails | primary |
| Wikipedia (KO) | Korean-language cross-check | secondary |
| myrunningman.com | Archive enrichment — guests, tags, location, thumbnails | secondary |
| myrm.tv | Archive enrichment — synopsis, guests, thumbnails | secondary |
| TheTVDB | Independent episode/date verification | secondary |
| KShow123 | Secondary availability check, thumbnail fallback | metadata |
| IMDb | **Diagnostics/verification only** — see below | metadata |
| Running Man Wiki (Fandom) | Fan-editorial corroboration/fill — guests, location, mission (PR12) | secondary |
| TVmaze | Zero-key episode/title/air_date corroboration only (PR12) | secondary |

## Source classes

Separate from field priority (which source *wins* a field), each source
is classified by what it *is* — and that decides what it may
**overwrite**:

| Class | Sources | May overwrite |
|---|---|---|
| `primary` | SBS | anything |
| `secondary` | Wikipedia EN/KO, myrunningman, myrm.tv, TheTVDB, Fandom, TVmaze | secondary, metadata |
| `metadata` | KShow123, IMDb | only its own earlier values |
| `identity` | *(none currently registered)* | nothing — not an episode source |

A metadata source may still *fill* an empty field. What it may not do is
replace a value the broadcaster supplied, on a day when the broadcaster
happens to be unreachable.

**IMDb is diagnostics/verification only.** It is fetched and its values
are compared against every other source, but it carries
`verification_only => true` in `config/scraping.php` and is absent from
every `field_priority` list. `RmEvidenceSet::candidates()` marks any
candidate whose *only* witnesses are verification-only sources, and
`RmDecisionEngine::decide()` refuses to FILL or UPDATE canonical
metadata from one — it can corroborate the real winner or raise a
conflict for review, never become the value written.

## AI reasoning (PR #4)

AI is a reasoning service, not a chatbot, and it is optional exactly
like a keyed source: no `RM_AI_API_KEY`/`ANTHROPIC_API_KEY` means the
layer reports itself unavailable and every caller falls back to its
own safe default. It is consulted in exactly two places:

- **`RmAiDecisionProvider`** (`AiReasoning.php`) plugs into
  `RmDecisionEngine`'s existing provider seam and is asked ONLY about
  decisions the deterministic rules already classified `REVIEW` — a
  genuine conflict between well-supported sources. It may only choose
  among the candidate values it was shown; it can never write a value
  no source actually offered.
- **`RmAiSynopsisService`** (`AiSynopsis.php`) drafts a synopsis when
  one is missing and the archive already holds enough verified facts
  (title, guests, mission, location, special notes) to write from.
  A usable existing synopsis is never rewritten. Every draft is checked
  by **`RmGroundingValidator`** (`GroundingValidator.php`) against that
  same evidence — an invented guest, location or outcome fails
  grounding, triggers one revision attempt, and is rejected outright if
  still unsupported. Confidence thresholds (`config/scraping.php` →
  `ai.thresholds`) then decide the outcome: high-confidence in `auto`
  mode is `GENERATE`, `review` mode (the default) always holds a
  grounded draft for a person, and anything below the review threshold
  is `INSUFFICIENT_EVIDENCE`. Every decision — including a refusal — is
  written to `ai_generation_log` when `database/pr4_ai_diagnostics.sql`
  is installed, so "why does EP809 have an AI-generated synopsis?" and
  "how many drafts were rejected this month?" both have an answer.

`RmFieldLock` (`FieldLock.php`, same migration) protects manually
verified data absolutely: a locked field is forced to `KEEP` before
either the deterministic engine or the AI layer ever sees a threshold.

## Source expansion (PR12)

Two new adapters, both registered `tier => 1` and placed **last** in
every `field_priority` list they appear in (`config/scraping.php`) —
neither has a production track record, so neither may outrank a source
that does. Both can only FILL a gap nothing else supplied, or
corroborate an existing value; the class-rank/overwrite-margin guards
that already protect every other secondary source protect these too.

- **`FandomScraper`** (`scrapers/FandomScraper.php`) — Running Man Wiki
  (runningman.fandom.com), read through the standard MediaWiki
  `action=parse` API (not the newer Wikimedia REST API, which is a
  Wikimedia-specific extension nothing confirms a third-party Fandom
  wiki runs). Fields come from Fandom's platform-standard "portable
  infobox" markup, read generically by label text — never by a
  bespoke, page-specific selector guessed without live verification.
  The *resolved* page title (after redirects) is checked against the
  requested episode number before any field is trusted, so a redirect
  to the wrong article can never be mistaken for that episode's data.
- **`TvMazeScraper`** (`scrapers/TvMazeScraper.php`) — api.tvmaze.com,
  deliberately narrow: episode-number/title/air_date corroboration
  only, nothing else. It needs no API key, unlike TheTVDB, which is
  why it earns a place alongside it rather than duplicating it — but
  its independence from TVDB's own data for a niche foreign show isn't
  fully confirmed, which is part of why it stays tier 1. Absolute
  episode numbers are extracted from each TVmaze episode's own name via
  the same digit-extraction heuristic already shipped in
  `TheTvdbScraper::locate()`, exposed as the pure, directly-tested
  `TvMazeScraper::absoluteEpisodeNumber()`.

Both were live-probed against their real endpoints, through the normal
`RmHttpClient` (robots.txt-respecting, no special-casing) before being
written — see `tests/pr12.php` and the PR12 description for what that
probe could and couldn't establish from a build sandbox whose network
egress is restricted to a fixed allow-list: it confirmed the requests
are constructed correctly and are refused by the sandbox's own proxy
(not by either site), the same limitation already on record from
PR10/PR11. No workaround was attempted for that restriction, in the
sandbox or in the adapters themselves — a real block from either site in
production degrades through the existing `blocked`/`fetch_failed`
classification and `SourceHealth` cool-down, exactly like any other
adapter.

## Tests

```
php includes/scraping/selftest.php   # normalisation, validation, resolution
php tests/adapter_contract.php       # every adapter × every malformed response
php tests/resolution.php             # conflicts, guests, thumbnails
php tests/ai.php                     # AI grounding, synopsis decisions, thresholds
php tests/migration.php --fresh      # the migration is additive and idempotent
php tests/integration.php            # write path, modes, dry run, cron recovery
php tests/research.php               # run state, evidence, decisions, research memory
php tests/pr12.php                   # Fandom + TVmaze: registry wiring, extraction, status
```

All are hermetic: they set `RM_SCRAPE_OFFLINE=1`, which makes the HTTP
client refuse every non-loopback request, so no test can reach a live
source — including `api.anthropic.com`, for `tests/ai.php`, which uses
a fake, injectable AI client (`RmAiClient`'s own seam) for every
GENERATE/REJECT/REQUEST_REVIEW path. Fixtures are served from a local
server in `tests/fixtures/`. `migration.php`, `integration.php`,
`research.php` and part of `ai.php` need MySQL/MariaDB and skip cleanly
without one. CI runs all of them (`.github/workflows/php.yml`).

Set `RM_SCRAPE_OFFLINE=1` yourself whenever you want to be certain a
command cannot touch a real site.

## Running

- **Admin → Scraper Centre** — status, targeted actions, dry run, logs,
  change feed, review queue.
- **Admin → Weekly Update** — the unattended run; also
  `php admin/cron.php --mode=latest --limit=8 [--dry]`.
- **Admin → Diagnostics → Single Episode Scrape Trace** — the whole
  pipeline for one episode: source → fetch → HTTP → parser → fields
  found → normalisation → validation → confidence → merge result. It
  runs read-only and writes *nothing* — not episode data, not
  provenance, not source health, not a log row.
- **Offline self-test** — `php includes/scraping/selftest.php`, or the
  button in Admin → Diagnostics.

## Database

`database/scraping_engine.sql`, `database/research_engine.sql` and
`database/pr4_source_cleanup.sql` are all additive: they create new
tables/columns and alter nothing existing. Every feature that uses them
degrades to a no-op when they are absent, so the site works either way.
Install them from the control centre or run them in phpMyAdmin, in that
order.
