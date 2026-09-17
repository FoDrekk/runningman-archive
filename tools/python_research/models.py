"""
models.py — the structured data model for the Python Research Engine POC.

Mirrors, at a conceptual level, the PHP scraping engine's own model
(includes/scraping/Evidence.php, SourceHealth.php) without copying its
implementation: this is a small, independent POC, not a port.

    PHP concept                          Python POC equivalent
    ------------------------------------ --------------------------------
    RmEvidence / RmEvidenceSet           Evidence / EvidenceSet
    RmSourceHealth's per-attempt record  SourceProbeResult
    RmDecisionEngine's REVIEW/UNKNOWN    ResolutionResult.conflicts /
      vocabulary (kept separate: this      .status (this POC never writes
      POC never writes anything)           to any database)
    fetch_failed/blocked/missing_episode FailureType (below) — the POC's
      /parser_warning status strings       own vocabulary, per the brief's
      (AbstractScraper::classifyFetchStatus) Phase 7 taxonomy, which is
                                            deliberately broader than any
                                            single PHP status string.

Confidence is never invented. Every Evidence carries a `rationale`
string that says, in plain language, why it has the confidence it has
(authoritative for the field vs. a corroborating witness vs. a single
uncorroborated source) — see ENGINE.md-equivalent notes in engine.py.
"""
from __future__ import annotations

import dataclasses
import enum
from datetime import datetime, timezone
from typing import Any, Optional


def utc_now_iso() -> str:
    return datetime.now(timezone.utc).isoformat(timespec="seconds")


class FailureType(str, enum.Enum):
    """Phase 7's exact taxonomy — kept as the literal vocabulary asked for."""

    ACCESS_FAILURE = "ACCESS_FAILURE"            # HTTP 403, robots restriction, timeout, DNS/network failure
    SOURCE_EMPTY = "SOURCE_EMPTY"                # request succeeded, source returned no relevant episode data
    PARSER_FAILURE = "PARSER_FAILURE"            # source returned relevant content, parser failed
    NORMALIZATION_FAILURE = "NORMALIZATION_FAILURE"  # data extracted, normalization failed
    RESOLUTION_CONFLICT = "RESOLUTION_CONFLICT"  # multiple usable sources disagree
    INSUFFICIENT_EVIDENCE = "INSUFFICIENT_EVIDENCE"  # accessible sources did not provide enough evidence


class SourceStatus(str, enum.Enum):
    """What actually happened when a source was asked."""

    USABLE = "usable"          # reached, parsed, yielded at least one usable field
    REACHABLE = "reachable"    # reached, but nothing usable was extracted (SOURCE_EMPTY or PARSER_FAILURE)
    BLOCKED = "blocked"        # ACCESS_FAILURE — 403/robots/timeout/DNS/proxy policy
    ERROR = "error"            # an unexpected exception while probing this source


class RobotsOutcome(str, enum.Enum):
    """
    What actually happened when a source's robots.txt was checked —
    kept separate from SourceStatus/FailureType because "robots.txt
    could not be fetched" and "robots.txt explicitly disallows this
    URL" are NOT the same thing (see sources/base.py::check_robots_allowed()
    and the 2026-09-17 Fandom incident: a robots.txt HTTP 403 was, before
    this fix, silently treated as identical to an explicit disallow —
    even though Fandom's real API endpoint returned HTTP 200).
    """

    ALLOWED = "ALLOWED"                    # explicit allow, or robots.txt absent (404) — conventionally "everything allowed"
    DISALLOWED = "ROBOTS_DISALLOWED"       # robots.txt fetched successfully and explicitly disallows this URL — an
                                            # ESTABLISHED rule. Always respected; the target is never attempted.
    FETCH_FAILED = "ROBOTS_FETCH_FAILED"   # robots.txt itself could not be retrieved/parsed for ANY reason (HTTP
                                            # error other than 404, timeout, DNS/TLS failure, ...). NO rule was
                                            # established either way — the target endpoint may still be attempted.


@dataclasses.dataclass
class Evidence:
    """
    One field, one value, one source, one honestly-justified confidence.

    Confidence is a float in [0, 1], never invented arbitrarily:
      - 0.9x  : the source is the documented authority for this field
                (e.g. an official broadcaster listing for air_date)
      - 0.7-0.85: an independent, structured API/wiki source with a
                track record of correct formatting for this field
      - 0.5-0.65: a corroborating witness — agrees with another source,
                but is not itself authoritative for the field
      - < 0.5 : extracted but weakly supported (single witness, unusual
                format, or a field the source does not specialise in)
    """

    source: str
    field: str
    value: Any
    confidence: float
    url: str
    retrieved_at: str
    status: str  # "usable" | "corroborating" | "weak"
    rationale: str

    def to_dict(self) -> dict:
        return dataclasses.asdict(self)


