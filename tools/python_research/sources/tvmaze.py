"""
sources/tvmaze.py — TVmaze API adapter.

Mirrors includes/scraping/scrapers/TvMazeScraper.php's role: episode
number / title / air_date corroboration only (TVmaze carries no guest/
mission/location data for a Korean variety show, so this adapter never
claims any — same "don't force every source to provide every field"
posture PHP's own PR12 comment documents).

One request: GET /shows/{show_id}?embed=episodes — the full embedded
episode list, from which every absolute episode number TVmaze names is
extracted directly (same digit-extraction heuristic as
TvMazeScraper::absoluteEpisodeNumber(), reimplemented in normalize.py).
No API key required, matching the PHP adapter's own "zero-configuration
independent witness" role.
"""
from __future__ import annotations

from .. import config
from ..models import FailureType, SourceProbeResult, SourceStatus
from ..normalize import normalize_air_date, normalize_episode_number, normalize_title
from .base import fetch_json, snippet


def probe() -> SourceProbeResult:
    url = f"{config.TVMAZE_BASE}/shows/{config.TVMAZE_SHOW_ID}?embed=episodes"
    r, data = fetch_json(url)

    result = SourceProbeResult(
        source="tvmaze", url=url, request_status="ok" if r.ok else "failed",
        http_status=r.http_status, response_time_ms=round(r.elapsed_ms, 1),
        access_result=SourceStatus.USABLE if r.ok else SourceStatus.BLOCKED,
        parsing_result="not_attempted",
    )
    if not r.ok:
        result.failure = FailureType.ACCESS_FAILURE
        result.error_detail = r.error
        result.access_result = SourceStatus.BLOCKED
        return result

    if not isinstance(data, dict):
        result.parsing_result = "parse_error"
        result.failure = FailureType.PARSER_FAILURE
        result.error_detail = "Response was not a JSON object"
        result.raw_snippet = snippet(r.body)
        result.access_result = SourceStatus.REACHABLE
        return result

    episodes = (data.get("_embedded") or {}).get("episodes") or []
    if not episodes:
        result.parsing_result = "no_relevant_data"
        result.failure = FailureType.SOURCE_EMPTY
        result.warnings.append("Response parsed but carried no embedded episode list")
        result.access_result = SourceStatus.REACHABLE
        return result

    for ep in episodes:
        name = str(ep.get("name") or "")
        abs_num = normalize_episode_number(name)
        if abs_num is None:
            continue  # not indexed — absent, not wrong (mirrors PHP's own posture)
        result.extracted_episode_numbers.append(abs_num)
        title = normalize_title(name, abs_num)
        if title:
            result.extracted_titles[abs_num] = title
        air_date = normalize_air_date(ep.get("airdate"))
        if air_date:
            result.extracted_air_dates[abs_num] = air_date
        if not result.thumbnail_available and (ep.get("image") or {}).get("medium"):
            result.thumbnail_available = True

    result.extracted_episode_numbers = sorted(set(result.extracted_episode_numbers))
    if not result.extracted_episode_numbers:
        result.parsing_result = "no_relevant_data"
        result.failure = FailureType.SOURCE_EMPTY
        result.warnings.append("Episode list present but no entry's name carried a recognisable absolute episode number")
        result.access_result = SourceStatus.REACHABLE
    else:
        result.parsing_result = "ok"
        result.access_result = SourceStatus.USABLE

    return result
