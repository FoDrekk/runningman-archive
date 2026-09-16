"""
sources/sbs.py — SBS (the Korean broadcaster) adapter.

Mirrors includes/scraping/scrapers/SbsScraper.php's own honest framing:
"SBS reorganises its endpoints periodically" — there is no confirmed,
stable JSON API, so several known page URLs are tried in the same order
the PHP adapter tries them, and this adapter reports ACCESS honestly
rather than pretending to a parsing capability nobody has confirmed.

This is deliberately the least-capable adapter of the four: per Phase 3
("A source can successfully provide only one useful field"), SBS here
only ever reports reachability plus a light heuristic scan for an
episode-number-shaped pattern in the page text — never a claimed
title/date/guest extraction the PHP system itself has not confirmed a
stable selector for either.
"""
from __future__ import annotations

import re

from .. import config
from ..models import FailureType, SourceProbeResult, SourceStatus
from .base import fetch_url, snippet

_EP_PATTERN = re.compile(r"(\d{2,4})\s*회")


def probe() -> SourceProbeResult:
    last_result: SourceProbeResult | None = None
    for url in config.SBS_CANDIDATE_URLS:
        result = SourceProbeResult(
            source="sbs", url=url, request_status="not_attempted",
            http_status=None, response_time_ms=None,
            access_result=SourceStatus.BLOCKED, parsing_result="not_attempted",
        )
        r = fetch_url(url, accept="text/html")
        result.request_status = "ok" if r.ok else "failed"
        result.http_status = r.http_status
        result.response_time_ms = round(r.elapsed_ms, 1)

        if not r.ok:
            result.failure = FailureType.ACCESS_FAILURE
            result.error_detail = r.error
            last_result = result
            continue

        result.access_result = SourceStatus.REACHABLE
        body = r.body or ""
        matches = sorted({int(m.group(1)) for m in _EP_PATTERN.finditer(body) if 1 <= int(m.group(1)) <= 2000})
        if not matches:
            result.parsing_result = "no_relevant_data"
            result.failure = FailureType.SOURCE_EMPTY
            result.warnings.append(
                "Page reached, but no confirmed episode-number pattern found — SBS has no stable "
                "JSON API and this adapter does not claim a selector the PHP system has not confirmed either"
            )
            result.raw_snippet = snippet(body)
            last_result = result
            continue

        result.extracted_episode_numbers = matches
        result.parsing_result = "ok"
        result.access_result = SourceStatus.USABLE
        return result

    return last_result
