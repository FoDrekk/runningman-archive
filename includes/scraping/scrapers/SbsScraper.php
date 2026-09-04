<?php
// ============================================================
// SbsScraper — the official broadcaster (SBS).
//
// This is the authoritative source for the two fields where being
// official actually matters: the BROADCAST DATE and the original
// Korean episode title. SBS publishes its programme data through a
// public content API used by its own site; where that is
// unreachable, the adapter falls back to parsing the programme page.
//
// Deliberately defensive: SBS reorganises its endpoints periodically,
// so several known endpoint shapes are tried in turn and a total miss
// is reported as a PARSER WARNING (reachable but nothing extracted)
// rather than pretending the episode has no data. Nothing here
// overwrites an existing value on its own — the resolver and the
// diff engine still decide that.
//
// Episodes are matched by the Korean episode marker "NNN회", falling
// back to matching on the expected broadcast date.
//
// Fields: title_ko · air_date · synopsis · image_url · title
// ============================================================
require_once __DIR__ . '/AbstractScraper.php';

class SbsScraper extends RmScraper
{
    public function name(): string { return 'sbs'; }
    public function parserVersion(): string { return 'sbs-1.0'; }

    public function fields(): array { return ['title_ko','air_date','synopsis','image_url','title']; }

    private const PROGRAM_ID = 'S01_E01'; // placeholder key; endpoints below carry the real slug
    private const PAGE_URLS = [
        'https://programs.sbs.co.kr/enter/runningman/visualboard/54666',
        'https://programs.sbs.co.kr/enter/runningman',
    ];

    public function episode(int $epNum, array $ctx = []): array
    {
        $t0 = microtime(true);
        $reached = false; $lastErr = null; $lastClass = null; $lastUrl = self::PAGE_URLS[0];
        $lastHtml = null; $lastHtmlUrl = null;

        foreach ($this->candidateSources($epNum, $ctx) as $cand) {
            $lastUrl = $cand['url'];
            if ($cand['type'] === 'json') {
                [$data, $res] = $this->getJson($cand['url'], $cand['opt']);
                if ($res->ok) { $reached = true; }
                else { $lastErr = $res->error; $lastClass = $res->errorClass; continue; }
                $found = is_array($data) ? $this->fromApi($data, $epNum, $ctx) : null;
                if ($found) return $this->result($found, $cand['url'], $res);
            } else {
                $res = $this->get($cand['url'], $cand['opt']);
                if ($res->ok) { $reached = true; $lastHtml = (string)$res->body; $lastHtmlUrl = $res->url; }
                else { $lastErr = $res->error; $lastClass = $res->errorClass; continue; }
                $found = $this->fromHtml((string)$res->body, $epNum, $ctx);
                if ($found) return $this->result($found, $cand['url'], $res);
            }
        }

        $ms = (int)round((microtime(true) - $t0) * 1000);
        if ($reached) {
            // Reached SBS but found nothing for this episode. WHY matters:
            // "the page is a JavaScript shell" and "our selectors drifted"
            // and "this episode isn't on the listing" need three different
            // responses, and reporting all three as one parser warning is
            // what makes the same message repeat for every recent episode
            // while telling nobody what to do about it.
            //
            // Worth stating plainly: this adapter has NO PER-EPISODE URL.
            // It fetches a programme listing and asks whether the episode
            // is on it, so even a perfectly-parsed listing can only ever
            // cover the most recent entries.
            $d = $lastHtml !== null ? $this->diagnose($lastHtml, $epNum)
                                    : ['kind' => 'not_listed', 'note' => 'no HTML document was captured'];
            $status = match ($d['kind']) {
                'structure_changed' => 'parser_warning',
                'needs_javascript'  => 'needs_javascript',
                default             => 'missing_episode',
            };
            return $this->emptyResult($lastHtmlUrl ?? $lastUrl, $status,
                "SBS has no per-episode endpoint — it was asked whether its programme listing contains episode "
                . "$epNum, and " . $d['note'],
                ['_ms' => $ms, '_error_class' => $d['kind'], '_http' => 200]);
        }
        return $this->emptyResult($lastUrl, 'fetch_failed', $lastErr ?? 'Could not reach any SBS endpoint',
            ['_ms' => $ms, '_error_class' => $lastClass ?? RmHttpClient::CLASS_OTHER]);
    }

