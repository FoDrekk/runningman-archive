#!/usr/bin/env python3
"""
run_benchmark.py — the benchmark entry point.

    python -m tools.python_research.benchmark.run_benchmark [--mode fixture|live]

Defaults to --mode fixture: this environment's outbound network access
is restricted (see the main engine's own README.md 'Known limitations'),
so a real --mode live run is provided for completeness but is expected
to fail with ACCESS_FAILURE here for the same environmental reason —
that failure would say nothing about the sources' own accessibility.
Run --mode live from an unrestricted machine to get a real answer.

Writes:
  benchmark/output/benchmark_report.json   (full structured result + summary)
  benchmark/output/benchmark_report.md     (human-readable report)
"""
from __future__ import annotations

import argparse
import json
import os
import sys

if __package__ in (None, ""):
    sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "..", "..")))
    __package__ = "tools.python_research.benchmark"

from . import report  # noqa: E402
from .runner import BenchmarkRunner  # noqa: E402

OUTPUT_DIR = os.path.join(os.path.dirname(__file__), "output")


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--mode", choices=("fixture", "live"), default="fixture")
    args = parser.parse_args()

    result = BenchmarkRunner(mode=args.mode).run()
    summary = report.summarize(result)
    result["summary"] = summary

    # Mode-suffixed filenames — a live run never silently overwrites a
    # fixture run or vice versa, so both remain available for comparison
    # (see "Clearly distinguish live-source measurements from
    # fixture-based measurements").
    json_path = os.path.join(OUTPUT_DIR, f"benchmark_report_{args.mode}.json")
    md_path = os.path.join(OUTPUT_DIR, f"benchmark_report_{args.mode}.md")

    os.makedirs(OUTPUT_DIR, exist_ok=True)
    with open(json_path, "w", encoding="utf-8") as f:
        json.dump(result, f, indent=2, ensure_ascii=False, default=str)

    md = report.render_markdown(result, summary)
    with open(md_path, "w", encoding="utf-8") as f:
        f.write(md)

    print(md)
    print(f"\nFull JSON written to {json_path}")
    print(f"Markdown report written to {md_path}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
