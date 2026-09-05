<?php
// ============================================================
// ImdbScraper — IMDb episode pages, read through their public
// structured data.
//
// IMDb renders almost everything client-side, so scraping its markup
// for text is a losing game. It does, however, publish a JSON-LD
// block and OpenGraph tags on every episode page — the machine
// -readable summary the site itself provides for exactly this
// purpose. That is what this adapter reads: no rendering, no private
// endpoint, no API key.
//
// It is worth having for one specific reason: it is an independent
// check on air dates for older episodes, where the Korean sources
// have dropped their VOD listings and Wikipedia's year tables are the
// only other witness. A second opinion from outside the Wikimedia
// family is the difference between a lone source and corroboration.
//
// IMDb blocks some networks outright. That is recorded as
// ACCESS_RESTRICTED and the run continues; nothing here attempts to
// get around it.
//
// Fields: title · air_date · synopsis · image_url
// ============================================================
require_once __DIR__ . '/AbstractScraper.php';

class ImdbScraper extends RmScraper
{
    public function name(): string { return 'imdb'; }
    public function parserVersion(): string { return 'imdb-1.0'; }

    public function fields(): array { return ['title', 'air_date', 'synopsis', 'image_url']; }

    private function seriesId(): string
    {
        return (string)rmScrapeConfig('sources.imdb.series', 'tt1587289');
    }

    public function episode(int $epNum, array $ctx = []): array
    {
        $t0 = microtime(true);
        $series = $this->seriesId();
        $year   = rmYear($epNum);

        // IMDb organises this show by broadcast year, not by a flat
        // episode number, so the episode-list page for the year is the
        // entry point. A URL discovery already confirmed wins.
        $urls = array_merge(
            (array)($ctx['discovered'][$this->name()] ?? []),
            ["https://www.imdb.com/title/$series/episodes/?season=" . ($year - 2009)]
        );

        $lastUrl = ''; $lastErr = null; $lastClass = null; $reached = false;
        foreach (array_unique($urls) as $url) {
            $lastUrl = $url;
            $res = $this->get($url, [
                'timeout'      => 15,
                'min_bytes'    => 800,
                'cache_ttl'    => (int)rmScrapeConfig('cache.ttl_index', 1800),
                'cache_key'    => 'imdb:season:' . md5($url),
                'bypass_cache' => !empty($ctx['bypass_cache']),
                'retries'      => 1,
                'headers'      => [
                    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language: en-US,en;q=0.9',
                ],
            ]);
            if (!$res->ok) {
                $lastErr = $res->error; $lastClass = $res->errorClass;
                continue;
            }
            $reached = true;
            $found = $this->fromStructuredData((string)$res->body, $epNum);
            if ($found) return $this->result($found, $url, $res);
        }

        $ms = (int)round((microtime(true) - $t0) * 1000);
        if (!$reached) {
            // A refusal is a fact about access, not a failure of ours, and
            // it keeps its own name so the run does not read as broken.
            $status = match ($lastClass) {
                RmHttpClient::CLASS_BLOCKED      => 'blocked',
                RmHttpClient::CLASS_RATE_LIMITED => 'rate_limited',
                default                          => 'fetch_failed',
            };
            return $this->emptyResult($lastUrl, $status,
                $lastErr ?? 'Could not reach IMDb',
                ['_ms' => $ms, '_error_class' => $lastClass ?? RmHttpClient::CLASS_OTHER]);
        }
        return $this->emptyResult($lastUrl, 'missing_episode',
            "IMDb's season listing does not carry episode $epNum",
            ['_ms' => $ms, '_error_class' => 'missing_episode', '_http' => 200]);
    }

    /**
     * Read the page's own structured data — JSON-LD first, then the
     * hydration blob IMDb ships for its client renderer. Both are
     * published by the site; neither is a private endpoint.
     */
    private function fromStructuredData(string $html, int $epNum): ?array
    {
        foreach ($this->jsonDocuments($html) as $doc) {
            $found = $this->walk($doc, $epNum);
            if ($found) return $found;
        }
        return null;
    }

    /** @return array<int,array> */
    private function jsonDocuments(string $html): array
    {
        $out = [];
        if (preg_match_all('~<script[^>]+type=["\']application/(?:ld\+)?json["\'][^>]*>(.*?)</script>~is',
                           $html, $m)) {
            foreach ($m[1] as $blk) {
                $d = json_decode(trim($blk), true);
                if (is_array($d)) $out[] = $d;
            }
        }
        return $out;
    }

    /**
     * Walk a decoded document for a node that names this episode. IMDb
     * changes the shape of these blobs regularly, so the search is by
     * content — an episodeNumber matching ours — rather than by a path
     * that will be wrong again next quarter.
     */
    private function walk(array $node, int $epNum, int $depth = 0): ?array
    {
        if ($depth > 8) return null;

        $num = $node['episodeNumber'] ?? $node['episode'] ?? null;
        if (is_array($num)) $num = $num['episodeNumber'] ?? null;
        if ($num !== null && (int)$num === $epNum) {
            $out = $this->extract($node, $epNum);
            if ($out) return $out;
        }

        foreach ($node as $v) {
            if (!is_array($v)) continue;
            $found = $this->walk($v, $epNum, $depth + 1);
            if ($found) return $found;
        }
        return null;
    }

    private function extract(array $node, int $epNum): array
    {
        $out = [];

        $name = $node['name'] ?? ($node['titleText']['text'] ?? null);
        if (is_string($name) && trim($name) !== '') {
            $clean = RmNormalizer::title($name, $epNum);
            if (RmNormalizer::titleDescriptor($clean) !== null) $out['title'] = $clean;
        }

        $date = $node['datePublished'] ?? ($node['releaseDate']['date'] ?? null);
        if (is_array($date)) {
            $date = isset($date['year'], $date['month'], $date['day'])
                ? sprintf('%04d-%02d-%02d', $date['year'], $date['month'], $date['day']) : null;
        }
        if (is_string($date)) {
            $d = RmNormalizer::date($date);
            if ($d !== null) $out['air_date'] = $d;
        }

        $desc = $node['description'] ?? ($node['plot']['plotText']['plainText'] ?? null);
        if (is_string($desc)) {
            $text = RmNormalizer::text($desc);
            if ($text !== null && mb_strlen($text) >= (int)rmScrapeConfig('safety.min_synopsis_chars', 15)) {
                $out['synopsis'] = mb_substr($text, 0, (int)rmScrapeConfig('safety.max_synopsis_chars', 4000));
            }
        }

        $img = $node['image'] ?? ($node['primaryImage']['url'] ?? null);
        if (is_array($img)) $img = $img['url'] ?? ($img[0] ?? null);
        if (is_string($img)) {
            $u = RmNormalizer::url($img);
            if ($u !== null) $out['image_url'] = $u;
        }

        return $out;
    }
}
