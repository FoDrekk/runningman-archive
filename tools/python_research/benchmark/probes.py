"""
benchmark/probes.py — per-episode-targeted probing for the fixed sample,
built ENTIRELY on top of the existing, already-tested adapter/normalize/
htmlutil code. No engine.py or registry.py changes; no duplicated
field-extraction logic — see each function's docstring for exactly which
existing building block it reuses.

Every function here returns one SourceProbeResult per episode number
(models.SourceProbeResult — the same type engine.py/registry.py use),
so the rest of the benchmark (runner.py/report.py) can measure a
per-episode result exactly the same way the real engine measures a
per-source result.
"""
from __future__ import annotations

import re

from ..models import FailureType, SourceProbeResult, SourceStatus
from ..sources import fandom, sbs, tvmaze, wikipedia
from ..sources.base import annotate_robots_access, fetch_json
from .episodes import EpisodeSample

NOT_APPLICABLE = "NOT_APPLICABLE"
NOT_FOUND = "NOT_FOUND"


def discover_fandom_canonical_numbers() -> tuple[SourceProbeResult, list[int]]:
    """
    Fetches Fandom's allpages listing exactly ONCE and returns (a) the
    raw SourceProbeResult for that listing request and (b) the sorted
    canonical episode numbers it actually discovered (empty when the
    listing itself failed, or parsed but yielded no "Episode/N" pages).

    This is the single source of truth for "what episode numbers exist
    on Fandom THIS run" — used both to select the historical sample
    (episodes.build_dynamic_samples()) and, below, to check per-episode
    existence before probing. Nothing here is invented: an episode
    number is only ever considered "discovered" if it came from this
    exact listing response.
    """
    list_url = fandom._allpages_url()
    listing = SourceProbeResult(
        source="fandom", url=list_url, request_status="not_attempted",
        http_status=None, response_time_ms=None,
        access_result=SourceStatus.BLOCKED, parsing_result="not_attempted",
    )
    r, data = fetch_json(list_url)
    listing.request_status = "ok" if r.ok else "failed"
    listing.http_status = r.http_status
    listing.response_time_ms = round(r.elapsed_ms, 1)
    annotate_robots_access(listing, r)

    if not r.ok:
        listing.failure = FailureType.ACCESS_FAILURE
        listing.error_detail = r.error
        return listing, []

    if not isinstance(data, dict) or "query" not in data:
        listing.parsing_result = "parse_error"
        listing.failure = FailureType.PARSER_FAILURE
        listing.error_detail = "Response missing expected 'query' envelope"
        listing.access_result = SourceStatus.REACHABLE
        return listing, []

    pages = (data.get("query") or {}).get("allpages") or []
    existing_numbers: set[int] = set()
    for p in pages:
        m = re.match(rf"^{re.escape(fandom._PAGE_PREFIX)}(\d+)$", str(p.get("title") or ""))
        if m:
            existing_numbers.add(int(m.group(1)))

    if not existing_numbers:
        listing.parsing_result = "no_relevant_data"
        listing.failure = FailureType.SOURCE_EMPTY
        listing.access_result = SourceStatus.REACHABLE
        return listing, []

    listing.extracted_episode_numbers = sorted(existing_numbers)
    listing.parsing_result = "ok"
    listing.access_result = SourceStatus.USABLE
    listing.canonical_episode_numbering = True
    return listing, sorted(existing_numbers)


