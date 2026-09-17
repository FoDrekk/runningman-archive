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
from ..models import FailureType, RobotsOutcome, SourceProbeResult


@dataclass
class FetchResult:
    ok: bool
    http_status: Optional[int]
    body: Optional[str]
    elapsed_ms: float
    error: Optional[str]
    failure: Optional[FailureType]
    # What happened to robots.txt on THIS attempt (RobotsOutcome.value, or
    # None if robots checking was skipped) and, when it could not be
    # fetched, the reason — kept separate from `error`/`failure` above,
    # which describe the TARGET request's own outcome. See
    # check_robots_allowed()'s docstring for why these must never be
    # conflated (2026-09-17 Fandom incident).
    robots_outcome: Optional[str] = None
    robots_detail: Optional[str] = None


def check_robots_allowed(url: str) -> tuple[RobotsOutcome, Optional[str]]:
    """
    Ask the target host's own robots.txt whether our User-Agent may fetch
    this URL. Returns (outcome, detail):

      - (ALLOWED, None)      — an explicit allow, or robots.txt is absent
                               (HTTP 404 — conventionally "everything
                               allowed").
      - (DISALLOWED, detail) — robots.txt was fetched successfully and
                               its rules explicitly disallow this URL.
                               An ESTABLISHED rule: fetch_url() below
                               NEVER attempts the target in this case,
                               no matter what.
      - (FETCH_FAILED, detail) — robots.txt itself could not be fetched
                               or parsed, for ANY reason: an HTTP error
                               other than 404 (e.g. a 403 ON ROBOTS.TXT
                               ITSELF), a timeout, a DNS/TLS failure, a
                               proxy CONNECT failure, etc. Critically,
                               this is NOT the same as an explicit
                               disallow — no rule was ever established
                               either way. Before this fix, fetch_url()
                               treated this identically to DISALLOWED and
                               never even tried the target URL, which is
                               exactly what caused Fandom to be reported
                               BLOCKED on 2026-09-17 despite its actual
                               API endpoint returning HTTP 200 — Fandom's
                               robots.txt happened to 403 while the real
                               endpoint was completely open. fetch_url()
                               now treats FETCH_FAILED as "undetermined"
                               and still attempts the target, recording
                               the fact that robots.txt could not confirm
                               permission either way.
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
            return RobotsOutcome.ALLOWED, None
        return RobotsOutcome.FETCH_FAILED, f"robots.txt fetch failed: HTTP {e.code}"
    except Exception as e:  # noqa: BLE001 — any robots.txt fetch problem is FETCH_FAILED, never DISALLOWED
        return RobotsOutcome.FETCH_FAILED, f"robots.txt fetch failed: {e}"

    if rp.can_fetch(config.USER_AGENT, url):
        return RobotsOutcome.ALLOWED, None
    return RobotsOutcome.DISALLOWED, "Disallowed by robots.txt"


def annotate_robots_access(result: SourceProbeResult, r: "FetchResult") -> None:
    """
    Record what robots.txt actually did on this attempt onto the
    SourceProbeResult, and — only for the two states that were
    previously ambiguous/wrong — the resolved access_classification
    (one of: ROBOTS_DISALLOWED, API_ACCESSIBLE_DESPITE_ROBOTS_FETCH_FAILURE,
    TARGET_ACCESS_FAILED). Never touches access_result/failure/error_detail
    — callers keep full, unmodified control over those, so the ordinary
    ALLOWED path's existing honest reporting is completely unchanged.
    """
    result.robots_outcome = r.robots_outcome
    if r.robots_outcome == RobotsOutcome.DISALLOWED.value:
        result.access_classification = "ROBOTS_DISALLOWED"
    elif r.robots_outcome == RobotsOutcome.FETCH_FAILED.value:
        if r.ok:
            result.access_classification = "API_ACCESSIBLE_DESPITE_ROBOTS_FETCH_FAILURE"
            result.warnings.append(
                f"robots.txt could not be retrieved ({r.robots_detail}); no rule was established, "
                "so the endpoint was attempted directly and it succeeded."
            )
        else:
            result.access_classification = "TARGET_ACCESS_FAILED"
            result.warnings.append(
                f"robots.txt could not be retrieved ({r.robots_detail}); the endpoint was attempted "
                f"directly anyway (no rule was established) but it also failed ({r.error}) — this "
                "looks like a genuine access/network problem, not merely a robots.txt artifact."
            )


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

    robots.txt handling (see check_robots_allowed()'s docstring for the
    full incident writeup):
      - DISALLOWED (an established rule) -> the target is NEVER attempted.
        This is never bypassed, no matter what.
      - FETCH_FAILED (robots.txt itself unreachable, no rule established)
        -> the target IS attempted; the resulting FetchResult still
        carries robots_outcome/robots_detail so the caller can report
        honestly on whichever of API_ACCESSIBLE_DESPITE_ROBOTS_FETCH_FAILURE
        or TARGET_ACCESS_FAILED it turns out to be.
      - ALLOWED -> ordinary path, unchanged from before this fix.
    """
    robots_outcome: Optional[RobotsOutcome] = None
    robots_detail: Optional[str] = None
    if respect_robots and config.RESPECT_ROBOTS_TXT:
        robots_outcome, robots_detail = check_robots_allowed(url)
        if robots_outcome is RobotsOutcome.DISALLOWED:
            return FetchResult(
                ok=False, http_status=None, body=None, elapsed_ms=0.0,
                error=robots_detail, failure=FailureType.ACCESS_FAILURE,
                robots_outcome=robots_outcome.value, robots_detail=robots_detail,
            )
        # ALLOWED or FETCH_FAILED both fall through to the real request.

    robots_kwargs = {
        "robots_outcome": robots_outcome.value if robots_outcome is not None else None,
        "robots_detail": robots_detail,
    }
    req = urllib.request.Request(url, headers={"User-Agent": config.USER_AGENT, "Accept": accept})
    t0 = time.monotonic()
    try:
        with urllib.request.urlopen(req, timeout=config.REQUEST_TIMEOUT_SECONDS) as resp:
            body = resp.read().decode("utf-8", errors="replace")
            elapsed_ms = (time.monotonic() - t0) * 1000
            return FetchResult(ok=True, http_status=resp.status, body=body, elapsed_ms=elapsed_ms, error=None, failure=None, **robots_kwargs)
    except urllib.error.HTTPError as e:
        elapsed_ms = (time.monotonic() - t0) * 1000
        return FetchResult(
            ok=False, http_status=e.code, body=None, elapsed_ms=elapsed_ms,
            error=f"HTTP {e.code}: {e.reason}", failure=classify_http_error(e), **robots_kwargs,
        )
    except Exception as e:  # noqa: BLE001 — every network failure is reported, not swallowed
        elapsed_ms = (time.monotonic() - t0) * 1000
        return FetchResult(
            ok=False, http_status=None, body=None, elapsed_ms=elapsed_ms,
            error=f"{type(e).__name__}: {e}", failure=classify_http_error(e), **robots_kwargs,
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
