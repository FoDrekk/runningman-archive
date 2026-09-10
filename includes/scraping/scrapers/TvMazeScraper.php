<?php
// ============================================================
// TvMazeScraper — TVmaze (api.tvmaze.com), PR12.
//
// Role, deliberately narrow (see PR12 scope): episode-number, title and
// air_date corroboration ONLY. TVmaze carries no guest/mission/location
// data for a Korean variety show, so this adapter never claims any.
//
// Why it earns a place next to TheTVDB rather than duplicating it: TVDB
// stays disabled and silently skipped until someone configures
// RM_TVDB_API_KEY (see TheTvdbScraper) — TVmaze's public API needs NO
// key at all, so it is the one zero-configuration independent
// air-date/title witness this project has. Its independence from TVDB
// itself is not fully confirmed (both are third-party TV databases and
// could in principle share upstream data for a niche foreign show) — an
// open question this project could not verify from the build sandbox,
// which is exactly why it is registered LOW tier and placed LAST in the
// 'air_date'/'title' priority lists: it can corroborate or fill a gap,
// it can never win a field outright until it has a track record.
//
// Absolute-episode-number matching reuses the SAME heuristic already
// reviewed and shipped in TheTvdbScraper::locate() — Running Man's own
// episode numbering never resets, so a source that publishes that number
// somewhere in its episode name can be indexed directly by it. Episodes
// whose name carries no recognisable number are simply not indexed
// (absent, not wrong) rather than guessed at via list position, which
// would silently break the moment TVmaze's own season/episode grouping
// doesn't line up 1:1 with the archive's numbering.
//
// Fields: title · air_date
// ============================================================
require_once __DIR__ . '/AbstractScraper.php';

class TvMazeScraper extends RmScraper
{
    public function name(): string { return 'tvmaze'; }
    public function parserVersion(): string { return 'tvmaze-1.0'; }

    public function fields(): array { return ['title', 'air_date']; }

    private function base(): string { return rtrim((string)rmScrapeConfig('sources.tvmaze.base', 'https://api.tvmaze.com/'), '/'); }

    private function showId(): int { return (int)rmScrapeConfig('sources.tvmaze.show_id', 0); }

    /**
     * Same digit-extraction heuristic as TheTvdbScraper::locate(), pulled
     * out as its own pure function so the matching rule itself — not just
     * the whole HTTP round trip — is directly testable. Returns null when
     * the name carries no recognisable absolute episode number, which the
     * caller treats as "not indexed", never as episode 0 or a guess.
     */
    public static function absoluteEpisodeNumber(string $name): ?int
    {
        if (!preg_match('/\b(?:ep(?:isode)?\.?\s*)?#?(\d{1,4})\s*(?:회|\b)/iu', $name, $m)) return null;
        $abs = (int)$m[1];
        return ($abs >= 1 && $abs <= 2000) ? $abs : null;
    }

    /**
     * Build epNum => ['air_date'=>..,'title'=>..] from the full embedded
     * episode list, keyed by absoluteEpisodeNumber(). Cached long-term at
     * the app level (not at the HTTP layer — 'cache_ttl'=>0 below is
     * deliberate, this index is the one and only cache of this call).
     */
    private function index(bool $bypassCache): ?array
    {
        $cacheKey = 'tvmaze:index';
        if (!$bypassCache) {
            $hit = $this->cache()->get($cacheKey);
            if (is_array($hit) && count($hit) > 0) return $hit;
        }

        [$data, $res] = $this->getJson($this->base() . '/shows/' . $this->showId() . '?embed=episodes', [
            'timeout' => 15, 'cache_ttl' => 0, 'retries' => 1,
        ]);
        if (!$res->ok || !is_array($data)) return null;

        $episodes = (array)($data['_embedded']['episodes'] ?? []);
        $index = [];
        foreach ($episodes as $ep) {
            $name = (string)($ep['name'] ?? '');
            $abs  = self::absoluteEpisodeNumber($name);
            if ($abs === null) continue;
            $index[$abs] = ['name' => $name !== '' ? $name : null, 'airdate' => $ep['airdate'] ?? null];
        }
        if ($index) $this->cache()->set($cacheKey, $index, (int)rmScrapeConfig('cache.ttl_api', 43200), 'api');
        return $index;
    }

    public function episode(int $epNum, array $ctx = []): array
    {
        $url = 'https://www.tvmaze.com/shows/' . $this->showId() . '/running-man';
        if ($this->showId() <= 0) {
            return $this->emptyResult($url, 'disabled', 'TVmaze show id not configured', ['_error_class' => 'not_configured']);
        }

        $index = $this->index(!empty($ctx['bypass_cache']));
        if ($index === null) {
            $err = RmHttpClient::instance()->lastError();
            return $this->emptyResult($url, 'fetch_failed', $err ?? 'Could not load the TVmaze episode list',
                ['_error_class' => RmHttpClient::CLASS_OTHER]);
        }

        $ep = $index[$epNum] ?? null;
        if ($ep === null) {
            return $this->emptyResult($url, 'missing_episode',
                "No TVmaze episode name resolves to absolute episode $epNum", ['_error_class' => 'missing_episode']);
        }

        $out = [];
        if (!empty($ep['name']))    $out['title']    = RmNormalizer::title((string)$ep['name'], $epNum);
        if (!empty($ep['airdate'])) $out['air_date'] = RmNormalizer::date((string)$ep['airdate']);

        $hasFields = $out !== [];
        $out['_url']            = $url;
        $out['_status']         = $hasFields ? 'ok' : 'parser_warning';
        $out['_parser_note']    = $hasFields ? null : 'Matched a TVmaze entry for this episode but it carried no usable title/air date';
        $out['_http']           = 200;
        $out['_ms']             = 0;
        $out['_error']          = $out['_parser_note'];
        $out['_error_class']    = $hasFields ? RmHttpClient::CLASS_OK : 'parser_failure';
        $out['_hash']           = RmNormalizer::hash(json_encode($ep, JSON_UNESCAPED_UNICODE));
        return $out;
    }
}
