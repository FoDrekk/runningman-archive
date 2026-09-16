"""
engine.py — orchestration, evidence-building, and resolution.

    PHP concept                          Python POC equivalent
    ------------------------------------ --------------------------------
    RmResearchService::researchEpisode() ResearchEngine.run()
    RmFieldResolver::resolve()           ResearchEngine._build_evidence()
    RmLatestEpisode::detect()/           ResearchEngine._resolve_latest()
      detectDetailed()                     — same aired-vs-announced-vs-
                                            conflict-vs-insufficient
                                            distinction, reimplemented
                                            independently
    RmDecisionEngine's REVIEW/conflict   ResolutionResult.conflicts +
      threshold (spread > N episodes       the same ">3 episodes" spread
      = a real disagreement, not lag)      threshold as RmLatestEpisode
"""
from __future__ import annotations

import datetime
import platform
import sys

from . import config
from .models import Evidence, FailureType, ResolutionResult, SourceProbeResult, SourceStatus, utc_now_iso
from .normalize import normalize_air_date
from .registry import FIELD_AUTHORITY, SourceRegistry

CONFLICT_SPREAD_THRESHOLD = 3  # same tolerance RmLatestEpisode::detect() uses for "normal source lag"


def _confidence_and_rationale(source: str, field: str, corroborated_by: list[str]) -> tuple[float, str]:
    authority_order = FIELD_AUTHORITY.get(field, [])
    is_authority = source in authority_order and authority_order.index(source) == 0
    n_corroborators = len(corroborated_by)

    if is_authority and n_corroborators > 0:
        return 0.95, f"{source} is this POC's designated authority for {field}, and {len(corroborated_by)} other source(s) agree"
    if is_authority:
        return 0.8, f"{source} is this POC's designated authority for {field} (no other source corroborates yet)"
    if n_corroborators > 0:
        return 0.7, f"{source} agrees with {len(corroborated_by)} other independent source(s) on this {field}"
    if source in authority_order:
        return 0.55, f"{source} is a recognised witness for {field} but is uncorroborated here"
    return 0.4, f"{source} is not this POC's designated authority for {field} and is uncorroborated — weak evidence"


