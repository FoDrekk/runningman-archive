"""
sources/tvmaze.py — TVmaze API adapter.

Mirrors includes/scraping/scrapers/TvMazeScraper.php's role: corroborating
air_date / title / thumbnail / source-local episode identity only (TVmaze
carries no guest/mission/location data for a Korean variety show, so this
adapter never claims any — same "don't force every source to provide
every field" posture PHP's own PR12 comment documents).

INCIDENT (2026-09-17): a live run resolved "latest episode = 1980" from
this adapter, when the real value was in the 790s (per Fandom). Root
cause: this adapter used to run the shared, deliberately permissive
`normalize_episode_number()` against each embedded episode's freeform
`name` field and treat whatever digit sequence it found as a trustworthy
absolute episode number. That function's job is to pull a number out of
already-narrowed, structurally-verified text (a table cell already known
to be an episode-number column, as wikipedia.py uses it) — TVmaze's
`name` field is unstructured title prose with NO such guarantee. A
digit sequence appearing there could be a year, a prize amount, a guest
count, or literally anything else; there is no way to tell without
narrower context, and TVmaze's OWN `season`/`number` fields (its actual
per-season index) are not proven to map 1:1 onto Running Man's real,
continuous canonical broadcast count either — that mapping is exactly
what this fix refuses to invent (see registry.py's
CANONICAL_EPISODE_SOURCES for the full reasoning).

So: this adapter NEVER populates extracted_episode_numbers /
extracted_titles / extracted_air_dates anymore, and always reports
canonical_episode_numbering=False. Everything it can still genuinely
observe per episode — TVmaze's own episode id/season/number, the title
text, the air date, whether a thumbnail exists, and (for transparency
only) any raw digit sequence found in the title — is preserved in
non_canonical_episode_hints, explicitly unkeyed by any trusted episode
number, so it can never be silently mistaken for episode_number
evidence. See engine.py's _resolve_latest(), which only ever treats
extracted_episode_numbers as a "latest episode" candidate when
canonical_episode_numbering is True.

One request: GET /shows/{show_id}?embed=episodes. No API key required,
matching the PHP adapter's own "zero-configuration independent witness"
role.
"""
from __future__ import annotations

from .. import config
from ..models import FailureType, SourceProbeResult, SourceStatus
from ..normalize import normalize_air_date, normalize_episode_number, normalize_title
from .base import annotate_robots_access, fetch_json, snippet

_NON_CANONICAL_REASON = (
    "TVmaze's episode 'name' field is freeform title text, not a "
    "structurally-verified canonical Running Man broadcast count (unlike "
    "Wikipedia's labeled episode-number column or Fandom's 'Episode/N' "
    "page-naming convention). Any digit sequence found in it "
    "('title_digit_sequence' below) is reported for transparency only and "
    "must never be treated as episode_number. TVmaze's own 'season'/"
    "'number_in_season' fields reflect TVmaze's internal indexing, which "
    "is not confirmed to align with Running Man's continuous canonical "
    "numbering either — no such mapping is invented here."
)


def probe() -> SourceProbeResult:
    url = f"{config.TVMAZE_BASE}/shows/{config.TVMAZE_SHOW_ID}?embed=episodes"
    r, data = fetch_json(url)

    result = SourceProbeResult(
        source="tvmaze", url=url, request_status="ok" if r.ok else "failed",
        http_status=r.http_status, response_time_ms=round(r.elapsed_ms, 1),
        access_result=SourceStatus.USABLE if r.ok else SourceStatus.BLOCKED,
        parsing_result="not_attempted",
    )
    annotate_robots_access(result, r)
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
        title = normalize_title(name, 0)
        air_date = normalize_air_date(ep.get("airdate"))
        thumb = bool((ep.get("image") or {}).get("medium"))
        # Kept ONLY for transparency/debugging — never promoted to
        # extracted_episode_numbers. See _NON_CANONICAL_REASON above.
        title_digit_sequence = normalize_episode_number(name)

        result.non_canonical_episode_hints.append({
            "tvmaze_episode_id": ep.get("id"),
            "season": ep.get("season"),
            "number_in_season": ep.get("number"),
            "title": title,
            "raw_name": name or None,
            "air_date": air_date,
            "thumbnail_available": thumb,
            "title_digit_sequence": title_digit_sequence,
            "reason": _NON_CANONICAL_REASON,
        })
        if thumb and not result.thumbnail_available:
            result.thumbnail_available = True

    # canonical_episode_numbering stays at its default (False) — declared
    # explicitly here anyway so the intent is never ambiguous at a glance.
    result.canonical_episode_numbering = False

    if not result.non_canonical_episode_hints:
        result.parsing_result = "no_relevant_data"
        result.failure = FailureType.SOURCE_EMPTY
        result.warnings.append("Episode list present but yielded no usable per-episode data")
        result.access_result = SourceStatus.REACHABLE
    else:
        result.parsing_result = "ok"
        result.access_result = SourceStatus.USABLE
        result.warnings.append(
            "TVmaze numbers are NOT canonical Running Man episode numbers — "
            "see non_canonical_episode_hints; extracted_episode_numbers is "
            "intentionally left empty by this adapter."
        )

    return result
