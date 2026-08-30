<?php
// ============================================================
// WikipediaScraper — English Wikipedia, via the MediaWiki parse API.
//
// The DOM table parser below is PRESERVED VERBATIM from the original
// includes/scraper.php. It is the most battle-tested code in this
// project: it handles th/td header alignment, rowspan and colspan
// carry-over, and nested tables, all of which real Running Man
// episode tables use heavily (a single "Mission" cell spanning four
// episode rows is routine). It was debugged against live pages and
// must not be replaced with regex parsing.
//
// What CHANGED around it: fetching now goes through RmHttpClient
// (classified errors, retry policy, robots, rate limiting) and
// RmCache instead of ad-hoc temp files, and the adapter reports
// parser warnings when a page loads but yields zero episodes.
//
// Fields: title · air_date · synopsis · guests · mission · teams ·
//         results · special_notes
// ============================================================
require_once __DIR__ . '/AbstractScraper.php';

function wikiClean(string $html): string {
    $t = preg_replace('/\[\d+\]/', '', $html);
    $t = strip_tags($t);
    $t = html_entity_decode($t, ENT_QUOTES|ENT_HTML5, 'UTF-8');
    return trim(preg_replace('/\s+/', ' ', $t));
}


// ── Wikipedia page titles ───────────────────────────────────────
// IMPORTANT: "List_of_Running_Man_episodes" (no year) is a near-empty
// hub/redirect page (~360 chars) — it does NOT contain episode tables.
// The real data lives on YEAR-SPECIFIC pages, confirmed by the actual
// Wikipedia article structure: "List of Running Man episodes (2024)".
// Every fetch below MUST target a specific year.

// ── Wikipedia: fetch & cache ONE YEAR'S episode page ───────────
// Same contract as before (kept for admin/diagnostics.php), now
// routed through the shared HTTP client + cache.
function rmWikiFetchYearPage(int $year, bool $bypassCache = false): ?string {
    $http  = RmHttpClient::instance();
    $isCurrent = $year >= (int)date('Y');
    $ttl   = $isCurrent ? (int)rmScrapeConfig('cache.ttl_index', 1800)
                        : (int)rmScrapeConfig('cache.ttl_reference', 604800);

    // 2010 is sometimes the exception — Wikipedia's earliest seasons can
    // live on the un-suffixed page if the show hadn't been split into
    // yearly articles yet. Try the year-suffixed title first regardless.
    $titles = ["List of Running Man episodes ($year)"];
    if ($year === 2010) $titles[] = "List of Running Man episodes";

    foreach ($titles as $title) {
        $url = 'https://en.wikipedia.org/w/api.php?' . http_build_query([
            'action'=>'parse','page'=>$title,
            'prop'=>'text','format'=>'json','disablelimitreport'=>1,'disableeditsection'=>1,
        ]);
        [$data, $res] = $http->getJson($url, [
            'timeout'      => 20,
            'cache_ttl'    => $ttl,
            'cache_key'    => "wiki:year:$year:" . md5($title),
            'cache_type'   => 'api',
            'bypass_cache' => $bypassCache,
            'delay_ms'     => (int)rmScrapeConfig('sources.wikipedia.delay_ms', 600),
        ]);
        if (!$res->ok || !is_array($data)) continue;
        if (isset($data['error'])) continue;   // "missingtitle" etc — try next candidate
        $html = $data['parse']['text']['*'] ?? null;
        // A real year page with ~50 episode rows is always several KB.
        // Anything under 2000 chars is a stub/redirect, not real data.
        if ($html && strlen($html) > 2000) return $html;
    }
    return null;
}

