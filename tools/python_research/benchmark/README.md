# Phase 18: Python Research Engine Benchmark

A benchmark of the existing Python Research Engine POC
(`tools/python_research/`) against a **historical sample selected from
episode numbers actually discovered THIS run**, instead of only ever
measuring "whatever is currently latest" (which the main
`run.py`/`engine.py` already does well, but which says nothing about
how each source behaves for OLDER episodes).

**This does not migrate PHP, does not modify `registry.py` or
`engine.py`, and does not make a migration decision.** It produces
evidence; a human reads that evidence and decides.

## 1. Why a historical sample instead of "just the latest"?

"The latest episode" is the one case every adapter is already optimized
for (Fandom picks the highest `Episode/N` page it finds; Wikipedia tries
the current year first). That tells you nothing about:

- Does Fandom's `Episode/N` page-naming convention hold for OLD episode
  numbers, or only recent ones?
- Does Wikipedia's per-year page structure make old episodes harder to
  find (you have to know which year to ask for)?
- Does SBS ever have anything to say about a non-current episode at all?
- How much do sources actually AGREE with each other once you're not
  just looking at the newest, best-covered case?

### The sample is selected from what this run actually discovers — never hardcoded

An earlier version of this benchmark used a fixed list (episodes 700,
750, 800, 810, 819). That silently went stale: once Fandom's real
archive only went up to episode 792, every one of those five numbers
was a guaranteed, meaningless `SOURCE_EMPTY` before any request even
ran — the benchmark was measuring nothing.

`episodes.build_dynamic_samples()` fixes this structurally:

1. Fandom's allpages listing is fetched once
   (`probes.discover_fandom_canonical_numbers()`) and its episode
   numbers are the ONLY candidate pool — a number never enters the
   sample unless this run's own discovery pass actually reported it.
2. `episodes.select_historical_sample()` deterministically picks up to
   `DEFAULT_SAMPLE_SIZE` (5) numbers spread evenly across that pool
   (same candidate pool + size -> same sample, every time — no
   randomness, no wall-clock input).
