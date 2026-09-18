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

from ..models import FailureType, SourceProbeResult, SourceStatus
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


def _ok_robots_fetch_failed(body: dict | str, ms: float = 12.0, robots_detail: str = "robots.txt fetch failed: HTTP 403") -> FetchResult:
    # The 2026-09-17 Fandom shape: robots.txt itself failed, but this
    # (the real target) request succeeded anyway.
    text = json.dumps(body) if isinstance(body, dict) else body
    return FetchResult(
        ok=True, http_status=200, body=text, elapsed_ms=ms, error=None, failure=None,
        robots_outcome="ROBOTS_FETCH_FAILED", robots_detail=robots_detail,
    )


def _fail_robots_fetch_failed(status: int = 500, error: str = "HTTP 500: Internal Server Error", robots_detail: str = "robots.txt fetch failed: HTTP 403") -> FetchResult:
    # robots.txt failed AND the real target also failed — a genuine
    # access problem, not merely a robots.txt-layer artifact.
    return FetchResult(
        ok=False, http_status=status, body=None, elapsed_ms=5.0, error=error, failure=FailureType.ACCESS_FAILURE,
        robots_outcome="ROBOTS_FETCH_FAILED", robots_detail=robots_detail,
    )


def _fail_robots_disallowed() -> FetchResult:
    return FetchResult(
        ok=False, http_status=None, body=None, elapsed_ms=0.0, error="Disallowed by robots.txt",
        failure=FailureType.ACCESS_FAILURE, robots_outcome="ROBOTS_DISALLOWED", robots_detail="Disallowed by robots.txt",
    )


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

    def test_robots_fetch_failure_does_not_reintroduce_canonical_numbering(self):
        # Regression combining both fixes: even when robots.txt itself
        # failed (so the target was attempted anyway and succeeded —
        # the 2026-09-17 Fandom-style shape), TVmaze must still never
        # populate extracted_episode_numbers or declare itself canonical.
        fixture = _load("tvmaze_show.json")
        with patch("tools.python_research.sources.tvmaze.fetch_json",
                   return_value=(_ok_robots_fetch_failed(fixture), fixture)):
            r = tvmaze.probe()
        self.assertEqual(r.access_result, SourceStatus.USABLE)
        self.assertEqual(r.robots_outcome, "ROBOTS_FETCH_FAILED")
        self.assertEqual(r.access_classification, "API_ACCESSIBLE_DESPITE_ROBOTS_FETCH_FAILURE")
        self.assertEqual(r.extracted_episode_numbers, [])
        self.assertFalse(r.canonical_episode_numbering)


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

    def test_robots_403_but_api_200_is_still_usable(self):
        # The exact reported incident: Fandom's robots.txt returns HTTP
        # 403, but the real api.php endpoint returns HTTP 200 with valid
        # data. Before this fix, the robots.txt 403 alone forced BLOCKED
        # and the real endpoint was never even attempted.
        allpages = _load("fandom_allpages.json")
        episode = _load("fandom_episode_parse.json")
        with patch(
            "tools.python_research.sources.fandom.fetch_json",
            side_effect=[(_ok_robots_fetch_failed(allpages), allpages), (_ok(episode), episode)],
        ):
            r = fandom.probe()
        self.assertEqual(r.access_result, SourceStatus.USABLE)
        self.assertEqual(r.extracted_episode_numbers, [817, 818, 819])
        self.assertEqual(r.robots_outcome, "ROBOTS_FETCH_FAILED")
        self.assertEqual(r.access_classification, "API_ACCESSIBLE_DESPITE_ROBOTS_FETCH_FAILURE")
        self.assertTrue(any("robots.txt" in w for w in r.warnings))

    def test_robots_fetch_failed_and_api_also_fails_is_target_access_failed(self):
        # robots.txt unreachable AND the real endpoint also genuinely
        # fails — correctly distinguished from the case above.
        with patch("tools.python_research.sources.fandom.fetch_json",
                   return_value=(_fail_robots_fetch_failed(), None)):
            r = fandom.probe()
        self.assertEqual(r.access_result, SourceStatus.BLOCKED)
        self.assertEqual(r.failure, FailureType.ACCESS_FAILURE)
        self.assertEqual(r.robots_outcome, "ROBOTS_FETCH_FAILED")
        self.assertEqual(r.access_classification, "TARGET_ACCESS_FAILED")

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


def _fandom_episode_page(ep_num: int, infobox_items_html: str, outside_infobox_html: str = "") -> dict:
    """
    Builds a synthetic action=parse response for Episode/<ep_num>, with
    `infobox_items_html` (one or more `<div class="pi-item pi-data">`
    blocks) inside the portable-infobox and, optionally,
    `outside_infobox_html` placed OUTSIDE it — for asserting that
    date-like text there is never mistaken for the air date.
    """
    return {
        "parse": {
            "title": f"Episode/{ep_num}",
            "text": {
                "*": (
                    f'<aside class="portable-infobox">{infobox_items_html}</aside>'
                    f"{outside_infobox_html}"
                ),
            },
        },
    }


