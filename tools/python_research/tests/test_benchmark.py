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


class SelectHistoricalSampleTests(unittest.TestCase):
    """
    episodes.select_historical_sample() / build_dynamic_samples() — the
    Issue 1 fix: the historical sample must come from episode numbers a
    canonical source actually discovered THIS run, never a hardcoded
    guess (which is exactly how the old fixed sample [700, 750, 800,
    810, 819] silently drifted out of range once Fandom's real archive
    only went up to episode 792).
    """

    def test_never_selects_a_number_outside_the_candidate_set(self):
        candidates = [1, 2, 3, 44, 45, 244, 300, 381, 500, 640, 792]
        for size in range(1, len(candidates) + 2):
            selected = episodes.select_historical_sample(candidates, size)
            self.assertTrue(set(selected).issubset(set(candidates)), (size, selected))

    def test_deterministic_and_reproducible(self):
        candidates = [1, 2, 3, 44, 45, 244, 252, 300, 381, 385, 390, 640, 792]
        first = episodes.select_historical_sample(candidates, 5)
        second = episodes.select_historical_sample(list(reversed(candidates)), 5)
        third = episodes.select_historical_sample(candidates, 5)
        self.assertEqual(first, second)
        self.assertEqual(first, third)

    def test_spreads_across_the_full_range_including_both_ends(self):
        candidates = list(range(1, 39))  # 38 candidates, matching a real observed pool size
        selected = episodes.select_historical_sample(candidates, 5)
        self.assertEqual(len(selected), 5)
        self.assertEqual(selected[0], candidates[0])
        self.assertEqual(selected[-1], candidates[-1])
        self.assertEqual(selected, sorted(selected))

    def test_fewer_than_requested_candidates_returns_all_available(self):
        candidates = [750, 800, 810, 819]  # only 4 — fewer than DEFAULT_SAMPLE_SIZE (5)
        selected = episodes.select_historical_sample(candidates, size=5)
        self.assertEqual(sorted(selected), sorted(candidates))
        self.assertEqual(len(selected), 4)

    def test_empty_candidate_pool_returns_empty_sample(self):
        self.assertEqual(episodes.select_historical_sample([], size=5), [])

    def test_duplicate_candidates_are_deduplicated_before_selection(self):
        candidates = [5, 5, 5, 10, 15, 20, 20]
        selected = episodes.select_historical_sample(candidates, size=3)
        self.assertEqual(len(selected), len(set(selected)))
        self.assertTrue(set(selected).issubset({5, 10, 15, 20}))

    def test_build_dynamic_samples_labels_highest_discovered_as_current(self):
        samples = episodes.build_dynamic_samples([1, 2, 3, 44, 792], size=5)
        current = [s for s in samples if s.is_current]
        self.assertEqual(len(current), 1)
        self.assertEqual(current[0].number, 792)
        # Every sampled number must be one that was actually discovered.
        self.assertTrue({s.number for s in samples}.issubset({1, 2, 3, 44, 792}))

    def test_build_dynamic_samples_wikipedia_year_hint_left_unset(self):
        # runner.py fills this in from Fandom's own extracted air_date;
        # episodes.py itself must never guess one.
        for s in episodes.build_dynamic_samples([1, 2, 3, 44, 792], size=5):
            self.assertIsNone(s.wikipedia_year_hint)

    def test_build_dynamic_samples_empty_discovery_yields_no_samples(self):
        self.assertEqual(episodes.build_dynamic_samples([], size=5), [])


class BenchmarkRunnerTests(unittest.TestCase):
    def test_invalid_mode_rejected(self):
        with self.assertRaises(ValueError):
            BenchmarkRunner(mode="bogus")

    def test_fixture_run_end_to_end(self):
        result = BenchmarkRunner(mode="fixture").run()
        self.assertEqual(result["mode"], "fixture")

        # The fixture's Fandom allpages listing only ever advertises 4
        # canonical episode numbers (750/800/810/819 — 700 is
        # deliberately absent, see fixtures/fandom_allpages.json), so a
        # requested sample of 5 is honestly reduced to 4, never padded
        # with an invented/hardcoded number.
        sel = result["sample_selection"]
        self.assertEqual(sel["candidate_pool_size"], 4)
        self.assertEqual(sel["actual_size"], 4)
        self.assertTrue(sel["reduced"])
        self.assertEqual(sel["current_episode"], 819)
        self.assertEqual(len(result["per_episode"]), 4)
        self.assertEqual(sorted(result["sample_episodes"]), [750, 800, 810, 819])

        # Every sampled episode number must be one Fandom's own listing
        # actually reported — never a number the discovery pass didn't see.
        discovered = {750, 800, 810, 819}
        self.assertTrue(set(result["sample_episodes"]).issubset(discovered))

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

    def test_wikipedia_year_hint_derived_from_fandoms_own_air_date(self):
        # 750/800/810/819 have real (synthetic-fixture) Fandom air dates
        # of 2025/2026/2026/2026 — the derived hint must match, not a
        # hardcoded table (episodes.WIKIPEDIA_YEAR_HINT is untouched by
        # this path and could disagree if this regressed to using it).
        result = BenchmarkRunner(mode="fixture").run()
        hints = {rec["episode_number"]: rec["wikipedia_year_hint"] for rec in result["per_episode"]}
        self.assertEqual(hints[750], 2025)
        self.assertEqual(hints[800], 2026)
        self.assertEqual(hints[810], 2026)
        self.assertEqual(hints[819], 2026)


