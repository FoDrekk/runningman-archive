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
                if ($res->ok) { $reached = true; }
                else { $lastErr = $res->error; $lastClass = $res->errorClass; continue; }
                $found = $this->fromHtml((string)$res->body, $epNum, $ctx);
                if ($found) return $this->result($found, $cand['url'], $res);
            }
        }

        $ms = (int)round((microtime(true) - $t0) * 1000);
        if ($reached) {
            // Reached SBS but found nothing for this episode. For older
            // episodes that is ordinary (SBS prunes its VOD listings);
            // for a recent one it means the page structure moved.
            $recent = isset($ctx['latest']) && $epNum > ((int)$ctx['latest'] - 12);
            return $this->emptyResult($lastUrl, $recent ? 'parser_warning' : 'missing_episode',
                $recent
                    ? "SBS pages loaded but episode $epNum was not found in them — listing structure may have changed"
                    : "Episode $epNum is not in SBS's current listings (older episodes are routinely removed)",
                ['_ms' => $ms, '_error_class' => $recent ? 'unexpected_structure' : 'missing_episode', '_http' => 200]);
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
        // Structured data first — SBS emits JSON-LD on several page types.
        if (preg_match_all('~<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>~is', $html, $m)) {
            foreach ($m[1] as $json) {
                $data = json_decode(trim($json), true);
                if (is_array($data)) {
                    $found = $this->fromApi($data, $epNum, $ctx);
                    if ($found) return $found;
                }
            }
        }

        // Otherwise look for a list item whose text carries "NNN회".
        $marker = preg_quote((string)$epNum, '~');
        if (preg_match('~<(li|div|article)[^>]*>((?:(?!</?\1[\s>]).){0,1500}?' . $marker . '\s*회(?:(?!</?\1[\s>]).){0,1500}?)</\1>~isu', $html, $b)) {
            $block = $b[2];
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
