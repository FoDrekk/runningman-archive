"""
benchmark/ — Phase 18: Python Research Engine Benchmarking.

Measures the existing Python Research Engine POC (models.py, sources/*,
registry.py, engine.py — all unmodified by this package) against a fixed
sample of historical episodes, instead of only ever looking at "whatever
is currently latest". See README.md in this directory for methodology,
and run_benchmark.py for the entry point.

This package does not change engine.py or registry.py: it composes their
existing, already-tested building blocks (fetch_json/fetch_url,
normalize.py, sources/htmlutil.py, and — deliberately, to avoid
duplicating tested per-episode field-extraction logic — a few adapter-
internal helpers such as fandom._fetch_episode_page and
wikipedia._extract_year) into new orchestration that targets specific
episode numbers rather than "the latest".
"""
