"""
sources/htmlutil.py — minimal, dependency-free HTML extraction helpers.

The PHP system uses DOMDocument + DOMXPath (includes/scraping/scrapers/
WikipediaScraper.php's rmWikiParseHtml(), FandomScraper::parseInfobox())
for the same two jobs done here: reading a MediaWiki wikitable by header
label, and reading a Fandom "portable infobox" by pi-data-label/
pi-data-value pairs. This is a deliberately smaller reimplementation
using only html.parser — matching the brief's "keep the POC intentionally
small" instruction — not a byte-for-byte port. See README.md's "Known
limitations" for what this simpler parser does not handle (e.g. rowspan
alignment across multi-part-special rows, which the PHP parser handles
explicitly and this one does not).
"""
from __future__ import annotations

import re
from html.parser import HTMLParser


def _classes(attrs: list[tuple[str, str | None]]) -> set[str]:
    for k, v in attrs:
        if k == "class" and v:
            return set(v.split())
    return set()


class _WikitableParser(HTMLParser):
    """Collects every <table class="wikitable"> as rows of cell text."""

    def __init__(self) -> None:
        super().__init__(convert_charrefs=True)
        self.tables: list[list[list[str]]] = []
        self._table_depth = 0
        self._in_wikitable = False
        self._current_table: list[list[str]] = []
        self._current_row: list[str] | None = None
        self._cell_buf: list[str] = []
        self._in_cell = False

    def handle_starttag(self, tag, attrs):
        if tag == "table":
            self._table_depth += 1
            if "wikitable" in _classes(attrs):
                self._in_wikitable = True
                self._current_table = []
        elif tag == "tr" and self._in_wikitable:
            self._current_row = []
        elif tag in ("td", "th") and self._in_wikitable:
            self._in_cell = True
            self._cell_buf = []
        elif tag == "br" and self._in_cell:
            self._cell_buf.append(" ")

    def handle_endtag(self, tag):
        if tag == "table":
            self._table_depth -= 1
            if self._in_wikitable and self._table_depth == 0:
                self.tables.append(self._current_table)
                self._in_wikitable = False
        elif tag == "tr" and self._in_wikitable and self._current_row is not None:
            self._current_table.append(self._current_row)
            self._current_row = None
        elif tag in ("td", "th") and self._in_wikitable and self._in_cell:
            text = re.sub(r"\s+", " ", "".join(self._cell_buf)).strip()
            if self._current_row is not None:
                self._current_row.append(text)
            self._in_cell = False

    def handle_data(self, data):
        if self._in_cell:
            self._cell_buf.append(data)


def extract_wikitables(html: str) -> list[list[list[str]]]:
    """Every wikitable on the page, each as a list of rows of cell text."""
    p = _WikitableParser()
    p.feed(html)
    return p.tables


class _InfoboxParser(HTMLParser):
    """
    Collects Fandom "portable infobox" pi-item label/value pairs.
    Tracks element depth per pi-item so a label/value div nested inside
    another pi-item cannot bleed into the wrong pair.
    """

    def __init__(self) -> None:
        super().__init__(convert_charrefs=True)
        self.pairs: list[tuple[str, str, list[str]]] = []  # (label, value_text, value_links)
        self._stack: list[tuple[str, set[str], int]] = []  # (tag, classes, depth)
        self._depth = 0
        self._item_depth: int | None = None
        self._label_depth: int | None = None
        self._value_depth: int | None = None
        self._label_buf: list[str] = []
        self._value_buf: list[str] = []
        self._value_links: list[str] = []
        self._in_link = False
        self._link_buf: list[str] = []

    def handle_starttag(self, tag, attrs):
        self._depth += 1
        classes = _classes(attrs)
        self._stack.append((tag, classes, self._depth))

        if self._item_depth is None and "pi-item" in classes:
            self._item_depth = self._depth
            self._label_buf, self._value_buf, self._value_links = [], [], []
        elif self._item_depth is not None:
            if self._label_depth is None and tag == "h3" and "pi-data-label" in classes:
                self._label_depth = self._depth
            elif self._value_depth is None and "pi-data-value" in classes:
                self._value_depth = self._depth
            elif self._value_depth is not None and tag == "a":
                self._in_link = True
                self._link_buf = []
            elif tag == "br" and self._value_depth is not None:
                self._value_buf.append(", ")

    def handle_endtag(self, tag):
        if self._stack and self._stack[-1][0] == tag:
            _, _, depth = self._stack.pop()
        else:
            depth = self._depth

        if self._in_link and tag == "a":
            self._value_links.append(re.sub(r"\s+", " ", "".join(self._link_buf)).strip())
            self._in_link = False

        if self._label_depth == depth:
            self._label_depth = None
        if self._value_depth == depth:
            self._value_depth = None
        if self._item_depth == depth:
            label = re.sub(r"\s+", " ", "".join(self._label_buf)).strip()
            value = re.sub(r"\s+", " ", "".join(self._value_buf)).strip()
            if label:
                self.pairs.append((label, value, list(self._value_links)))
            self._item_depth = None

        self._depth -= 1

    def handle_data(self, data):
        if self._in_link:
            self._link_buf.append(data)
        elif self._label_depth is not None:
            self._label_buf.append(data)
        elif self._value_depth is not None:
            self._value_buf.append(data)


def extract_infobox_pairs(html: str) -> list[tuple[str, str, list[str]]]:
    """[(label, value_text, value_link_texts), ...] for every infobox item."""
    p = _InfoboxParser()
    p.feed(html)
    return p.pairs
