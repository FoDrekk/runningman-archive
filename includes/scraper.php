<?php
// ============================================================
// Running Man Archive — Scraper
// Priority order: myrm.tv > Wikipedia > myrunningman.com
// Primary:  myrm.tv (title, synopsis, air_date, guests)
// Secondary: Wikipedia API (fills whatever myrm.tv is missing)
// Tertiary: myrunningman.com (location, tags — see rmMyRunningManExtra)
// Fallback: MyDramaList (synopsis/location/guests, only when still
//           empty after the first 3 — see rmMyDramaListEpisode)
// Always merges ALL of these — never skips a source early
// ============================================================

// Last failure reason, exposed for diagnostics/logging — e.g.
// "DNS resolution failed" vs "SSL handshake failed" vs "Timed out"
// vs "HTTP 403" tell completely different stories and need different fixes.
$GLOBALS['__rm_last_fetch_error'] = null;

function rmLastFetchError(): ?string {
    return $GLOBALS['__rm_last_fetch_error'] ?? null;
}

function rmLastMdlError(): ?string {
    return $GLOBALS['__rm_last_mdl_error'] ?? null;
}

// rmFetch — automatic retry + exponential backoff, with the REAL
// curl error captured (not just a generic "could not reach") so
// failures are diagnosable instead of a black box.
function rmFetch(string $url, int $timeout = 15, int $retries = 2): ?string {
    if (!function_exists('curl_init')) {
        $GLOBALS['__rm_last_fetch_error'] = 'PHP curl extension not available';
        return null;
    }

    $lastErr = null;
    for ($attempt = 0; $attempt <= $retries; $attempt++) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124.0 Safari/537.36',
            CURLOPT_HTTPHEADER     => ['Accept: text/html,application/json,*/*;q=0.8','Accept-Language: en-US,en;q=0.9'],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_ENCODING       => 'gzip, deflate',
        ]);
        $body   = curl_exec($ch);
        $code   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno  = curl_errno($ch);
        $errMsg = curl_error($ch);
        curl_close($ch);

        if ($body !== false && $code === 200) {
            $GLOBALS['__rm_last_fetch_error'] = null;
            return $body;
        }

        // Build a human-readable reason, since curl error codes alone
        // ("curl error 6") mean nothing to whoever reads the log later.
        if ($errno !== 0) {
            $known = [
                6  => 'DNS resolution failed — domain could not be resolved (no internet, DNS server unreachable, or domain blocked)',
                7  => 'Connection refused — host unreachable or firewall actively blocking the connection',
                28 => 'Connection timed out — server too slow to respond or network is dropping packets',
                35 => 'SSL connection failed — TLS handshake error',
                51 => 'SSL certificate verification failed',
                60 => 'SSL CA certificate problem',
            ];
            $lastErr = $known[$errno] ?? "curl error $errno: $errMsg";
        } elseif ($code > 0) {
            $lastErr = "HTTP $code response";
        } else {
            $lastErr = 'Unknown failure (no HTTP code, no curl error)';
        }

        $shouldRetry = ($code === 0 || $code >= 500);
        if (!$shouldRetry || $attempt === $retries) break;
        usleep((int)(400000 * pow(2, $attempt))); // 0.4s, 0.8s, 1.6s backoff
    }

    $GLOBALS['__rm_last_fetch_error'] = $lastErr;
    return null;
}

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
// Wikipedia article structure: "List of Running Man episodes (2024)" etc.
// Every rmWikiFetchPage() call below MUST target a specific year.