class ResearchEngine:
    def __init__(self, registry: SourceRegistry | None = None) -> None:
        self.registry = registry or SourceRegistry()

    def run(self) -> dict:
        """Probe every source, resolve the latest episode, and return the full report dict."""
        source_results = self.registry.probe_all()
        resolution = self._resolve_latest(source_results)
        evidence = self._build_evidence(source_results, resolution.latest_episode)
        resolution.latest_episode_evidence = evidence

        return {
            "timestamp": utc_now_iso(),
            "environment": {
                "python_version": sys.version.split()[0],
                "platform": platform.platform(),
                "note": "Outbound network access in this build sandbox is restricted by an organization "
                        "egress proxy policy to a fixed allow-list (pypi.org, api.anthropic.com, etc). "
                        "None of the four research sources below are on that allow-list, so every live "
                        "probe from THIS environment fails with ACCESS_FAILURE regardless of the target "
                        "site's own accessibility. This is a sandbox network policy, not a finding about "
                        "the sources themselves — see README.md 'Known limitations'.",
            },
            "source_results": {name: r.to_dict() for name, r in source_results.items()},
            "resolution": resolution.to_dict(),
        }

    # ── Resolution ────────────────────────────────────────────────
    def _resolve_latest(self, source_results: dict[str, SourceProbeResult]) -> ResolutionResult:
        today = datetime.date.today().isoformat()

        # Per source: the highest episode number that source ALSO backs
        # with an air_date <= today (i.e. actually aired, not merely
        # announced) — mirrors RmLatestEpisode::detectDetailed()'s own
        # aired-vs-upcoming distinction, never "highest number = latest".
        aired_max: dict[str, int] = {}
        upcoming: dict[str, int] = {}
        number_only_max: dict[str, int] = {}
        sources_with_no_data: list[str] = []
        sources_inaccessible: list[str] = []
        failures: list[dict] = []

        for name, r in source_results.items():
            if r.access_result == SourceStatus.BLOCKED or r.failure == FailureType.ACCESS_FAILURE:
                sources_inaccessible.append(name)
                failures.append({"source": name, "type": FailureType.ACCESS_FAILURE.value, "detail": r.error_detail})
                continue
            if r.access_result == SourceStatus.ERROR:
                failures.append({"source": name, "type": FailureType.PARSER_FAILURE.value, "detail": r.error_detail})
                continue
            if not r.extracted_episode_numbers:
                sources_with_no_data.append(name)
                if r.failure:
                    failures.append({"source": name, "type": r.failure.value, "detail": r.error_detail or "; ".join(r.warnings)})
                continue

            aired = [n for n in r.extracted_episode_numbers if r.extracted_air_dates.get(n, "9999-99-99") <= today]
            future = [n for n in r.extracted_episode_numbers if n not in aired and r.extracted_air_dates.get(n)]
            number_only = [n for n in r.extracted_episode_numbers if n not in aired and n not in future]

            if aired:
                aired_max[name] = max(aired)
            if future:
                upcoming[name] = min(future)  # the nearest announced-but-not-aired episode
            if number_only:
                number_only_max[name] = max(number_only)

        insufficient_evidence = not aired_max
        conflicts: list[dict] = []
        resolved_latest = None
        resolved_air_date = None

        if aired_max:
            resolved_latest = max(aired_max.values())
            spread = resolved_latest - min(aired_max.values())
            if spread > CONFLICT_SPREAD_THRESHOLD:
                conflicts.append({
                    "field": "latest_episode",
                    "type": FailureType.RESOLUTION_CONFLICT.value,
                    "detail": f"Sources disagree on the latest AIRED episode by {spread} episodes (normal lag is "
                              f"up to {CONFLICT_SPREAD_THRESHOLD}) — resolved to the highest, but this should be "
                              f"verified by a human before being trusted.",
                    "per_source": aired_max,
                })
            # The air_date the highest-reporting source(s) attached to that episode.
            for name, r in source_results.items():
                if aired_max.get(name) == resolved_latest:
                    d = r.extracted_air_dates.get(resolved_latest)
                    if d:
                        resolved_air_date = d
                        break
        elif number_only_max:
            # Evidence exists (a number was seen) but nothing confirms it aired —
            # exactly INSUFFICIENT_EVIDENCE, not a resolved answer.
            failures.append({
                "source": "*", "type": FailureType.INSUFFICIENT_EVIDENCE.value,
                "detail": f"Highest episode numbers seen ({number_only_max}) carry no confirming air date from any source — "
                          f"not reported as the latest episode.",
            })

        return ResolutionResult(
            latest_episode=resolved_latest,
            latest_episode_evidence=[],  # filled in by run()
            air_date=normalize_air_date(resolved_air_date) if resolved_air_date else resolved_air_date,
            conflicts=conflicts,
            sources_with_no_data=sources_with_no_data,
            sources_inaccessible=sources_inaccessible,
            insufficient_evidence=insufficient_evidence,
            failures=failures,
        )

    # ── Evidence ──────────────────────────────────────────────────
    def _build_evidence(self, source_results: dict[str, SourceProbeResult], latest_episode: int | None) -> list[Evidence]:
        if latest_episode is None:
            return []
        evidence: list[Evidence] = []
        supporting_sources = [
            name for name, r in source_results.items()
            if latest_episode in r.extracted_episode_numbers
        ]
        for name in supporting_sources:
            r = source_results[name]
            others = [s for s in supporting_sources if s != name]
            conf, rationale = _confidence_and_rationale(name, "episode_number", others)
            evidence.append(Evidence(
                source=name, field="episode_number", value=latest_episode,
                confidence=conf, url=r.url, retrieved_at=utc_now_iso(),
                status="usable" if others else "weak", rationale=rationale,
            ))
            if latest_episode in r.extracted_air_dates:
                d = r.extracted_air_dates[latest_episode]
                date_others = [
                    s for s in supporting_sources
                    if s != name and source_results[s].extracted_air_dates.get(latest_episode) == d
                ]
                dconf, drationale = _confidence_and_rationale(name, "air_date", date_others)
                evidence.append(Evidence(
                    source=name, field="air_date", value=d,
                    confidence=dconf, url=r.url, retrieved_at=utc_now_iso(),
                    status="usable" if date_others else "corroborating" if others else "weak",
                    rationale=drationale,
                ))
        return evidence
