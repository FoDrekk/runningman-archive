# Python Research Engine POC

A small, isolated proof-of-concept to answer one question empirically:

> Can a small Python research engine reliably discover and extract
> current Running Man episode information from accessible sources
> better than our current PHP implementation?

**This is not a migration.** Nothing here modifies the production MySQL
schema, the PHP scraping engine, or any existing PHP code. This directory
is entirely self-contained and can be deleted without affecting the
running application in any way.

## 1. Why this POC exists

The PHP scraping/research system (`includes/scraping/`) is architecturally
mature — evidence-based resolution, source health tracking, field-level
provenance, an AI reasoning layer, an archive-health auditor. But live
scraping has been producing very little usable evidence lately. Before
considering any migration work, we needed evidence for *why*: is that a
PHP implementation problem, a parser problem, a source-access
restriction, a source-data-availability problem, or genuinely
insufficient evidence anywhere? This POC exists to test the "maybe
Python's HTTP/parsing ecosystem does better" hypothesis directly,
rather than assume it.

## PHP → Python concept mapping

| PHP concept | File | Python POC equivalent | File |
|---|---|---|---|
| `RmSourceRegistry` | `SourceRegistry.php` | `SourceRegistry` | `registry.py` |
| `RmScraper` (`AbstractScraper`) | `scrapers/AbstractScraper.php` | `SourceAdapter` | `sources/base.py` |
| `RmEvidence` / `RmEvidenceSet` | `Evidence.php` | `Evidence` | `models.py` |
| `RmSourceHealth`'s per-attempt record | `SourceHealth.php` | `SourceProbeResult` | `models.py` |
| `RmFieldResolver`-style field resolution | `FieldResolver.php` | `ResearchEngine._build_evidence()` | `engine.py` |
| `RmLatestEpisode::detect()`/`detectDetailed()` | `Integrity.php` | `ResearchEngine._resolve_latest()` | `engine.py` |
| `RmNormalizer` | `DataNormalizer.php` | normalization functions | `normalize.py` |
| `AbstractScraper::classifyFetchStatus()` | `scrapers/AbstractScraper.php` | `FailureType` enum + `classify_http_error()` | `models.py`, `sources/base.py` |
| `config/scraping.php`'s `sources`/`field_priority` | `config/scraping.php` | `config.py`, `registry.FIELD_AUTHORITY` | `config.py`, `registry.py` |

This is a deliberately smaller reimplementation, not a port: e.g. the
PHP Wikipedia parser handles `rowspan` alignment across multi-part
special episodes explicitly; this POC's HTML table reader
(`sources/htmlutil.py`) does not. See **Known limitations** below.

## 2. Structure

```
tools/python_research/
  README.md
  requirements.txt        # intentionally empty — stdlib only
  run.py                  # live probe entry point (writes output/*.json)
  config.py                # source endpoints, timeouts, output paths
  models.py                # Evidence, SourceProbeResult, ResolutionResult, FailureType
  normalize.py             # pure normalization functions (episode #, date, title, guest name)
  registry.py              # SourceRegistry + field-authority ranking
  engine.py                # orchestration, resolution, confidence/rationale
  php_diagnostics.py        # best-effort reuse of PHP's own LOCAL diagnostic state
  sources/
    __init__.py             # ADAPTERS registry
    base.py                  # shared HTTP + robots.txt + error classification
    htmlutil.py              # minimal stdlib HTML table/infobox extraction
    fandom.py wikipedia.py tvmaze.py sbs.py
  tests/
    test_normalize.py        # offline
    test_engine.py            # offline
    test_adapters_offline.py  # offline, mocked HTTP
    fixtures/                 # canned JSON snippets used by the offline tests
  output/
    research_report.json     # written by run.py (git-ignored content, .gitkeep tracked)
    comparison.json           # written by run.py
```

## 3. Installing dependencies

None. Python 3.10+ standard library only — see `requirements.txt`.

## 4. Running the offline tests

These require **no internet access at all** (verified by running them
with the network deliberately broken):

```bash
cd runningman-archive
python3 -m unittest discover -s tools/python_research/tests -t .
```

55 tests, covering: episode-number normalization, air-date normalization
(5 formats), duplicate evidence, conflicting evidence (small lag vs. a
genuine >3-episode spread), an inaccessible source, an empty source, a
parser failure, resolution logic (aired vs. announced vs.
insufficient-evidence), confidence/rationale assignment, and end-to-end
report generation — plus per-adapter parsing tests against fixture JSON
(`tests/fixtures/`) for Fandom, TVmaze, Wikipedia and SBS.

## 5. Running the live research probe

```bash
cd runningman-archive/tools/python_research
python3 run.py
```