3. If the pool has fewer candidates than requested, every candidate is
   used and the reduced size is reported explicitly
   (`sample_selection.reduced`/`.actual_size` in the JSON output,
   surfaced in the Markdown report's header) — never padded with an
   invented number.
4. The highest discovered number is also always probed, explicitly
   labeled `is_current_episode: true` — this benchmark's own
   bookkeeping for "current", not a claim about the true
   broadcast-confirmed latest (that is `engine.py`'s separate,
   air-date-gated resolution, unmodified by this benchmark).
5. Wikipedia's per-episode year hint (needed because Wikipedia has no
   searchable absolute-episode-number index) is derived from **Fandom's
   own extracted `air_date`** for that same episode, once probed — real,
   sourced data, never a hardcoded/guessed table (which cannot cover a
   dynamically-selected number it didn't anticipate). An episode Fandom
   couldn't date is honestly reported as not probed on Wikipedia, rather
   than guessing a year.
6. TVmaze is still never targeted by canonical episode number, and SBS
   is still never treated as a historical archive (see §4) — this fix
   only changes *which* episode numbers are selected, not what each
   source is asked to do with them.

The FIXED list (`episodes.SAMPLE_EPISODES`/`episodes.sample()`) still
exists, but only for the isolated, low-level unit tests in
`tests/test_benchmark.py` that deliberately exercise a known structural
gap (Fandom has no page for 700) — the real end-to-end run
(`runner.py`) never uses it.

## 2. What this reuses vs. what it adds

Nothing in `models.py`, `registry.py`, `engine.py`, or `sources/*.py` is
modified. This package only ADDS new orchestration in
`tools/python_research/benchmark/` that composes the EXISTING, already-
tested building blocks differently:

| Existing building block (unmodified) | Reused for |
|---|---|
| `sources.fandom._allpages_url()` / `._parse_url()` / `._fetch_episode_page()` | Fetching and parsing ONE specific Fandom episode page instead of only the latest; also the sole discovery source for `probes.discover_fandom_canonical_numbers()` (§1) |
| `sources.wikipedia._page_url()` / `._extract_year()` | Fetching and parsing ONE specific Wikipedia year page instead of only the current/previous year |
| `sources.tvmaze.probe()` | The real, whole-show TVmaze probe, used as a REFERENCE for non-canonical air_date corroboration only |
| `sources.sbs.probe()` | The real, current-page-only SBS probe, used as a REFERENCE for current-episode applicability only |
| `sources.base.fetch_json/fetch_url/annotate_robots_access` | All HTTP + robots.txt handling — completely unchanged |
| `normalize.py`, `sources/htmlutil.py` | All field extraction — completely unchanged |
| `engine.ResearchEngine().run()` | One aggregate, CURRENT-state pass, used unmodified for the resolution/confidence/failure-classification measures |
| `registry.CANONICAL_EPISODE_SOURCES` | Read (not modified) to confirm which sources this benchmark may ever treat as canonical |

`fandom._fetch_episode_page()` and `wikipedia._extract_year()` are
technically "private" (leading underscore) module functions, imported
directly from within the same package. This was a deliberate choice:
duplicating their field-extraction logic (infobox parsing, table-header
matching) would risk the exact kind of drift this whole project has
spent two incidents fixing (TVmaze's canonical-numbering bug, the
robots.txt fetch-failure/disallow conflation) — reusing the tested code
path is safer than re-implementing a second copy of it.

## 3. Fixture vs live mode

```
python -m tools.python_research.benchmark.run_benchmark --mode fixture   # default
python -m tools.python_research.benchmark.run_benchmark --mode live
```

- **`--mode fixture`** (the default): the ONLY thing mocked is
  `urllib.request.urlopen` itself (`fixture_transport.py`), dispatched
  per URL to canned files under `benchmark/fixtures/`. Every layer above
  that — robots.txt checking, HTTP error classification, adapter
  parsing, engine resolution — runs for real, unmodified code.
- **`--mode live`**: makes real network requests, exactly like the main
  `run.py`. Whether any given source is actually reachable **depends on
  the environment this runs in and is not assumed** — see "Environment
  notes are observational" below.

Every output file is **mode-suffixed**
(`benchmark_report_fixture.json`/`.md` vs. `benchmark_report_live.json`/
`.md`) so a fixture run never silently overwrites, or gets confused
with, a live run. The Markdown report's very first line is a banner
(🔴 FIXTURE-BASED RUN / 🟢 LIVE RUN) stating which one produced it.

### Environment notes are observational, generated from THIS run

A live run's Markdown report has its own **"Environment notes (observed
this run)"** section (`report.build_environment_notes()`), generated
entirely from `aggregate_engine_report.source_results` — the real,
unmodified `engine.py` output for THIS specific run — never from a
hardcoded assumption about whatever container the benchmark happens to
execute in. Six distinct outcomes are told apart per source: a
source-side robots.txt disallow, a robots.txt *fetch* failure (not the
same thing — see `sources/base.py`'s `check_robots_allowed()` docstring
and the 2026-09-17 Fandom incident), a target request timeout, a target
HTTP failure, successful access, and source-empty/no-relevant-data.
Whether a given source is reachable **varies by environment and by
run** — a source is only ever reported as blocked in this benchmark's
own notes when THIS run's own data actually shows that.

`aggregate_engine_report.environment.note` (embedded verbatim from
`engine.py`, which this benchmark never modifies) is a separate,
**static, generic description of the build container** — it does not
vary per source or per run, and can read as stale relative to what a
specific run's `source_results` actually show (e.g. it can describe a
blanket network restriction even when several sources demonstrably
succeeded). `environment_notes.static_engine_note_mismatch` in the JSON
output flags exactly this case when it happens, so the report can be
trusted even when that embedded static text cannot. Read
`environment_notes.per_source`, not `aggregate_engine_report.environment.note`,
for what actually happened on any given run.

### All fixture content is SYNTHETIC

Every fixture under `benchmark/fixtures/` is invented test data (titles,
guest names, and dates are placeholders, several explicitly prefixed
`[SYNTHETIC]`) — **none of it is a real captured response, and none of
it should be read as a claim about the real show.** It exists purely to
exercise this benchmark's own logic reproducibly and offline. Getting a
report that reflects the REAL sources requires an actual `--mode live`
run; per "prefer cached/local fixtures where possible **after obtaining
a live sample**", the intent is that a future live run's real
(sanitized) responses would replace these synthetic ones for ongoing
reproducibility. Whether a live run from any given environment can
reach a particular source is exactly what that run's own "Environment
notes" section reports — see above, not a blanket claim made here.