// ── Wikipedia: parse ONE year page's wikitable(s) into episodes ─
// Running Man's Wikipedia tables use: "Ep." | "Airdate (Filming date)" |
// "Title" | "Guest(s)" | "Teams" | "Mission" | "Results" — confirmed via
// raw HTML inspection (_trace_detection.php Step 3b).
//
// IMPORTANT: this used to be regex-based with a lazy (.*?)<\/table>
// capture for the whole table body. That breaks the moment a citation/
// footnote popup or any other element renders its OWN nested <table>
// inside a cell (common in real Wikipedia markup) — the lazy match
// stops at that first INNER </table>, silently truncating the real
// table and losing all the data rows. Regex cannot reliably handle
// nested tags of the same type; a real HTML parser can. Hence DOMDocument.
// Wikipedia commonly marks the FIRST column of an episode table ("Ep.")
// as a <th scope="row"> on every data row — not <td> — since it acts as
// a row header. Querying "./td" alone on a data row therefore SKIPS that
// column entirely, shifting every subsequent index left by one and
// silently pulling the wrong data into every field (confirmed via debug
// trace: epCell ended up containing the Airdate text instead of the
// episode number). Pulling th+td together, in actual document order via
// direct child-node traversal, keeps the same column alignment for both
// the header row and every data row regardless of which tag each site
// happens to use for which column.
function rmGetRowCells(DOMNode $row): array {
    $cells = [];
    foreach ($row->childNodes as $child) {
        if ($child->nodeName === 'td' || $child->nodeName === 'th') $cells[] = $child;
    }
    return $cells;
}

// ── Colspan/rowspan-aware cell extraction ───────────────────────
// Same as rmGetRowCells() but also captures each cell's colspan/rowspan
// attributes, needed by rmAlignTableRows() below to correctly carry
// rowspan'd cells into the following row(s).
function rmGetRowCellsWithSpan(DOMNode $row): array {
    $cells = [];
    foreach ($row->childNodes as $child) {
        if ($child->nodeName === 'td' || $child->nodeName === 'th') {
            $colspan = (int)($child->getAttribute('colspan') ?: 1);
            $rowspan = (int)($child->getAttribute('rowspan') ?: 1);
            $cells[] = ['node' => $child, 'colspan' => max(1, $colspan), 'rowspan' => max(1, $rowspan)];
        }
    }
    return $cells;
}

// ── Build a column-aligned cell grid for an entire table ────────
// CONFIRMED BUG (found live on EP584 — see DEBUGGING_LESSONS.md/chat
// history): Running Man's multi-part specials (e.g. a Year-End Party
// filmed/aired across two weeks, shared between two episode numbers)
// use rowspan on Wikipedia to merge a cell — title/location/a team
// listing — across BOTH episodes' rows. rmGetRowCells() alone only
// ever looks at the td/th physically present IN that one row, with no
// awareness that a rowspan'd cell from the row ABOVE should still
// occupy a column in THIS row too. The result: every column after the
// rowspan gets silently shifted by however many slots the rowspan ate,
// so mission text lands in the teams field, team rosters land in the
// mission field, etc. — exactly what was observed on EP584's Main
// Mission/Teams/Result/Description fields.
//
// This walks every row of the table ONCE, in document order, tracking
// which columns are still "occupied" by a rowspan from an earlier row
// (and for how many more rows), and produces a plain colIndex=>DOMNode
// array per row with carried-forward cells correctly slotted back in
// at their original column position. Cells with colspan>1 occupy that
// many consecutive column slots (all pointing at the same node, which
// is the conventional way to read merged horizontal cells — every
// field-extractor downstream just reads textContent off whichever
// node ends up at its expected index, so a repeated node reference is
// harmless). Ordinary single-cell rows (the overwhelming majority)
// behave completely identically to the old rmGetRowCells() output.
function rmAlignTableRows(array $rawRowsWithSpan): array {
    $pending = []; // colIndex => ['node'=>DOMNode, 'remaining'=>int]
    $aligned = [];
    foreach ($rawRowsWithSpan as $rowCells) {
        $result = [];
        $col = 0; $cellPtr = 0; $guard = 0;
        // The loop must keep running until BOTH the physical cells are
        // exhausted AND every still-active rowspan column has been drained
        // for this row. EDGE CASE (found in review): if a row has FEWER
        // physical cells than the highest pending rowspan column — most
        // extremely, a completely empty <tr> while a rowspan from above is
        // still active (RM's Wikipedia tables do contain odd sub-rows:
        // rating sub-tables, footnote rows) — the old condition
        // "isset($pending[$col])" was only ever checked at the CURRENT col,
        // and col stopped advancing once physical cells ran out, so a
        // pending column sitting at a HIGHER index than the last physical
        // cell was silently dropped and its rowspan countdown desynced for
        // every following row. Tracking the max pending index explicitly,
        // and advancing past empty slots instead of breaking, fixes it.
        $maxPending = empty($pending) ? -1 : max(array_keys($pending));
        while (($cellPtr < count($rowCells) || $col <= $maxPending) && $guard++ < 1000) {
            if (isset($pending[$col])) {
                $result[$col] = $pending[$col]['node'];
                $pending[$col]['remaining']--;
                if ($pending[$col]['remaining'] <= 0) unset($pending[$col]);
                $col++;
                continue;
            }
            if ($cellPtr < count($rowCells)) {
                $c = $rowCells[$cellPtr];
                for ($k = 0; $k < $c['colspan']; $k++) {
                    $result[$col] = $c['node'];
                    if ($c['rowspan'] > 1) $pending[$col] = ['node' => $c['node'], 'remaining' => $c['rowspan'] - 1];
                    $col++;
                }
                $cellPtr++;
            } else {
                // No physical cell for this column, but a higher pending
                // rowspan column still needs draining — skip this empty slot
                // rather than breaking, so we reach it.
                $col++;
            }
        }
        $aligned[] = $result;
    }
    return $aligned;
}

