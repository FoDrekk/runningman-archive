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
| `ThumbnailEngine.php` | Validated, scored image acquisition with cross-source fallback and a six-state classifier — see below. |
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

## Latest episode detection & the research queue (PR13)

Seven distinct concepts, each answering a different question. Conflating
any two of them is the exact bug class PR13 exists to close:

| Concept | Answers | Where |
|---|---|---|
| **Archive Latest** | The highest episode number actually stored in the database. A fact about the archive, not a claim about the real world. | `RmMissingData::maxEpisode()` |
| **Latest Verified Aired** | The highest episode number at least one live source resolved a real, past-or-today air date for, through the full evidence pipeline. Never a raw "sources mention this number" vote. | `RmLatestEpisode::detectDetailed()['latest_aired']` |
| **Upcoming** | An announced episode whose resolved air date is in the future. Never counted as aired, never counted as missing. | `detectDetailed()['upcoming']` |
| **Missing Episode** | A specific episode number with confirmed evidence of existing (a resolved air date) that has no row in the database yet. A coverage gap. | `detectDetailed()['missing_aired']`, `RmResearchState::attention()['new']` |
| **Incomplete Metadata** | A row that exists, but is missing a non-identity field (synopsis, guests, …). A metadata gap, not a coverage gap — the episode is real either way. | `RmMissingData::incompleteEpisodes()` |
| **Research State** | What was already tried for one episode, and when it's worth trying again — never "does it exist". | `RmResearchState` (PR11) |
| **Source Health** | Whether a source itself is currently reachable — orthogonal to whether any given episode has evidence. | `RmSourceHealth` |

**Evidence-based, always.** `RmLatestEpisode::detect()`/`detectDetailed()`
(PR9/PR10) gather every signal a source can offer (each adapter's own
`latestEpisode()`, plus a short gap-probe past the highest reported
number) and REPORT DISAGREEMENT rather than average or pick a favourite:
a spread of more than a couple of episodes across sources is a
`SOURCE_DISAGREEMENT`, not a vote. Every raw number is then indepen-
dently re-verified — the full `collect()` + `RmFieldResolver::resolve()`
pipeline runs against each candidate past the archive max, and it is
classified by its *resolved air date* against today's date, never by
its mere existence as a number: `air_date <= today` is `missing_aired`
(a real gap), `air_date > today` is `upcoming`, no resolvable air date
at all is `insufficient_evidence`. No external signal → `SOURCE_
UNAVAILABLE`, reported as `UNKNOWN`, never invented.

**`DB latest + 1` is explicitly NOT a valid detection strategy.** It
appears nowhere in this codebase as a way to decide what episode comes
next — the two places that ever loop from `$dbMax + 1` are
`RmMissingData::targetsFor('latest', …)`, which only builds a *candidate
search space* for `detectDetailed()` to independently verify (nothing
in that range is trusted until re-checked), and, before PR13,
`RmResearchState::buildQueue('new', …)` — which queued that whole raw
range as if every number in it were confirmed. **That was a real gap,
and PR13 closed it**: `buildQueue('new', …)` now requires the caller's
`missing_aired` list — the SPECIFIC episode numbers `detectDetailed()`
already independently confirmed a real air date for — and queues only
those. A confirmed EP819 with EP818 still unconfirmed queues EP819
alone; EP818 is never interpolated in just because it sits between two
known numbers. `admin/auto_sync.php`'s `run_start` handler for
`scope=new` reads this list from its own cached detection state rather
than trusting any client-supplied number, for the same reason.

**A new episode record requires a resolved air date, full stop.**
`RmScrapingEngine::plan()` will not treat a brand-new episode candidate
as real just because some source returned evidence for a field *other*
than `air_date` (a stray location match, say) — `insertEpisode()`'s
placeholder-title/NULL-date fallback existed for the normal "existing
episode, filling a gap" path and was never meant to create a row from
nothing. PR13 added the explicit guard: no resolved `air_date` on a
new-episode candidate means the plan is refused (`failed`, nothing
staged to `apply`) before a single row can be written — see
`tests/pr13.php`.