    /** Endpoint shapes tried in order — API first, rendered page last. */
    private function candidateSources(int $epNum, array $ctx): array
    {
        $ttl = RmCache::episodeTtl($epNum, $ctx['latest'] ?? null, 'api');
        $bypass = !empty($ctx['bypass_cache']);
        $out = [];

        foreach ([
            'https://static.apis.sbs.co.kr/program-api/1.0/menu/runningman',
            'https://static.apis.sbs.co.kr/program-api/1.0/board/runningman/vod',
        ] as $u) {
            $out[] = ['type'=>'json','url'=>$u,'opt'=>[
                'timeout'=>15,'cache_ttl'=>$ttl,'cache_key'=>'sbs:api:'.md5($u),
                'cache_type'=>'api','bypass_cache'=>$bypass,'retries'=>1,
            ]];
        }
        foreach (self::PAGE_URLS as $u) {
            $out[] = ['type'=>'html','url'=>$u,'opt'=>[
                'timeout'=>15,'min_bytes'=>1000,'cache_ttl'=>max(900, (int)($ttl / 4)),
                'cache_key'=>'sbs:page:'.md5($u),'bypass_cache'=>$bypass,'retries'=>1,
            ]];
        }
        return $out;
    }

    /** Walk an arbitrary SBS JSON shape looking for this episode's entry. */
    private function fromApi(array $data, int $epNum, array $ctx): ?array
    {
        $best = null;
        $walk = function ($node) use (&$walk, $epNum, &$best) {
            if ($best !== null || !is_array($node)) return;
            // A candidate record has a title-ish key with an episode marker.
            foreach (['title','program_title','vod_title','subject','name'] as $k) {
                if (!isset($node[$k]) || !is_string($node[$k])) continue;
                if ($this->matchesEpisode($node[$k], $epNum)) { $best = $node; return; }
            }
            foreach ($node as $child) if (is_array($child)) $walk($child);
        };
        $walk($data);
        if ($best === null) return null;

        $out = [];
        foreach (['title','program_title','vod_title','subject','name'] as $k) {
            if (!empty($best[$k]) && is_string($best[$k])) {
                $ko = RmNormalizer::text($this->stripEpisodeMarker($best[$k]));
                if ($ko !== null && mb_strlen($ko) > 1) { $out['title_ko'] = $ko; break; }
            }
        }
        foreach (['broaddate','broad_date','onair_date','air_date','broadcast_date','regdate'] as $k) {
            if (!empty($best[$k])) { $d = RmNormalizer::date((string)$best[$k]); if ($d) { $out['air_date'] = $d; break; } }
        }
        foreach (['synopsis','content','description','desc','summary'] as $k) {
            if (!empty($best[$k]) && is_string($best[$k])) {
                $s = RmNormalizer::paragraphText(strip_tags($best[$k]));
                if ($s !== null && mb_strlen($s) > 20) { $out['synopsis'] = $s; break; }
            }
        }
        foreach (['thumb','thumbnail','image','imgurl','poster','thumb_url'] as $k) {
            if (!empty($best[$k]) && is_string($best[$k])) {
                $u = RmNormalizer::url($best[$k], 'https://programs.sbs.co.kr');
                if ($u) { $out['image_url'] = $u; break; }
            }
        }
        return $out ?: null;
    }

