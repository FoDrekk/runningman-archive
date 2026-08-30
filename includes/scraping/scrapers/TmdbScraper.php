<?php
// ============================================================
// TmdbScraper — The Movie Database, OPTIONAL.
//
// Value: clean per-episode air dates, English overviews and — most
// usefully — episode STILLS, which give the thumbnail engine a real
// fallback when myrunningman has no image for an episode.
//
// Strictly optional by design. TMDB requires an API key; this project
// must never depend on a paid or keyed service, so:
//   · the key is read from the environment (RM_TMDB_API_KEY) or from
//     the git-ignored config/scraping.local.php — never hardcoded
//   · with no key the source reports itself disabled and the engine
//     skips it entirely, costing nothing
//   · the key is redacted from every log line (see RmScrapeRun::redact)
//
// Running Man's 800+ episodes are spread across TMDB "seasons" by
// year, so the adapter resolves episode number → (season, episode)
// via the cached season index rather than assuming a flat numbering.
//
// Fields: title · air_date · synopsis · image_url
// ============================================================
require_once __DIR__ . '/AbstractScraper.php';

class TmdbScraper extends RmScraper
{
    public function name(): string { return 'tmdb'; }
    public function parserVersion(): string { return 'tmdb-1.0'; }

    public function fields(): array { return ['title','air_date','synopsis','image_url']; }

    private function key(): ?string
    {
        $k = rmScrapeConfig('api_keys.tmdb');
        return (is_string($k) && trim($k) !== '') ? trim($k) : null;
    }

    private function tvId(): int { return (int)rmScrapeConfig('sources.tmdb.tv_id', 33238); }

    private function base(): string { return rtrim((string)rmScrapeConfig('sources.tmdb.base', 'https://api.themoviedb.org/3/'), '/'); }

    /** Bearer for a v4 token, query param for a v3 key — both are common. */
    private function auth(string $url, array &$opt): string
    {
        $key = (string)$this->key();
        if (str_starts_with($key, 'ey') && substr_count($key, '.') === 2) {   // JWT → v4 token
            $opt['headers'] = array_merge($opt['headers'] ?? [], ['Authorization: Bearer ' . $key]);
            return $url;
        }
        return $url . (str_contains($url, '?') ? '&' : '?') . 'api_key=' . urlencode($key);
    }

    /**
     * Map an absolute episode number to TMDB's season/episode pair.
     * Cached hard: the mapping only changes when a season is added.
     */
    private function locate(int $epNum): ?array
    {
        $cacheKey = 'tmdb:index';
        $index = $this->cache()->get($cacheKey);

        if (!is_array($index)) {
            $opt = ['timeout' => 15, 'cache_ttl' => 0, 'retries' => 1];
            $url = $this->auth($this->base() . '/tv/' . $this->tvId(), $opt);
            [$show, $res] = $this->getJson($url, $opt);
            if (!$res->ok || !is_array($show)) return null;

            $index = [];
            foreach ((array)($show['seasons'] ?? []) as $season) {
                $sn = (int)($season['season_number'] ?? -1);
                if ($sn < 0) continue;
                $opt2 = ['timeout' => 15, 'cache_ttl' => (int)rmScrapeConfig('cache.ttl_api', 43200),
                         'cache_key' => "tmdb:season:$sn", 'retries' => 1];
                $surl = $this->auth($this->base() . '/tv/' . $this->tvId() . '/season/' . $sn, $opt2);
                [$sdata, $sres] = $this->getJson($surl, $opt2);
                if (!$sres->ok || !is_array($sdata)) continue;
                foreach ((array)($sdata['episodes'] ?? []) as $ep) {
                    // TMDB stores the real episode number in the name for
                    // long-running variety shows; prefer that, fall back to
                    // the in-season number when the name has no marker.
                    $abs = null;
                    if (preg_match('/\b(?:Ep(?:isode)?\.?\s*)?(\d{1,4})\s*(?:회|\b)/u', (string)($ep['name'] ?? ''), $m)) {
                        $n = (int)$m[1];
                        if ($n >= 1 && $n <= 2000) $abs = $n;
                    }
                    if ($abs === null) continue;
                    $index[$abs] = ['season' => $sn, 'episode' => (int)($ep['episode_number'] ?? 0)];
                }
            }
            if ($index) $this->cache()->set($cacheKey, $index, (int)rmScrapeConfig('cache.ttl_api', 43200), 'api');
        }
        return $index[$epNum] ?? null;
    }

    public function episode(int $epNum, array $ctx = []): array
    {
        $pageUrl = 'https://www.themoviedb.org/tv/' . $this->tvId();
        if ($this->key() === null) {
            return $this->emptyResult($pageUrl, 'disabled',
                'No TMDB API key configured — optional source skipped (set RM_TMDB_API_KEY to enable)',
                ['_error_class' => 'not_configured']);
        }

        $loc = $this->locate($epNum);
        if ($loc === null) {
            return $this->emptyResult($pageUrl, 'missing_episode',
                "TMDB has no entry mapping to episode $epNum", ['_error_class' => 'missing_episode']);
        }

        $opt = [
            'timeout'   => 15,
            'cache_ttl' => RmCache::episodeTtl($epNum, $ctx['latest'] ?? null, 'api'),
            'cache_key' => "tmdb:ep:$epNum",
            'bypass_cache' => !empty($ctx['bypass_cache']),
            'retries'   => 1,
        ];
        $url = $this->auth($this->base() . '/tv/' . $this->tvId() . '/season/' . $loc['season'] . '/episode/' . $loc['episode'], $opt);
        [$data, $res] = $this->getJson($url, $opt);

        if (!$res->ok) {
            $status = match ($res->errorClass) {
                RmHttpClient::CLASS_BLOCKED      => 'blocked',      // usually a bad/expired key
                RmHttpClient::CLASS_RATE_LIMITED => 'rate_limited',
                RmHttpClient::CLASS_NOT_FOUND    => 'missing_episode',
                default                          => 'fetch_failed',
            };
            $hint = $res->status === 401 ? ' — the configured TMDB key was rejected' : '';
            return $this->emptyResult($pageUrl, $status, $res->error . $hint,
                ['_error_class' => $res->errorClass, '_http' => $res->status, '_ms' => $res->ms]);
        }

        $out = [];
        if (!empty($data['name']))    $out['title']    = RmNormalizer::title((string)$data['name'], $epNum);
        if (!empty($data['air_date'])) $out['air_date'] = RmNormalizer::date((string)$data['air_date']);
        if (!empty($data['overview'])) $out['synopsis'] = RmNormalizer::paragraphText((string)$data['overview']);
        if (!empty($data['still_path'])) $out['image_url'] = 'https://image.tmdb.org/t/p/w1280' . $data['still_path'];

        // The public page, not the keyed API call, is what provenance stores.
        $res->url = $pageUrl;
        return $this->result($out, $pageUrl, $res);
    }
}