**The queue is bounded, prioritised and cool-down-aware** —
`RmResearchState::buildQueue()`/`priority()`/`queueReasons()` (PR11),
unchanged in shape by PR13 beyond the `'new'` scope fix above and two
added priority bands (`research_failed` once its cool-down has passed,
and `researched_needs_review`, for when it's surfaced via an explicit/
forced scope — `REVIEW` stays excluded from the ordinary auto-built
queue entirely, per `isEligible()`). Every run still goes through the
same `RmResearchService::startRun()` → `RmResearchRun` lifecycle, the
same per-source field-capability selection (`RmSourceRegistry::
sourcesForFields()`), the same dry-run/safe-write path
(`RmScrapingEngine::apply()` with `dry_run` — identical code, no writes)
and the same `max_per_run` cap — nothing here runs an unbounded resync,
and nothing in PR13 changes that.

## Thumbnail recovery & quality (PR14)

`RmThumbnailEngine` already did most of what a thumbnail system needs
before this PR: validated real image bytes rather than trusting HTTP 200
(HTML served as `image/jpeg`, a tracking pixel, and a truncated download
were all already caught), fell back across every candidate source in
`field_priority.image_url` order, never displaced a good stored file
with a bad fetch, skipped re-downloading identical bytes, flagged
byte-identical images shared across episodes for human review (never
auto-deleted), and resolved Windows/XAMPP paths correctly
(`absolutePath()`'s regex only ever matches a strict `ep<digits>.<ext>`
filename, which makes path traversal impossible by construction, not
merely filtered). PR14 did not rebuild any of that — it closed the
specific, narrow gaps a full audit actually found.

**The six-state model** — `RmThumbnailEngine::classify()` — is a thin
aggregator over signals the class already computed, not a parallel
system:

| State | Meaning | Composed from |
|---|---|---|
| `VALID` | A real, decodable, non-problematic file. | `verify()` |
| `BROKEN` | The database points at a file; the file isn't there. | `verify()` |
| `MISSING` | No thumbnail recorded for this episode at all. | `verify()` |
| `INVALID` | The file is there, but fails image validation (corrupted, truncated, not decodable). | `verify()` |
| `DUPLICATE` | Byte-identical to another episode's image, and `classifyDuplicate()` doesn't consider that legitimate. | `findDuplicate()` + `classifyDuplicate()` |
| `SUSPECT_DUPLICATE` | Visually similar (not byte-identical) to another episode's image — confidence insufficient for automatic action. | `findNearDuplicate()` |

`BROKEN` and `INVALID` used to be the same status (`broken`) — a
missing-file problem and a corrupted-file problem are different things
with different fixes, so `verify()` now tells them apart. A
byte-identical image shared between **adjacent** episode numbers
(`classifyDuplicate()` → `LEGITIMATE_SHARED_IMAGE`, the real two-part-
special case) is reported as `VALID`, not `DUPLICATE` — **duplicate is
not automatically wrong**, and this model never pretends otherwise.

**Near-duplicate detection** (`perceptualHash()` / `hammingDistance()` /
`findNearDuplicate()`) is new: a deterministic dHash (9×8 grayscale
gradient → 64 bits) computed alongside the existing SHA-1 content hash
at store time. Two images compress to different bytes but decode to the
same or near-identical gradient (a re-compression, a minor crop) land a
few bits apart; two genuinely different images land dozens of bits
apart. Like exact-duplicate detection, this only ever labels a group for
`admin/thumbnails.php` to show — nothing here deletes, merges or
replaces anything automatically.

**Candidate scoring** (`scoreCandidate()`) answers a question raw byte
validation can't: a technically valid, decodable image can still be the
wrong one to trust — well below the target resolution, from a source
with a poor track record, or a URL that structurally looks like it names
a different episode (`looksRelevant()` — a heuristic that only ever
lowers confidence, checked against the candidate's URL **path** only, so
a port number or query string can never masquerade as an episode
number). `acquire()` now refuses a candidate scoring below
`thumbnail.min_score` even after it passes every byte-level check —
"select only a sufficiently reliable candidate" from a set of otherwise-
valid ones. `RmValidator::imageBytes()` also gained an aspect-ratio
sanity bound (`thumbnail.min_aspect_ratio`/`max_aspect_ratio`): a 1000×50
banner strip clears every dimension minimum but is not a thumbnail.