// ── Wikipedia: fetch & cache ONE YEAR'S episode page ───────────
function rmWikiFetchYearPage(int $year): ?string {
    $cache = sys_get_temp_dir()."/rm_wiki_page_$year.html";
    if (file_exists($cache) && (time()-filemtime($cache)) < 1800) { // 30 min — these change rarely
        $html = @file_get_contents($cache);
        if ($html && strlen($html) > 2000) return $html;
    }

    // 2010 is sometimes the exception — Wikipedia's earliest seasons can
    // live on the un-suffixed page if the show hadn't been split into
    // yearly articles yet. Try the year-suffixed title first regardless,
    // since that's what every other year (and the user's own Wikipedia
    // exports for 2010-2026) consistently uses.
    $titles = ["List of Running Man episodes ($year)"];
    if ($year === 2010) $titles[] = "List of Running Man episodes";

    foreach ($titles as $title) {
        $url = 'https://en.wikipedia.org/w/api.php?'.http_build_query([
            'action'=>'parse','page'=>$title,
            'prop'=>'text','format'=>'json','disablelimitreport'=>1,'disableeditsection'=>1,
        ]);
        $body = rmFetch($url, 20);
        if (!$body) continue;
        $data = json_decode($body, true);
        if (isset($data['error'])) continue; // "missingtitle" etc — try next candidate
        $html = $data['parse']['text']['*'] ?? null;
        // A real year page with ~50 episode rows is always several KB+.
        // Anything under 2000 chars is a stub/redirect, not real data.
        if ($html && strlen($html) > 2000) {
            @file_put_contents($cache, $html);
            return $html;
        }
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
function rmWikiParseYear(int $year): array {
    static $cache = [];
    if (isset($cache[$year])) return $cache[$year];

    $cacheFile = sys_get_temp_dir()."/rm_wiki_episodes_$year.json";
    if (file_exists($cacheFile) && (time()-filemtime($cacheFile)) < 1800) {
        $d = json_decode(@file_get_contents($cacheFile), true);
        // json_decode('[]') returns [] — NOT null — so a previous failed
        // parse (which wrote an empty result) would otherwise look like
        // a valid cache hit forever and silently block every future fix
        // from ever taking effect until the file expired or was deleted
        // by hand. Never trust (or write) an empty cache.
        if (is_array($d) && count($d) > 0) { $cache[$year] = $d; return $d; }
    }

    $html = rmWikiFetchYearPage($year);
    $episodes = $html ? rmWikiParseHtml($html) : [];
    if ($episodes) {
        @file_put_contents($cacheFile, json_encode($episodes));
    } else {
        @unlink($cacheFile); // clear any stale empty cache so the next run retries cleanly
    }
    $cache[$year] = $episodes;
    return $episodes;
}

// ── Wikipedia: get one episode (looks up the RIGHT year page) ──
function rmWikiEpisode(int $epNum): array {
    $year   = rmYear($epNum);
    $all    = rmWikiParseYear($year);
    $ep     = $all[$epNum] ?? [];
    $padded = str_pad($epNum,3,'0',STR_PAD_LEFT);
    $result = ['episode_number'=>$epNum,'source'=>'wikipedia','guests'=>[],'image_url'=>null];
    if (!empty($ep['title']))    $result['title']    = rmCleanTitle($ep['title'],$epNum);
    if (!empty($ep['air_date'])) $result['air_date'] = $ep['air_date'];
    if (!empty($ep['synopsis'])) $result['synopsis'] = $ep['synopsis'];
    if (!empty($ep['guests']))   $result['guests']   = $ep['guests'];
    if (!empty($ep['mission']))  $result['mission']  = $ep['mission'];
    if (!empty($ep['teams']))    $result['teams']    = $ep['teams'];
    if (!empty($ep['results']))  $result['results']  = $ep['results'];
    if (empty($result['title'])) $result['title']    = "Episode #$padded";
    return $result;
}

// ── myrm.tv: get one episode (synopsis, guests) ────────────────
// NOTE: image_url removed from here — myrm.tv's og:image is an SPA
// meta tag that is frequently absent or a generic site-wide image,
// not the real per-episode thumbnail (same root issue documented in
// DEBUGGING_LESSONS.md for synopsis/title). myrunningman.com is
// server-rendered and reliably has the real thumbnail — see
// rmMyRunningManExtra() below, which is now the thumbnail source.
function rmMyrmTvEpisode(int $epNum): array {
    $padded = str_pad($epNum,3,'0',STR_PAD_LEFT);
    $result = ['episode_number'=>$epNum,'source'=>'myrm.tv','guests'=>[],'image_url'=>null];
    $html   = rmFetch("https://myrm.tv/ep/$epNum", 12);
    if (!$html||strlen($html)<300) return $result;

    foreach ([
        '/<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)["\']/',
        '/<h1[^>]*>([^<]{5,120})<\/h1>/',
    ] as $p) {
        if (preg_match($p,$html,$m)) {
            $t=rmCleanTitle(html_entity_decode(trim($m[1]),ENT_QUOTES),$epNum);
            if (str_contains($t,' - ')) { $result['title']=$t; break; }
        }
    }
    foreach ([
        '/<meta[^>]+property=["\']og:description["\'][^>]+content=["\']([^"\']{20,})["\']/',
        '/<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']{20,})["\']/',
    ] as $p) {
        if (preg_match($p,$html,$m)) {
            $syn=html_entity_decode(trim($m[1]),ENT_QUOTES);
            if (!preg_match('/watch running man|myrunningman|episode list/i',$syn)) {
                $result['synopsis']=$syn; break;
            }
        }
    }
    // Air date: prefer the date that immediately follows a "Broadcast
    // Date" label, because myrm.tv pages show BOTH a broadcast date and a
    // filming date right next to each other, e.g.
    //   "Broadcast Date: 2020-08-09 (filmed on 2020-07-21)"
    // A naive "first YYYY-MM-DD anywhere in the HTML" match risks
    // grabbing the filming date, a meta/last-updated date, or a date from
    // a "related episodes" sidebar instead. Try the labelled form first,
    // then fall back to the loose match only if no label is present.
    if (preg_match('/Broadcast\s*Date[^0-9]{0,20}(\d{4}-\d{2}-\d{2})/i', $html, $m) && $m[1] > '2009-01-01') {
        $result['air_date'] = $m[1];
    } elseif (preg_match('/(\d{4}-\d{2}-\d{2})/', $html, $m) && $m[1] > '2009-01-01') {
        $result['air_date'] = $m[1];
    }
    return $result;
}

// ── myrunningman.com: thumbnail + location + tags ───────────────
// Server-rendered (confirmed reliably throughout this project's
// debugging history — see DEBUGGING_LESSONS.md), unlike myrm.tv's
// SPA shell. This is the primary source for the 3 fields that need
// genuine per-episode content: the real thumbnail image, filming
// location, and community tags.
//
// NOTE ON RELIABILITY: location/tag markup was identified by
// inspecting one real episode page during development, not from an
// official API — if myrunningman.com changes its page structure,
// use Admin → Diagnostics → "Single Episode Scrape Trace" to verify
// these still extract correctly, the same way the Wikipedia parser
// bugs were found and fixed.
function rmMyRunningManExtra(int $epNum): array {
    $result = ['image_url' => null, 'location' => null, 'tags' => []];
    // BUGFIX: myrunningman.com and myrm.tv are the SAME site (two domain
    // aliases for one backend — see shared footer "myrunningman.com -
    // myrm.tv" and identical stats on both). The per-episode route on
    // BOTH domains is /ep/{n}. /episodes/{n} is NOT a per-episode page —
    // it silently 200s into the paginated episode INDEX (interpreting
    // {n} as a page number), which contains zero episode-specific
    // content. That's why location/tags always came back empty: every
    // fetch was hitting the listing, never the actual episode page.
    // Confirmed by directly fetching https://www.myrunningman.com/ep/300
    // (and /episodes/300 separately, which returns "Episodes - Page 300").
    $html = rmFetch("https://www.myrunningman.com/ep/$epNum", 10);
    if (!$html || strlen($html) < 500) return $result;

    // Thumbnail — og:image on a server-rendered page is the real episode image.
    if (preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']/', $html, $m))
        $result['image_url'] = trim($m[1]);

    // Location — myrunningman.com shows "📍 Location: <name>" as plain text
    // near the top of the episode detail area.
    if (preg_match('/Location\s*:\s*<\/[^>]+>\s*([^<\n]{3,150})/i', $html, $m)
        || preg_match('/>\s*Location\s*:\s*([^<\n]{3,150})/i', $html, $m)) {
        $loc = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES));
        $loc = preg_replace('/\s+/', ' ', $loc);
        if (strlen($loc) > 2 && strtolower($loc) !== 'unknown') $result['location'] = $loc;
    }

    // Tags — rendered as a list of links to /tags/<slug> on the episode page.
    if (preg_match_all('/<a[^>]+href=["\'][^"\']*\/tags?\/[^"\']+["\'][^>]*>([^<]{2,40})<\/a>/i', $html, $tm)) {
        $tags = array_map(fn($t) => trim(html_entity_decode($t, ENT_QUOTES)), $tm[1]);
        $tags = array_values(array_unique(array_filter($tags, fn($t) => strlen($t) > 1 && !preg_match('/^(home|episodes?|guests?|tags?)$/i', $t))));
        $result['tags'] = array_slice($tags, 0, 15);
    }

    return $result;
}