function rmWikiParseHtml(string $html): array {
    $dbg = !empty($GLOBALS['__rm_debug_parse']);
    if ($dbg) echo "[debug] rmWikiParseHtml called, html length=".strlen($html)."\n";

    $episodes = [];
    if (trim($html) === '') { if ($dbg) echo "[debug] EARLY RETURN: html is empty after trim\n"; return $episodes; }

    $dom = new DOMDocument();
    libxml_use_internal_errors(true); // real-world HTML is never fully valid — don't let warnings leak
    // The encoding declaration forces DOMDocument to treat the string as
    // UTF-8; without it, non-ASCII (Korean names, em-dashes) gets mangled.
    $loadOk = $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    if ($dbg) echo "[debug] loadHTML returned: ".($loadOk?'true':'FALSE')."\n";
    libxml_clear_errors();
    $xpath = new DOMXPath($dom);

    $tables = $xpath->query("//table[contains(concat(' ', normalize-space(@class), ' '), ' wikitable ')]");
    if ($dbg) echo "[debug] wikitable query result: ".($tables===false ? 'FALSE (query error)' : $tables->length." tables")."\n";
    if ($tables === false || $tables->length === 0) { if ($dbg) echo "[debug] EARLY RETURN: no wikitables found\n"; return $episodes; }

    $tableIndex = -1;
    foreach ($tables as $table) {
        $tableIndex++;
        $rows = $xpath->query("./tr", $table);
        if ($rows->length === 0) $rows = $xpath->query("./tbody/tr", $table);
        if ($rows->length === 0) $rows = $xpath->query(".//tr", $table);
        if ($dbg) echo "[debug] table[$tableIndex]: rows found = {$rows->length}\n";
        if ($rows->length < 2) { if ($dbg) echo "[debug] table[$tableIndex]: SKIP (less than 2 rows)\n"; continue; }

        // Build the column-aligned grid for the WHOLE table up front (see
        // rmAlignTableRows() for why this matters — naive per-row
        // extraction breaks on rowspan'd cells from multi-part specials).
        $rawRowsWithSpan = [];
        foreach ($rows as $rowNode) $rawRowsWithSpan[] = rmGetRowCellsWithSpan($rowNode);
        $alignedRows = rmAlignTableRows($rawRowsWithSpan);

        // Header columns identified from the FIRST row's cells — using the
        // same combined th+td extraction as data rows, so column indices
        // line up consistently between the header and every data row.
        $headerCells = $alignedRows[0] ?? [];
        if ($dbg) echo "[debug] table[$tableIndex]: header cells found = ".count($headerCells)."\n";
        if (count($headerCells) === 0) { if ($dbg) echo "[debug] table[$tableIndex]: SKIP (no header cells)\n"; continue; }

        $cols = ['ep'=>-1,'title'=>-1,'date'=>-1,'plot'=>-1,'guest'=>-1,'mission'=>-1,'teams'=>-1,'results'=>-1];
        foreach ($headerCells as $i => $th) {
            $ht = trim(strtolower(preg_replace('/\s+/', ' ', $th->textContent)));
            if ($dbg) echo "[debug] table[$tableIndex]: header[$i] = \"$ht\"\n";
            if (preg_match('/^ep\.?$|^no\.?$|^#$|no\..*series|series.*no/', $ht)) $cols['ep']=$i;
            elseif (str_contains($ht,'title'))                          $cols['title']=$i;
            elseif (preg_match('/air.?date|release.?date|broadcast/', $ht)) $cols['date']=$i;
            elseif (str_contains($ht,'guest'))                          $cols['guest']=$i;
            elseif (str_contains($ht,'mission'))                        $cols['mission']=$i;
            elseif (str_contains($ht,'team'))                           $cols['teams']=$i;
            elseif (str_contains($ht,'result'))                         $cols['results']=$i;
            elseif (preg_match('/plot|synopsis|summary|^desc/', $ht))   $cols['plot']=$i;
        }
        if ($dbg) echo "[debug] table[$tableIndex]: cols=".json_encode($cols)."\n";
        if ($cols['ep'] < 0) { if ($dbg) echo "[debug] table[$tableIndex]: SKIP (no ep column identified)\n"; continue; }

        for ($r = 1; $r < $rows->length; $r++) {
            $cells = $alignedRows[$r] ?? [];
            if ($dbg && $r <= 2) echo "[debug] table[$tableIndex] row[$r]: cell count = ".count($cells)."\n";
            if (count($cells) < 2) continue;

            $epCell = $cells[$cols['ep']] ?? null;
            $epNum  = $epCell ? (int)preg_replace('/[^\d]/', '', $epCell->textContent) : 0;
            if ($dbg && $r <= 2) echo "[debug] table[$tableIndex] row[$r]: epCell text=\"".($epCell?trim($epCell->textContent):'NULL')."\" -> epNum=$epNum\n";
            if ($epNum < 1 || $epNum > 2000) continue;

            $ep = [];

            if ($cols['title'] >= 0 && ($tc = $cells[$cols['title']] ?? null)) {
                $italics = $xpath->query(".//i", $tc);
                $ep['title'] = $italics->length > 0
                    ? trim(preg_replace('/\s+/', ' ', $italics->item(0)->textContent))
                    : trim(preg_replace('/\s+/', ' ', $tc->textContent));
            }

            if ($cols['date'] >= 0 && ($dc = $cells[$cols['date']] ?? null)) {
                $dateText = trim(preg_replace('/\s+/', ' ', $dc->textContent));
                $dr = preg_split('/[\n\/(]/', $dateText)[0];
                $p  = @date('Y-m-d', strtotime(trim($dr)));
                if ($p && $p > '2009-01-01' && $p < '2030-01-01') $ep['air_date'] = $p;
            }

            if ($cols['guest'] >= 0 && ($gc = $cells[$cols['guest']] ?? null)) {
                // Guests within one cell are separated a few different ways
                // across Wikipedia's RM tables: usually <br>, but special
                // episodes with large casts (e.g. the 100-vs-100 athletes
                // specials) wrap the list in a {{hidden}}/collapsible
                // template. That template injects a <style> block and a
                // bunch of CSS (".mw-parser-output .hidden-begin{...}") plus
                // [ko] interwiki links right into the cell — naively reading
                // textContent pulled all of that in as a fake "guest" and
                // glued the real names together with no separators
                // (confirmed live: a guest literally read
                // "Jung Doo-hongKim Ki-tae...Taemi [ko].mw-parser-output
                // .hidden-begin{box-sizing:border-box;...").
                //
                // So: (a) skip style/script/comment nodes entirely, (b) treat
                // <br> AND block-level / list / link boundaries as guest
                // separators so names don't merge, and (c) scrub any residual
                // CSS/template fragments from each candidate before keeping it.
                $guests = []; $buf = '';
                $flush = function() use (&$guests, &$buf) {
                    if (trim($buf) !== '') $guests[] = trim($buf);
                    $buf = '';
                };
                $walk = function($parent) use (&$walk, &$buf, $flush) {
                    foreach ($parent->childNodes as $node) {
                        $name = $node->nodeName;
                        // Drop template/style noise outright.
                        if (in_array($name, ['style','script','#comment'], true)) continue;
                        if ($name === 'br') { $flush(); continue; }
                        // Block-level and list boundaries also separate names.
                        if (in_array($name, ['li','p','div','tr','td','ul','ol','dd','dt'], true)) {
                            $flush();
                            if ($node->hasChildNodes()) $walk($node);
                            $flush();
                            continue;
                        }
                        // Links (<a>) are usually one complete name — flush
                        // before and after so an <a>Name</a><a>Name</a> run
                        // doesn't merge into one blob.
                        if ($name === 'a') {
                            $flush();
                            $buf .= $node->textContent;
                            $flush();
                            continue;
                        }
                        if ($node->hasChildNodes()) { $walk($node); }
                        else { $buf .= $node->textContent; }
                    }
                };
                $walk($gc);
                $flush();

                // Fallback de-gluing: if the collapsible template gave us a
                // run of names with NO node boundaries (e.g.
                // "Jung Doo-hongKim Ki-taeLee Won-hee"), the walker can't
                // split what has no separator. Detect the tell-tale
                // lowercase→uppercase seam (…hong│Kim…) and split there.
                // Korean romanizations are "Word-word"/"Word Word" so a
                // lowercase letter directly followed by an uppercase one is
                // almost always a join between two names, not part of one.
                $split = [];
                foreach ($guests as $g) {
                    // Only attempt if it actually looks merged (long + multiple
                    // seams) — leave ordinary single names untouched.
                    if (strlen($g) > 22 && preg_match_all('/[a-z][A-Z]/', $g) >= 2) {
                        $parts = preg_split('/(?<=[a-z])(?=[A-Z])/', $g);
                        foreach ($parts as $p) $split[] = $p;
                    } else {
                        $split[] = $g;
                    }
                }
                $guests = $split;

                $guests = array_map(function($g) {
                    // Strip anything from a leaked CSS/template marker onward.
                    $g = preg_split('/\[(?:ko|ja|zh|en)\]|\.mw-parser-output|\{|box-sizing/i', $g)[0];
                    $g = preg_replace('/\s*\([^)]*\)\s*/', ' ', $g);   // strip (group name)
                    $g = preg_replace('/\[[^\]]*\]/', ' ', $g);        // strip [123] refs / [ko]
                    return trim(preg_replace('/\s+/', ' ', $g));
                }, $guests);
                $guests = array_values(array_unique(array_filter($guests, function($g) {
                    if ($g === '' || strtolower($g) === 'none' || strlen($g) <= 1) return false;
                    // Reject anything that still looks like markup/CSS rather
                    // than a person's name (defensive belt-and-suspenders).
                    if (preg_match('/[{}<>;]|mw-parser|box-sizing|padding|border|width:|font-/i', $g)) return false;
                    if (strlen($g) > 60) return false; // real names are short; long = merged/garbage
                    return true;
                })));
                if ($guests) $ep['guests'] = $guests;
            }

            if ($cols['mission'] >= 0 && ($mc = $cells[$cols['mission']] ?? null)) {
                $mn = trim(preg_replace('/\s+/', ' ', $mc->textContent));
                if (strlen($mn) > 5) $ep['mission'] = $mn;
            }

            if ($cols['teams'] >= 0 && ($tmc = $cells[$cols['teams']] ?? null)) {
                $tx = trim(preg_replace('/\s+/', ' ', $tmc->textContent));
                if (strlen($tx) > 2) $ep['teams'] = $tx;
            }

            if ($cols['results'] >= 0 && ($rc = $cells[$cols['results']] ?? null)) {
                $rx = trim(preg_replace('/\s+/', ' ', $rc->textContent));
                if (strlen($rx) > 2) $ep['results'] = $rx;
            }

            if ($cols['plot'] >= 0 && ($pc = $cells[$cols['plot']] ?? null)) {
                $syn = trim(preg_replace('/\s+/', ' ', $pc->textContent));
                if (strlen($syn) > 10) $ep['synopsis'] = $syn;
            }

            // NOTE: mission is intentionally NOT auto-copied into synopsis
            // here anymore — see rmScrapeEpisode() in this file for why.
            // (Was previously copied eagerly at parse time, which silently
            // discarded myrm.tv's much better narrative description for
            // most episodes — RM's Wikipedia tables almost never have a
            // dedicated Plot column, so this fallback fired on nearly
            // every episode, making the cross-source merge below think
            // Wikipedia already "had" a synopsis and never even checking
            // myrm.tv's real one. Confirmed live on EP515: DB showed the
            // terse "Prisoner Team's mission Identify and eliminated
            // boss..." instead of myrm.tv's actual readable description.)

            if ($ep) $episodes[$epNum] = $ep;
        }
        if ($dbg) echo "[debug] table[$tableIndex]: episodes accumulated so far = ".count($episodes)."\n";

        // Found and successfully parsed the episode table — no need to
        // keep checking the remaining wikitables on the page.
        if ($episodes) break;
    }
    if ($dbg) echo "[debug] rmWikiParseHtml FINAL result count = ".count($episodes)."\n";
    return $episodes;
}