**Dry run** — `acquire($ep, $year, $candidates, ['dry_run' => true])` —
runs the real fetch, real byte validation and real scoring, then stops:
nothing is written to disk, no `thumbnail_meta`/`thumbnails` row changes,
no provenance is recorded. `admin/thumbnails.php`'s `a=preview` action
and its "Dry run" button expose exactly this.

**Source capability, unchanged from PR12**: thumbnail candidates come
from whichever sources declare `image_url` in `field_priority` — TVmaze
and Fandom both correctly stay absent (neither adapter offers
`image_url` in its `fields()`), so neither is ever asked for a
thumbnail. This was already true before PR14; nothing needed to change.

**Output format**: still JPEG via GD (`imagejpeg()`), resized/cropped to
`thumbnail.target_w`×`target_h`. There is no WebP conversion anywhere in
this codebase to preserve — stated plainly here rather than assumed.

`database/pr14_thumbnail_quality.sql` (new, additive, mirrors the
`pr4_source_cleanup.sql`/`pr12_source_expansion.sql` precedent) adds the
`perceptual_hash` column to `thumbnail_meta` for a database that
installed `scraping_engine.sql` before this PR; the base file is also
updated directly for fresh installs. Existing rows are untouched —
`perceptual_hash` simply starts `NULL` until an episode's thumbnail is
next verified or re-acquired.

## AI metadata intelligence & grounded synopsis (PR15)

**AI is not a source of truth, and AI-generated metadata must be
grounded in evidence.** PR15 did not rebuild the AI layer — the
pipeline PR #4 already built (external sources → evidence → resolution
→ AI reasoning → validation → review/safe write) already matched the
brief: `RmAiSynopsisService` already refused to draft below a minimum
fact count, already ran every draft through `RmGroundingValidator`
before trusting it, and already gated automatic *synopsis* generation
on `ai.mode === 'auto'` plus a high-confidence threshold. The audit
found four narrow, real gaps in that pipeline and fixed only those:

- **The conflict-resolution provider had no mode gate.**
  `RmAiDecisionProvider::decide()` (`AiReasoning.php`) mapped a
  confident `USE_SOURCE_DATA` reply straight to `UPDATE` — an
  auto-writable decision (`RmDecision::isSafe()`) — using only the
  `ai.thresholds.review` bar, with no check of `ai.mode` at all. Unlike
  `RmAiSynopsisService`'s GENERATE path, a source-conflict resolution
  could reach an unattended write even in the default `review` mode.
  Fixed to mirror the synopsis path exactly: `USE_SOURCE_DATA` becomes
  `UPDATE` only when `ai.mode === 'auto'` **and** confidence clears the
  stricter `ai.thresholds.high` bar; otherwise it is `REVIEW`, exactly
  like every other unresolved conflict. The existing safeguard that
  refuses a chosen source no candidate actually offered is unchanged.
- **"Accept" in the review inbox didn't write anything.**
  `RmDecisionEngine::resolveReview($db, $id, 'accepted')` only ever
  relabelled the `research_decisions` row's `review_status` — the
  admin "Accept" button never touched the episode. That meant an
  approved AI-drafted synopsis (or any other accepted conflict
  resolution) could never actually reach the archive; the pipeline's
  final "review → safe write" step was a dead end. Fixed: accepting
  now performs the write for the scalar fields it's safe to write
  automatically (`title`, `air_date`, `synopsis`, `mission`,
  `special_notes`, `teams`, `results` — the same column map
  `RmScrapingEngine::updateEpisode()` already trusts), respects
  `RmFieldLock` absolutely, and records field-level provenance
  (`source: admin_review`). A field type that needs identity
  resolution instead of a plain column write (`guests`, `location`) is
  reported honestly — "needs identity resolution... edit it directly
  on the episode" — never silently dropped. `resolveReview()` now
  returns `{ok, note?, error?}` instead of a bare bool so the admin UI
  can show that message.
