"""
Offline tests for sources/base.py's robots.txt classification and the
fetch_url() behavior it feeds — the 2026-09-17 Fandom-robots-403 fix.

No network access: urllib.request.urlopen is mocked directly, keyed by
which URL (robots.txt vs. the real target) is being requested, so both
requests in a single fetch_url() call can be given independent outcomes.
"""
from __future__ import annotations

import unittest
import urllib.error
from unittest.mock import patch

from ..models import FailureType, RobotsOutcome
from ..sources.base import check_robots_allowed, fetch_url


class _FakeResponse:
    def __init__(self, status: int, body: str = ""):
        self.status = status
        self._body = body.encode("utf-8")

    def read(self):
        return self._body

    def __enter__(self):
        return self

    def __exit__(self, *exc):
        return False


def _mock_urlopen(robots_outcome, target_outcome=None):
    """
    robots_outcome / target_outcome: ("ok", status, body) or ("error", exception)
    target_outcome=None means the target must NEVER be requested (asserts on it).
    """
    def _urlopen(req, timeout=None):
        url = req.full_url if hasattr(req, "full_url") else req.get_full_url()
        is_robots = url.endswith("/robots.txt")
        spec = robots_outcome if is_robots else target_outcome
        if spec is None:
            raise AssertionError(f"target URL should never have been requested: {url}")
        kind = spec[0]
        if kind == "ok":
            return _FakeResponse(spec[1], spec[2])
        raise spec[1]

    return _urlopen


def _http_error(code: int, reason: str = "Forbidden") -> urllib.error.HTTPError:
    return urllib.error.HTTPError("https://example.com/robots.txt", code, reason, {}, None)


class CheckRobotsAllowedTests(unittest.TestCase):
    def test_404_is_allowed(self):
        with patch("tools.python_research.sources.base.urllib.request.urlopen",
                   _mock_urlopen(("error", _http_error(404, "Not Found")))):
            outcome, detail = check_robots_allowed("https://example.com/api")
        self.assertEqual(outcome, RobotsOutcome.ALLOWED)
        self.assertIsNone(detail)

    def test_explicit_allow_is_allowed(self):
        robots_txt = "User-agent: *\nAllow: /api\n"
        with patch("tools.python_research.sources.base.urllib.request.urlopen",
                   _mock_urlopen(("ok", 200, robots_txt))):
            outcome, detail = check_robots_allowed("https://example.com/api")
        self.assertEqual(outcome, RobotsOutcome.ALLOWED)

    def test_explicit_disallow_is_disallowed(self):
        robots_txt = "User-agent: *\nDisallow: /api\n"
        with patch("tools.python_research.sources.base.urllib.request.urlopen",
                   _mock_urlopen(("ok", 200, robots_txt))):
            outcome, detail = check_robots_allowed("https://example.com/api")
        self.assertEqual(outcome, RobotsOutcome.DISALLOWED)
        self.assertTrue(detail)

    def test_non_404_http_error_on_robots_is_fetch_failed_not_disallowed(self):
        # The exact Fandom shape: robots.txt itself returns 403.
        with patch("tools.python_research.sources.base.urllib.request.urlopen",
                   _mock_urlopen(("error", _http_error(403)))):
            outcome, detail = check_robots_allowed("https://example.com/api")
        self.assertEqual(outcome, RobotsOutcome.FETCH_FAILED)
        self.assertIn("403", detail)

    def test_generic_network_failure_on_robots_is_fetch_failed(self):
        with patch("tools.python_research.sources.base.urllib.request.urlopen",
                   _mock_urlopen(("error", urllib.error.URLError("timed out")))):
            outcome, detail = check_robots_allowed("https://example.com/api")
        self.assertEqual(outcome, RobotsOutcome.FETCH_FAILED)
        self.assertTrue(detail)


class FetchUrlRobotsClassificationTests(unittest.TestCase):
    def test_robots_403_but_target_200_is_accessible(self):
        # Regression: Fandom's robots.txt returns 403, but its real API
        # endpoint returns 200 — the target MUST be attempted and its
        # real result MUST win, not be pre-empted by the robots failure.
        with patch(
            "tools.python_research.sources.base.urllib.request.urlopen",
            _mock_urlopen(("error", _http_error(403)), ("ok", 200, '{"ok": true}')),
        ):
            r = fetch_url("https://runningman.fandom.com/api.php?action=query")
        self.assertTrue(r.ok)
        self.assertEqual(r.http_status, 200)
        self.assertEqual(r.robots_outcome, RobotsOutcome.FETCH_FAILED.value)
        self.assertIn("403", r.robots_detail)

    def test_explicit_disallow_never_attempts_target(self):
        # An established rule must NEVER be bypassed — target_outcome=None
        # makes the mock raise if the target URL is ever requested.
        robots_txt = "User-agent: *\nDisallow: /w/api.php\n"
        with patch(
            "tools.python_research.sources.base.urllib.request.urlopen",
            _mock_urlopen(("ok", 200, robots_txt), None),
        ):
            r = fetch_url("https://en.wikipedia.org/w/api.php?action=parse")
        self.assertFalse(r.ok)
        self.assertEqual(r.failure, FailureType.ACCESS_FAILURE)
        self.assertEqual(r.robots_outcome, RobotsOutcome.DISALLOWED.value)

    def test_robots_fetch_failed_and_target_also_fails(self):
        # robots.txt unreachable AND the real endpoint also fails — a
        # genuine access problem, correctly distinguished from the
        # robots-403-but-target-200 case above.
        with patch(
            "tools.python_research.sources.base.urllib.request.urlopen",
            _mock_urlopen(("error", _http_error(403)), ("error", _http_error(500, "Internal Server Error"))),
        ):
            r = fetch_url("https://example.com/api")
        self.assertFalse(r.ok)
        self.assertEqual(r.robots_outcome, RobotsOutcome.FETCH_FAILED.value)
        self.assertIn("500", r.error)

    def test_ordinary_allowed_path_is_unchanged(self):
        robots_txt = "User-agent: *\nAllow: /api\n"
        with patch(
            "tools.python_research.sources.base.urllib.request.urlopen",
            _mock_urlopen(("ok", 200, robots_txt), ("ok", 200, "hello")),
        ):
            r = fetch_url("https://example.com/api")
        self.assertTrue(r.ok)
        self.assertEqual(r.robots_outcome, RobotsOutcome.ALLOWED.value)
        self.assertIsNone(r.robots_detail)


if __name__ == "__main__":
    unittest.main()