// ── Wikipedia: parsed + cached episodes for ONE year ───────────
function rmWikiParseYear(int $year, bool $bypassCache = false): array {
    static $memo = [];
    if (!$bypassCache && isset($memo[$year])) return $memo[$year];

    $cache = RmCache::instance();
    $key   = "wiki:episodes:$year";
    if (!$bypassCache) {
        $hit = $cache->get($key);
        // json_decode('[]') returns [] — NOT null — so a previous failed
        // parse would otherwise look like a valid cache hit forever and
        // silently block every future fix. Never trust an empty cache.
        if (is_array($hit) && count($hit) > 0) { $memo[$year] = $hit; return $hit; }
    }

    $html     = rmWikiFetchYearPage($year, $bypassCache);
    $episodes = $html ? rmWikiParseHtml($html) : [];
    if ($episodes) {
        $ttl = $year >= (int)date('Y')
            ? (int)rmScrapeConfig('cache.ttl_index', 1800)
            : (int)rmScrapeConfig('cache.ttl_reference', 604800);
        $cache->set($key, $episodes, $ttl, 'parsed');
    } else {
        $cache->forget($key);   // clear any stale cache so the next run retries cleanly
    }
    $memo[$year] = $episodes;
    return $episodes;
}

class WikipediaScraper extends RmScraper
{
    public function name(): string { return 'wikipedia'; }
    public function parserVersion(): string { return 'wiki-3.0'; }