    /** Parse the rendered programme page for a block naming this episode. */
    private function fromHtml(string $html, int $epNum, array $ctx): ?array
    {
        // Embedded JSON first. SBS renders its VOD list client-side, so
        // the episode text is frequently absent from the served markup
        // while the data itself ships in a hydration blob. Reading those
        // blobs is reading the page's actual mechanism, rather than
        // adding another selector for markup that was never there.
        foreach ($this->embeddedJson($html) as $data) {
            $found = $this->fromApi($data, $epNum, $ctx);
            if ($found) return $found;
        }

        // Otherwise look for the list item whose text carries "NNN회".
        //
        // This used to be one regex with two bounded negative-lookahead
        // repetitions ({0,1500} each). PCRE could not compile it — every
        // call returned false and raised
        //     preg_match(): Compilation failed: regular expression is too large
        // which meant this extraction never ran at all, AND the warning was
        // printed into whatever response was in flight, corrupting admin
        // AJAX bodies. Locating the marker by position and walking out to
        // the enclosing element does the same job, cannot blow up the
        // regex compiler, and is easier to reason about.
        $block = $this->blockAroundEpisode($html, $epNum);
        if ($block !== null) {
            $out = [];
            $title = $this->firstMatch($block, [
                '~<(?:h\d|strong|p|span)[^>]*class=["\'][^"\']*(?:tit|title|subject)[^"\']*["\'][^>]*>([^<]{2,150})<~i',
                '~<(?:h\d|strong)[^>]*>([^<]{2,150})<~i',
                '~alt=["\']([^"\']{4,150})["\']~i',
            ]);
            if ($title) {
                $ko = RmNormalizer::text($this->stripEpisodeMarker($title));
                if ($ko !== null && mb_strlen($ko) > 1) $out['title_ko'] = $ko;
            }
            $date = $this->firstMatch($block, [
                '~(\d{4}[.\-/]\d{1,2}[.\-/]\d{1,2})~',
                '~(\d{4}\s*년\s*\d{1,2}\s*월\s*\d{1,2}\s*일)~u',
            ]);
            if ($date) { $d = RmNormalizer::date($date); if ($d) $out['air_date'] = $d; }

            $img = $this->firstMatch($block, ['~<img[^>]+src=["\']([^"\']+)["\']~i', '~data-src=["\']([^"\']+)["\']~i']);
            if ($img) { $u = RmNormalizer::url($img, 'https://programs.sbs.co.kr'); if ($u) $out['image_url'] = $u; }

            $desc = $this->firstMatch($block, ['~<p[^>]*>([^<]{30,600})</p>~i']);
            if ($desc) $out['synopsis'] = $desc;

            if ($out) return $out;
        }
        return null;
    }

    /**
     * The markup block surrounding the "NNN회" marker for this episode.
     *
     * Finds the marker by byte offset, then walks backwards to the nearest
     * enclosing <li>/<div>/<article> and forwards to its close. Returns
     * null when the marker is absent. Deliberately not a single regex:
     * matching a balanced element with bounded lookaheads is what made the
     * previous pattern uncompilable.
     */
    private function blockAroundEpisode(string $html, int $epNum): ?string
    {
        if (!preg_match('/(?<!\d)0*' . $epNum . '\s*회/u', $html, $m, PREG_OFFSET_CAPTURE)) return null;
        $at = (int)$m[0][1];

        // Nearest opening tag before the marker, within a sane window.
        $windowStart = max(0, $at - 4000);
        $before = substr($html, $windowStart, $at - $windowStart);
        $openAt = -1; $openTag = null;
        foreach (['li', 'article', 'div'] as $tag) {
            $p = strripos($before, '<' . $tag);
            if ($p !== false && $p > $openAt) { $openAt = $p; $openTag = $tag; }
        }
        if ($openTag === null) {
            // No container: return a bounded window around the marker so
            // the field extractors still have something to work with.
            return substr($html, max(0, $at - 600), 1600);
        }

        $start = $windowStart + $openAt;
        $closeNeedle = '</' . $openTag . '>';
        $closeAt = stripos($html, $closeNeedle, $at);
        $end = $closeAt === false ? min(strlen($html), $at + 2000) : $closeAt + strlen($closeNeedle);
        if ($end - $start > 8000) $end = $start + 8000;   // never hand back half the page

        return substr($html, $start, $end - $start);
    }

