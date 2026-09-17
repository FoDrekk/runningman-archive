"""
Offline tests for each source adapter's PARSING logic.

Every network call is mocked (unittest.mock.patch on each adapter
module's imported fetch_json/fetch_url) — these tests must pass with
no internet access at all. They exercise exactly the scenarios the
live probe would hit: a clean success, an empty/irrelevant response, a
malformed response (parser failure), and a blocked/unreachable source.
"""
from __future__ import annotations

import json
import os
import unittest
from unittest.mock import patch

from ..models import FailureType, SourceStatus
from ..sources.base import FetchResult
from ..sources import fandom, sbs, tvmaze, wikipedia

_FIXTURES = os.path.join(os.path.dirname(__file__), "fixtures")


def _load(name: str) -> dict:
    with open(os.path.join(_FIXTURES, name), encoding="utf-8") as f:
        return json.load(f)


def _ok(body: dict | str, ms: float = 12.0) -> FetchResult:
    text = json.dumps(body) if isinstance(body, dict) else body
    return FetchResult(ok=True, http_status=200, body=text, elapsed_ms=ms, error=None, failure=None)


def _fail(status: int = 403, error: str = "HTTP 403: Forbidden") -> FetchResult:
    return FetchResult(ok=False, http_status=status, body=None, elapsed_ms=5.0, error=error, failure=FailureType.ACCESS_FAILURE)


class TvMazeAdapterTests(unittest.TestCase):
    def test_usable_extraction_never_asserts_canonical_numbers(self):
        # Regression for the 2026-09-17 "latest episode = 1980" incident:
        # TVmaze must NEVER populate extracted_episode_numbers, no matter
        # how clean-looking the freeform title text is ("Episode 818/819"
        # in this fixture would, pre-fix, have round-tripped perfectly).
        fixture = _load("tvmaze_show.json")
        with patch("tools.python_research.sources.tvmaze.fetch_json", return_value=(_ok(fixture), fixture)):
            r = tvmaze.probe()
        self.assertEqual(r.access_result, SourceStatus.USABLE)
        self.assertEqual(r.extracted_episode_numbers, [])
        self.assertEqual(r.extracted_titles, {})
        self.assertEqual(r.extracted_air_dates, {})
        self.assertFalse(r.canonical_episode_numbering)

    def test_usable_extraction_still_reports_non_canonical_hints(self):
        # air_date / title / thumbnail / source-local identity may still
        # be genuinely reported — just never keyed by a trusted number.
        fixture = _load("tvmaze_show.json")
        with patch("tools.python_research.sources.tvmaze.fetch_json", return_value=(_ok(fixture), fixture)):
            r = tvmaze.probe()
        self.assertEqual(len(r.non_canonical_episode_hints), 3)
        self.assertTrue(r.thumbnail_available)
        by_id = {h["tvmaze_episode_id"]: h for h in r.non_canonical_episode_hints}
        self.assertEqual(by_id[2]["air_date"], "2026-09-13")
        self.assertEqual(by_id[2]["title_digit_sequence"], 819)
        self.assertTrue(by_id[2]["reason"])
        self.assertIn("not", by_id[2]["reason"].lower())

    def test_tvmaze_episode_id_cannot_become_canonical_episode_number(self):
        # A TVmaze internal episode "id" (source-local identity) must
        # never leak into extracted_episode_numbers, even when it is a
        # large, suspicious-looking value.
        fixture = {
            "_embedded": {"episodes": [
                {"id": 987654, "name": "A Perfectly Normal Title", "airdate": "2026-09-13", "image": None},
            ]}
        }
        with patch("tools.python_research.sources.tvmaze.fetch_json", return_value=(_ok(fixture), fixture)):
            r = tvmaze.probe()
        self.assertEqual(r.extracted_episode_numbers, [])
        self.assertEqual(r.non_canonical_episode_hints[0]["tvmaze_episode_id"], 987654)

    def test_tvmaze_season_episode_number_cannot_become_canonical(self):
        # TVmaze's own season/number indexing is reported for
        # transparency but must not be promoted to episode_number either
        # — no season/episode -> RM-absolute-count mapping is invented.
        fixture = {
            "_embedded": {"episodes": [
                {"id": 1, "season": 1, "number": 5, "name": "Some Title", "airdate": "2012-12-02", "image": None},
            ]}
        }
        with patch("tools.python_research.sources.tvmaze.fetch_json", return_value=(_ok(fixture), fixture)):
            r = tvmaze.probe()
        self.assertEqual(r.extracted_episode_numbers, [])
        self.assertEqual(r.non_canonical_episode_hints[0]["number_in_season"], 5)
        self.assertEqual(r.non_canonical_episode_hints[0]["season"], 1)

    def test_freeform_title_digit_sequence_never_becomes_canonical(self):
        # The exact reported incident shape: a freeform title carrying an
        # embedded digit sequence ("1980") paired with a genuine air date
        # from the same record must not surface as episode_number.
        fixture = {
            "_embedded": {"episodes": [
                {"id": 42, "name": "Running Man meets the Class of 1980", "airdate": "2012-12-02", "image": None},
            ]}
        }
        with patch("tools.python_research.sources.tvmaze.fetch_json", return_value=(_ok(fixture), fixture)):
            r = tvmaze.probe()
        self.assertEqual(r.extracted_episode_numbers, [])
        hint = r.non_canonical_episode_hints[0]
        self.assertEqual(hint["title_digit_sequence"], 1980)
        self.assertEqual(hint["air_date"], "2012-12-02")

    def test_access_failure(self):
        with patch("tools.python_research.sources.tvmaze.fetch_json", return_value=(_fail(), None)):
            r = tvmaze.probe()
        self.assertEqual(r.access_result, SourceStatus.BLOCKED)
        self.assertEqual(r.failure, FailureType.ACCESS_FAILURE)

    def test_empty_response(self):
        empty = {"_embedded": {"episodes": []}}
        with patch("tools.python_research.sources.tvmaze.fetch_json", return_value=(_ok(empty), empty)):
            r = tvmaze.probe()
        self.assertEqual(r.failure, FailureType.SOURCE_EMPTY)
        self.assertEqual(r.extracted_episode_numbers, [])

    def test_parser_failure_on_non_dict_response(self):
        with patch("tools.python_research.sources.tvmaze.fetch_json", return_value=(_ok("[]"), None)):
            r = tvmaze.probe()
        self.assertEqual(r.failure, FailureType.PARSER_FAILURE)


