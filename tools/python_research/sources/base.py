"""
sources/base.py — the shared HTTP + robots.txt + error-classification
plumbing every adapter uses, and the SourceAdapter interface itself.

    PHP concept                       Python POC equivalent
    ---------------------------------- --------------------------------
    RmScraper (AbstractScraper.php)    SourceAdapter (this file)
    RmHttpClient                       fetch_url() (this file)
    AbstractScraper::classifyFetchStatus() classify_http_error() (this file)
    robots.txt check inside RmHttpClient  check_robots_allowed() (this file)

Stdlib only: urllib.request for HTTP, urllib.robotparser for robots.txt,
socket/ssl/urllib.error for failure classification. No requests library,
no proxy rotation, no fingerprint spoofing, no header forgery beyond a
single honest, identifying User-Agent string.
"""
from __future__ import annotations

import abc
import json
import socket
import ssl
import time
import urllib.error
import urllib.parse
import urllib.request
import urllib.robotparser
from dataclasses import dataclass
from typing import Optional

from .. import config
from ..models import FailureType, SourceProbeResult


@dataclass
class FetchResult:
    ok: bool
    http_status: Optional[int]
    body: Optional[str]
    elapsed_ms: float
    error: Optional[str]
    failure: Optional[FailureType]


def check_robots_allowed(url: str) -> tuple[bool, Optional[str]]:
    """
    Ask the target host's own robots.txt whether our User-Agent may fetch
    this URL. Returns (allowed, error) — on any failure to even reach
    robots.txt, this defaults to NOT allowed (fail closed): a POC that
    cannot confirm permission does not proceed to guess.
    """
    parsed = urllib.parse.urlsplit(url)
    robots_url = f"{parsed.scheme}://{parsed.netloc}/robots.txt"
    rp = urllib.robotparser.RobotFileParser()
    rp.set_url(robots_url)
    try:
        req = urllib.request.Request(robots_url, headers={"User-Agent": config.USER_AGENT})
        with urllib.request.urlopen(req, timeout=config.REQUEST_TIMEOUT_SECONDS) as resp:
            raw = resp.read().decode("utf-8", errors="replace")
        rp.parse(raw.splitlines())
    except urllib.error.HTTPError as e:
        if e.code == 404:
            # No robots.txt at all is conventionally "everything allowed".
            return True, None
        return False, f"robots.txt fetch failed: HTTP {e.code}"
    except Exception as e:  # noqa: BLE001 — any robots.txt fetch problem fails closed
        return False, f"robots.txt fetch failed: {e}"
    return rp.can_fetch(config.USER_AGENT, url), None


def classify_http_error(exc: BaseException) -> FailureType:
    """Map a raised exception to the Phase 7 failure taxonomy."""
    if isinstance(exc, urllib.error.HTTPError):
        return FailureType.ACCESS_FAILURE
    if isinstance(exc, (urllib.error.URLError, socket.timeout, socket.gaierror, ssl.SSLError, TimeoutError, ConnectionError)):
        return FailureType.ACCESS_FAILURE
    return FailureType.ACCESS_FAILURE


def fetch_url(url: str, accept: str = "*/*", respect_robots: bool = True) -> FetchResult:
    """
    One GET request: robots.txt check, real timing, honest error
    classification. No retries, no backoff, no proxy rotation — a
    single, observable attempt per call, matching this POC's role as a
    measurement tool rather than a production crawler.
    """
    if respect_robots and config.RESPECT_ROBOTS_TXT:
        allowed, robots_error = check_robots_allowed(url)
        if not allowed:
            return FetchResult(
                ok=False, http_status=None, body=None, elapsed_ms=0.0,
                error=robots_error or "Disallowed by robots.txt",
                failure=FailureType.ACCESS_FAILURE,
            )

    req = urllib.request.Request(url, headers={"User-Agent": config.USER_AGENT, "Accept": accept})
    t0 = time.monotonic()
    try:
        with urllib.request.urlopen(req, timeout=config.REQUEST_TIMEOUT_SECONDS) as resp:
            body = resp.read().decode("utf-8", errors="replace")
            elapsed_ms = (time.monotonic() - t0) * 1000
            return FetchResult(ok=True, http_status=resp.status, body=body, elapsed_ms=elapsed_ms, error=None, failure=None)
    except urllib.error.HTTPError as e:
        elapsed_ms = (time.monotonic() - t0) * 1000
        return FetchResult(
            ok=False, http_status=e.code, body=None, elapsed_ms=elapsed_ms,
            error=f"HTTP {e.code}: {e.reason}", failure=classify_http_error(e),
        )
    except Exception as e:  # noqa: BLE001 — every network failure is reported, not swallowed
        elapsed_ms = (time.monotonic() - t0) * 1000
        return FetchResult(
            ok=False, http_status=None, body=None, elapsed_ms=elapsed_ms,
            error=f"{type(e).__name__}: {e}", failure=classify_http_error(e),
        )


def fetch_json(url: str) -> tuple[FetchResult, Optional[dict]]:
    r = fetch_url(url, accept="application/json")
    if not r.ok or r.body is None:
        return r, None
    try:
        return r, json.loads(r.body)
    except json.JSONDecodeError:
        return r, None


def snippet(text: Optional[str]) -> Optional[str]:
    """A short debug snippet only — never the full raw response (Phase 8)."""
    if text is None:
        return None
    t = text.strip().replace("\n", " ")
    return t[: config.RAW_SNIPPET_MAX_CHARS] + ("…" if len(t) > config.RAW_SNIPPET_MAX_CHARS else "")


class SourceAdapter(abc.ABC):
    """One source. Mirrors RmScraper's role, not its exact interface."""

    name: str

    @abc.abstractmethod
    def probe(self) -> SourceProbeResult:
        """
        Make exactly one real (or robots-blocked) attempt against this
        source and return a fully-populated SourceProbeResult — never
        raises; every failure mode becomes a classified result instead.
        """
        raise NotImplementedError
