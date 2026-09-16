"""
normalize.py — pure, dependency-free normalization functions.

Mirrors the SHAPE of includes/scraping/DataNormalizer.php (multi-format
date parsing, title cleanup, guest-name cleanup, absolute-episode-number
extraction) without copying its implementation. Every function here is
pure (no I/O, no network) so it is directly unit-testable offline.
"""
from __future__ import annotations

import re
from datetime import date

_MONTHS = {
    "jan": 1, "january": 1, "feb": 2, "february": 2, "mar": 3, "march": 3,
    "apr": 4, "april": 4, "may": 5, "jun": 6, "june": 6, "jul": 7, "july": 7,
    "aug": 8, "august": 8, "sep": 9, "sept": 9, "september": 9, "oct": 10,
    "october": 10, "nov": 11, "november": 11, "dec": 12, "december": 12,
}

_GUEST_NOISE = {
    "guest", "guests", "cast", "special guest", "special guests",
    "members", "n/a", "none", "tbd", "unknown",
}


def normalize_episode_number(raw) -> int | None:
    """
    Extract an absolute Running Man episode number from a string or int.

    Mirrors TvMazeScraper::absoluteEpisodeNumber() / TheTvdbScraper::locate():
    look for "Episode 813", "Ep. 813", "E813", "#813", or a bare number,
    and reject anything outside a plausible range. Returns None — never a
    guess — when nothing recognisable is present.
    """
    if raw is None:
        return None
    if isinstance(raw, int):
        n = raw
    else:
        s = str(raw)
        m = re.search(r"(?:ep(?:isode)?\.?\s*)?#?(\d{1,4})\s*(?:회)?", s, re.IGNORECASE)
        if not m:
            return None
        n = int(m.group(1))
    return n if 1 <= n <= 2000 else None


def normalize_air_date(raw: str | None) -> str | None:
    """
    Normalize a date string to ISO YYYY-MM-DD.

    Accepts (mirroring DataNormalizer::date()):
      - "2026-09-13", "2026.09.13", "2026/09/13"
      - "13 September 2026"
      - "September 13, 2026"
      - "2026년 9월 13일"
    Returns None for anything unrecognised — never a guessed date.
    """
    if not raw:
        return None
    s = str(raw).strip()
    if not s:
        return None
    # Strip a parenthesised aside, e.g. "2026-09-13 (filmed 2026-09-01)".
    s = re.sub(r"\((?:filmed|촬영)[^)]*\)", "", s, flags=re.IGNORECASE).strip()

    m = re.search(r"(\d{4})\s*년\s*(\d{1,2})\s*월\s*(\d{1,2})\s*일", s)
    if m:
        return _assemble(int(m.group(1)), int(m.group(2)), int(m.group(3)))

    m = re.search(r"\b(\d{4})[-.\/](\d{1,2})[-.\/](\d{1,2})\b", s)
    if m:
        return _assemble(int(m.group(1)), int(m.group(2)), int(m.group(3)))

    m = re.search(r"\b(\d{1,2})\s+([A-Za-z]{3,9})\s+(\d{4})\b", s)
    if m:
        mon = _MONTHS.get(m.group(2).lower())
        if mon:
            return _assemble(int(m.group(3)), mon, int(m.group(1)))

    m = re.search(r"\b([A-Za-z]{3,9})\s+(\d{1,2}),?\s+(\d{4})\b", s)
    if m:
        mon = _MONTHS.get(m.group(1).lower())
        if mon:
            return _assemble(int(m.group(3)), mon, int(m.group(2)))

    return None


def _assemble(year: int, month: int, day: int) -> str | None:
    try:
        return date(year, month, day).isoformat()
    except ValueError:
        return None


def normalize_title(raw: str | None, ep_num: int) -> str | None:
    """
    Clean a scraped title, stripping site-suffix noise. Returns None
    (never a fabricated placeholder) when nothing usable remains — the
    caller decides how to represent "no title", exactly like the PHP
    normalizer's caller decides whether "Episode #NNN" is a placeholder.
    """
    if not raw:
        return None
    s = str(raw).strip()
    if not s or len(s) < 4:
        return None
    # Strip trailing site/publisher noise.
    s = re.sub(
        r"\s*[-–|]\s*(Wikipedia|위키백과|myrm\.tv|MyRunningMan|My Running Man.*|MyRM.*|MyDramaList|SBS.*)$",
        "", s, flags=re.IGNORECASE,
    ).strip()
    # Strip a leading show name.
    s = re.sub(r"^(Running\s*Man|런닝맨)\s*[–\-—:|]?\s*", "", s, flags=re.IGNORECASE).strip()
    return s or None


def normalize_guest_name(raw: str | None) -> str | None:
    """Clean a scraped guest name; returns None for noise/placeholders."""
    if not raw:
        return None
    s = str(raw)
    s = re.sub(r"<[^>]+>", "", s)  # strip any stray HTML
    s = re.sub(r"^[\s\-–—•*·,;:]+|[\s\-–—•*·,;:]+$", "", s)
    s = re.sub(r"^\d+[.)]\s*", "", s)          # "1. Name"
    s = re.sub(r"\s*\([^)]*\)\s*$", "", s)     # trailing "(actor)"
    s = re.sub(r"\s*\[[^\]]*\]\s*$", "", s)
    s = re.sub(r"\s+[–—]\s+.*$", "", s)        # trailing " – role"
    s = re.sub(r"\s+", " ", s).strip()
    if not s or len(s) < 2 or len(s) > 60:
        return None
    if s.lower() in _GUEST_NOISE:
        return None
    return s


def guest_identity_key(raw: str | None) -> str:
    """A loose identity key for de-duplicating guest names across sources."""
    n = normalize_guest_name(raw)
    if n is None:
        return ""
    n = n.lower()
    return re.sub(r"[^a-z0-9가-힣]+", "", n)
