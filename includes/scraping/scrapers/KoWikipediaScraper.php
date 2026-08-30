<?php
// ============================================================
// KoWikipediaScraper — Korean Wikipedia (ko.wikipedia.org).
//
// Why this source earns its place: it carries the ORIGINAL Korean
// episode titles and Korean guest names, which the English article
// mostly omits, and it is edited by a different community — so it
// independently corroborates air dates. Corroboration is what turns
// a MEDIUM-confidence field into a HIGH-confidence one, which is the
// entire point of having more than one source.
//
// Uses the same MediaWiki parse API as the English adapter (no
// scraping of rendered pages, no robots concerns, generous limits).
//
// Fields: title_ko · air_date · guests · title
// ============================================================
require_once __DIR__ . '/AbstractScraper.php';

class KoWikipediaScraper extends RmScraper
{
    public function name(): string { return 'kowiki'; }
    public function parserVersion(): string { return 'kowiki-1.0'; }

    public function fields(): array { return ['title_ko','air_date','guests','title']; }

    /** Candidate article titles, newest naming convention first. */
    private function pageTitles(int $year): array
    {
        return [
            "런닝맨의 에피소드 목록 ($year)",
            "런닝맨의 에피소드 목록 ({$year}년)",
            '런닝맨의 에피소드 목록',
        ];
    }

    /** @return array<int,array> epNum => fields, parsed from the year page */
    public function parseYear(int $year, bool $bypass = false): array
    {
        $cache = $this->cache();
        $key   = "kowiki:episodes:$year";
        if (!$bypass) {
            $hit = $cache->get($key);
            if (is_array($hit) && count($hit) > 0) return $hit;
        }

        $html = null;
        foreach ($this->pageTitles($year) as $title) {
            $url = 'https://ko.wikipedia.org/w/api.php?' . http_build_query([
                'action'=>'parse','page'=>$title,'prop'=>'text','format'=>'json',
                'disablelimitreport'=>1,'disableeditsection'=>1,
            ]);
            [$data, $res] = $this->getJson($url, [
                'timeout'   => 20,
                'cache_ttl' => $year >= (int)date('Y') ? (int)rmScrapeConfig('cache.ttl_index', 1800) : (int)rmScrapeConfig('cache.ttl_reference', 604800),
                'cache_key' => "kowiki:page:$year:" . md5($title),
                'bypass_cache' => $bypass,
            ]);
            if (!$res->ok || !is_array($data) || isset($data['error'])) continue;
            $candidate = $data['parse']['text']['*'] ?? null;
            if ($candidate && strlen($candidate) > 2000) { $html = $candidate; break; }
        }
        if ($html === null) return [];

        $episodes = $this->parseTables($html);
        if ($episodes) {
            $cache->set($key, $episodes, $year >= (int)date('Y') ? 1800 : 604800, 'parsed');
        } else {
            $cache->forget($key);
        }
        return $episodes;
    }

    /**
     * Korean episode tables use 회차 (episode), 방송일 (air date),
     * 제목/부제 (title) and 게스트 (guests). Columns are located by
     * HEADER LABEL rather than by fixed index, so a column being added
     * or reordered upstream degrades one field instead of shifting
     * every field by one and silently writing guests into titles.
     */
    private function parseTables(string $html): array
    {
        $xp = $this->xpath($html);
        if ($xp === null) return [];
        $episodes = [];

        foreach ($xp->query("//table[contains(@class,'wikitable')]") as $table) {
            $rows = $xp->query('.//tr', $table);
            if ($rows === false || $rows->length < 2) continue;

            $cols = [];
            foreach ($xp->query('.//tr[1]/th | .//tr[1]/td', $table) as $i => $th) {
                $label = RmNormalizer::text($th->textContent) ?? '';
                if (preg_match('/회|화$|에피소드/u', $label) && !isset($cols['ep']))       $cols['ep'] = $i;
                elseif (preg_match('/방송|날짜|일자/u', $label) && !isset($cols['date']))  $cols['date'] = $i;
                elseif (preg_match('/제목|부제|타이틀/u', $label) && !isset($cols['title'])) $cols['title'] = $i;
                elseif (preg_match('/게스트|출연/u', $label) && !isset($cols['guests']))   $cols['guests'] = $i;
            }
            if (!isset($cols['ep'])) continue;   // not an episode table

            foreach ($rows as $ri => $row) {
                if ($ri === 0) continue;
                $cells = [];
                foreach ($xp->query('./th | ./td', $row) as $cell) $cells[] = $cell;
                if (!$cells) continue;

                $epRaw = isset($cells[$cols['ep']]) ? RmNormalizer::text($cells[$cols['ep']]->textContent) : null;
                if ($epRaw === null || !preg_match('/(\d{1,4})/', $epRaw, $m)) continue;
                $epNum = (int)$m[1];
                if ($epNum < 1 || $epNum > 2000) continue;

                $data = [];
                if (isset($cols['date'], $cells[$cols['date']])) {
                    $d = RmNormalizer::date($cells[$cols['date']]->textContent);
                    if ($d) $data['air_date'] = $d;
                }
                if (isset($cols['title'], $cells[$cols['title']])) {
                    $t = RmNormalizer::text($cells[$cols['title']]->textContent);
                    if ($t !== null && mb_strlen($t) > 1) $data['title_ko'] = $t;
                }
                if (isset($cols['guests'], $cells[$cols['guests']])) {
                    $raw = RmNormalizer::text($cells[$cols['guests']]->textContent) ?? '';
                    $guests = [];
                    foreach (preg_split('/\s*[,·、]\s*|\s+และ\s+/u', $raw) as $g) {
                        $n = RmNormalizer::guestName($g);
                        if ($n !== null) $guests[] = $n;
                    }
                    if ($guests) $data['guests'] = $guests;
                }
                if ($data) $episodes[$epNum] = $data;
            }
        }
        return $episodes;
    }

    public function episode(int $epNum, array $ctx = []): array
    {
        $year = (int)($ctx['year'] ?? rmYear($epNum));
        $url  = 'https://ko.wikipedia.org/wiki/' . rawurlencode("런닝맨의 에피소드 목록 ($year)");
        $t0   = microtime(true);
        $all  = $this->parseYear($year, !empty($ctx['bypass_cache']));
        $ms   = (int)round((microtime(true) - $t0) * 1000);

        if (!$all) {
            return $this->emptyResult($url, 'empty',
                "No Korean episode table found for $year (article may not exist or uses different headers)",
                ['_ms' => $ms, '_error_class' => 'missing_page']);
        }
        $ep = $all[$epNum] ?? null;
        if (!$ep) {
            return $this->emptyResult($url, 'missing_episode',
                "Episode $epNum not listed on the Korean $year page (" . count($all) . ' rows found)',
                ['_ms' => $ms, '_error_class' => 'missing_episode']);
        }

        $out = $ep;
        $out['_url']         = $url;
        $out['_status']      = 'ok';
        $out['_ms']          = $ms;
        $out['_http']        = 200;
        $out['_error']       = null;
        $out['_error_class'] = RmHttpClient::CLASS_OK;
        $out['_hash']        = RmNormalizer::hash(json_encode($ep, JSON_UNESCAPED_UNICODE));
        return $out;
    }

    public function latestEpisode(): ?int
    {
        foreach ([(int)date('Y'), (int)date('Y') - 1] as $year) {
            $all = $this->parseYear($year);
            if ($all) return max(array_keys($all));
        }
        return null;
    }
}