    public function fields(): array {
        return ['title','air_date','synopsis','guests','mission','teams','results','special_notes'];
    }

    public function episode(int $epNum, array $ctx = []): array
    {
        $year = (int)($ctx['year'] ?? rmYear($epNum));
        $url  = 'https://en.wikipedia.org/wiki/List_of_Running_Man_episodes_(' . $year . ')';
        $t0   = microtime(true);

        $all = rmWikiParseYear($year, !empty($ctx['bypass_cache']));
        $ms  = (int)round((microtime(true) - $t0) * 1000);

        if (!$all) {
            // Distinguish "couldn't reach Wikipedia" from "reached it and
            // parsed nothing" — the second means our selectors are stale.
            $reachable = rmWikiFetchYearPage($year) !== null;
            return $this->emptyResult($url,
                $reachable ? 'parser_warning' : 'fetch_failed',
                $reachable
                    ? "Year page $year loaded but produced 0 episode rows — table structure may have changed"
                    : (RmHttpClient::instance()->lastError() ?? 'Could not load the Wikipedia year page'),
                ['_ms' => $ms, '_error_class' => $reachable ? 'parser_failure' : RmHttpClient::CLASS_OTHER]
            );
        }

        $ep = $all[$epNum] ?? null;
        if (!$ep) {
            return $this->emptyResult($url, 'missing_episode',
                "Episode $epNum is not listed on the $year page (" . count($all) . ' episodes found)',
                ['_ms' => $ms, '_error_class' => 'missing_episode']);
        }

        $out = [];
        foreach (['title','air_date','synopsis','guests','mission','teams','results'] as $f) {
            if (!empty($ep[$f])) $out[$f] = $ep[$f];
        }
        if (isset($out['title'])) $out['title'] = RmNormalizer::title((string)$out['title'], $epNum);
        if (isset($out['air_date'])) $out['air_date'] = RmNormalizer::date((string)$out['air_date']) ?? $out['air_date'];

        $out['_url']         = $url;
        $out['_status']      = count($out) > 1 ? 'ok' : 'empty';
        $out['_ms']          = $ms;
        $out['_http']        = 200;
        $out['_error']       = null;
        $out['_error_class'] = RmHttpClient::CLASS_OK;
        $out['_hash']        = RmNormalizer::hash(json_encode($ep, JSON_UNESCAPED_UNICODE));
        return $out;
    }

    /** Highest episode number Wikipedia currently lists. */
    public function latestEpisode(): ?int
    {
        foreach ([(int)date('Y'), (int)date('Y') - 1] as $year) {
            $all = rmWikiParseYear($year);
            if ($all) return max(array_keys($all));
        }
        return null;
    }

    /** How many episodes the year page yields — the parser-health signal. */
    public function yearCount(int $year): int
    {
        return count(rmWikiParseYear($year));
    }
}
