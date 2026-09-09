<?php
// ============================================================
// KShow123Scraper — kshow123.tv, secondary availability check + a
// thumbnail fallback.
//
// Role (PR #4 source policy): a community-run streaming index, not an
// editorial or broadcast record. It earns a place only for what it
// genuinely offers that the canonical/verification sources don't — a
// second confirmation that an episode EXISTS (useful right after a new
// one airs, before Wikipedia/SBS have caught up) and one more candidate
// image when SBS/MyRunningMan/MyRM have none. It is `class: metadata`,
// so RmDecisionEngine's class-rank guard already keeps it from
// overwriting anything a stronger source supplied — it can only FILL a
// gap, never UPDATE a value another class already holds.
//
// Fields: title · image_url
// ============================================================
require_once __DIR__ . '/AbstractScraper.php';

class KShow123Scraper extends RmScraper
{
    public function name(): string { return 'kshow123'; }
    public function parserVersion(): string { return 'kshow123-1.0'; }

    public function fields(): array { return ['title', 'image_url']; }

    private function base(): string { return rtrim((string)rmScrapeConfig('sources.kshow123.base', 'https://kshow123.tv/'), '/'); }

    /**
     * The site indexes by a slug, not a stable numeric id, so the
     * episode page is reached through its search endpoint rather than a
     * guessed URL pattern — the same "ask the site, don't assume its
     * routes" approach Discovery.php uses for the other sources.
     */
    private function findUrl(int $epNum): ?string
    {
        $cacheKey = "kshow123:url:$epNum";
        $cached = $this->cache()->get($cacheKey);
        if (is_string($cached)) return $cached ?: null;

        $searchUrl = $this->base() . '/search.html?keyword=' . urlencode("running man episode $epNum");
        $res = $this->get($searchUrl, ['timeout' => 12, 'min_bytes' => 200, 'cache_ttl' => (int)rmScrapeConfig('cache.ttl_index', 1800)]);
        $found = null;
        if ($res->ok) {
            $html = (string)$res->body;
            if (preg_match_all('/<a[^>]+href=["\']([^"\']*running-man[^"\']*episode-' . $epNum . '(?:-|["\'])[^"\']*)["\']/i', $html, $m)) {
                $found = RmNormalizer::url($m[1][0], $this->base());
            }
        }
        // Cache a miss too (empty string), so a non-existent episode does
        // not trigger a fresh search on every subsequent run.
        $this->cache()->set($cacheKey, (string)$found, (int)rmScrapeConfig('cache.ttl_index', 1800), 'index');
        return $found;
    }

    public function episode(int $epNum, array $ctx = []): array
    {
        $listUrl = $this->base() . '/running-man.html';
        $url = $this->findUrl($epNum);
        if ($url === null) {
            return $this->emptyResult($listUrl, 'missing_episode',
                "No page found for episode $epNum via search", ['_error_class' => 'missing_episode']);
        }

        $res = $this->get($url, [
            'timeout'   => 12,
            'min_bytes' => 300,
            'cache_ttl' => RmCache::episodeTtl($epNum, $ctx['latest'] ?? null),
            'cache_key' => "kshow123:ep:$epNum",
            'bypass_cache' => !empty($ctx['bypass_cache']),
        ]);
        if (!$res->ok) {
            $status = match ($res->errorClass) {
                RmHttpClient::CLASS_NOT_FOUND    => 'missing_episode',
                RmHttpClient::CLASS_BLOCKED      => 'blocked',
                RmHttpClient::CLASS_RATE_LIMITED => 'rate_limited',
                default                          => 'fetch_failed',
            };
            return $this->emptyResult($url, $status, $res->error,
                ['_error_class' => $res->errorClass, '_http' => $res->status, '_ms' => $res->ms]);
        }
        $html = (string)$res->body;
        $out  = [];

        // Only a title that actually names this episode is accepted —
        // an aggregator's own confirmation that the page it served is the
        // one that was asked for, not a "video removed" placeholder.
        $title = $this->episodeTitleCandidate($html, $epNum);
        if ($title !== null && $this->mentionsEpisode($title, $epNum)) {
            $out['title'] = RmNormalizer::title($title, $epNum);
        }

        $img = $this->meta($html, 'og:image') ?? $this->firstMatch($html, [
            '/<img[^>]+class=["\'][^"\']*(?:poster|thumb|cover)[^"\']*["\'][^>]+src=["\']([^"\']+)["\']/i',
        ]);
        if ($img) $out['image_url'] = RmNormalizer::url($img, $this->base());

        return $this->result($out, $url, $res);
    }
}