- **`ai_generation_log.applied` was always `0`.** `consider()` logs its
  decision before the caller has attempted the actual write — whether
  a `GENERATE` decision survives `RmDecision::isSafe()` and the
  anomaly veto is decided later, in `RmResearchService`. The provenance
  audit trail (Section 6 of the brief) is only honest if `applied`
  reflects what actually happened, so `RmAiSynopsisService::markApplied()`
  is now called once the write is confirmed, updating the most recent
  log row for that episode/run.
- **"Regenerate" had nothing to attach to.** `consider()`'s own
  `force` option (bypass "a usable synopsis already exists") was never
  threaded through from `RmResearchService::researchEpisode()`. Fixed
  via a new `regenerate_synopsis` research option, wired to a
  "Regenerate synopsis" button in Auto Sync's preview panel — every
  other AI safety gate (evidence, grounding, mode, thresholds) still
  applies to the redraft exactly as it does to the first draft.

**AI modes, unchanged and now correctly enforced everywhere they
apply**: `disabled` never calls AI at all; `review` (the existing,
preserved default) always produces a proposal for a human, never an
unattended write, for *both* AI use — synopsis drafting and conflict
resolution; `auto` allows an unattended write only past the strict
`high` threshold. Auto Sync's stat grid now shows the current AI
mode/availability at a glance (`AI synopsis` tile, reads directly from
`RmAiClient` — no API keys, no complicated dashboard, just the same
config already driving the engine).