def probe_fandom_sample(
    samples: list[EpisodeSample],
    discovery: tuple[SourceProbeResult, list[int]] | None = None,
) -> dict[int, SourceProbeResult]:
    """
    Reuses fandom._allpages_url()/_parse_url() and, critically,
    fandom._fetch_episode_page() UNMODIFIED for the actual field
    extraction (title/air_date/guests) — the exact same tested logic
    the real adapter uses for "the latest episode", just pointed at
    each sample number instead. The allpages listing is fetched ONCE
    for the whole sample (matching the real adapter's one-call-per-run
    cost), not once per episode.

    `discovery` lets a caller (runner.py) pass in an already-fetched
    discover_fandom_canonical_numbers() result so the listing is never
    fetched twice in one run; when omitted (as every existing caller/
    test does), the listing is fetched here, same as before.
    """
    listing, discovered_numbers = discovery if discovery is not None else discover_fandom_canonical_numbers()
    existing_numbers = set(discovered_numbers)

    out: dict[int, SourceProbeResult] = {}

    if listing.failure in (FailureType.ACCESS_FAILURE, FailureType.PARSER_FAILURE):
        # The listing itself failed -> every sample episode shares that
        # SAME access failure; none can be individually checked.
        for s in samples:
            out[s.number] = listing
        return out

    for s in samples:
        n = s.number
        if n not in existing_numbers:
            miss = SourceProbeResult(
                source="fandom", url=fandom._parse_url(f"{fandom._PAGE_PREFIX}{n}"),
                request_status="ok", http_status=listing.http_status,
                response_time_ms=0.0, access_result=SourceStatus.REACHABLE,
                parsing_result="no_relevant_data", failure=FailureType.SOURCE_EMPTY,
                warnings=[f"{fandom._PAGE_PREFIX}{n} does not exist in Fandom's allpages listing — a real, "
                          f"structural gap, not a parser bug."],
            )
            out[n] = miss
            continue

        result = SourceProbeResult(
            source="fandom", url=fandom._parse_url(f"{fandom._PAGE_PREFIX}{n}"),
            request_status="ok", http_status=200, response_time_ms=None,
            access_result=SourceStatus.USABLE, parsing_result="ok",
            extracted_episode_numbers=[n], canonical_episode_numbering=True,
        )
        fandom._fetch_episode_page(result, n)  # reuse: real, tested field extraction
        out[n] = result

    return out


def probe_wikipedia_sample(samples: list[EpisodeSample]) -> dict[int, SourceProbeResult]:
    """
    Reuses wikipedia._page_url()/_extract_year() UNMODIFIED. Each
    DISTINCT hinted year is fetched only ONCE for the whole sample
    (e.g. episodes 800/810/819 all hint 2026 -> one request), then every
    sample episode's presence in that year's extracted tables is checked.

    A sample with wikipedia_year_hint=None (runner.py could not derive
    one from Fandom's own air_date for that episode — see episodes.py's
    module docstring) is never fetched: there is no real, sourced year
    to ask Wikipedia for, and guessing one would misreport a benchmark
    limitation as a Wikipedia coverage gap. It is reported honestly as
    not attempted instead.
    """
    out: dict[int, SourceProbeResult] = {}
    by_year: dict[int, list[EpisodeSample]] = {}
    for s in samples:
        if s.wikipedia_year_hint is None:
            out[s.number] = SourceProbeResult(
                source="wikipedia", url="", request_status="not_attempted",
                http_status=None, response_time_ms=0.0,
                access_result=SourceStatus.BLOCKED, parsing_result="not_attempted",
                warnings=[f"No wikipedia_year_hint available for episode {s.number} — Fandom (this run's "
                          f"only source of a real air_date for it) did not provide one, and Wikipedia has "
                          f"no searchable absolute-episode-number index, so no year page was requested "
                          f"rather than guessing one."],
            )
            continue
        by_year.setdefault(s.wikipedia_year_hint, []).append(s)

    for year, year_samples in by_year.items():
        url = wikipedia._page_url(year)
        r, data = fetch_json(url)
        base_result = SourceProbeResult(
            source="wikipedia", url=wikipedia._human_url(year), request_status="ok" if r.ok else "failed",
            http_status=r.http_status, response_time_ms=round(r.elapsed_ms, 1),
            access_result=SourceStatus.BLOCKED, parsing_result="not_attempted",
        )
        annotate_robots_access(base_result, r)

        if not r.ok:
            base_result.failure = FailureType.ACCESS_FAILURE
            base_result.error_detail = r.error
            for s in year_samples:
                out[s.number] = base_result
            continue

        if not isinstance(data, dict) or "parse" not in data:
            base_result.parsing_result = "parse_error"
            base_result.failure = FailureType.PARSER_FAILURE
            base_result.error_detail = "Response missing expected 'parse' envelope"
            base_result.access_result = SourceStatus.REACHABLE
            for s in year_samples:
                out[s.number] = base_result
            continue

        html = str((data["parse"].get("text") or {}).get("*") or "")
        titles, dates = wikipedia._extract_year(html)  # reuse: real, tested header-based extraction

        for s in year_samples:
            n = s.number
            if n not in titles and n not in dates:
                out[n] = SourceProbeResult(
                    source="wikipedia", url=base_result.url, request_status="ok",
                    http_status=base_result.http_status, response_time_ms=0.0,
                    access_result=SourceStatus.REACHABLE, parsing_result="no_relevant_data",
                    failure=FailureType.SOURCE_EMPTY,
                    warnings=[f"Episode {n} not present on the hinted {year} page — either this "
                              f"benchmark's year hint is wrong, or Wikipedia's own coverage has a gap "
                              f"({NOT_FOUND}_ON_HINTED_YEAR)."],
                )
                continue
            out[n] = SourceProbeResult(
                source="wikipedia", url=base_result.url, request_status="ok",
                http_status=base_result.http_status, response_time_ms=0.0,
                access_result=SourceStatus.USABLE, parsing_result="ok",
                extracted_episode_numbers=[n],
                extracted_titles={n: titles[n]} if n in titles else {},
                extracted_air_dates={n: dates[n]} if n in dates else {},
                canonical_episode_numbering=True,
            )

    return out


