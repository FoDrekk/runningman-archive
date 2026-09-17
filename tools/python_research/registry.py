"""
registry.py — the list of source adapters and field-authority ranking.

    PHP concept                          Python POC equivalent
    ------------------------------------ --------------------------------
    RmSourceRegistry                     SourceRegistry (this file)
    config/scraping.php's 'field_priority' FIELD_AUTHORITY (this file) —
      (which source wins a field first)    scoped to only the 4 sources
                                            this POC implements; used
                                            purely to justify Evidence
                                            confidence, never to write
                                            anything anywhere.
"""
from __future__ import annotations

import traceback

from .models import FailureType, SourceProbeResult, SourceStatus
from .sources import ADAPTERS

# Sources whose episode_number is structurally verified to be Running
# Man's OWN canonical, continuous broadcast count — not merely "a number
# this source reports somewhere". "Structurally verified" means either:
#   - an explicitly-labeled table column header (Wikipedia's "No."/"Ep."
#     column), or
#   - a community-maintained page-naming CONVENTION tied 1:1 to that
#     count (Fandom's "Episode/N" page title).
# Excluded, and why:
#   - tvmaze: its episode "number" is only ever extracted from freeform
#     title text (sources/tvmaze.py) — no structural guarantee any digit
#     found there is RM's broadcast count rather than, e.g., a year, a
#     prize amount, or some other number embedded in the title prose.
#     Even TVmaze's own season/number fields reflect TVMAZE's internal
#     indexing, not a confirmed mapping onto RM's canonical numbering
#     (see tvmaze.py's module docstring — no such mapping is invented).
#   - sbs: its "N회" pattern requires a genuine Korean episode-count
#     marker, but is matched against raw page text with no confirmation
#     that a given match is even about the current show (no page-target
#     verification) — a real but currently-unproven signal.
# THIS SET IS THE SINGLE SOURCE OF TRUTH gating which sources' numbers
# may resolve engine.py's "latest episode" — see _resolve_latest().
CANONICAL_EPISODE_SOURCES: frozenset[str] = frozenset({"wikipedia", "fandom"})

# Trust ranking WITHIN THIS POC ONLY — mirrors the spirit of
# config/scraping.php's field_priority (an independent, structured API/
# wiki with a track record leads; a source that has never been checked
# for a field does not). Not a claim about the PHP system's own ranking.
#
# episode_number is intentionally narrowed to CANONICAL_EPISODE_SOURCES
# only — see that constant's comment for why tvmaze/sbs are excluded.
# air_date/title keep tvmaze listed: it may still genuinely corroborate
# those fields (see tvmaze.py), even though it can no longer assert a
# canonical episode number to key them by.
FIELD_AUTHORITY: dict[str, list[str]] = {
    "episode_number": ["wikipedia", "fandom"],
    "air_date": ["wikipedia", "tvmaze", "fandom"],
    "title": ["wikipedia", "tvmaze", "fandom"],
    "guests": ["fandom"],
}


class SourceRegistry:
    def __init__(self) -> None:
        self._adapters = dict(ADAPTERS)

    def names(self) -> list[str]:
        return list(self._adapters.keys())

    def probe_all(self) -> dict[str, SourceProbeResult]:
        """
        Probe every registered source. A bug in one adapter must never
        take down the whole run — any unexpected exception becomes an
        honestly-labelled error result instead of crashing the engine.
        """
        out: dict[str, SourceProbeResult] = {}
        for name, probe_fn in self._adapters.items():
            try:
                out[name] = probe_fn()
            except Exception as e:  # noqa: BLE001 — isolate one adapter's failure from the rest
                out[name] = SourceProbeResult(
                    source=name, url="", request_status="failed",
                    http_status=None, response_time_ms=None,
                    access_result=SourceStatus.ERROR, parsing_result="parse_error",
                    failure=FailureType.PARSER_FAILURE,
                    error_detail=f"Unhandled exception in adapter: {e}",
                    raw_snippet=traceback.format_exc(limit=3),
                )
        return out
