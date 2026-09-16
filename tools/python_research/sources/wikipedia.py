"""
sources/wikipedia.py — English Wikipedia adapter.

Mirrors includes/scraping/scrapers/WikipediaScraper.php's own latest-
episode strategy exactly: fetch "List of Running Man episodes (YEAR)"
for the current year via the MediaWiki action=parse API (the same
transport, not the REST surface, for identical reasons — a third-party-
independent, decades-stable API), fall back to last year if the current
year's page has no usable table yet, and read the wikitable by HEADER
LABEL rather than fixed column position (see sources/htmlutil.py and
WikipediaScraper.php's rmWikiParseHtml() for the same principle).

One request (two only if the current year's page is genuinely empty).
"""
from __future__ import annotations

import datetime
import urllib.parse

from .. import config
from ..models import FailureType, SourceProbeResult, SourceStatus
from ..normalize import normalize_air_date, normalize_episode_number, normalize_title
from .base import fetch_json, snippet
from .htmlutil import extract_wikitables

_EP_HEADER = ("ep.", "ep", "no.", "no", "#")
_TITLE_HEADER = "title"
_DATE_HEADER = ("air date", "airdate", "release date", "broadcast")


def _page_url(year: int) -> str:
    title = f"List of Running Man episodes ({year})"
    return f"{config.WIKIPEDIA_BASE}/w/api.php?" + urllib.parse.urlencode({
        "action": "parse", "page": title, "prop": "text",
        "format": "json", "disablelimitreport": 1, "disableeditsection": 1,
    })


def _human_url(year: int) -> str:
    title = f"List_of_Running_Man_episodes_({year})".replace(" ", "_")
    return f"{config.WIKIPEDIA_BASE}/wiki/{title}"


def _extract_year(html: str) -> tuple[dict[int, str], dict[int, str]]:
    """Returns (titles_by_ep, dates_by_ep) from whichever wikitable has an episode-number column."""
    titles: dict[int, str] = {}
    dates: dict[int, str] = {}
    for table in extract_wikitables(html):
        if len(table) < 2:
            continue
        header = [c.lower().strip() for c in table[0]]
        col = {"ep": -1, "title": -1, "date": -1}
        for i, h in enumerate(header):
            if h in _EP_HEADER or "series no" in h or "no. overall" in h:
                col["ep"] = i
            elif _TITLE_HEADER in h and col["title"] == -1:
                col["title"] = i
            elif any(k in h for k in _DATE_HEADER):
                col["date"] = i
        if col["ep"] < 0:
            continue
        for row in table[1:]:
            if col["ep"] >= len(row):
                continue
            ep_num = normalize_episode_number(row[col["ep"]])
            if ep_num is None:
                continue
            if col["title"] >= 0 and col["title"] < len(row):
                t = normalize_title(row[col["title"]], ep_num)
                if t:
                    titles[ep_num] = t
            if col["date"] >= 0 and col["date"] < len(row):
                d = normalize_air_date(row[col["date"]])
                if d:
                    dates[ep_num] = d
        if titles or dates:
            break  # first table that actually yields episode data wins
    return titles, dates


def probe() -> SourceProbeResult:
    this_year = datetime.date.today().year
    years_to_try = [this_year, this_year - 1][: 1 + config.CURRENT_YEAR_CANDIDATES_BACK]

    last_result: SourceProbeResult | None = None
    prior_attempts: list[str] = []
    for year in years_to_try:
        url = _page_url(year)
        result = SourceProbeResult(
            source="wikipedia", url=_human_url(year), request_status="not_attempted",
            http_status=None, response_time_ms=None,
            access_result=SourceStatus.BLOCKED, parsing_result="not_attempted",
        )
        r, data = fetch_json(url)
        result.request_status = "ok" if r.ok else "failed"
        result.http_status = r.http_status
        result.response_time_ms = round(r.elapsed_ms, 1)

        if not r.ok:
            result.failure = FailureType.ACCESS_FAILURE
            result.error_detail = r.error
            prior_attempts.append(f"{year}: ACCESS_FAILURE — {r.error}")
            last_result = result
            continue

        if not isinstance(data, dict) or "parse" not in data:
            reason = None
            if isinstance(data, dict):
                reason = (data.get("error") or {}).get("info")
            result.parsing_result = "no_relevant_data" if reason else "parse_error"
            result.failure = FailureType.SOURCE_EMPTY if reason else FailureType.PARSER_FAILURE
            result.error_detail = reason or "Response missing expected 'parse' envelope"
            result.raw_snippet = snippet(r.body)
            result.access_result = SourceStatus.REACHABLE
            prior_attempts.append(f"{year}: {result.failure.value} — {result.error_detail}")
            last_result = result
            continue

        html = str((data["parse"].get("text") or {}).get("*") or "")
        if not html.strip():
            result.parsing_result = "no_relevant_data"
            result.failure = FailureType.SOURCE_EMPTY
            result.warnings.append(f"{year} page resolved but returned no body content")
            result.access_result = SourceStatus.REACHABLE
            prior_attempts.append(f"{year}: SOURCE_EMPTY — page resolved but returned no body content")
            last_result = result
            continue

        titles, dates = _extract_year(html)
        if not titles and not dates:
            result.parsing_result = "no_relevant_data"
            result.failure = FailureType.SOURCE_EMPTY
            result.warnings.append(f"{year} page parsed but no wikitable yielded an episode-number column")
            result.access_result = SourceStatus.REACHABLE
            prior_attempts.append(f"{year}: SOURCE_EMPTY — no wikitable yielded an episode-number column")
            last_result = result
            continue

        result.extracted_titles = titles
        result.extracted_air_dates = dates
        result.extracted_episode_numbers = sorted(set(titles) | set(dates))
        result.parsing_result = "ok"
        result.access_result = SourceStatus.USABLE
        if prior_attempts:
            result.warnings.append("Earlier year(s) tried first: " + "; ".join(prior_attempts))
        return result

    # Every year tried failed or was empty — return the most informative
    # (last) attempt, but never silently lose what the earlier year(s) did.
    if last_result is not None and len(prior_attempts) > 1:
        last_result.warnings = prior_attempts[:-1] + last_result.warnings
    return last_result
