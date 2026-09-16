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
    def test_usable_extraction(self):
        fixture = _load("tvmaze_show.json")
        with patch("tools.python_research.sources.tvmaze.fetch_json", return_value=(_ok(fixture), fixture)):
            r = tvmaze.probe()
        self.assertEqual(r.access_result, SourceStatus.USABLE)
        self.assertIn(818, r.extracted_episode_numbers)
        self.assertIn(819, r.extracted_episode_numbers)
        self.assertEqual(r.extracted_air_dates[819], "2026-09-13")
        self.assertTrue(r.thumbnail_available)
        # "Unrelated Special" has no absolute episode number -> correctly absent, not guessed.
        self.assertEqual(len(r.extracted_episode_numbers), 2)

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