    /**
     * Every JSON document embedded in a page: <script type=…json> blocks
     * (JSON-LD included) and the `window.__STATE__ = {…}` hydration
     * assignments frameworks emit. Malformed blocks are skipped rather
     * than aborting the scan.
     *
     * @return array<int,array> decoded documents
     */
    private function embeddedJson(string $html): array
    {
        $out = [];
        if (preg_match_all('~<script[^>]+type=["\']application/(?:ld\+)?json["\'][^>]*>(.*?)</script>~is', $html, $m)) {
            foreach ($m[1] as $blk) {
                $d = json_decode(trim($blk), true);
                if (is_array($d)) $out[] = $d;
            }
        }
        // Hydration state: __NEXT_DATA__, __NUXT__, __INITIAL_STATE__, …
        if (preg_match_all('~(?:window\.)?(__[A-Z0-9_]+__)\s*=\s*(\{.*?\})\s*[;<]~s', $html, $wm, PREG_SET_ORDER)) {
            foreach ($wm as $blk) {
                $d = json_decode($blk[2], true);
                if (is_array($d)) $out[] = $d;
            }
        }
        return $out;
    }

    /**
     * Why did this document not yield the episode? The three answers need
     * three different fixes, and collapsing them into one warning is what
     * makes a scraper impossible to debug:
     *   needs_javascript  a client-rendered shell — no episode text was
     *                     ever served, so no selector can find it
     *   not_listed        a real listing that simply does not include
     *                     this episode (SBS prunes older VOD entries)
     *   structure_changed the episode IS in the markup but our extraction
     *                     missed it — the genuine stale-selector case
     */
    private function diagnose(string $html, int $epNum): array
    {
        $mentions = (bool)preg_match('/(?<!\d)0*' . $epNum . '(?!\d)/', $html);
        $episodeMarkers = preg_match_all('/\d{1,4}\s*회/u', $html);

        $stripped = preg_replace('~<script\b[^>]*>.*?</script>~is', '', $html);
        $stripped = preg_replace('~<style\b[^>]*>.*?</style>~is', '', (string)$stripped);
        $visible  = strlen(trim(preg_replace('/\s+/u', ' ', strip_tags((string)$stripped))));
        $spa = false;
        foreach (['__NEXT_DATA__', '__NUXT__', '__INITIAL_STATE__', 'data-reactroot', 'ng-app'] as $needle) {
            if (str_contains($html, $needle)) { $spa = true; break; }
        }
        // A programme page with almost no prose and no episode markers is
        // a shell, whatever framework built it.
        $shell = $spa || ($episodeMarkers === 0 && $visible < 2000);

        if ($mentions && $episodeMarkers > 0) {
            return ['kind' => 'structure_changed',
                    'note' => "the markup contains episode $epNum but the current extraction did not pick it up — selectors are stale"];
        }
        if ($shell) {
            return ['kind' => 'needs_javascript',
                    'note' => 'the response is a client-rendered shell (' . $visible . ' bytes of visible text, '
                            . $episodeMarkers . ' episode markers) — the episode list is loaded by JavaScript, '
                            . 'so no HTML selector can reach it. Capture the real endpoint with '
                            . 'tools/capture_source.php --source=sbs --ep=' . $epNum . ' --save=/tmp/sbs'];
        }
        return ['kind' => 'not_listed',
                'note' => 'the page is a listing carrying ' . $episodeMarkers . ' episode(s), and episode '
                        . $epNum . ' is not among them'];
    }

    /** "런닝맨 810회" / "810회 -" / "Ep.810" all identify episode 810. */
    private function matchesEpisode(string $text, int $epNum): bool
    {
        if (preg_match('/\b0*' . $epNum . '\s*회/u', $text)) return true;
        if (preg_match('/\bEp\.?\s*0*' . $epNum . '\b/i', $text)) return true;
        return false;
    }

    private function stripEpisodeMarker(string $s): string
    {
        $s = preg_replace('/^\s*런닝맨\s*/u', '', $s);
        $s = preg_replace('/\b\d{1,4}\s*회\s*[-–—:]?\s*/u', '', $s);
        $s = preg_replace('/\bEp\.?\s*\d{1,4}\s*[-–—:]?\s*/i', '', $s);
        return trim($s, " \t\n\r-–—:·");
    }
}
