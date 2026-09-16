"""
Offline unit tests for engine.py's resolution and evidence logic.

No network access: SourceProbeResult objects are constructed directly,
exactly as an adapter would return them, so the resolution/evidence
logic is tested independently of any real HTTP call.
"""
from __future__ import annotations

import unittest
from unittest.mock import MagicMock

from ..engine import ResearchEngine
from ..models import FailureType, SourceProbeResult, SourceStatus


def _usable(source: str, eps: list[int], dates: dict[int, str] | None = None, url: str = "https://example.com") -> SourceProbeResult:
    return SourceProbeResult(
        source=source, url=url, request_status="ok", http_status=200,
        response_time_ms=12.3, access_result=SourceStatus.USABLE,
        parsing_result="ok", extracted_episode_numbers=sorted(eps),
        extracted_air_dates=dates or {},
    )


def _blocked(source: str, detail: str = "HTTP 403: Forbidden") -> SourceProbeResult:
    return SourceProbeResult(
        source=source, url="https://example.com", request_status="failed",
        http_status=403, response_time_ms=5.0, access_result=SourceStatus.BLOCKED,
        parsing_result="not_attempted", failure=FailureType.ACCESS_FAILURE, error_detail=detail,
    )


def _empty(source: str, detail: str = "reached, nothing relevant") -> SourceProbeResult:
    return SourceProbeResult(
        source=source, url="https://example.com", request_status="ok", http_status=200,
        response_time_ms=8.0, access_result=SourceStatus.REACHABLE,
        parsing_result="no_relevant_data", failure=FailureType.SOURCE_EMPTY, warnings=[detail],
    )


def _parser_failure(source: str) -> SourceProbeResult:
    return SourceProbeResult(
        source=source, url="https://example.com", request_status="ok", http_status=200,
        response_time_ms=8.0, access_result=SourceStatus.REACHABLE,
        parsing_result="parse_error", failure=FailureType.PARSER_FAILURE,
        error_detail="Response missing expected envelope",
    )


class ResolutionTests(unittest.TestCase):
    def setUp(self):
        self.engine = ResearchEngine(registry=MagicMock())

    def test_agreement_resolves_cleanly(self):
        results = {
            "wikipedia": _usable("wikipedia", [818, 819], {818: "2026-09-06", 819: "2026-09-13"}),
            "tvmaze": _usable("tvmaze", [818, 819], {818: "2026-09-06", 819: "2026-09-13"}),
        }
        res = self.engine._resolve_latest(results)
        self.assertEqual(res.latest_episode, 819)
        self.assertEqual(res.air_date, "2026-09-13")
        self.assertEqual(res.conflicts, [])
        self.assertFalse(res.insufficient_evidence)

    def test_duplicate_evidence_does_not_double_count_as_conflict(self):
        # Two sources reporting the SAME value is agreement, not a conflict —
        # and not treated as "two separate facts" either.
        results = {
            "wikipedia": _usable("wikipedia", [819], {819: "2026-09-13"}),
            "fandom": _usable("fandom", [819], {819: "2026-09-13"}),
        }
        res = self.engine._resolve_latest(results)
        self.assertEqual(res.latest_episode, 819)
        self.assertEqual(res.conflicts, [])

    def test_small_spread_is_not_a_conflict(self):
        # Normal lag: sources agree within the tolerance.
        results = {
            "wikipedia": _usable("wikipedia", [819], {819: "2026-09-13"}),
            "tvmaze": _usable("tvmaze", [817], {817: "2026-08-30"}),
        }
        res = self.engine._resolve_latest(results)
        self.assertEqual(res.latest_episode, 819)
        self.assertEqual(res.conflicts, [])

    def test_large_spread_is_a_genuine_conflict(self):
        results = {
            "wikipedia": _usable("wikipedia", [819], {819: "2026-09-13"}),
            "tvmaze": _usable("tvmaze", [810], {810: "2026-07-01"}),
        }
        res = self.engine._resolve_latest(results)
        self.assertEqual(res.latest_episode, 819)  # still resolves to the best-supported answer
        self.assertEqual(len(res.conflicts), 1)
        self.assertEqual(res.conflicts[0]["type"], "RESOLUTION_CONFLICT")

    def test_inaccessible_source_is_reported_not_silently_dropped(self):
        results = {"sbs": _blocked("sbs")}
        res = self.engine._resolve_latest(results)
        self.assertIn("sbs", res.sources_inaccessible)
        self.assertTrue(res.insufficient_evidence)
        self.assertIsNone(res.latest_episode)

    def test_empty_source_is_distinguished_from_inaccessible(self):
        results = {"sbs": _empty("sbs")}
        res = self.engine._resolve_latest(results)
        self.assertIn("sbs", res.sources_with_no_data)
        self.assertNotIn("sbs", res.sources_inaccessible)

    def test_parser_failure_is_reported_as_a_failure(self):
        results = {"fandom": _parser_failure("fandom")}
        res = self.engine._resolve_latest(results)
        failure_types = {f["type"] for f in res.failures}
        self.assertIn("PARSER_FAILURE", failure_types)

    def test_number_only_without_air_date_is_insufficient_evidence_not_a_guess(self):
        results = {
            "fandom": SourceProbeResult(
                source="fandom", url="https://example.com", request_status="ok", http_status=200,
                response_time_ms=8.0, access_result=SourceStatus.USABLE, parsing_result="ok",
                extracted_episode_numbers=[820],  # a page exists, but no air date confirms it aired
            ),
        }
        res = self.engine._resolve_latest(results)
        self.assertTrue(res.insufficient_evidence)
        self.assertIsNone(res.latest_episode)
        self.assertTrue(any(f["type"] == "INSUFFICIENT_EVIDENCE" for f in res.failures))

    def test_no_sources_at_all_is_insufficient_evidence(self):
        res = self.engine._resolve_latest({})
        self.assertTrue(res.insufficient_evidence)
        self.assertIsNone(res.latest_episode)


