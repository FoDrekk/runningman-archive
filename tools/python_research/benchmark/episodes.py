"""
benchmark/episodes.py — episode-sample selection for the benchmark.

Two things live here:

1. The FIXED reference sample (SAMPLE_EPISODES/sample()) used only by
   the low-level, isolated unit tests in tests/test_benchmark.py that
   demonstrate specific structural behaviors (e.g. probes.py's honest
   "page does not exist" path) against known, hand-picked numbers —
   including one (700) deliberately NOT present in the Fandom fixture,
   to exercise that path. This fixed list is intentionally never used
   by the real end-to-end run (runner.py) — see (2).

2. select_historical_sample()/build_dynamic_samples() — what the real
   benchmark run actually uses. The historical sample is selected from
   episode numbers ACTUALLY DISCOVERED by a canonical source during
   this run (Fandom's allpages listing — see probes.discover_fandom_
   canonical_numbers()), never from a hardcoded guess. This fixes a
   real bug: a fixed sample (700/750/800/810/819) silently drifted out
   of range once Fandom's real archive only went up to episode 792 —
   every one of those numbers was a guaranteed, meaningless
   SOURCE_EMPTY before any request even ran. Selecting from the
   discovered pool guarantees every sampled number corresponds to a
   page that was confirmed to exist THIS run.

A small sample spread across the archive's history (not just the
newest episode) is what actually stresses each source's DIFFERENT
structural capability:
  - Fandom: does the "Episode/N" page-naming convention hold for OLD
    episode numbers too, not just recent ones?
  - Wikipedia: per-year list pages require knowing which year an
    arbitrary historical episode aired in. Since this benchmark no
    longer hardcodes a year-hint table (that table cannot cover
    numbers it didn't anticipate), the year hint for a dynamically
    selected episode is derived from FANDOM'S OWN extracted air_date
    for that same episode (runner.py) — real, sourced data, never a
    guess. When Fandom has no air_date for a sampled episode, Wikipedia
    is honestly reported as not probed for it, rather than guessing a
    year.
  - SBS: has no historical archive at all (config.SBS_CANDIDATE_URLS only
    ever reflects the CURRENT program page) — this benchmark reports that
    structurally as NOT_APPLICABLE for every non-current sample episode,
    rather than misreporting it as an extraction failure.
  - TVmaze: has no canonical numbering (see registry.CANONICAL_EPISODE_
    SOURCES) — this benchmark NEVER maps a sample number onto a specific
    TVmaze episode object. It only ever reports pure air_date corroboration
    against whatever a canonical source already resolved for that episode.

DEFAULT_SAMPLE_SIZE is intentionally small (5 points): this is a
POC-scale benchmark, not an exhaustive audit — see README.md's
"Limitations".
"""
from __future__ import annotations

import dataclasses
from typing import Iterable, Optional

SAMPLE_EPISODES: list[int] = [700, 750, 800, 810, 819]

# Fixed reference bookkeeping for the ISOLATED unit tests only (see (1)
# in the module docstring) — never consulted by the real run.
WIKIPEDIA_YEAR_HINT: dict[int, int] = {
    700: 2024,
    750: 2025,
    800: 2026,
    810: 2026,
    819: 2026,
}

CURRENT_EPISODE: int = 819

DEFAULT_SAMPLE_SIZE: int = 5


@dataclasses.dataclass(frozen=True)
class EpisodeSample:
    number: int
    wikipedia_year_hint: Optional[int]
    is_current: bool


def sample() -> list[EpisodeSample]:
    """The FIXED reference sample — see module docstring (1). Not used by runner.py."""
    return [
        EpisodeSample(n, WIKIPEDIA_YEAR_HINT[n], n == CURRENT_EPISODE)
        for n in SAMPLE_EPISODES
    ]


def select_historical_sample(candidates: Iterable[int], size: int = DEFAULT_SAMPLE_SIZE) -> list[int]:
    """
    Deterministically picks up to `size` episode numbers spread evenly
    across the sorted, de-duplicated candidate pool — e.g. the episode
    numbers a canonical source (Fandom) actually reported this run.

    Never returns a number absent from `candidates` (it only ever
    indexes into the sorted pool). If `candidates` has fewer than
    `size` distinct entries, every candidate is returned — the caller
    is expected to report the resulting (reduced) sample size
    explicitly rather than treat it as a full sample.

    Deterministic and reproducible: for a fixed candidate pool and
    size, the same indices are chosen every time (pure function of the
    sorted pool's contents and length, no randomness, no wall-clock
    input).
    """
    pool = sorted(set(candidates))
    n = len(pool)
    if n == 0 or size <= 0:
        return []
    if n <= size:
        return pool
    if size == 1:
        return [pool[0]]

    indices: list[int] = []
    for i in range(size):
        idx = (i * (n - 1)) // (size - 1)
        if indices and idx <= indices[-1]:
            idx = indices[-1] + 1
        indices.append(min(idx, n - 1))
    return [pool[i] for i in sorted(set(indices))]


def build_dynamic_samples(discovered_numbers: Iterable[int], size: int = DEFAULT_SAMPLE_SIZE) -> list[EpisodeSample]:
    """
    Builds this run's actual EpisodeSample list from episode numbers a
    canonical source discovered THIS run (see probes.discover_fandom_
    canonical_numbers()) — never from a hardcoded list.

    The highest discovered number is this benchmark's own definition of
    "the current episode" (the same role episodes.CURRENT_EPISODE
    played for the fixed sample — bookkeeping, not a claim that it is
    the true broadcast-confirmed latest; see engine.py's own, separate,
    air-date-gated resolution for that). It is always included and
    explicitly labeled `is_current=True`, in addition to whatever
    historical spread select_historical_sample() picks (the two may
    naturally overlap at the high end — that is not double-counted,
    since is_current is a label on one EpisodeSample, not a second probe).

    wikipedia_year_hint is left unset (None) here — runner.py fills it
    in per-sample from Fandom's own extracted air_date once probed,
    which is the only real, sourced data available for it at this point.
    """
    pool = sorted(set(discovered_numbers))
    if not pool:
        return []
    current = pool[-1]
    historical = select_historical_sample(pool, size)
    numbers = sorted(set(historical) | {current})
    return [EpisodeSample(n, None, n == current) for n in numbers]