This makes **real** network requests to four public, keyless sources
(Fandom's MediaWiki API, TVmaze's API, Wikipedia's MediaWiki API, and
SBS's public program pages) — never more than one or two requests per
source, always through `urllib.request` with a real, identifying
User-Agent, and always checking `robots.txt` first (fail-closed: if
`robots.txt` itself cannot be fetched, the request is treated as
disallowed rather than proceeding). No proxy rotation, no fingerprint
spoofing, no CAPTCHA/Cloudflare/auth bypass of any kind.

It prints a terminal summary and writes:

- **`output/research_report.json`** — full detail per source (status,
  HTTP code, timing, every extracted candidate, warnings, failure
  classification, a short debug snippet on parse failures — never the
  full raw response) plus the final resolution (latest episode, air
  date, evidence list with confidence + rationale, conflicts, which
  sources returned nothing, which were inaccessible).
- **`output/comparison.json`** — the PHP-vs-Python capability
  comparison (Phase 6). The PHP half is built by shelling out to the
  repository's own `php` CLI and calling three already-existing,
  read-only PHP methods (`RmSourceRegistry::coveredFields()`,
  `RmSourceHealth::instance()->all()`, `RmMissingData::maxEpisode()`)
  — **never invented**. If `php` isn't on `PATH`, or the database isn't
  reachable, `comparison.json`'s `php` block says so explicitly instead
  of fabricating numbers.

## 6. What each output file means

`research_report.json`:
```jsonc
{
  "timestamp": "...", "environment": {...},
  "source_results": { "<source>": { /* SourceProbeResult, see models.py */ } },
  "resolution": {
    "latest_episode": 819, "latest_episode_evidence": [ /* Evidence[] */ ],
    "air_date": "2026-09-13", "conflicts": [...],
    "sources_with_no_data": [...], "sources_inaccessible": [...],
    "insufficient_evidence": false, "failures": [...]
  }
}
```

`comparison.json`: `{"php": {...}, "python": {...}}`, each with
`sources_attempted`, `sources_usable`, `latest_episode`,
`fields_extracted`, `blocking_reasons` — see Phase 6 of the task brief
for the exact shape.

## 7. How Python results should be compared with PHP

**Fairly, and only on capability — not raw speed.** The comparison this
POC produces is: which sources responded, which yielded usable data,
what fields were extracted, and *why* anything failed. A meaningful
Python advantage would look like: Python adapters reaching sources the
PHP ones currently can't (a genuinely different HTTP/TLS stack
succeeding where PHP's fails), or extracting fields PHP's parsers
currently miss on the same live page. It would **not** look like "the
Python script ran in 200ms" — this POC deliberately does not measure
that.

## 8. Known limitations

- **The build sandbox's own network policy blocked every live probe in
  this run** — see `output/research_report.json`'s `environment.note`
  and the actual run below. This is a property of the container this
  POC was built in (an organization egress proxy allow-listing only
  `pypi.org`, `api.anthropic.com`, and similar infra hosts), not a
  finding about Fandom/TVmaze/Wikipedia/SBS's own accessibility. **Run
  `run.py` from an unrestricted environment (a developer machine, or
  CI without this proxy) to get a real answer to the POC's question.**
- The HTML table/infobox reader (`sources/htmlutil.py`) is intentionally
  simpler than the PHP parser's `DOMDocument`/`DOMXPath`-based one — it
  does not handle `rowspan`-aligned rows (multi-part specials), which
  the PHP parser (`rmAlignTableRows()`) handles explicitly.
  Wikipedia/Fandom pages using that pattern may extract fewer rows here.
- The `latest_episode` resolution logic requires an air date to trust an
  episode number as "aired" (mirroring the PHP system's own
  aired-vs-announced distinction). A source that lists an episode
  number with no date at all is treated as `INSUFFICIENT_EVIDENCE`, not
  a guess — this is intentional, not a bug.
- SBS has no confirmed stable JSON API in either codebase (see
  `SbsScraper.php`'s own comment: "SBS reorganises its endpoints
  periodically"). This POC's SBS adapter only ever claims episode-number
  detection via a light text pattern — it does not claim title/date/
  guest extraction PHP itself has no confirmed selector for either.
- Confidence values in `Evidence.confidence` are this POC's own,
  documented heuristic (see `engine._confidence_and_rationale()` and
  `models.py`'s `Evidence` docstring) — a fresh design for this POC, not
  a port of `RmEvidenceSet`'s scoring algorithm.
- Tests cover the code in this directory only; they do not exercise the
  PHP system.

## 9. Why this is NOT yet a migration

Per the task brief: **first build the POC, then measure, then decide.**
This POC's own live run (below) could not reach any of the four sources
because of this build sandbox's network policy — so it answers "is the
tooling correct and ready to measure with" (yes — 55 hermetic tests
pass, the report/comparison JSON structures are complete and debuggable,
robots.txt is genuinely respected), but it does **not** yet answer the
POC's actual question ("does Python access/extract better than PHP?").
That answer requires running `run.py` from a network-unrestricted
environment. No migration recommendation should be made — by this POC
or anyone reading it — until that live run has actually happened.
See the top-level task response for the full evidence-based
recommendation.

---

## Actual run, this environment (for the record)

```
$ python3 run.py

RUNNING MAN RESEARCH POC

Source                Status       Latest EP   Air Date       Fields
--------------------------------------------------------------------
fandom                blocked      -           -              -
tvmaze                blocked      -           -              -
wikipedia             blocked      -           -              -
sbs                   blocked      -           -              -

Resolved: INSUFFICIENT_EVIDENCE — no source could confirm an aired episode number.

Evidence:
- none

Conflicts:
None

Sources with no usable data: none
Sources inaccessible: fandom, tvmaze, wikipedia, sbs
```

Every one of the four adapters failed at the `robots.txt` check itself
(fail-closed — see `sources/base.py::check_robots_allowed()`) with
`Tunnel connection failed: 403 Forbidden` — the sandbox's own egress
proxy, not the target sites. Note, from `comparison.json`'s `php`
block: the PHP system's own `source_health` table (populated by real
scraping activity from earlier sessions in this same environment)
already recorded the **identical** failure — `"Blocked by a proxy or
gateway before reaching the site (CONNECT tunnel failed, response
403)"` — for `sbs`, `wikipedia`, `kowiki`, `myrunningman`, `myrm`,
`imdb`, and `fandom`. That is direct, concrete evidence that this
specific failure mode is a sandbox network property affecting **both**
implementations identically, not something Python-vs-PHP differs on.