// ── MyDramaList feature flag ──────────────────────────────────
// CONFIRMED DEAD END (June 2026): mydramalist.com returns a flat
// HTTP 403 to every request from this network, even after adding a
// full browser-realistic header set (Accept-Language, Sec-Fetch-*,
// Referer, etc — see rmMyDramaListEpisode() below). A bare 403 that
// survives proper headers points to TLS-fingerprint (JA3) or
// IP-reputation based blocking — neither is fixable by changing PHP
// curl's HTTP headers; that needs an actual browser TLS stack
// (headless Chrome/Playwright), which is out of scope for this project.
//
// Set to true ONLY if you've confirmed (via Admin → Diagnostics →
// Test Any URL on https://mydramalist.com/25565-running-man/episode/3)
// that this specific network/host can actually reach MyDramaList with
// a 200 response — e.g. running from a different server, through a
// proxy, etc. Leaving this false costs nothing: rmScrapeEpisode()
// simply skips the call entirely, no wasted requests.
const RM_ENABLE_MYDRAMALIST = false;

// ── MyDramaList: 4th fallback source (synopsis + location + guests) ──
// myrm.tv and Wikipedia both have real, genuine coverage gaps — many
// episodes (especially early seasons, 2010-2017) simply never had a
// synopsis written on either site. MyDramaList
// (mydramalist.com/25565-running-man/episode/{n}) has per-episode pages
// with editor-contributed synopsis text that fills many of these same
// gaps — confirmed e.g. EP3, EP24, EP300 all have real per-episode text
// here even when myrm.tv's own page has no Description line at all.
//
// IMPORTANT — MyDramaList does NOT have complete coverage either: for
// episodes nobody has written a specific synopsis for, the page falls
// back to the SHOW-level generic blurb ("In each episode, the members
// must compete in a series of games and missions to win the race...").
// That generic text is explicitly rejected below so it never pollutes
// the database — confirmed present on at least EP224/EP244/EP249.
//
// Only called when synopsis/location/guests are STILL empty after the
// first 3 sources (see rmScrapeEpisode below) — it's a 4th HTTP request
// and the slowest source, so it should never run unnecessarily during
// a bulk sync of hundreds of episodes.
//
// "Landmark:"/"Site:" (location) and "Guest:"/"Guests:" lines are
// editor-contributed free text, not a fixed structured field — labelling
// is inconsistent across episodes (a "Sie:" typo was seen on at least
// one real episode), so this matches loosely on purpose.
function rmMyDramaListEpisode(int $epNum): array {
    $result = ['synopsis' => null, 'location' => null, 'guests' => []];
    $url = "https://mydramalist.com/25565-running-man/episode/$epNum";

    // ATTEMPT: MyDramaList returned a bare HTTP 403 to rmFetch()'s normal
    // headers (confirmed live via Admin → Diagnostics → Test Any URL) —
    // basic bot-filtering, not a JS/Cloudflare challenge page (those
    // return HTTP 200 with a "just a moment" stub, not 403 outright).
    // A more complete browser-realistic header set sometimes clears this
    // tier of filtering. dedicated curl call here (not rmFetch) so this
    // doesn't change headers for every other source.
    $html = null;
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_ENCODING       => 'gzip, deflate, br',
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
            CURLOPT_HTTPHEADER     => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.9',
                'Upgrade-Insecure-Requests: 1',
                'Sec-Fetch-Dest: document',
                'Sec-Fetch-Mode: navigate',
                'Sec-Fetch-Site: none',
                'Sec-Fetch-User: ?1',
                'Referer: https://www.google.com/',
            ],
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $GLOBALS['__rm_last_mdl_error'] = ($body === false || $code !== 200)
            ? ('HTTP '.$code.($code===403?' — still blocked even with full browser headers; see note below':'')) : null;
        curl_close($ch);
        if ($body !== false && $code === 200) $html = $body;
    }
    if (!$html || strlen($html) < 500) return $result;

    $genericPattern = '/members?\s+must\s+compete\s+in\s+a\s+series\s+of\s+games?\s+and\s+missions?\s+to\s+win\s+the\s+race/i';

    $syn = null;
    if (preg_match('/<meta[^>]+property=["\']og:description["\'][^>]+content=["\']([^"\']{20,})["\']/', $html, $m)) {
        $syn = html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
    }
    if ($syn && preg_match($genericPattern, $syn)) $syn = null; // show-level blurb, not episode-specific — reject

    if ($syn) {
        // og:description is auto-truncated by the site (~200 chars) and
        // sometimes cuts off mid-word right at a trailing "Landmark:" —
        // strip any such trailing structured line so we never save a
        // synopsis that ends abruptly on a label with no value.
        $syn = preg_split('/\s*(?:Landmark|Site|Sie|Guests?)\s*:\s*$/i', $syn)[0];
        $syn = trim($syn);
        if (mb_strlen($syn) > 15) $result['synopsis'] = $syn;
    }

    if (preg_match('/\b(?:Landmark|Site|Sie)\s*:\s*([^\n\/]{3,150})/i', $html, $m)) {
        $loc = trim(preg_replace('/\s+/', ' ', $m[1]));
        if (strlen($loc) > 2) $result['location'] = $loc;
    }

    if (preg_match('/\bGuests?\s*:\s*([^\n]{3,200})/i', $html, $m)) {
        $guests = array_values(array_filter(array_map('trim', explode(',', $m[1])), fn($g) => strlen($g) > 1));
        if ($guests) $result['guests'] = $guests;
    }

    return $result;
}


