#!/usr/bin/env python3
"""
run.py — the live research probe entry point.

    python -m python_research.run          (from inside tools/)
    python -m tools.python_research.run    (from the repo root)

Makes real network requests to the four configured sources (Fandom,
TVmaze, Wikipedia, SBS). Writes output/research_report.json and
output/comparison.json, and prints a human-readable summary. Does NOT
touch the production MySQL database — see php_diagnostics.py for the
one read-only, best-effort exception (shelling out to the PHP CLI to
reuse already-recorded local diagnostic state, never to write it).

For hermetic, network-free tests, see tests/ — this file is the live
counterpart, not something the test suite imports.
"""
from __future__ import annotations

import json
import os
import sys

if __package__ in (None, ""):
    # Allow `python run.py` directly from within tools/python_research/.
    sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "..")))
    __package__ = "python_research"

from . import config  # noqa: E402
from .engine import ResearchEngine  # noqa: E402
from .models import utc_now_iso  # noqa: E402
from .php_diagnostics import collect_php_diagnostics  # noqa: E402


def _print_table(report: dict) -> None:
    print("\nRUNNING MAN RESEARCH POC\n")
    header = f"{'Source':<22}{'Status':<13}{'Latest EP':<12}{'Air Date':<15}{'Fields'}"
    print(header)
    print("-" * len(header))
    for name, r in report["source_results"].items():
        status = r["access_result"]
        eps = r["extracted_episode_numbers"]
        latest = max(eps) if eps else "-"
        air_date = r["extracted_air_dates"].get(str(latest)) or r["extracted_air_dates"].get(latest) or "-"
        fields = ",".join(r_fields) if (r_fields := _short_fields(r)) else "-"
        print(f"{name:<22}{status:<13}{str(latest):<12}{str(air_date):<15}{fields}")

    print()
    res = report["resolution"]
    if res["latest_episode"] is not None:
        print(f"Resolved:\nLatest supported episode: {res['latest_episode']}")
        print(f"Air date: {res['air_date'] or 'unknown'}")
    else:
        print("Resolved: INSUFFICIENT_EVIDENCE — no source could confirm an aired episode number.")

    print("\nEvidence:")
    if res["latest_episode_evidence"]:
        for e in res["latest_episode_evidence"]:
            if e["field"] == "episode_number":
                print(f"- {e['source']} -> episode {e['value']} (confidence {e['confidence']:.2f}: {e['rationale']})")
    else:
        print("- none")

    print("\nConflicts:")
    print("None" if not res["conflicts"] else "\n".join(f"- {c['detail']}" for c in res["conflicts"]))

    print("\nSources with no usable data:", ", ".join(res["sources_with_no_data"]) or "none")
    print("Sources inaccessible:", ", ".join(res["sources_inaccessible"]) or "none")
    print()


def _short_fields(r: dict) -> list[str]:
    out = []
    if r["extracted_episode_numbers"]:
        out.append("ep")
    if r["extracted_air_dates"]:
        out.append("date")
    if r["extracted_titles"]:
        out.append("title")
    if r["extracted_guests"]:
        out.append("guests")
    if r["extracted_synopsis"]:
        out.append("synopsis")
    if r["thumbnail_available"]:
        out.append("thumbnail")
    return out


def build_comparison(report: dict) -> dict:
    php = collect_php_diagnostics()
    py_sources_attempted = len(report["source_results"])
    py_sources_usable = sum(1 for r in report["source_results"].values() if r["access_result"] == "usable")
    py_fields = sorted({f for r in report["source_results"].values() for f in _short_fields(r)})
    py_blocking = [
        f"{name}: {r['failure']}" + (f" — {r['error_detail']}" if r.get("error_detail") else "")
        for name, r in report["source_results"].items() if r.get("failure")
    ]

    comparison = {
        "generated_at": utc_now_iso(),
        "php": (
            {
                "sources_attempted": php["sources_attempted"],
                "sources_usable": php["sources_usable"],
                "latest_episode": php.get("archive_max_episode_stored_locally"),
                "fields_extracted": php["covered_fields"],
                "blocking_reasons": php["blocking_reasons"],
                "note": "latest_episode here is the ARCHIVE'S STORED maximum episode number (a local DB fact), "
                        "not a live latest-episode detection — the PHP system itself (RmLatestEpisode) never "
                        "treats stored-max as 'latest' either; sources_usable/blocking_reasons come from "
                        "whatever real scraping activity this database has already recorded, not a fresh "
                        "live PHP run (which this environment's network restrictions block just as they "
                        "block the Python side below).",
            }
            if php.get("ok")
            else {
                "sources_attempted": None,
                "sources_usable": None,
                "latest_episode": None,
                "fields_extracted": [],
                "blocking_reasons": [],
                "note": f"PHP-side comparison NOT possible from this environment: {php.get('reason')}",
            }
        ),
        "python": {
            "sources_attempted": py_sources_attempted,
            "sources_usable": py_sources_usable,
            "latest_episode": report["resolution"]["latest_episode"],
            "fields_extracted": py_fields,
            "blocking_reasons": py_blocking,
        },
    }
    return comparison


def main() -> int:
    os.makedirs(config.OUTPUT_DIR, exist_ok=True)
    engine = ResearchEngine()
    report = engine.run()

    with open(config.RESEARCH_REPORT_PATH, "w", encoding="utf-8") as f:
        json.dump(report, f, indent=2, ensure_ascii=False)

    comparison = build_comparison(report)
    with open(config.COMPARISON_REPORT_PATH, "w", encoding="utf-8") as f:
        json.dump(comparison, f, indent=2, ensure_ascii=False)

    _print_table(report)
    print(f"Full report written to {config.RESEARCH_REPORT_PATH}")
    print(f"PHP vs Python comparison written to {config.COMPARISON_REPORT_PATH}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