class EvidenceTests(unittest.TestCase):
    def setUp(self):
        self.engine = ResearchEngine(registry=MagicMock())

    def test_no_evidence_when_no_resolution(self):
        self.assertEqual(self.engine._build_evidence({}, None), [])

    def test_corroborated_evidence_has_higher_confidence_than_lone_witness(self):
        results = {
            "wikipedia": _usable("wikipedia", [819], {819: "2026-09-13"}),
            "tvmaze": _usable("tvmaze", [819], {819: "2026-09-13"}),
        }
        evidence = self.engine._build_evidence(results, 819)
        by_source = {(e.source, e.field): e for e in evidence}
        wiki_conf = by_source[("wikipedia", "episode_number")].confidence
        tvmaze_conf = by_source[("tvmaze", "episode_number")].confidence
        # Wikipedia is the designated authority AND corroborated -> highest confidence.
        self.assertGreaterEqual(wiki_conf, tvmaze_conf)
        self.assertGreater(wiki_conf, 0.9)

    def test_lone_uncorroborated_witness_has_lower_confidence(self):
        results = {"sbs": _usable("sbs", [819])}
        evidence = self.engine._build_evidence(results, 819)
        self.assertEqual(len(evidence), 1)
        self.assertLess(evidence[0].confidence, 0.6)

    def test_every_evidence_item_has_a_rationale(self):
        results = {"wikipedia": _usable("wikipedia", [819], {819: "2026-09-13"})}
        for e in self.engine._build_evidence(results, 819):
            self.assertTrue(e.rationale)
            self.assertIn(e.source, e.rationale)


class ReportGenerationTests(unittest.TestCase):
    def test_run_produces_a_complete_report(self):
        registry = MagicMock()
        registry.probe_all.return_value = {
            "wikipedia": _usable("wikipedia", [819], {819: "2026-09-13"}),
            "sbs": _blocked("sbs"),
        }
        engine = ResearchEngine(registry=registry)
        report = engine.run()

        for key in ("timestamp", "environment", "source_results", "resolution"):
            self.assertIn(key, report)
        self.assertEqual(report["resolution"]["latest_episode"], 819)
        self.assertIn("sbs", report["resolution"]["sources_inaccessible"])
        # Serializable end to end (Phase 8 requires valid JSON output).
        import json
        json.dumps(report)


if __name__ == "__main__":
    unittest.main()