@dataclasses.dataclass
class SourceProbeResult:
    """Everything worth knowing about one attempt to ask one source."""

    source: str
    url: str
    request_status: str          # "ok" | "failed" | "skipped"
    http_status: Optional[int]
    response_time_ms: Optional[float]
    access_result: SourceStatus
    parsing_result: str          # "ok" | "no_relevant_data" | "parse_error" | "not_attempted"
    extracted_episode_numbers: list[int] = dataclasses.field(default_factory=list)
    extracted_air_dates: dict[int, str] = dataclasses.field(default_factory=dict)
    extracted_titles: dict[int, str] = dataclasses.field(default_factory=dict)
    extracted_guests: dict[int, list[str]] = dataclasses.field(default_factory=dict)
    extracted_synopsis: dict[int, str] = dataclasses.field(default_factory=dict)
    thumbnail_available: Optional[bool] = None
    warnings: list[str] = dataclasses.field(default_factory=list)
    failure: Optional[FailureType] = None
    error_detail: Optional[str] = None
    raw_snippet: Optional[str] = None   # small debug snippet only — never the full raw response

    # Self-declared capability flag: True only when THIS adapter has a
    # structurally-verified signal that its numbers are Running Man's own
    # canonical, continuous broadcast count (an explicitly-labeled column
    # header, or a page-naming convention tied 1:1 to that count) — never
    # set merely because a number was extracted. See registry.py's
    # CANONICAL_EPISODE_SOURCES and engine.py's _resolve_latest(), which
    # refuse to treat extracted_episode_numbers from a source with this
    # flag False as a candidate for the resolved "latest episode".
    canonical_episode_numbering: bool = False

    # For a source whose numbers are NOT canonical (e.g. tvmaze.py): the
    # air_date/title/thumbnail/source-local-identity it can still
    # genuinely support, reported per raw source record and explicitly
    # NOT keyed by any trusted episode number — so nothing here can ever
    # be silently treated as "episode N's data". Each dict should carry
    # its own "reason" explaining why it isn't canonical.
    non_canonical_episode_hints: list[dict] = dataclasses.field(default_factory=list)

    # What actually happened to robots.txt on this attempt — one of
    # RobotsOutcome's values, or None when robots checking was skipped
    # entirely (respect_robots=False). Recorded regardless of whether
    # the overall probe succeeded, for transparency (see sources/base.py).
    robots_outcome: Optional[str] = None

    # Only set for the two states that were previously ambiguous/wrong
    # (see RobotsOutcome's docstring): "ROBOTS_DISALLOWED" (an established
    # rule, respected — the target was never attempted), or, when
    # robots.txt itself could not be fetched, whichever of
    # "API_ACCESSIBLE_DESPITE_ROBOTS_FETCH_FAILURE" / "TARGET_ACCESS_FAILED"
    # the actual target attempt resolved to. None on the ordinary
    # ALLOWED path — that path's reporting is unchanged by this fix.
    access_classification: Optional[str] = None

    def fields_provided(self) -> list[str]:
        out = []
        if self.extracted_episode_numbers:
            out.append("episode_number")
        if self.extracted_air_dates:
            out.append("air_date")
        if self.extracted_titles:
            out.append("title")
        if self.extracted_guests:
            out.append("guests")
        if self.extracted_synopsis:
            out.append("synopsis")
        if self.thumbnail_available:
            out.append("thumbnail")
        return out

    def to_dict(self) -> dict:
        d = dataclasses.asdict(self)
        d["access_result"] = self.access_result.value
        d["failure"] = self.failure.value if self.failure else None
        return d


@dataclasses.dataclass
class ResolutionResult:
    """The engine's final answer, plus everything needed to see why."""

    latest_episode: Optional[int]
    latest_episode_evidence: list[Evidence]
    air_date: Optional[str]
    conflicts: list[dict]
    sources_with_no_data: list[str]
    sources_inaccessible: list[str]
    insufficient_evidence: bool
    failures: list[dict]

    def to_dict(self) -> dict:
        d = dataclasses.asdict(self)
        d["latest_episode_evidence"] = [e.to_dict() for e in self.latest_episode_evidence]
        return d
