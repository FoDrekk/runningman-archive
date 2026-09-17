"""
benchmark/runner.py — orchestrates the fixed-sample benchmark.

Two passes:
  1. Per-episode targeted probing (probes.py) for the 5 sample episodes,
     against Fandom/Wikipedia directly, plus TVmaze/SBS reference probes
     (their real, unmodified whole-show/current-page probe()) used only
     for non-canonical corroboration/applicability — never for canonical
     numbering (registry.CANONICAL_EPISODE_SOURCES is untouched).
  2. One aggregate pass through the REAL, UNMODIFIED ResearchEngine —
     covering "resolution result"/"confidence"/failure classification
     for the CURRENT state exactly as the live engine already does it.

Runtime (wall-clock) is measured per phase. Every result records whether
it came from "fixture" or "live" transport — see fixture_transport.py
and README.md's "Fixture vs live mode".
"""
from __future__ import annotations

import platform
import sys
import time
from unittest.mock import patch

from ..engine import ResearchEngine
from ..models import utc_now_iso
from ..registry import CANONICAL_EPISODE_SOURCES
from . import episodes, probes
from .fixture_transport import FixtureTransport

_URLOPEN_TARGET = "tools.python_research.sources.base.urllib.request.urlopen"


class BenchmarkRunner:
    def __init__(self, mode: str = "fixture"):
        if mode not in ("fixture", "live"):
            raise ValueError(f"mode must be 'fixture' or 'live', got {mode!r}")
        self.mode = mode

    def run(self) -> dict:
        samples = episodes.sample()
        patcher = patch(_URLOPEN_TARGET, FixtureTransport()) if self.mode == "fixture" else None
        if patcher:
            patcher.start()
        try:
            timings: dict[str, float] = {}

            t0 = time.monotonic()
            fandom_by_ep = probes.probe_fandom_sample(samples)
            timings["fandom_sample_ms"] = round((time.monotonic() - t0) * 1000, 1)

            t0 = time.monotonic()
            wikipedia_by_ep = probes.probe_wikipedia_sample(samples)
            timings["wikipedia_sample_ms"] = round((time.monotonic() - t0) * 1000, 1)

            t0 = time.monotonic()
            tvmaze_ref = probes.probe_tvmaze_reference()
            timings["tvmaze_reference_ms"] = round((time.monotonic() - t0) * 1000, 1)

            t0 = time.monotonic()
            sbs_ref = probes.probe_sbs_reference()
            timings["sbs_reference_ms"] = round((time.monotonic() - t0) * 1000, 1)

            canonical_air_dates: dict[int, str] = {}
            for n, r in fandom_by_ep.items():
                if n in r.extracted_air_dates:
                    canonical_air_dates.setdefault(n, r.extracted_air_dates[n])
            for n, r in wikipedia_by_ep.items():
                if n in r.extracted_air_dates:
                    canonical_air_dates.setdefault(n, r.extracted_air_dates[n])

            tvmaze_corrob = probes.tvmaze_corroboration_for(samples, tvmaze_ref, canonical_air_dates)
            sbs_applic = probes.sbs_applicability_for(samples, sbs_ref)

            t0 = time.monotonic()
            engine_report = ResearchEngine().run()  # real, UNMODIFIED aggregate pass
            timings["engine_aggregate_ms"] = round((time.monotonic() - t0) * 1000, 1)
        finally:
            if patcher:
                patcher.stop()

        per_episode = self._build_per_episode(samples, fandom_by_ep, wikipedia_by_ep, tvmaze_corrob, sbs_applic)

        return {
            "timestamp": utc_now_iso(),
            "mode": self.mode,
            "mode_note": (
                "Every source_results/per_episode value below comes from synthetic local fixtures "
                "(benchmark/fixtures/), NOT a live network call. This is explicit, not a fallback: "
                "see README.md's 'Fixture vs live mode'."
                if self.mode == "fixture" else
                "Every value below came from a real network request made just now."
            ),
            "environment": {
                "python_version": sys.version.split()[0],
                "platform": platform.platform(),
            },
            "sample_episodes": episodes.SAMPLE_EPISODES,
            "canonical_episode_sources": sorted(CANONICAL_EPISODE_SOURCES),
            "timings": timings,
            "per_episode": per_episode,
            "aggregate_engine_report": engine_report,
        }

    @staticmethod
    def _build_per_episode(samples, fandom_by_ep, wikipedia_by_ep, tvmaze_corrob, sbs_applic) -> list[dict]:
        out = []
        for s in samples:
            n = s.number
            f = fandom_by_ep.get(n)
            w = wikipedia_by_ep.get(n)

            f_date = f.extracted_air_dates.get(n) if f else None
            w_date = w.extracted_air_dates.get(n) if w else None
            f_title = f.extracted_titles.get(n) if f else None
            w_title = w.extracted_titles.get(n) if w else None

            record = {
                "episode_number": n,
                "wikipedia_year_hint": s.wikipedia_year_hint,
                "is_current_episode": s.is_current,
                "fandom": _source_field_record(f, n),
                "wikipedia": _source_field_record(w, n),
                "tvmaze": tvmaze_corrob.get(n),
                "sbs": sbs_applic.get(n),
                "cross_source_agreement": {
                    "air_date": {
                        "fandom": f_date, "wikipedia": w_date,
                        "both_present": bool(f_date and w_date),
                        "agree": bool(f_date and w_date and f_date == w_date),
                    },
                    "title": {
                        "fandom": f_title, "wikipedia": w_title,
                        "both_present": bool(f_title and w_title),
                        "agree": bool(f_title and w_title and f_title == w_title),
                    },
                },
            }
            out.append(record)
        return out


def _source_field_record(r, ep_num: int) -> dict:
    if r is None:
        return {"accessible": False, "reason": "not probed"}
    return {
        "accessible": r.access_result.value in ("usable", "reachable"),
        "access_result": r.access_result.value,
        "access_classification": r.access_classification,
        "robots_outcome": r.robots_outcome,
        "episode_number_confirmed": ep_num in r.extracted_episode_numbers,
        "air_date": r.extracted_air_dates.get(ep_num),
        "title": r.extracted_titles.get(ep_num),
        "guests": r.extracted_guests.get(ep_num, []),
        "thumbnail_available": r.thumbnail_available,
        "failure": r.failure.value if r.failure else None,
        "warnings": r.warnings,
    }
