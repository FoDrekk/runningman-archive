<?php
// ============================================================
// RmDiscovery — finding public pages the way sites publish them.
//
// A guessed URL pattern works until the site reorganises, and then it
// works for nobody and nothing says why. Sites already publish where
// their pages are: sitemap.xml, an RSS or Atom feed, a canonical link
// on a page we can reach, JSON-LD naming a related page. Reading
// those is asking the site rather than guessing at it.
//
// What this deliberately does NOT do:
//   · scrape search-engine result pages
//   · follow anything behind a login, paywall or bot challenge
//   · probe for endpoints the site has not published
//   · retry past a 401/403/429
//
// A source that says no is recorded as ACCESS_RESTRICTED and left
// alone. Aggressive research, conservative access.
// ============================================================
require_once __DIR__ . '/../../config/scraping.php';
require_once __DIR__ . '/Http.php';
require_once __DIR__ . '/Cache.php';
require_once __DIR__ . '/ResearchState.php';

class RmDiscovery
{
    private ?PDO $db;

    public function __construct(?PDO $db = null)
    {
        try { $this->db = $db ?: getDBSafe(); } catch (Throwable $e) { $this->db = null; }
    }

    private function enabled(): bool { return (bool)rmScrapeConfig('discovery.enabled', true); }

    /**
     * Candidate public URLs for one episode, per source.
     *
     * Cheap strategies first: anything already confirmed for this
     * episode, then the site's own sitemap and feed. Nothing here
     * fetches an episode page — that is the adapter's job. This only
     * answers "where would the page be".
     *
     * @return array<string,string[]> source => URLs, best first
     */
    public function forEpisode(int $epNum, array $sources = [], array $opt = []): array
    {
        if (!$this->enabled()) return [];
        $max = (int)rmScrapeConfig('discovery.max_candidates', 6);
        $out = [];

        foreach ($this->confirmed($epNum) as $source => $urls) {
            if ($sources && !in_array($source, $sources, true)) continue;
            $out[$source] = array_slice($urls, 0, $max);
        }

        foreach ($sources ?: ['myrunningman', 'myrm', 'kshow123'] as $source) {
            $found = array_merge(
                $out[$source] ?? [],
                $this->fromSitemap($source, $epNum),
                $this->fromFeed($source, $epNum),
            );
            $found = array_values(array_unique(array_filter($found)));
            if ($found) $out[$source] = array_slice($found, 0, $max);
        }

        foreach ($out as $source => $urls) $this->remember($epNum, $source, $urls, 'discovered');
        return $out;
    }

    // ────────────────────────────────────────────────────────────
    // Strategies
    // ────────────────────────────────────────────────────────────
    /** URLs this episode has been fetched from successfully before. */
    public function confirmed(int $epNum): array
    {
        if ($this->db === null || !rmResearchTablesExist()) return [];
        try {
            $s = $this->db->prepare(
                "SELECT source_name, url FROM research_discovery
                  WHERE episode_number = ? AND status = 'confirmed'
                  ORDER BY confirmed_at DESC"
            );
            $s->execute([$epNum]);
            $out = [];
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $out[$r['source_name']][] = $r['url'];
            return $out;
        } catch (Throwable $e) { return []; }
    }

    /**
     * The site's own sitemap, filtered to entries that name this
     * episode. Fetched at most once a day per source, because a
     * sitemap is a big file and re-reading it per episode would be
     * exactly the kind of rudeness the rate limiter exists to prevent.
     *
     * @return string[]
     */
    public function fromSitemap(string $source, int $epNum): array
    {
        $base = (string)rmScrapeConfig("sources.$source.base", '');
        if ($base === '') return [];

        $xml = $this->cachedDocument($source, rtrim($base, '/') . '/sitemap.xml', 'sitemap');
        if ($xml === null) return [];

        // Sitemap indexes point at other sitemaps; follow one level only.
        if (str_contains($xml, '<sitemapindex')) {
            $child = null;
            if (preg_match_all('~<loc>\s*([^<]+?)\s*</loc>~i', $xml, $m)) {
                foreach ($m[1] as $loc) {
                    if (preg_match('/episode|post|page/i', $loc)) { $child = $loc; break; }
                }
                $child ??= $m[1][0] ?? null;
            }
            if ($child === null) return [];
            $xml = $this->cachedDocument($source, $child, 'sitemap') ?? '';
        }

        return $this->matchingLocs($xml, $epNum);
    }

    /**
     * A public RSS/Atom feed, for the newest episodes. Feeds are the
     * fastest honest way to learn that something aired.
     *
     * @return string[]
     */
    public function fromFeed(string $source, int $epNum): array
    {
        $base = (string)rmScrapeConfig("sources.$source.base", '');
        if ($base === '') return [];
        foreach (['/feed', '/rss', '/feed.xml', '/rss.xml'] as $path) {
            $xml = $this->cachedDocument($source, rtrim($base, '/') . $path, 'feed');
            if ($xml === null) continue;
            $hits = $this->matchingLocs($xml, $epNum);
            if ($hits) return $hits;
        }
        return [];
    }