// ── MAIN: ALWAYS merge myrm.tv + Wikipedia + myrunningman.com ──
// Priority order (explicit request): myrm.tv > Wikipedia > myrunningman.com
// myrm.tv          → PRIMARY: title, synopsis, air_date, guests
// Wikipedia        → fills whatever myrm.tv didn't have (title, synopsis,
//                     air_date, guests, mission, teams, results)
// myrunningman.com → thumbnail, location, tags (server-rendered, reliable —
//                     neither myrm.tv nor Wikipedia provide these at all,
//                     so there's nothing to prioritize against here)
// MyDramaList      → 4th fallback for synopsis/location/guests, only
//                    consulted if STILL empty after the first 3 — see
//                    rmMyDramaListEpisode() above for why this exists.
// NEVER skip a source just because an earlier one returned something —
// see DEBUGGING_LESSONS.md bug #2 for why that was wrong before.
function rmScrapeEpisode(int $epNum): array {
    $myrm  = rmMyrmTvEpisode($epNum);
    $wiki  = rmWikiEpisode($epNum);
    $extra = rmMyRunningManExtra($epNum);

    // myrm.tv is the base now — fill in whatever it's missing from
    // Wikipedia. 'title' is included here too (not a special case
    // anymore): rmMyrmTvEpisode() only ever sets a title when it already
    // found a real " - " descriptor (see that function — it explicitly
    // checks str_contains before assigning), so a myrm.tv title is
    // always already good by construction. If myrm.tv found nothing at
    // all, this loop falls through to Wikipedia's title, which always
    // exists (rmWikiEpisode() guarantees at least the padded fallback).
    $merged = $myrm;
    foreach (['title','synopsis','guests','air_date','mission','teams','results'] as $k)
        if (empty($merged[$k]) && !empty($wiki[$k])) $merged[$k] = $wiki[$k];
    if (empty($merged['title'])) $merged['title'] = "Episode #".str_pad($epNum,3,'0',STR_PAD_LEFT);

    // Thumbnail/location/tags come from myrunningman.com specifically —
    // neither Wikipedia nor myrm.tv reliably provide these.
    $merged['image_url'] = $extra['image_url'] ?? null;
    $merged['location']  = $extra['location']  ?? null;
    $merged['tags']      = $extra['tags']       ?? [];

    // 4th source — only fired when there's still a genuine gap, to avoid
    // an extra HTTP round-trip on every single episode during bulk sync.
    // Gated behind RM_ENABLE_MYDRAMALIST — see that constant's comment
    // above rmMyDramaListEpisode() for why this is off by default.
    $mdl = null;
    if (RM_ENABLE_MYDRAMALIST && (empty($merged['synopsis']) || empty($merged['location']) || empty($merged['guests']))) {
        $mdl = rmMyDramaListEpisode($epNum);
        if (empty($merged['synopsis']) && !empty($mdl['synopsis'])) $merged['synopsis'] = $mdl['synopsis'];
        if (empty($merged['location']) && !empty($mdl['location'])) $merged['location'] = $mdl['location'];
        if (empty($merged['guests']) && !empty($mdl['guests']))     $merged['guests']   = $mdl['guests'];
    }

    $merged['source'] = !empty($wiki['air_date']) ? 'myrm.tv+wikipedia+myrunningman' : 'myrm.tv+myrunningman';
    if ($mdl && (!empty($mdl['synopsis']) || !empty($mdl['location']) || !empty($mdl['guests'])))
        $merged['source'] .= '+mydramalist';

    // NOTE: we deliberately do NOT copy the Wikipedia "Mission" text into
    // synopsis as a fallback anymore. The mission already has its own
    // dedicated "Main Mission" field/row everywhere it's displayed, so
    // copying it into synopsis just made the Description box restate the
    // mission verbatim (confirmed on EP807: Main Mission and Description
    // were the identical one-liner). When there's no real synopsis from
    // myrm.tv/MyDramaList, synopsis stays empty on purpose — the page-level
    // generateEpisodeSummary() then builds a NON-redundant summary from
    // the remaining fields (or shows N/A), instead of echoing the mission.

    return $merged;
}