def probe_tvmaze_reference() -> SourceProbeResult:
    """The real, UNMODIFIED tvmaze.probe() — one whole-show call, exactly as the live engine makes it."""
    return tvmaze.probe()


def tvmaze_corroboration_for(samples: list[EpisodeSample], tvmaze_result: SourceProbeResult, canonical_air_dates: dict[int, str]) -> dict[int, dict]:
    """
    Pure air_date-only corroboration — NEVER a number mapping (TVmaze
    stays non-canonical for episode numbering; see registry.py). For
    each sample episode with a canonical air_date (from Fandom/
    Wikipedia), checks whether ANY of TVmaze's non_canonical_episode_hints
    shares that exact date — reported as corroboration only, never as
    "TVmaze identified episode N".
    """
    out: dict[int, dict] = {}
    hints = tvmaze_result.non_canonical_episode_hints
    for s in samples:
        n = s.number
        canonical_date = canonical_air_dates.get(n)
        if not canonical_date:
            out[n] = {"targetable": False, "reason": "no canonical air_date available to corroborate against"}
            continue
        match = next((h for h in hints if h.get("air_date") == canonical_date), None)
        out[n] = {
            "targetable": False,  # TVmaze is NEVER a canonical-numbering target, by design
            "corroborated_by_air_date": match is not None,
            "matched_hint": match,
            "reason": "TVmaze has no canonical episode numbering (registry.CANONICAL_EPISODE_SOURCES); "
                      "this is air_date-only corroboration, never a number mapping.",
        }
    return out


def probe_sbs_reference() -> SourceProbeResult:
    """The real, UNMODIFIED sbs.probe() — SBS only ever reflects the CURRENT program page."""
    return sbs.probe()


def sbs_applicability_for(samples: list[EpisodeSample], sbs_result: SourceProbeResult) -> dict[int, dict]:
    """
    SBS has no historical archive (config.SBS_CANDIDATE_URLS are current-
    program pages only — see sbs.py's own module docstring). For every
    sample episode that is NOT the one currently live on that page, the
    honest answer is NOT_APPLICABLE, not "extraction failed".
    """
    out: dict[int, dict] = {}
    found = set(sbs_result.extracted_episode_numbers)
    for s in samples:
        n = s.number
        if not s.is_current:
            out[n] = {"applicable": False, "reason": "SBS's page reflects only the current episode; it has no historical archive for this number."}
            continue
        out[n] = {
            "applicable": True,
            "matched": n in found,
            "reason": "SBS is being evaluated for the one sample episode marked as current." if n in found
                      else "SBS was reachable but its current-page pattern did not match this episode number.",
        }
    return out
