"""Offline unit tests for normalize.py — no network access required."""
from __future__ import annotations

import unittest

from ..normalize import (
    guest_identity_key,
    normalize_air_date,
    normalize_episode_number,
    normalize_guest_name,
    normalize_title,
)


class EpisodeNumberTests(unittest.TestCase):
    def test_plain_int(self):
        self.assertEqual(normalize_episode_number(819), 819)

    def test_episode_word(self):
        self.assertEqual(normalize_episode_number("Episode 819"), 819)

    def test_hash_form(self):
        self.assertEqual(normalize_episode_number("#819"), 819)

    def test_ep_dot_form(self):
        self.assertEqual(normalize_episode_number("Ep. 819"), 819)

    def test_korean_hoe_form(self):
        self.assertEqual(normalize_episode_number("819회"), 819)

    def test_out_of_range_rejected(self):
        self.assertIsNone(normalize_episode_number(0))
        self.assertIsNone(normalize_episode_number(5000))

    def test_none_input(self):
        self.assertIsNone(normalize_episode_number(None))

    def test_no_number_present(self):
        self.assertIsNone(normalize_episode_number("Running Man Special"))


class AirDateTests(unittest.TestCase):
    def test_iso_form(self):
        self.assertEqual(normalize_air_date("2026-09-13"), "2026-09-13")

    def test_dotted_form(self):
        self.assertEqual(normalize_air_date("2026.09.13"), "2026-09-13")

    def test_day_month_year(self):
        self.assertEqual(normalize_air_date("13 September 2026"), "2026-09-13")

    def test_month_day_year(self):
        self.assertEqual(normalize_air_date("September 13, 2026"), "2026-09-13")

    def test_korean_form(self):
        self.assertEqual(normalize_air_date("2026년 9월 13일"), "2026-09-13")

    def test_filmed_aside_stripped(self):
        self.assertEqual(normalize_air_date("2026-09-13 (filmed 2026-09-01)"), "2026-09-13")

    def test_invalid_date_rejected(self):
        self.assertIsNone(normalize_air_date("2026-13-45"))

    def test_empty_and_none(self):
        self.assertIsNone(normalize_air_date(""))
        self.assertIsNone(normalize_air_date(None))

    def test_unrecognised_text(self):
        self.assertIsNone(normalize_air_date("sometime next week"))


class TitleTests(unittest.TestCase):
    def test_strips_site_suffix(self):
        self.assertEqual(normalize_title("Running Man Episode 819 - Jeju Race - Wikipedia", 819), "Episode 819 - Jeju Race")

    def test_too_short_is_none(self):
        self.assertIsNone(normalize_title("Ep", 819))

    def test_none_input(self):
        self.assertIsNone(normalize_title(None, 819))

    def test_empty_input(self):
        self.assertIsNone(normalize_title("", 819))


class GuestNameTests(unittest.TestCase):
    def test_strips_parenthetical(self):
        self.assertEqual(normalize_guest_name("Kim Jong-kook (actor)"), "Kim Jong-kook")

    def test_strips_leading_number(self):
        self.assertEqual(normalize_guest_name("1. Song Ji-hyo"), "Song Ji-hyo")

    def test_noise_word_rejected(self):
        self.assertIsNone(normalize_guest_name("TBD"))

    def test_none_and_empty(self):
        self.assertIsNone(normalize_guest_name(None))
        self.assertIsNone(normalize_guest_name(""))

    def test_too_long_rejected(self):
        self.assertIsNone(normalize_guest_name("x" * 61))

    def test_identity_key_matches_across_spelling(self):
        self.assertEqual(guest_identity_key("Kim Jong-kook"), guest_identity_key("Kim Jong Kook"))


if __name__ == "__main__":
    unittest.main()
