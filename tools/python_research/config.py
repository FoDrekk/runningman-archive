"""
config.py — endpoints and settings for the Python Research Engine POC.

Values are copied from the PHP system's own config/scraping.php so the
two are asking the same real endpoints for the same real show — this is
a comparability requirement, not a coincidence. See README.md's mapping
table for exactly which PHP config key each value below mirrors.

Nothing here is a secret: every source used is a public, keyless API or
a public web page, matching the PHP system's own "no API key needed"
sources (config/scraping.php's fandom/tvmaze/wikipedia/sbs blocks).
"""
from __future__ import annotations

import os

# ── HTTP behaviour ──────────────────────────────────────────────────
# Deliberately conservative: this POC is a measurement tool, not a
# scraper meant to run unattended. One request per source per run,
# a real User-Agent (never spoofed/rotated), a short timeout, and
# whatever robots.txt says is respected — see sources/base.py.
REQUEST_TIMEOUT_SECONDS = float(os.environ.get("RM_POC_TIMEOUT", "10"))
USER_AGENT = "RunningManArchive-ResearchPOC/0.1 (+https://github.com/FoDrekk/runningman-archive; research use, not for production scraping)"
RESPECT_ROBOTS_TXT = True

# ── Source endpoints (mirrors config/scraping.php's 'sources' block) ──
FANDOM_BASE = "https://runningman.fandom.com"
TVMAZE_BASE = "https://api.tvmaze.com"
TVMAZE_SHOW_ID = 3479          # "Running Man" on TVmaze — same id as sources.tvmaze.show_id
WIKIPEDIA_BASE = "https://en.wikipedia.org"

# SBS has no stable per-episode JSON endpoint (see SbsScraper.php's own
# comment: "SBS reorganises its endpoints periodically"). These are the
# same candidate URLs the PHP adapter tries, in the same order.
SBS_CANDIDATE_URLS = [
    "https://programs.sbs.co.kr/enter/runningman/visualboard/54666",
    "https://programs.sbs.co.kr/enter/runningman",
]

# ── Discovery bounds ────────────────────────────────────────────────
# The years actually worth checking for "latest episode" on a
# year-indexed source (Wikipedia's "List of Running Man episodes (YEAR)").
CURRENT_YEAR_CANDIDATES_BACK = 1  # this year, then last year, matching WikipediaScraper::latestEpisode()

OUTPUT_DIR = os.path.join(os.path.dirname(__file__), "output")
RESEARCH_REPORT_PATH = os.path.join(OUTPUT_DIR, "research_report.json")
COMPARISON_REPORT_PATH = os.path.join(OUTPUT_DIR, "comparison.json")

# Max characters of a raw response kept as a debug snippet — never the
# full payload (Phase 8: "Do NOT store huge raw HTML/API responses").
RAW_SNIPPET_MAX_CHARS = 400
