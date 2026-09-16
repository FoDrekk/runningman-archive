"""Source adapters — one module per source, each exposing a probe() function."""
from . import fandom, sbs, tvmaze, wikipedia

ADAPTERS = {
    "fandom": fandom.probe,
    "tvmaze": tvmaze.probe,
    "wikipedia": wikipedia.probe,
    "sbs": sbs.probe,
}