The fixtures also deliberately encode two structural GAPS (Fandom has no
page for episode 700; Wikipedia's 2026 page is missing episode 800) so
the benchmark's own "not found" / "structural gap" reporting path is
exercised, not just the all-succeeds happy path.

## 4. What's measured (and how "unsupported" is reported)

The 13 measures from the Phase 18 brief map onto:

| # | Measure | Where |
|---|---|---|
| 1 | Source accessibility | `per_episode[*].{fandom,wikipedia}.accessible`, `access_classification`, `robots_outcome` |
| 2-5 | Episode number / air date / title / guest extraction | `per_episode[*].{fandom,wikipedia}.{episode_number_confirmed,air_date,title,guests}` |
| 6-7 | Location / team-result extraction | **Explicitly reported as `UNSUPPORTED_BY_ALL_ADAPTERS`** in `summary.unsupported_fields_by_all_adapters` — no adapter in `sources/*.py` extracts either field at all. This is a finding, not a benchmark gap. |
| 8 | Thumbnail availability | `per_episode[*].fandom.thumbnail_available` (Fandom has no thumbnail field at all — always `None`/absent) / TVmaze's reference probe (`aggregate_engine_report`) |
| 9 | Cross-source agreement | `per_episode[*].cross_source_agreement.{air_date,title}` (Fandom vs. Wikipedia only — the two canonical sources) |
| 10 | Resolution result | `aggregate_engine_report.resolution` — the real engine's own CURRENT-state resolution, unmodified |
| 11 | Confidence | `aggregate_engine_report.resolution.latest_episode_evidence[*].confidence` — the real engine's own confidence model, unmodified |
| 12 | Failure classification | `summary.failure_classification_tally` |
| 13 | Runtime | `timings` (per-phase wall-clock ms; fixture-mode timings are LOCAL PARSING cost only, not network latency — the report says so explicitly) |

TVmaze and SBS are reported SEPARATELY from the accessibility/field-
coverage table, not folded into it, because forcing them into the same
per-episode-number shape as Fandom/Wikipedia would require exactly the
kind of invented mapping the TVmaze-1980 incident fix refuses to make:

- **TVmaze**: `per_episode[*].tvmaze` always has `"targetable": False` —
  it is evaluated ONLY for whether its own (non-canonical) air_date data
  happens to match a date a canonical source already established for
  that episode (`corroborated_by_air_date`). It is never asked "is this
  episode N", because TVmaze cannot answer that question honestly.
- **SBS**: `per_episode[*].sbs` reports `"applicable": False` for every
  sample episode except the one explicitly marked current
  (`is_current_episode` — the highest number `episodes.build_dynamic_
  samples()` discovered this run, see §1) — SBS's adapter only ever
  reads the CURRENT program page, so asking it about a historical
  episode is a category error, not a failed extraction. The benchmark
  reports that distinction explicitly rather than scoring it as a miss.

## 5. Limitations

- **Requested sample size is 5 episodes** (`episodes.DEFAULT_SAMPLE_
  SIZE`). This is a POC-scale benchmark, not a statistically powered
  study — treat percentages in the report as illustrative, not as a
  confidence interval. The ACTUAL size can be smaller than requested
  when Fandom's discovered candidate pool has fewer than 5 numbers (see
  §1's point 3) — check `sample_selection.actual_size`/`.reduced` in
  the JSON output, or the Markdown header, rather than assuming 5.
- **Whether a live sample is obtainable depends on the environment this
  runs in** — see "Environment notes are observational" above; each
  run's own Markdown report states what it actually observed, rather
  than this README asserting a fixed answer.
- **Wikipedia's per-episode year hint is derived from Fandom's own
  extracted `air_date`** for that episode (real, sourced data — see
  §1's point 5), not from Wikipedia itself, since Wikipedia has no
  searchable absolute-episode-number index. A wrong hint (or an episode
  Fandom couldn't date, which skips Wikipedia probing entirely) reports
  as an honest gap, not a guess — but a hint derived from Fandom's own
  data can still, in principle, point at the wrong Wikipedia year page
  if Fandom's air_date extraction itself were wrong; that would report
  as `NOT_FOUND_ON_HINTED_YEAR`, indistinguishable from this
  benchmark's outside view from a genuine Wikipedia coverage gap.
- **SBS and TVmaze's roles are intentionally narrow** (see §4) — this
  benchmark does not attempt to make either of them do more than their
  underlying adapters actually support, per the project's explicit
  "do not invent missing values" / "TVmaze must never contribute
  canonical episode numbering" constraints.
- **This benchmark does not answer questions G/H/I** (what should move
  to Python, what should stay in PHP, whether migration is justified) —
  by design. It supplies evidence; a human makes that call.