// ── Latest episode detection ──────────────────────────────────
// ── Does episode N have a real page on myrunningman.com? ────────
// Server-rendered (unlike myrm.tv's SPA), so curl actually sees the
// real per-episode content. Used to probe PAST Wikipedia's last known
// episode — Wikipedia is accurate but edited by volunteers and can lag
// days behind an episode actually airing.
function rmEpisodeExistsOnMRM(int $epNum): bool {
    // BUGFIX: same /episodes/ → /ep/ mistake as rmMyRunningManExtra() above.
    $html = rmFetch("https://www.myrunningman.com/ep/$epNum", 10);
    if (!$html || strlen($html) < 500) return false;
    if (preg_match('/Episode\s*#\s*0*'.$epNum.'\b/i', $html)) return true;
    if (preg_match('/<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)["\']/', $html, $m)
        && preg_match('/Episode\s*#?\s*0*'.$epNum.'\b/i', $m[1])) return true;
    return false;
}

function rmGetLatestEpNumber(): ?int {
    // ── Primary: current year's Wikipedia page (static HTML, scrapable) ──
    $thisYear = (int)date('Y');
    $wikiMax = 0;
    $all = rmWikiParseYear($thisYear);
    if (!empty($all)) $wikiMax = max(array_keys($all));

    // ── Edge case: very start of a new year, that year's Wikipedia page
    // may not exist yet even though episodes have started airing.
    if ($wikiMax < 1) {
        $all = rmWikiParseYear($thisYear - 1);
        if (!empty($all)) $wikiMax = max(array_keys($all));
    }

    // ── Wikipedia lag bridge: an episode can air and appear on
    // myrunningman.com days before a volunteer editor updates Wikipedia's
    // table. Probe a handful of episodes past Wikipedia's last known
    // number — if myrunningman.com already has it, trust that instead
    // of waiting for Wikipedia to catch up.
    if ($wikiMax >= 1) {
        $latest = $wikiMax;
        for ($n = $wikiMax + 1; $n <= $wikiMax + 5; $n++) {
            if (rmEpisodeExistsOnMRM($n)) $latest = $n;
            else break; // stop at the first gap — don't skip past missing eps
        }
        return $latest;
    }

    // ── Last resort: myrm.tv. NOTE — myrm.tv is a client-rendered SPA.
    // A plain curl fetch only gets the static HTML shell; the actual
    // /ep/NNN links are inserted by JavaScript at runtime and are NOT
    // present in what curl receives. This fallback will usually find
    // nothing and is kept only in case myrm.tv changes to server-render
    // its listing page in the future.
    $html = rmFetch('https://myrm.tv/episodes', 10);
    if (!$html) return null;
    $max=0;
    if (preg_match_all('~href=["\'](?:https://myrm\.tv)?/ep/([1-9]\d{2,4})["\']~',$html,$m))
        foreach ($m[1] as $n) { $n=(int)$n; if ($n>$max&&$n<2000) $max=$n; }
    return $max>=100 ? $max : null;
}

