# Phase 18: Python Research Engine Benchmark

A benchmark of the existing Python Research Engine POC
(`tools/python_research/`) against a **fixed sample of historical
episodes**, instead of only ever measuring "whatever is currently
latest" (which the main `run.py`/`engine.py` already does well, but
which says nothing about how each source behaves for OLDER episodes).

**This does not migrate PHP, does not modify `registry.py` or
`engine.py`, and does not make a migration decision.** It produces
evidence; a human reads that evidence and decides.

## 1. Why a fixed sample instead of "just the latest"?

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

The sample used here: **episodes 700, 750, 800, 810, 819** — a spread
across the archive's history, not a cluster at the end.

## 2. What this reuses vs. what it adds

Nothing in `models.py`, `registry.py`, `engine.py`, or `sources/*.py` is
modified. This package only ADDS new orchestration in
`tools/python_research/benchmark/` that composes the EXISTING, already-
tested building blocks differently:

| Existing building block (unmodified) | Reused for |
|---|---|
| `sources.fandom._allpages_url()` / `._parse_url()` / `._fetch_episode_page()` | Fetching and parsing ONE specific Fandom episode page instead of only the latest |
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
  `run.py`. In THIS build environment, live requests are expected to
  fail with `ACCESS_FAILURE` (an organizational egress-proxy allow-list
  that blocks Fandom/Wikipedia/TVmaze/SBS identically — see the main
  README.md's "Known limitations"). That failure is a property of this
  sandbox, not a finding about the sources. **Run `--mode live` from an
  unrestricted machine to get real answers to this benchmark's
  questions.**

Every output file is **mode-suffixed**
(`benchmark_report_fixture.json`/`.md` vs. `benchmark_report_live.json`/
`.md`) so a fixture run never silently overwrites, or gets confused
with, a live run. The Markdown report's very first line is a banner
(🔴 FIXTURE-BASED RUN / 🟢 LIVE RUN) stating which one produced it.

### All fixture content is SYNTHETIC

Every fixture under `benchmark/fixtures/` is invented test data (titles,
guest names, and dates are placeholders, several explicitly prefixed
`[SYNTHETIC]`) — **none of it is a real captured response, and none of
it should be read as a claim about the real show.** It exists purely to
exercise this benchmark's own logic reproducibly and offline. Getting a
report that reflects the REAL sources requires an actual `--mode live`
run from an unrestricted environment; per "prefer cached/local fixtures
where possible **after obtaining a live sample**", the intent is that a
future live run's real (sanitized) responses would replace these
synthetic ones for ongoing reproducibility — that live sample could not
be obtained in this environment (see above).

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
  sample episode except the one marked current
  (`benchmark/episodes.py::CURRENT_EPISODE`) — SBS's adapter only ever
  reads the CURRENT program page, so asking it about episode 700 is a
  category error, not a failed extraction. The benchmark reports that
  distinction explicitly rather than scoring it as a miss.

## 5. Limitations

- **Sample size is 5 episodes.** This is a POC-scale benchmark, not a
  statistically powered study — treat percentages in the report as
  illustrative, not as a confidence interval.
- **No live sample was obtainable from this environment** (see "Fixture
  vs live mode" above) — every current run's data is synthetic.
- **Wikipedia's year-hint mapping (`episodes.py::WIKIPEDIA_YEAR_HINT`)
  is this benchmark's own guess**, not sourced from Wikipedia itself. A
  wrong hint reports as `NOT_FOUND_ON_HINTED_YEAR`, which is
  indistinguishable, from this benchmark's outside view, from a genuine
  Wikipedia coverage gap — a live run against the real site (with a
  corrected hint table) would be needed to tell them apart.
- **SBS and TVmaze's roles are intentionally narrow** (see §4) — this
  benchmark does not attempt to make either of them do more than their
  underlying adapters actually support, per the project's explicit
  "do not invent missing values" / "TVmaze must never contribute
  canonical episode numbering" constraints.
- **This benchmark does not answer questions G/H/I** (what should move
  to Python, what should stay in PHP, whether migration is justified) —
  by design. It supplies evidence; a human makes that call.
