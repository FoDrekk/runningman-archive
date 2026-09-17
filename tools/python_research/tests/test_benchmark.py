"""
Offline tests for the Phase 18 benchmark package (benchmark/). Every
network call is replaced by benchmark.fixture_transport.FixtureTransport
— no internet access required, matching the rest of this test suite's
hermetic convention.
"""
from __future__ import annotations

import unittest
from unittest.mock import patch

from ..benchmark import episodes, probes, report
from ..benchmark.fixture_transport import FixtureTransport
from ..benchmark.runner import BenchmarkRunner
from ..models import SourceStatus

_URLOPEN_TARGET = "tools.python_research.sources.base.urllib.request.urlopen"


class FandomSampleTests(unittest.TestCase):
    def test_mixed_hits_and_a_structural_miss(self):
        with patch(_URLOPEN_TARGET, FixtureTransport()):
            results = probes.probe_fandom_sample(episodes.sample())
        self.assertEqual(set(results.keys()), set(episodes.SAMPLE_EPISODES))

        # Fixture deliberately omits Episode/700 from allpages.
        self.assertEqual(results[700].access_result, SourceStatus.REACHABLE)
        self.assertEqual(results[700].extracted_episode_numbers, [])

        for n in (750, 800, 810, 819):
            r = results[n]
            self.assertEqual(r.access_result, SourceStatus.USABLE)
            self.assertEqual(r.extracted_episode_numbers, [n])
            self.assertTrue(r.canonical_episode_numbering)
            self.assertIn(n, r.extracted_air_dates)

        self.assertIn("Kim Jong-kook", results[819].extracted_guests.get(819, []))


class WikipediaSampleTests(unittest.TestCase):
    def test_mixed_hits_and_a_structural_miss(self):
        with patch(_URLOPEN_TARGET, FixtureTransport()):
            results = probes.probe_wikipedia_sample(episodes.sample())
        self.assertEqual(set(results.keys()), set(episodes.SAMPLE_EPISODES))

        # Fixture deliberately omits episode 800 from the 2026 year page.
        self.assertEqual(results[800].access_result, SourceStatus.REACHABLE)
        self.assertEqual(results[800].extracted_episode_numbers, [])

        for n in (700, 750, 810, 819):
            r = results[n]
            self.assertEqual(r.access_result, SourceStatus.USABLE)
            self.assertEqual(r.extracted_episode_numbers, [n])
            self.assertTrue(r.canonical_episode_numbering)

    def test_only_one_request_per_distinct_hinted_year(self):
        # 800/810/819 all hint 2026 -> exactly one fetch for that year,
        # not three (matching the real adapter's "one call" cost model).
        calls = []
        transport = FixtureTransport()

        def _counting(req, timeout=None):
            url = req.full_url
            if "en.wikipedia.org/w/api.php" in url:
                calls.append(url)
            return transport(req, timeout=timeout)

        with patch(_URLOPEN_TARGET, _counting):
            probes.probe_wikipedia_sample(episodes.sample())
        self.assertEqual(len(calls), 3)  # 2024, 2025, 2026 — one each


class TvMazeAndSbsRoleTests(unittest.TestCase):
    def test_tvmaze_never_targetable_for_canonical_numbering(self):
        with patch(_URLOPEN_TARGET, FixtureTransport()):
            tvmaze_ref = probes.probe_tvmaze_reference()
            corrob = probes.tvmaze_corroboration_for(
                episodes.sample(), tvmaze_ref, canonical_air_dates={819: "2026-09-13"},
            )
        self.assertFalse(tvmaze_ref.canonical_episode_numbering)
        self.assertEqual(tvmaze_ref.extracted_episode_numbers, [])
        for rec in corrob.values():
            self.assertFalse(rec["targetable"])
        # The fixture's episode 641 shares episode 819's canonical air_date.
        self.assertTrue(corrob[819]["corroborated_by_air_date"])
        # No canonical air_date was supplied for 700 -> nothing to corroborate against.
        self.assertNotIn("corroborated_by_air_date", corrob[700])

    def test_sbs_only_applicable_to_the_current_episode(self):
        with patch(_URLOPEN_TARGET, FixtureTransport()):
            sbs_ref = probes.probe_sbs_reference()
            applic = probes.sbs_applicability_for(episodes.sample(), sbs_ref)
        for n in episodes.SAMPLE_EPISODES:
            if n == episodes.CURRENT_EPISODE:
                self.assertTrue(applic[n]["applicable"])
                self.assertTrue(applic[n]["matched"])
            else:
                self.assertFalse(applic[n]["applicable"])


class BenchmarkRunnerTests(unittest.TestCase):
    def test_invalid_mode_rejected(self):
        with self.assertRaises(ValueError):
            BenchmarkRunner(mode="bogus")

    def test_fixture_run_end_to_end(self):
        result = BenchmarkRunner(mode="fixture").run()
        self.assertEqual(result["mode"], "fixture")
        self.assertEqual(len(result["per_episode"]), len(episodes.SAMPLE_EPISODES))
        self.assertEqual(sorted(result["canonical_episode_sources"]), ["fandom", "wikipedia"])

        # Regression: TVmaze must never appear as canonical in the aggregate pass either.
        agg_sources = result["aggregate_engine_report"]["source_results"]
        self.assertFalse(agg_sources["tvmaze"]["canonical_episode_numbering"])
        self.assertEqual(agg_sources["tvmaze"]["extracted_episode_numbers"], [])

        # The aggregate current-state resolution should resolve to the
        # current sample episode (819), never something invented.
        self.assertEqual(result["aggregate_engine_report"]["resolution"]["latest_episode"], 819)

        for rec in result["per_episode"]:
            self.assertFalse(rec["tvmaze"]["targetable"])


class ReportTests(unittest.TestCase):
    def setUp(self):
        self.result = BenchmarkRunner(mode="fixture").run()
        self.summary = report.summarize(self.result)

    def test_summary_has_expected_shape(self):
        self.assertEqual(self.summary["sample_size"], 5)
        self.assertIn("fandom", self.summary["accessibility"])
        self.assertIn("wikipedia", self.summary["accessibility"])
        self.assertEqual(self.summary["tvmaze"]["targetable_for_canonical_numbering"], 0)
        self.assertIn("location", self.summary["unsupported_fields_by_all_adapters"])
        self.assertIn("team_result", self.summary["unsupported_fields_by_all_adapters"])

    def test_markdown_renders_without_error_and_is_honest(self):
        md = report.render_markdown(self.result, self.summary)
        self.assertIn("🔴 FIXTURE-BASED RUN", md)
        self.assertIn("UNSUPPORTED BY EVERY CURRENT ADAPTER", md)
        self.assertIn("must always be 0", md)
        # The migration decision must never be made automatically by this report.
        self.assertIn("does NOT answer this question", md)


if __name__ == "__main__":
    unittest.main()