**Cost/usage bounds, audited, not changed**: AI is only ever invoked
per-episode, from `RmResearchService::researchEpisode()`, triggered by
an admin action (Auto Sync's `preview`/`apply_safe`) or a single
episode inside a bounded, admin-triggered scraper run — never from
`admin/cron.php`'s unattended weekly update, which calls
`RmScrapingEngine` directly and has no AI integration at all. There is
no code path that calls AI across the whole archive automatically.

**Field-level, unchanged**: AI only ever proposes one field at a time —
`synopsis` for `RmAiSynopsisService`, whichever single field a
conflict concerns for `RmAiDecisionProvider` — and each field keeps its
own decision, confidence and provenance; nothing here ever replaces a
full episode record.

## Archive verification & health (PR16)

**Archive Health is a diagnostic system. It does not automatically
repair the archive.** SCAN → DETECT → CLASSIFY → EXPLAIN → RECOMMEND,
never SCAN → AUTO FIX. `RmArchiveHealth` (`ArchiveHealth.php`) is a
thin, read-only aggregator — PR16 did not rebuild integrity checking;
every domain below is built entirely from a class PR10–PR15 already
shipped:

| Domain | Built from |
|---|---|
| A. Episode Coverage | `RmResearchState::archiveCoverage()` (PR11/PR13) + `RmDuplicateDetector::fullCheck()` (PR #4) |
| B. Metadata Integrity | `RmMissingData::coreCompleteness()`/`fieldGapCounts()` (PR11) + `fullCheck()`'s date/reference checks |
| C. Research Integrity | `RmResearchState::archiveHealth()` + `attention()` (PR11/PR13) |
| D. Thumbnail Integrity | `RmThumbnailEngine::classify()` — the six-state model (PR14) |
| E. Source Health | `RmSourceHealth::all()` (PR10) |
| F. Latest Episode Verification | `RmLatestEpisode::detectDetailed()` (PR13) |
| G. Provenance / AI Application Integrity | **new** — see below |

Domain G is the one genuinely new check: nothing before PR16 audited
whether AI/decision provenance is internally *consistent*, only
whether it was *recorded*. It checks structural invariants that the
write-gates in `Decision.php`/`AiSynopsis.php`/`AiReasoning.php`
(PR15) are supposed to guarantee — an `ai_generation_log` row marked
`applied` despite `grounding_status = 'rejected'`, an `applied` row
whose logged decision was never `GENERATE`, a `research_decisions` row
marked `applied` for a decision other than `UPDATE`/`FILL`
(`RmDecision::isSafe()`'s own vocabulary), or an `applied` synopsis
whose field is empty now. A hit here means a write-gate was bypassed —
a bug or a manual edit — not a normal archive condition.

**Every issue** (`RmArchiveHealth::scan()`'s `issues` array) carries:
`id`, `domain`, `severity` (`INFO`/`WARNING`/`ERROR`/`CRITICAL`),
`episode` (nullable — many issues are archive-wide, not per-episode),
`description`, `evidence`, `recommended_action` (always an existing
controlled workflow — Auto Sync, Thumbnail Recovery, the review inbox
— never a button that runs one), `safe_to_auto_repair` (always
`false` today — nothing here writes), and `detected_at`.
`RmArchiveHealth::filterIssues()` is a pure filter over that list
(severity/domain/episode/id) — the admin page filters client-side over
the same array rather than re-deriving anything, so a filtered view
can never disagree with the full one.

**No blended numeric health score.** The brief is explicit that a
score without a shown calculation is worse than no score
("`Archive Health: 87%` without showing how" is exactly what not to
build) — this PR ships the domain-by-domain status breakdown instead
(`GOOD`/`WARNING`/`ERROR`/`CRITICAL`/`EMPTY`/`UNKNOWN`/`NOT_INSTALLED`,
plus semantic labels for Latest Verification —
`VERIFIED`/`CONFLICT`/`MISSING_AIRED_EPISODES`/`UNKNOWN`). Explainability
over a cosmetic number.

**Never invents confidence.** Two examples the audit specifically
found and fixed while building this:
- *Latest-episode verification* only ever reports what
  `RmLatestEpisode::detectDetailed()` (or a cached one) actually
  established — `SOURCE_UNAVAILABLE`/`NOT_CHECKED`/
  `INSUFFICIENT_EVIDENCE` all map to the honest `UNKNOWN`, never a
  guessed episode number.
- *Source health* only counts a source as "independent evidence
  remains available" when it is **confirmed** `ok` — a source that has
  simply never been tried (`unknown`) does not count toward that
  reassurance, even though it isn't confirmed broken either. Counting
  "untested" as "available" would be exactly the invented confidence
  Section 7/10 of the brief warns against.

**Read-only, always, and bounded.** No method in `ArchiveHealth.php`
writes anything. Thumbnail classification and the provenance audit are
capped (`archive_health.thumbnail_scan_limit` /
`.provenance_scan_limit`, both in `config/scraping.php`) so an explicit
scan on a large archive stays fast and predictable — the report says
`capped: true` when it only covered part of the archive rather than
silently under-reporting. Live latest-episode verification is opt-in
only (`?fresh=1` on Admin → Archive Health, exactly like Auto Sync's
own "Detect Latest" button) — a scan never contacts a network source
on its own.

**Empty/partial archives are handled honestly.** Zero episodes reports
`EMPTY`, not a vacuous "100% healthy" — Section 19's exact requirement.
Every domain degrades the same way every class in this codebase
already does when a table or connection is missing (`NOT_INSTALLED` /
`UNKNOWN`, never a fabricated `GOOD`).

**Admin UI** — `admin/archive_health.php` (linked from the sidebar and
the dashboard's Quick Actions): seven domain cards, an "Attention
Required" issue list with severity/domain/episode filters, and two
buttons — "Run Health Scan" (DB-only) and "Scan + Verify Latest Live"
(the one opt-in network call). No repair actions; every recommendation
is a link to an existing page.

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
php tests/pr13.php                   # new-scope queue evidence, new-episode air_date guard, priority bands
php tests/pr14.php                   # thumbnail classify() states, perceptual hash, scoring, dry-run
php tests/pr15.php                   # AI mode gating (conflict resolution), review-inbox safe write, applied provenance, regenerate
php tests/pr16.php                   # archive health: 7 domains, severity, determinism, read-only, empty-archive, provenance invariants
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
