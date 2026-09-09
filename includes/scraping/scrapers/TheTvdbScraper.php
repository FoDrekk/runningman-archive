<?php
// ============================================================
// TheTvdbScraper — TheTVDB, independent episode/date verification.
//
// Role (PR #4 source policy): a second, editorially-run database that
// did not copy its numbering from Wikipedia or SBS. Its value is not
// being the best source for any one field — it rarely is — but being
// an INDEPENDENT witness that either corroborates the leading candidate
// or raises a SOURCE_CONFLICT worth a human's attention.
//
// Strictly optional, exactly like the old TMDB adapter it replaces:
//   · the key is read from the environment (RM_TVDB_API_KEY) or from
//     the git-ignored config/scraping.local.php — never hardcoded
//   · with no key the source reports itself disabled and the engine
//     skips it entirely, costing nothing
//   · the key is never logged; only the bearer token derived from it
//     is held in cache, and only for its own short lifetime
//
// TVDB v4 requires a POST login exchange (apikey → bearer token) before
// any read — the one place in this project that needs RmHttpClient's
// postJson(), everything else here is a normal GET.
//
// Fields: title · air_date · image_url
// ============================================================
require_once __DIR__ . '/AbstractScraper.php';

class TheTvdbScraper extends RmScraper
{
    public function name(): string { return 'tvdb'; }
    public function parserVersion(): string { return 'tvdb-1.0'; }

    public function fields(): array { return ['title', 'air_date', 'image_url']; }

    private function key(): ?string
    {
        $k = rmScrapeConfig('api_keys.tvdb');
        return (is_string($k) && trim($k) !== '') ? trim($k) : null;
    }

    private function seriesId(): int { return (int)rmScrapeConfig('sources.tvdb.series', 0); }

    private function base(): string { return rtrim((string)rmScrapeConfig('sources.tvdb.base', 'https://api4.thetvdb.com/v4/'), '/'); }

    /** Bearer token, cached for well under its real ~1 month lifetime. */
    private function token(): ?string
    {
        $cacheKey = 'tvdb:token';
        $cached = $this->cache()->get($cacheKey);
        if (is_string($cached) && $cached !== '') return $cached;

        [$data, $res] = $this->postJson($this->base() . '/login', ['apikey' => $this->key()],
            ['timeout' => 15, 'cache_ttl' => 0]);
        if (!$res->ok || !is_array($data)) return null;

        $token = (string)($data['data']['token'] ?? '');
        if ($token === '') return null;
        $this->cache()->set($cacheKey, $token, 82800, 'api');   // 23h — refreshed well before expiry
        return $token;
    }

    /**
     * Map an absolute episode number to TVDB's internal episode id.
     * Running Man is catalogued as one continuous ordering on TVDB, but
     * the "official" order still numbers by in-season position, so the
     * same digits-in-name heuristic used for the old TMDB adapter finds
     * the real absolute number.
     */
    private function locate(int $epNum, string $token): ?array
    {
        $cacheKey = 'tvdb:index';
        $index = $this->cache()->get($cacheKey);

        if (!is_array($index)) {
            $index = [];
            $auth = ['headers' => ['Authorization: Bearer ' . $token]];
            for ($page = 0; $page < 40; $page++) {
                $opt = $auth + ['timeout' => 15, 'cache_ttl' => 0, 'retries' => 1];
                [$data, $res] = $this->getJson(
                    $this->base() . '/series/' . $this->seriesId() . '/episodes/default?page=' . $page, $opt
                );
                if (!$res->ok || !is_array($data)) break;
                $episodes = (array)($data['data']['episodes'] ?? []);
                if (!$episodes) break;
                foreach ($episodes as $ep) {
                    $abs = null;
                    if (preg_match('/\b(?:Ep(?:isode)?\.?\s*)?#?(\d{1,4})\s*(?:회|\b)/u', (string)($ep['name'] ?? ''), $m)) {
                        $n = (int)$m[1];
                        if ($n >= 1 && $n <= 2000) $abs = $n;
                    }
                    if ($abs === null) continue;
                    $index[$abs] = ['id' => (int)($ep['id'] ?? 0), 'image' => $ep['image'] ?? null,
                                     'aired' => $ep['aired'] ?? null, 'name' => $ep['name'] ?? null];
                }
                if (empty($data['links']['next'])) break;
            }
            if ($index) $this->cache()->set($cacheKey, $index, (int)rmScrapeConfig('cache.ttl_api', 43200), 'api');
        }
        return $index[$epNum] ?? null;
    }

    public function episode(int $epNum, array $ctx = []): array
    {
        $pageUrl = 'https://thetvdb.com/series/running-man';
        if ($this->key() === null || $this->seriesId() <= 0) {
            return $this->emptyResult($pageUrl, 'disabled',
                'No TheTVDB API key configured — optional source skipped (set RM_TVDB_API_KEY to enable)',
                ['_error_class' => 'not_configured']);
        }

        $token = $this->token();
        if ($token === null) {
            return $this->emptyResult($pageUrl, 'blocked',
                'TheTVDB login failed — the configured key was rejected or the login endpoint is unreachable',
                ['_error_class' => RmHttpClient::CLASS_BLOCKED]);
        }

        $loc = $this->locate($epNum, $token);
        if ($loc === null) {
            return $this->emptyResult($pageUrl, 'missing_episode',
                "TheTVDB has no entry mapping to episode $epNum", ['_error_class' => 'missing_episode']);
        }

        $opt = [
            'headers'   => ['Authorization: Bearer ' . $token],
            'timeout'   => 15,
            'cache_ttl' => RmCache::episodeTtl($epNum, $ctx['latest'] ?? null, 'api'),
            'cache_key' => 'tvdb:ep:' . $epNum,
            'bypass_cache' => !empty($ctx['bypass_cache']),
            'retries'   => 1,
        ];
        [$data, $res] = $this->getJson($this->base() . '/episodes/' . $loc['id'], $opt);

        if (!$res->ok) {
            $status = match ($res->errorClass) {
                RmHttpClient::CLASS_BLOCKED      => 'blocked',
                RmHttpClient::CLASS_RATE_LIMITED => 'rate_limited',
                RmHttpClient::CLASS_NOT_FOUND    => 'missing_episode',
                default                          => 'fetch_failed',
            };
            return $this->emptyResult($pageUrl, $status, $res->error,
                ['_error_class' => $res->errorClass, '_http' => $res->status, '_ms' => $res->ms]);
        }

        $ep = (array)($data['data'] ?? []);
        $out = [];
        if (!empty($ep['name']))  $out['title']    = RmNormalizer::title((string)$ep['name'], $epNum);
        if (!empty($ep['aired'])) $out['air_date'] = RmNormalizer::date((string)$ep['aired']);
        $image = $ep['image'] ?? $loc['image'] ?? null;
        if (!empty($image)) {
            $out['image_url'] = str_starts_with((string)$image, 'http')
                ? (string)$image : 'https://artworks.thetvdb.com' . $image;
        }

        $res->url = $pageUrl;
        return $this->result($out, $pageUrl, $res);
    }
}
