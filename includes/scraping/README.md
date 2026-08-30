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

## Running

- **Admin → Scraper Centre** — status, targeted actions, dry run, logs,
  change feed, review queue.
- **Admin → Weekly Update** — the unattended run; also
  `php admin/cron.php --mode=latest --limit=8 [--dry]`.
- **Offline self-test** — `php includes/scraping/selftest.php`, or the
  button in Admin → Diagnostics. No network, no writes.

## Database

`database/scraping_engine.sql` is additive: it creates new tables and
alters nothing existing. Every feature that uses them degrades to a
no-op when they are absent, so the site works either way. Install it
from the control centre or run it in phpMyAdmin.
