"""
benchmark/fixture_transport.py — the ONLY thing this benchmark ever mocks
in "fixture" mode: urllib.request.urlopen itself, dispatched per URL to
canned files under benchmark/fixtures/. Everything above that layer
(robots.txt checking, HTTP error classification, adapter parsing, engine
resolution) runs for real and unmodified — see README.md's "Fixture vs
live mode" section.

robots.txt is deliberately answered with a plain 404 (-> ALLOWED by
convention, see sources/base.py::check_robots_allowed()) for every host
in fixture mode: robots.txt CLASSIFICATION is already covered by
tests/test_base.py and tests/test_adapters_offline.py — this benchmark's
job is field-extraction/coverage measurement, not re-litigating that.
"""
from __future__ import annotations

import json
import os
import urllib.error

_FIXTURES_DIR = os.path.join(os.path.dirname(__file__), "fixtures")


class FakeResponse:
    def __init__(self, status: int, body: str):
        self.status = status
        self._body = body.encode("utf-8")

    def read(self):
        return self._body

    def __enter__(self):
        return self

    def __exit__(self, *exc):
        return False


def _read_fixture(name: str) -> str:
    with open(os.path.join(_FIXTURES_DIR, name), encoding="utf-8") as f:
        return f.read()


def _http_error(url: str, code: int, reason: str = "error") -> urllib.error.HTTPError:
    return urllib.error.HTTPError(url, code, reason, {}, None)


class FixtureTransport:
    """
    Callable drop-in replacement for urllib.request.urlopen, used via
    unittest.mock.patch("tools.python_research.sources.base.urllib.request.urlopen", FixtureTransport()).
    """

    def __call__(self, req, timeout=None):
        url = req.full_url if hasattr(req, "full_url") else req.get_full_url()

        if url.endswith("/robots.txt"):
            raise _http_error(url, 404, "Not Found")

        if "runningman.fandom.com/api.php" in url:
            return self._fandom_api(url)
        if "en.wikipedia.org/w/api.php" in url:
            return self._wikipedia_api(url)
        if "api.tvmaze.com/shows/" in url:
            return FakeResponse(200, _read_fixture("tvmaze_show.json"))
        if "programs.sbs.co.kr" in url:
            return FakeResponse(200, _read_fixture("sbs_page.html"))

        raise AssertionError(f"benchmark fixture transport: no fixture mapped for URL: {url}")

    @staticmethod
    def _fandom_api(url: str):
        if "list=allpages" in url:
            return FakeResponse(200, _read_fixture("fandom_allpages.json"))
        if "action=parse" in url:
            for n in (700, 750, 800, 810, 819):
                if f"Episode%2F{n}" in url or f"Episode/{n}" in url:
                    try:
                        return FakeResponse(200, _read_fixture(f"fandom_episode_{n}.json"))
                    except FileNotFoundError:
                        raise _http_error(url, 404, "Not Found")
            raise _http_error(url, 404, "Not Found")
        raise AssertionError(f"benchmark fixture transport: unrecognised Fandom API call: {url}")

    @staticmethod
    def _wikipedia_api(url: str):
        for year, fname in ((2024, "wikipedia_2024.json"), (2025, "wikipedia_2025.json"), (2026, "wikipedia_2026.json")):
            if f"({year})" in url or f"%28{year}%29" in url:
                return FakeResponse(200, _read_fixture(fname))
        raise _http_error(url, 404, "Not Found")


def load_json_fixture(name: str) -> dict:
    return json.loads(_read_fixture(name))
