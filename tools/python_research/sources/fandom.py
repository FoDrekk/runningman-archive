"""
sources/fandom.py — Running Man Wiki (Fandom) adapter.

Mirrors includes/scraping/scrapers/FandomScraper.php's transport choice
(the universal MediaWiki `action=parse`/`action=query` API, never the
Wikimedia-specific REST surface) and its confirmed page-title convention
(`Episode/N`), and its generic "read the portable infobox by label text,
not position" extraction principle — see sources/htmlutil.py.

Two requests, same bound as the PHP adapter's per-episode cost:
  1. action=query&list=allpages&apprefix=Episode/ — a real, public
     MediaWiki API call that lists every "Episode/N" page that exists,
     from which every advertised episode number is extracted directly
     (no guessing, no scraping a rendered page for this part).
  2. action=parse on the single highest-numbered page found — to show
     what per-episode field extraction (title/air_date/guests/location/
     mission) actually looks like for the latest episode, exactly like
     FandomScraper::episode() does for one PHP-side episode.
"""
from __future__ import annotations

import re
import urllib.parse

from .. import config
from ..models import FailureType, SourceProbeResult, SourceStatus
from ..normalize import normalize_air_date, normalize_episode_number, normalize_guest_name, normalize_title
from .base import annotate_robots_access, fetch_json, snippet
from .htmlutil import extract_infobox_pairs

_PAGE_PREFIX = "Episode/"

# Matches this infobox's air-date LABEL only (never page-wide text — see
# extract_infobox_pairs(), which already scopes every (label, value)
# pair to one portable-infobox pi-item). "air date"/"broadcast"/
# "original air" are substring matches (existing behavior, unchanged).
# "date" is matched EXACTLY (^date$, not a substring) — older episode
# pages (confirmed live: Episode/385, /400, /514) label this field just
# "Date", but a substring match on "date" would also catch an unrelated
# infobox label like "Filming Date" or "Release Date" were one ever
# added; requiring the bare, exact label keeps this targeted to the
# one confirmed real-world convention instead of guessing at others.
_AIR_DATE_LABEL_RE = re.compile(r"^date$|air.?date|broadcast|original air")


def _allpages_url() -> str:
    return f"{config.FANDOM_BASE}/api.php?" + urllib.parse.urlencode({
        "action": "query", "list": "allpages", "apprefix": _PAGE_PREFIX,
        "apnamespace": 0, "aplimit": 500, "format": "json",
    })


def _parse_url(page_title: str) -> str:
    return f"{config.FANDOM_BASE}/api.php?" + urllib.parse.urlencode({
        "action": "parse", "page": page_title, "prop": "text|displaytitle",
        "format": "json", "disablelimitreport": 1, "disableeditsection": 1,
    })


def probe() -> SourceProbeResult:
    list_url = _allpages_url()
    result = SourceProbeResult(
        source="fandom", url=list_url, request_status="not_attempted",
        http_status=None, response_time_ms=None,
        access_result=SourceStatus.BLOCKED, parsing_result="not_attempted",
    )

    r, data = fetch_json(list_url)
    result.request_status = "ok" if r.ok else "failed"
    result.http_status = r.http_status
    result.response_time_ms = round(r.elapsed_ms, 1)
    annotate_robots_access(result, r)

    if not r.ok:
        result.failure = FailureType.ACCESS_FAILURE
        result.error_detail = r.error
        return result

    if not isinstance(data, dict) or "query" not in data:
        result.parsing_result = "parse_error"
        result.failure = FailureType.PARSER_FAILURE
        result.error_detail = "Response missing expected 'query' envelope"
        result.raw_snippet = snippet(r.body)
        result.access_result = SourceStatus.REACHABLE
        return result

    pages = (data.get("query") or {}).get("allpages") or []
    numbers: list[int] = []
    for p in pages:
        title = str(p.get("title") or "")
        m = re.match(rf"^{re.escape(_PAGE_PREFIX)}(\d+)$", title)
        if m:
            n = normalize_episode_number(m.group(1))
            if n is not None:
                numbers.append(n)

    if not numbers:
        result.parsing_result = "no_relevant_data"
        result.failure = FailureType.SOURCE_EMPTY
        result.warnings.append(f"allpages returned {len(pages)} page(s) but none matched 'Episode/<number>'")
        result.access_result = SourceStatus.REACHABLE
        return result

    result.extracted_episode_numbers = sorted(set(numbers))
    result.parsing_result = "ok"
    result.access_result = SourceStatus.USABLE
    # Structurally verified: these numbers came from the wiki's own
    # "Episode/N" page-naming convention (a strict, full-string match),
    # not a bare digit found in unnarrowed text — see
    # registry.CANONICAL_EPISODE_SOURCES.
    result.canonical_episode_numbering = True

    latest = result.extracted_episode_numbers[-1]
    _fetch_episode_page(result, latest)
    return result


def _fetch_episode_page(result: SourceProbeResult, ep_num: int) -> None:
    """Best-effort per-episode field extraction for the latest episode found."""
    page_title = f"{_PAGE_PREFIX}{ep_num}"
    url = _parse_url(page_title)
    r, data = fetch_json(url)
    if not r.ok:
        result.warnings.append(f"Episode list came from allpages, but fetching {page_title} for field detail failed: {r.error}")
        return
    if not isinstance(data, dict) or "parse" not in data:
        result.warnings.append(f"{page_title}: parse response missing 'parse' envelope")
        return

    html = str((data["parse"].get("text") or {}).get("*") or "")
    if not html.strip():
        result.warnings.append(f"{page_title}: page resolved but returned no body content")
        return

    pairs = extract_infobox_pairs(html)
    if not pairs:
        result.warnings.append(f"{page_title}: no portable-infobox found — page layout may differ from the confirmed convention")
        return

    for label, value_text, value_links in pairs:
        low = label.lower()
        if _AIR_DATE_LABEL_RE.search(low):
            d = normalize_air_date(value_text)
            if d:
                result.extracted_air_dates[ep_num] = d
        elif re.search(r"^(episode\s*)?title$|sub.?title", low):
            t = normalize_title(value_text, ep_num)
            if t:
                result.extracted_titles[ep_num] = t
        elif "guest" in low:
            names = value_links if value_links else re.split(r"\s*[,&·]\s*|\s+and\s+", value_text)
            guests = [g for g in (normalize_guest_name(n) for n in names) if g]
            if guests:
                result.extracted_guests[ep_num] = guests