class FandomAdapterTests(unittest.TestCase):
    def test_usable_two_stage_extraction(self):
        allpages = _load("fandom_allpages.json")
        episode = _load("fandom_episode_parse.json")
        with patch("tools.python_research.sources.fandom.fetch_json", side_effect=[(_ok(allpages), allpages), (_ok(episode), episode)]):
            r = fandom.probe()
        self.assertEqual(r.access_result, SourceStatus.USABLE)
        self.assertEqual(r.extracted_episode_numbers, [817, 818, 819])
        self.assertEqual(r.extracted_air_dates.get(819), "2026-09-13")
        self.assertIn("Kim Jong-kook", r.extracted_guests.get(819, []))

    def test_access_failure_on_list_call(self):
        with patch("tools.python_research.sources.fandom.fetch_json", return_value=(_fail(), None)):
            r = fandom.probe()
        self.assertEqual(r.failure, FailureType.ACCESS_FAILURE)

    def test_no_matching_pages_is_source_empty(self):
        empty = {"query": {"allpages": [{"title": "Main Page"}]}}
        with patch("tools.python_research.sources.fandom.fetch_json", return_value=(_ok(empty), empty)):
            r = fandom.probe()
        self.assertEqual(r.failure, FailureType.SOURCE_EMPTY)

    def test_malformed_envelope_is_parser_failure(self):
        with patch("tools.python_research.sources.fandom.fetch_json", return_value=(_ok({"nope": True}), {"nope": True})):
            r = fandom.probe()
        self.assertEqual(r.failure, FailureType.PARSER_FAILURE)


class WikipediaAdapterTests(unittest.TestCase):
    def test_usable_extraction(self):
        fixture = _load("wikipedia_year_parse.json")
        with patch("tools.python_research.sources.wikipedia.fetch_json", return_value=(_ok(fixture), fixture)):
            r = wikipedia.probe()
        self.assertEqual(r.access_result, SourceStatus.USABLE)
        self.assertEqual(sorted(r.extracted_episode_numbers), [818, 819])
        self.assertEqual(r.extracted_titles[819], "Jeju Race")
        self.assertEqual(r.extracted_air_dates[819], "2026-09-13")

    def test_falls_back_to_previous_year_when_current_year_empty(self):
        empty = {"parse": {"text": {"*": "<p>No table here</p>"}}}
        fixture = _load("wikipedia_year_parse.json")
        with patch("tools.python_research.sources.wikipedia.fetch_json", side_effect=[(_ok(empty), empty), (_ok(fixture), fixture)]):
            r = wikipedia.probe()
        self.assertEqual(r.access_result, SourceStatus.USABLE)
        self.assertIn(819, r.extracted_episode_numbers)

    def test_access_failure_on_every_year(self):
        with patch("tools.python_research.sources.wikipedia.fetch_json", return_value=(_fail(), None)):
            r = wikipedia.probe()
        self.assertEqual(r.failure, FailureType.ACCESS_FAILURE)


class SbsAdapterTests(unittest.TestCase):
    def test_pattern_match_is_usable(self):
        html = "<html>Running Man 819회 방송</html>"
        with patch("tools.python_research.sources.sbs.fetch_url", return_value=FetchResult(True, 200, html, 20.0, None, None)):
            r = sbs.probe()
        self.assertEqual(r.access_result, SourceStatus.USABLE)
        self.assertIn(819, r.extracted_episode_numbers)

    def test_no_pattern_is_source_empty(self):
        html = "<html>Running Man homepage</html>"
        with patch("tools.python_research.sources.sbs.fetch_url", return_value=FetchResult(True, 200, html, 20.0, None, None)):
            r = sbs.probe()
        self.assertEqual(r.failure, FailureType.SOURCE_EMPTY)

    def test_access_failure(self):
        with patch("tools.python_research.sources.sbs.fetch_url", return_value=FetchResult(False, 403, None, 5.0, "HTTP 403: Forbidden", FailureType.ACCESS_FAILURE)):
            r = sbs.probe()
        self.assertEqual(r.failure, FailureType.ACCESS_FAILURE)


if __name__ == "__main__":
    unittest.main()