// ── Clean/validate title ──────────────────────────────────────
function rmCleanTitle(string $raw, int $epNum): string {
    $padded = str_pad($epNum,3,'0',STR_PAD_LEFT);
    if (preg_match('/Episodes?\s*[-–]\s*Page\s*\d+/i',$raw)) return "Episode #$padded";
    if (mb_strlen(trim($raw))<4) return "Episode #$padded";
    $raw = preg_replace('/\s*[-–|]\s*(?:Wikipedia|myrm\.tv|myRunningMan|My Running Man.*|MyRM.*|SBS.*)$/i','',$raw);
    $raw = trim($raw);
    if (preg_match('/(?:Running\s*Man\s*)?Episode\s*#?\d+\s*[–\-—:]\s*(.+)/i',$raw,$m)) {
        $desc=trim($m[1]);
        if (strlen($desc)>3&&!preg_match('/^(?:Page\s*\d+|Episodes?)/i',$desc)) return "Episode #$padded - $desc";
    }
    if (preg_match('/^(?:Running\s*Man\s*)?Episode\s*#?\d+$/i',trim($raw))) return "Episode #$padded";
    $desc = preg_replace('/^Running\s*Man\s*[–\-—]?\s*(Episode\s*#?\d+\s*[–\-—]?\s*)?/i','',$raw);
    $desc = trim($desc);
    if (strlen($desc)>4&&!preg_match('/^(?:Episodes?|Page\s*\d+)/i',$desc)) return "Episode #$padded - $desc";
    return "Episode #$padded";
}