    /**
     * The canonical URL and any structured-data links a page declares
     * about itself. Used to confirm that the page we landed on is the
     * page the site considers authoritative for this episode.
     *
     * @return string[]
     */
    public static function canonicalLinks(string $html): array
    {
        $out = [];
        if (preg_match('~<link[^>]+rel=["\']canonical["\'][^>]+href=["\']([^"\']+)~i', $html, $m)) $out[] = $m[1];
        if (preg_match('~<meta[^>]+property=["\']og:url["\'][^>]+content=["\']([^"\']+)~i', $html, $m)) $out[] = $m[1];
        if (preg_match_all('~<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>~is', $html, $m)) {
            foreach ($m[1] as $blk) {
                $d = json_decode(trim($blk), true);
                if (!is_array($d)) continue;
                foreach (['url', '@id', 'mainEntityOfPage'] as $k) {
                    $v = $d[$k] ?? null;
                    if (is_array($v)) $v = $v['@id'] ?? ($v['url'] ?? null);
                    if (is_string($v) && str_starts_with($v, 'http')) $out[] = $v;
                }
            }
        }
        return array_values(array_unique($out));
    }

    // ────────────────────────────────────────────────────────────
    // Plumbing
    // ────────────────────────────────────────────────────────────
    /** @return string[] <loc>/<link> entries that name this episode */
    private function matchingLocs(string $xml, int $epNum): array
    {
        $out = [];
        $pattern = '~(?<!\d)0*' . $epNum . '(?!\d)~';
        if (preg_match_all('~<(?:loc|link)>\s*([^<]+?)\s*</(?:loc|link)>~i', $xml, $m)) {
            foreach ($m[1] as $loc) if (preg_match($pattern, $loc)) $out[] = trim($loc);
        }
        if (preg_match_all('~<link[^>]+href=["\']([^"\']+)["\']~i', $xml, $m2)) {
            foreach ($m2[1] as $loc) if (preg_match($pattern, $loc)) $out[] = trim($loc);
        }
        return array_values(array_unique($out));
    }

    /**
     * Fetch a discovery document, cached hard and never retried past a
     * refusal. A source that 403s its sitemap has told us not to ask.
     */
    private function cachedDocument(string $source, string $url, string $kind): ?string
    {
        $res = RmHttpClient::instance()->get($url, [
            'timeout'    => (int)rmScrapeConfig('discovery.timeout', 12),
            'retries'    => 0,
            'source'     => $source,
            'accept_json'=> false,
            'min_bytes'  => 60,
            'cache_ttl'  => (int)rmScrapeConfig('discovery.sitemap_ttl', 86400),
            'cache_key'  => "discovery:$kind:" . md5($url),
            'cache_type' => 'api',
        ]);
        if (!$res->ok) return null;
        $body = (string)$res->body;
        // A themed 404 page is HTML, not a sitemap. Reading it as one
        // produces confident nonsense.
        return preg_match('~<(?:urlset|sitemapindex|rss|feed)\b~i', $body) ? $body : null;
    }

    /** Remember a candidate so the next run does not re-derive it. */
    public function remember(int $epNum, string $source, array $urls, string $strategy): void
    {
        if ($this->db === null || !rmResearchTablesExist() || !$urls) return;
        try {
            $ins = $this->db->prepare(
                'INSERT INTO research_discovery (episode_number, source_name, url, strategy, status)
                 VALUES (?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE strategy = VALUES(strategy)'
            );
            foreach ($urls as $u) {
                $ins->execute([$epNum, mb_substr($source, 0, 40), mb_substr((string)$u, 0, 500),
                               mb_substr($strategy, 0, 30), 'candidate']);
            }
        } catch (Throwable $e) { }
    }

    /** Mark the URL an adapter actually got data from, so it is tried first next time. */
    public function confirm(int $epNum, string $source, ?string $url): void
    {
        if ($this->db === null || !rmResearchTablesExist() || !$url) return;
        try {
            $this->db->prepare(
                "INSERT INTO research_discovery (episode_number, source_name, url, strategy, status, confirmed_at)
                 VALUES (?,?,?,'confirmed','confirmed',NOW())
                 ON DUPLICATE KEY UPDATE status='confirmed', confirmed_at=NOW()"
            )->execute([$epNum, mb_substr($source, 0, 40), mb_substr($url, 0, 500)]);
        } catch (Throwable $e) { }
    }

    /**
     * The latest episode, asked of the sources rather than assumed from
     * the database maximum — the archive cannot know about an episode
     * it has never seen.
     */
    public static function latestEpisode(): array
    {
        $votes = []; $notes = [];
        foreach (RmSourceRegistry::instance()->active() as $name => $adapter) {
            try { $n = $adapter->latestEpisode(); } catch (Throwable $e) { $n = null; }
            if ($n === null || $n <= 0) continue;
            $votes[$name] = (int)$n;
            $notes[] = "$name says EP$n";
        }
        if (!$votes) {
            return ['latest' => null, 'confidence' => 'none', 'votes' => [],
                    'note' => 'No source could be asked for the latest episode'];
        }
        $counts = array_count_values($votes);
        arsort($counts);
        $top    = (int)array_key_first($counts);
        $agree  = $counts[$top];
        $spread = max($votes) - min($votes);

        return [
            'latest'     => max($votes),          // never behind what a source has seen
            'consensus'  => $top,
            'confidence' => match (true) {
                $agree >= 3 && $spread === 0 => 'high',
                $agree >= 2                  => 'medium',
                default                      => 'low',
            },
            'votes'      => $votes,
            'conflict'   => $spread > 0,
            'note'       => implode(' · ', $notes),
        ];
    }
}
