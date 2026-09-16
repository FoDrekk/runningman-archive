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

# Trust ranking WITHIN THIS POC ONLY — mirrors the spirit of
# config/scraping.php's field_priority (an independent, structured API/
# wiki with a track record leads; a source that has never been checked
# for a field does not). Not a claim about the PHP system's own ranking.
FIELD_AUTHORITY: dict[str, list[str]] = {
    "episode_number": ["wikipedia", "tvmaze", "fandom", "sbs"],
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
