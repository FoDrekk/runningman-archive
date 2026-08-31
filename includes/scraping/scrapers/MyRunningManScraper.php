<?php
// ============================================================
// MyRunningManScraper — myrunningman.com (server-rendered).
//
// The archive's best source for the three fields nothing else
// reliably carries: the real per-episode THUMBNAIL, the filming
// LOCATION, and community TAGS. Also carries per-episode synopsis
// text for many episodes Wikipedia never described.
//
// Route note kept from the original debugging: the per-episode page
// is /ep/{n}. /episodes/{n} silently 200s into the paginated episode
// INDEX (treating {n} as a page number) and contains zero
// episode-specific content — which is exactly why location and tags
// used to come back empty on every single episode.
//
// Fields: synopsis · location · tags · image_url · title · air_date · mission
// ============================================================
require_once __DIR__ . '/AbstractScraper.php';

class MyRunningManScraper extends RmScraper
{
    public function name(): string { return 'myrunningman'; }
    public function parserVersion(): string { return 'mrm-3.0'; }

    public function fields(): array {
        return ['synopsis','location','tags','image_url','title','air_date','mission'];
    }

    protected function episodeUrl(int $epNum): string {
        return "https://www.myrunningman.com/ep/$epNum";
    }

    public function episode(int $epNum, array $ctx = []): array
    {
        $url = $this->episodeUrl($epNum);
        $res = $this->get($url, [
            'timeout'    => 12,
            'min_bytes'  => 500,
            'cache_ttl'  => RmCache::episodeTtl($epNum, $ctx['latest'] ?? null),
            'cache_key'  => "mrm:ep:$epNum",
            'bypass_cache' => !empty($ctx['bypass_cache']),
        ]);
        if (!$res->ok) {
            return $this->emptyResult($url, $this->statusFor($res), $res->error,
                ['_error_class' => $res->errorClass, '_http' => $res->status, '_ms' => $res->ms]);
        }
        $html = (string)$res->body;

        // A page that renders the paginated index instead of an episode is
        // a routing failure, not data. Catch it before parsing anything.
        if (preg_match('/Episodes?\s*[-–]\s*Page\s*\d+/i', $html)) {
            return $this->emptyResult($url, 'parser_warning',
                'Received the paginated episode index instead of the episode page',
                ['_error_class' => 'unexpected_page', '_http' => $res->status, '_ms' => $res->ms]);
        }

        $out = [];

        // Thumbnail — og:image on a server-rendered page is the real image.
        $img = $this->meta($html, 'og:image') ?? $this->firstMatch($html, [
            '/<img[^>]+class=["\'][^"\']*(?:episode|thumb|poster)[^"\']*["\'][^>]+src=["\']([^"\']+)["\']/i',
            '/<img[^>]+src=["\']([^"\']*\/(?:thumbs?|episodes?|images?)\/[^"\']+\.(?:jpe?g|png|webp))["\']/i',
        ]);
        if ($img) $out['image_url'] = RmNormalizer::url($img, 'https://www.myrunningman.com');

        // Location — "📍 Location: <name>" as plain text near the top.
        $loc = $this->firstMatch($html, [
            '/Location\s*:\s*<\/[^>]+>\s*([^<\n]{3,150})/i',
            '/>\s*Location\s*:\s*([^<\n]{3,150})/i',
            '/<[^>]+class=["\'][^"\']*location[^"\']*["\'][^>]*>([^<]{3,150})</i',
        ]);
        if ($loc) $out['location'] = $loc;

        // Synopsis — prefer real body prose, fall back to og:description.
        $syn = $this->firstMatch($html, [
            '/<div[^>]+class=["\'][^"\']*(?:description|synopsis|summary|ep-?desc)[^"\']*["\'][^>]*>(.{30,3000}?)<\/div>/is',
            '/<p[^>]+class=["\'][^"\']*(?:description|synopsis|summary)[^"\']*["\'][^>]*>(.{30,3000}?)<\/p>/is',
        ]);
        if ($syn) $syn = RmNormalizer::paragraphText(strip_tags($syn));
        if (!$syn) {
            $meta = $this->meta($html, 'og:description') ?? $this->meta($html, 'description');
            if ($meta && !preg_match('/watch running man|myrunningman|episode list|browse/i', $meta)) $syn = $meta;
        }
        if ($syn) $out['synopsis'] = $syn;

        // Title — only useful when it carries a descriptor beyond the number.
        $title = $this->episodeTitleCandidate($html, $epNum);
        if ($title && str_contains($title, ' - ')) $out['title'] = RmNormalizer::title($title, $epNum);

        $date = $this->firstMatch($html, [
            '/Broadcast\s*Date[^0-9]{0,20}(\d{4}-\d{2}-\d{2})/i',
            '/Air(?:ed|\s*Date)[^0-9]{0,20}(\d{4}-\d{2}-\d{2})/i',
            '/<time[^>]+datetime=["\'](\d{4}-\d{2}-\d{2})/i',
        ]);
        if ($date) $out['air_date'] = RmNormalizer::date($date);

        $mission = $this->firstMatch($html, [
            '/Mission\s*:\s*<\/[^>]+>\s*([^<\n]{3,200})/i',
            '/>\s*(?:Main\s*)?Mission\s*:\s*([^<\n]{3,200})/i',
        ]);
        if ($mission) $out['mission'] = $mission;

        // Tags — links to /tags/<slug> on the episode page.
        if (preg_match_all('/<a[^>]+href=["\'][^"\']*\/tags?\/[^"\']+["\'][^>]*>([^<]{2,40})<\/a>/i', $html, $tm)) {
            $tags = [];
            foreach ($tm[1] as $t) { $c = RmNormalizer::tag($t); if ($c !== null) $tags[mb_strtolower($c,'UTF-8')] = $c; }
            if ($tags) $out['tags'] = array_slice(array_values($tags), 0, 15);
        }

        return $this->result($out, $url, $res);
    }