def _pi_item(label: str, value_html: str) -> str:
    return (
        f'<div class="pi-item pi-data"><h3 class="pi-data-label">{label}</h3>'
        f'<div class="pi-data-value">{value_html}</div></div>'
    )


class FandomAirDateLabelTests(unittest.TestCase):
    """
    Regression coverage for the bare "Date" infobox label (older episode
    pages — confirmed live on Episode/385, /400, /514) alongside the
    pre-existing "Air Date"/"Broadcast"/"Original Air" labels, and for
    the fact this must stay scoped to an exact, infobox-only label match
    — never a page-wide or substring "date" match that could pick up an
    unrelated field or unrelated page text.
    """

    @staticmethod
    def _extract(ep_num: int, infobox_items_html: str, outside_infobox_html: str = "") -> dict:
        fixture = _fandom_episode_page(ep_num, infobox_items_html, outside_infobox_html)
        result = SourceProbeResult(
            source="fandom", url="https://example.invalid", request_status="ok",
            http_status=200, response_time_ms=0.0, access_result=SourceStatus.USABLE,
            parsing_result="ok", extracted_episode_numbers=[ep_num], canonical_episode_numbering=True,
        )
        with patch("tools.python_research.sources.fandom.fetch_json", return_value=(_ok(fixture), fixture)):
            fandom._fetch_episode_page(result, ep_num)
        return result.extracted_air_dates

    def test_bare_date_label_is_recognized(self):
        dates = self._extract(999, _pi_item("Date", "January 14, 2018"))
        self.assertEqual(dates.get(999), "2018-01-14")

    def test_confirmed_real_examples_385_400_514(self):
        self.assertEqual(self._extract(385, _pi_item("Date", "January 14, 2018")).get(385), "2018-01-14")
        self.assertEqual(self._extract(400, _pi_item("Date", "May 13, 2018")).get(400), "2018-05-13")
        self.assertEqual(self._extract(514, _pi_item("Date", "August 2, 2020")).get(514), "2020-08-02")

    def test_air_date_label_still_recognized(self):
        dates = self._extract(1, _pi_item("Air Date", "September 13, 2026"))
        self.assertEqual(dates.get(1), "2026-09-13")

    def test_broadcast_label_still_recognized(self):
        dates = self._extract(2, _pi_item("Broadcast", "September 13, 2026"))
        self.assertEqual(dates.get(2), "2026-09-13")

    def test_original_air_label_still_recognized(self):
        dates = self._extract(3, _pi_item("Original Air", "September 13, 2026"))
        self.assertEqual(dates.get(3), "2026-09-13")

    def test_unrelated_infobox_label_containing_date_substring_is_not_captured(self):
        # A label that merely CONTAINS "date" (but is not the exact bare
        # "Date" label, nor "Air Date"/"Broadcast"/"Original Air") must
        # never be treated as the air date — the fix is an exact match
        # for the bare label, not a broad substring match.
        dates = self._extract(4, _pi_item("Filming Date", "January 1, 2020"))
        self.assertNotIn(4, dates)

    def test_date_like_text_outside_the_infobox_is_never_captured(self):
        # extract_infobox_pairs() only ever yields pairs from inside the
        # portable-infobox, so page-body text can never reach the label
        # regex at all — asserted explicitly as its own regression case.
        outside = "<p>This episode was originally scheduled for January 1, 2099 but was delayed.</p>"
        dates = self._extract(5, _pi_item("Air Date", "September 13, 2026"), outside_infobox_html=outside)
        self.assertEqual(dates.get(5), "2026-09-13")
        self.assertNotIn("2099-01-01", dates.values())


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

    def test_explicit_robots_disallow_is_respected_and_distinguished(self):
        # A genuine, established robots.txt rule (as real Wikipedia's
        # robots.txt does for this POC's exact action=parse&prop=text
        # request) must still block the source — but the report should
        # say WHY specifically, not lump it in with an ordinary failure.
        with patch("tools.python_research.sources.wikipedia.fetch_json",
                   return_value=(_fail_robots_disallowed(), None)):
            r = wikipedia.probe()
        self.assertEqual(r.access_result, SourceStatus.BLOCKED)
        self.assertEqual(r.failure, FailureType.ACCESS_FAILURE)
        self.assertEqual(r.robots_outcome, "ROBOTS_DISALLOWED")
        self.assertEqual(r.access_classification, "ROBOTS_DISALLOWED")


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
