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

## Sources (PR #4 policy)

Eight sources, each with an explicit role. Wikipedia EN is canonical
for episode metadata; nothing silently falls back to a weaker source to
replace it, and nothing outside this list gets contacted at all —
MyDramaList, TMDB, Wikidata, AsianWiki and every other unreliable/unused
adapter discovered during the PR #4 audit were removed, not disabled.

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

## Source classes

Separate from field priority (which source *wins* a field), each source
is classified by what it *is* — and that decides what it may
**overwrite**:

| Class | Sources | May overwrite |
|---|---|---|
| `primary` | SBS | anything |
| `secondary` | Wikipedia EN/KO, myrunningman, myrm.tv, TheTVDB | secondary, metadata |
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

## Tests

```
php includes/scraping/selftest.php   # normalisation, validation, resolution
php tests/adapter_contract.php       # every adapter × every malformed response
php tests/resolution.php             # conflicts, guests, thumbnails
php tests/migration.php --fresh      # the migration is additive and idempotent
php tests/integration.php            # write path, modes, dry run, cron recovery
```

All five are hermetic: they set `RM_SCRAPE_OFFLINE=1`, which makes the
HTTP client refuse every non-loopback request, so no test can reach a
live source. Fixtures are served from a local server in
`tests/fixtures/`. The last two need MySQL/MariaDB and skip cleanly
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