class ReportTests(unittest.TestCase):
    def setUp(self):
        self.result = BenchmarkRunner(mode="fixture").run()
        self.summary = report.summarize(self.result)

    def test_summary_has_expected_shape(self):
        self.assertEqual(self.summary["sample_size"], 4)  # see BenchmarkRunnerTests.test_fixture_run_end_to_end
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
        # Issue 2: the report must surface observed-this-run environment notes.
        self.assertIn("Environment notes (observed this run)", md)


class EnvironmentNotesTests(unittest.TestCase):
    """
    report.build_environment_notes() — the Issue 2 fix: environment
    notes must reflect this run's own OBSERVED per-source
    classifications, distinguishing source-side robots disallow, robots
    fetch failure, target timeout, target HTTP failure, successful
    access, and source-empty — never a static "every source is
    sandbox-blocked" assumption regardless of what actually happened.
    """

    @staticmethod
    def _result(source_results: dict, static_note: str = "") -> dict:
        return {
            "aggregate_engine_report": {
                "environment": {"note": static_note},
                "source_results": source_results,
            }
        }

    def test_robots_disallowed_is_distinguished_from_other_failures(self):
        result = self._result({
            "wikipedia": {
                "request_status": "failed", "http_status": None,
                "access_classification": "ROBOTS_DISALLOWED", "robots_outcome": "ROBOTS_DISALLOWED",
                "failure": "ACCESS_FAILURE", "error_detail": "Disallowed by robots.txt",
            },
        })
        notes = report.build_environment_notes(result)
        self.assertEqual(notes["per_source"]["wikipedia"]["target_classification"], report.ROBOTS_DISALLOWED)

    def test_target_timeout_is_distinguished_from_http_failure(self):
        result = self._result({
            "tvmaze": {
                "request_status": "failed", "http_status": None,
                "access_classification": None, "robots_outcome": "ALLOWED",
                "failure": "ACCESS_FAILURE", "error_detail": "TimeoutError: The read operation timed out",
            },
        })
        notes = report.build_environment_notes(result)
        self.assertEqual(notes["per_source"]["tvmaze"]["target_classification"], report.TARGET_TIMEOUT)

    def test_successful_access_reported_even_with_robots_fetch_failure(self):
        # The 2026-09-17 Fandom incident shape: robots.txt fetch failed,
        # but the real target endpoint returned HTTP 200 and was usable.
        result = self._result({
            "fandom": {
                "request_status": "ok", "http_status": 200,
                "access_classification": "API_ACCESSIBLE_DESPITE_ROBOTS_FETCH_FAILURE",
                "robots_outcome": "ROBOTS_FETCH_FAILED",
                "failure": None, "parsing_result": "ok", "error_detail": None,
            },
        })
        notes = report.build_environment_notes(result)
        info = notes["per_source"]["fandom"]
        self.assertEqual(info["target_classification"], report.SUCCESSFUL_ACCESS)
        self.assertTrue(info["robots_fetch_failed"])

    def test_source_empty_is_distinguished_from_access_failure(self):
        result = self._result({
            "sbs": {
                "request_status": "ok", "http_status": 200,
                "access_classification": None, "robots_outcome": "ALLOWED",
                "failure": "SOURCE_EMPTY", "parsing_result": "no_relevant_data", "error_detail": None,
            },
        })
        notes = report.build_environment_notes(result)
        self.assertEqual(notes["per_source"]["sbs"]["target_classification"], report.SOURCE_EMPTY_CLASSIFICATION)

    def test_flags_mismatch_when_static_note_claims_blanket_sandbox_block_but_a_source_succeeded(self):
        stale_note = (
            "Outbound network access in this build sandbox is restricted by an organization egress "
            "proxy policy to a fixed allow-list. None of the four research sources below are on that "
            "allow-list, so every live probe from THIS environment fails with ACCESS_FAILURE."
        )
        result = self._result(
            {
                "fandom": {
                    "request_status": "ok", "http_status": 200, "access_classification": None,
                    "robots_outcome": "ALLOWED", "failure": None, "parsing_result": "ok", "error_detail": None,
                },
            },
            static_note=stale_note,
        )
        notes = report.build_environment_notes(result)
        self.assertTrue(notes["static_engine_note_mismatch"])

    def test_no_mismatch_flagged_when_every_source_is_genuinely_blocked(self):
        stale_note = "Outbound network access in this build sandbox is restricted by an organization egress proxy allow-list."
        result = self._result(
            {
                "fandom": {
                    "request_status": "failed", "http_status": None, "access_classification": None,
                    "robots_outcome": None, "failure": "ACCESS_FAILURE", "error_detail": "Tunnel connection failed: 403 Forbidden",
                },
            },
            static_note=stale_note,
        )
        notes = report.build_environment_notes(result)
        self.assertFalse(notes["static_engine_note_mismatch"])

    def test_never_describes_a_source_as_sandbox_blocked_without_observed_evidence(self):
        # A source that plainly succeeded must never be classified as any
        # kind of failure, regardless of what a static/hardcoded note claims.
        result = self._result({
            "fandom": {
                "request_status": "ok", "http_status": 200, "access_classification": None,
                "robots_outcome": "ALLOWED", "failure": None, "parsing_result": "ok", "error_detail": None,
            },
        }, static_note="sandbox egress allow-list blocks everything")
        notes = report.build_environment_notes(result)
        self.assertEqual(notes["per_source"]["fandom"]["target_classification"], report.SUCCESSFUL_ACCESS)


if __name__ == "__main__":
    unittest.main()