// ── Download thumbnail ────────────────────────────────────────
function rmDownloadThumb(string $imgUrl, int $epNum, int $year): ?string {
    $dir=__DIR__.'/../thumbnails/'.$year;
    $padded=str_pad($epNum,3,'0',STR_PAD_LEFT);
    $file=$dir.'/ep'.$padded.'.jpg';
    $web=bp().'/thumbnails/'.$year.'/ep'.$padded.'.jpg';
    if (!is_dir($dir)) mkdir($dir,0755,true);
    $ch=curl_init($imgUrl);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,
        CURLOPT_TIMEOUT=>25,CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_USERAGENT=>'Mozilla/5.0']);
    $data=curl_exec($ch);$code=curl_getinfo($ch,CURLINFO_HTTP_CODE);
    $ct=curl_getinfo($ch,CURLINFO_CONTENT_TYPE)??'';curl_close($ch);
    if (!$data||$code!==200||strlen($data)<2000||!str_contains($ct,'image/')) return null;

    // HD target (was 640×360). Crop-to-fit preserves the source's real
    // aspect ratio instead of squishing it into 16:9 — a portrait or
    // unusual-ratio source no longer comes out visibly stretched.
    // Never upscale past the source's own resolution (that only blurs
    // a small image, it can't add detail that isn't there).
    $targetW = 1280; $targetH = 720;

    if (extension_loaded('gd')) {
        $src = @imagecreatefromstring($data);
        if ($src) {
            $srcW = imagesx($src); $srcH = imagesy($src);
            $w = min($targetW, max($srcW, 854));   // don't go below a sensible floor either
            $h = (int)round($w * $targetH / $targetW);

            $dstRatio = $w / $h;
            $srcRatio = $srcW / $srcH;
            if ($srcRatio > $dstRatio) {
                $cropH = $srcH; $cropW = (int)round($srcH * $dstRatio);
                $cropX = (int)(($srcW - $cropW) / 2); $cropY = 0;
            } else {
                $cropW = $srcW; $cropH = (int)round($srcW / $dstRatio);
                $cropX = 0; $cropY = (int)(($srcH - $cropH) / 2);
            }

            $dst = imagecreatetruecolor($w, $h);
            imagecopyresampled($dst, $src, 0, 0, $cropX, $cropY, $w, $h, $cropW, $cropH);
            imagejpeg($dst, $file, 92);
            imagedestroy($src); imagedestroy($dst);
        } else {
            file_put_contents($file, $data); // GD couldn't decode this format — keep the raw download
        }
    } else {
        file_put_contents($file, $data); // no GD extension — keep the raw download
    }

    return (file_exists($file) && filesize($file) > 1000) ? $web : null;
}

// ── Year from episode number (user-verified) ──────────────────
function rmYear(int $n): int {
    if ($n<=25)  return 2010; if ($n<=74)  return 2011;
    if ($n<=126) return 2012; if ($n<=178) return 2013;
    if ($n<=227) return 2014; if ($n<=279) return 2015;
    if ($n<=331) return 2016; if ($n<=382) return 2017;
    if ($n<=433) return 2018; if ($n<=485) return 2019;
    if ($n<=537) return 2020; if ($n<=589) return 2021;
    if ($n<=634) return 2022; if ($n<=685) return 2023;
    if ($n<=733) return 2024; if ($n<=783) return 2025;
    return 2026;
}
