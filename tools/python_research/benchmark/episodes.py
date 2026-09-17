"""
benchmark/episodes.py — the fixed episode sample this benchmark measures
against, instead of only ever looking at "whatever is currently latest".

A small, fixed sample spread across the archive's history (not just the
newest episode) is what actually stresses each source's DIFFERENT
structural capability:
  - Fandom: does the "Episode/N" page-naming convention hold for OLD
    episode numbers too, not just recent ones? (Some may genuinely not
    have a page — that is a real finding, not a bug — see probes.py.)
  - Wikipedia: per-year list pages require knowing which year an
    arbitrary historical episode aired in. WIKIPEDIA_YEAR_HINT below is
    THIS BENCHMARK'S OWN bookkeeping for that — Wikipedia itself does not
    provide a searchable absolute-number index. A wrong/missing hint is
    reported as an honest NOT_FOUND_ON_HINTED_YEAR, never guessed around.
  - SBS: has no historical archive at all (config.SBS_CANDIDATE_URLS only
    ever reflects the CURRENT program page) — this benchmark reports that
    structurally as NOT_APPLICABLE for every non-current sample episode,
    rather than misreporting it as an extraction failure.
  - TVmaze: has no canonical numbering (see registry.CANONICAL_EPISODE_
    SOURCES) — this benchmark NEVER maps a sample number onto a specific
    TVmaze episode object. It only ever reports pure air_date corroboration
    against whatever a canonical source already resolved for that episode.

SAMPLE_EPISODES is intentionally small (5 points): this is a POC-scale
benchmark, not an exhaustive audit — see README.md's "Limitations".
"""
from __future__ import annotations

import dataclasses

SAMPLE_EPISODES: list[int] = [700, 750, 800, 810, 819]

# THIS BENCHMARK'S OWN bookkeeping (not sourced from Wikipedia itself —
# see module docstring). CURRENT_EPISODE is the one sample number treated
# as "the current/latest episode" for sources (SBS) that can only ever
# speak to the present.
WIKIPEDIA_YEAR_HINT: dict[int, int] = {
    700: 2024,
    750: 2025,
    800: 2026,
    810: 2026,
    819: 2026,
}

CURRENT_EPISODE: int = 819


@dataclasses.dataclass(frozen=True)
class EpisodeSample:
    number: int
    wikipedia_year_hint: int
    is_current: bool


def sample() -> list[EpisodeSample]:
    return [
        EpisodeSample(n, WIKIPEDIA_YEAR_HINT[n], n == CURRENT_EPISODE)
        for n in SAMPLE_EPISODES
    ]