    /** Does this source have a real page for episode N? Used for latest-ep probing. */
    public function episodeExists(int $epNum): bool
    {
        $res = $this->get($this->episodeUrl($epNum), [
            'timeout' => 10, 'retries' => 1, 'min_bytes' => 500,
            'cache_ttl' => (int)rmScrapeConfig('cache.ttl_recent', 900),
            'cache_key' => "mrm:exists:$epNum",
        ]);
        if (!$res->ok) return false;
        $html = (string)$res->body;
        if (preg_match('/Episodes?\s*[-–]\s*Page\s*\d+/i', $html)) return false;
        if (preg_match('/Episode\s*#\s*0*' . $epNum . '\b/i', $html)) return true;
        $og = $this->meta($html, 'og:title');
        return $og !== null && (bool)preg_match('/Episode\s*#?\s*0*' . $epNum . '\b/i', $og);
    }

    private function statusFor(RmHttpResponse $res): string
    {
        return match ($res->errorClass) {
            RmHttpClient::CLASS_NOT_FOUND    => 'missing_episode',
            RmHttpClient::CLASS_BLOCKED      => 'blocked',
            RmHttpClient::CLASS_RATE_LIMITED => 'rate_limited',
            RmHttpClient::CLASS_EMPTY        => 'empty',
            default                          => 'fetch_failed',
        };
    }
}

// ── myrm.tv — the same backend behind a second domain ──────────
// Kept as its own adapter because its per-episode markup differs
// (SPA shell with meta tags) and because it is genuinely useful for
// synopsis/air_date on episodes myrunningman.com renders differently.
// It is NOT used for thumbnails: its og:image is a site-wide meta tag
// that is frequently absent or generic, never the real episode still.
class MyRMtvScraper extends RmScraper
{
    public function name(): string { return 'myrm'; }
    public function parserVersion(): string { return 'myrm-3.0'; }

    public function fields(): array { return ['title','synopsis','air_date','guests']; }

    public function episode(int $epNum, array $ctx = []): array
    {
        $url = "https://myrm.tv/ep/$epNum";
        $res = $this->get($url, [
            'timeout'   => 12,
            'min_bytes' => 300,
            'cache_ttl' => RmCache::episodeTtl($epNum, $ctx['latest'] ?? null),
            'cache_key' => "myrm:ep:$epNum",
            'bypass_cache' => !empty($ctx['bypass_cache']),
        ]);
        if (!$res->ok) {
            return $this->emptyResult($url,
                $res->errorClass === RmHttpClient::CLASS_NOT_FOUND ? 'missing_episode' : 'fetch_failed',
                $res->error, ['_error_class' => $res->errorClass, '_http' => $res->status, '_ms' => $res->ms]);
        }
        $html = (string)$res->body;
        $out  = [];

        // Only accept a title that actually carries a descriptor — this
        // adapter's bare titles are just the episode number restated —
        // and only from evidence that is scoped to this episode.
        $title = $this->episodeTitleCandidate($html, $epNum);
        if ($title !== null) {
            $t = RmNormalizer::title($title, $epNum);
            if (str_contains($t, ' - ')) $out['title'] = $t;
        }

        $syn = $this->meta($html, 'og:description') ?? $this->meta($html, 'description');
        if ($syn !== null && !preg_match('/watch running man|myrunningman|episode list/i', $syn)) {
            $out['synopsis'] = $syn;
        }

        // Pages show BOTH a broadcast date and a filming date; the labelled
        // form is tried first so a naive match can't grab the wrong one.
        $date = $this->firstMatch($html, [
            '/Broadcast\s*Date[^0-9]{0,20}(\d{4}-\d{2}-\d{2})/i',
            '/<time[^>]+datetime=["\'](\d{4}-\d{2}-\d{2})/i',
            '/(\d{4}-\d{2}-\d{2})/',
        ]);
        if ($date) $out['air_date'] = RmNormalizer::date($date);

        if (preg_match('/Guests?\s*:\s*<\/[^>]+>\s*([^<\n]{3,250})/i', $html, $m)
            || preg_match('/>\s*Guests?\s*:\s*([^<\n]{3,250})/i', $html, $m)) {
            $guests = [];
            foreach (preg_split('/\s*[,·&]\s*|\s+and\s+/i', $m[1]) as $g) {
                $n = RmNormalizer::guestName($g);
                if ($n !== null) $guests[] = $n;
            }
            if ($guests) $out['guests'] = $guests;
        }

        return $this->result($out, $url, $res);
    }
}
