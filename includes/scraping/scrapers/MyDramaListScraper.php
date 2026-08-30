<?php
// ============================================================
// MyDramaListScraper — mydramalist.com per-episode pages.
//
// Genuine value: editor-written synopses for episodes neither
// Wikipedia nor myrunningman ever described (early seasons in
// particular), plus "Landmark:"/"Guest:" lines.
//
// Known constraint, preserved from the original investigation: this
// host has returned a flat HTTP 403 to every request from some
// networks even with a complete browser header set, which points at
// TLS-fingerprint or IP-reputation filtering rather than anything a
// PHP header can fix. That is now HANDLED rather than hard-disabled:
// the health monitor marks the source BLOCKED, the negative cache
// suppresses further attempts for an hour, and the engine skips it
// while suppressed. If the host can reach MDL, it just works.
//
// Fields: synopsis · location · guests · title · air_date
// ============================================================
require_once __DIR__ . '/AbstractScraper.php';

class MyDramaListScraper extends RmScraper
{
    public function name(): string { return 'mydramalist'; }
    public function parserVersion(): string { return 'mdl-3.0'; }

    public function fields(): array { return ['synopsis','location','guests','title','air_date']; }

    /** The show-level blurb MDL falls back to when it has no episode text. */
    private const GENERIC = '/members?\s+compete\s+in\s+a\s+series\s+of\s+games?\s+and\s+missions?\s+to\s+win\s+the\s+race/i';

    public function episode(int $epNum, array $ctx = []): array
    {
        $url = "https://mydramalist.com/25565-running-man/episode/$epNum";
        $res = $this->get($url, [
            'timeout'   => 12,
            'min_bytes' => 500,
            'cache_ttl' => RmCache::episodeTtl($epNum, $ctx['latest'] ?? null),
            'cache_key' => "mdl:ep:$epNum",
            'bypass_cache' => !empty($ctx['bypass_cache']),
            // A fuller browser header set clears simple bot filtering.
            'headers'   => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
                'Upgrade-Insecure-Requests: 1',
                'Sec-Fetch-Dest: document',
                'Sec-Fetch-Mode: navigate',
                'Sec-Fetch-Site: none',
                'Sec-Fetch-User: ?1',
            ],
        ]);

        if (!$res->ok) {
            $status = match ($res->errorClass) {
                RmHttpClient::CLASS_BLOCKED      => 'blocked',
                RmHttpClient::CLASS_RATE_LIMITED => 'rate_limited',
                RmHttpClient::CLASS_NOT_FOUND    => 'missing_episode',
                default                          => 'fetch_failed',
            };
            $hint = $res->errorClass === RmHttpClient::CLASS_BLOCKED
                ? ' — bot filtering at the TLS/IP layer, not fixable by changing request headers'
                : '';
            return $this->emptyResult($url, $status, $res->error . $hint,
                ['_error_class' => $res->errorClass, '_http' => $res->status, '_ms' => $res->ms]);
        }

        $html = (string)$res->body;
        // Cloudflare-style interstitials return 200 with a stub body.
        if (preg_match('/just a moment|checking your browser|cf-browser-verification/i', $html)) {
            return $this->emptyResult($url, 'blocked',
                'Received an anti-bot interstitial instead of the episode page',
                ['_error_class' => RmHttpClient::CLASS_BLOCKED, '_http' => 200, '_ms' => $res->ms]);
        }

        $out = [];

        $syn = $this->meta($html, 'og:description');
        if ($syn === null) {
            $syn = $this->firstMatch($html, [
                '/<div[^>]+class=["\'][^"\']*episode-synopsis[^"\']*["\'][^>]*>(.{30,3000}?)<\/div>/is',
                '/<div[^>]+class=["\'][^"\']*(?:show-synopsis|description)[^"\']*["\'][^>]*>(.{30,3000}?)<\/div>/is',
            ]);
            if ($syn) $syn = RmNormalizer::paragraphText(strip_tags($syn));
        }
        if ($syn !== null && !preg_match(self::GENERIC, $syn)) {
            // og:description is auto-truncated (~200 chars) and sometimes
            // cuts off right at a trailing structured label, which would
            // otherwise be saved as a synopsis ending on a dangling word.
            $syn = trim(preg_split('/\s*(?:Landmark|Site|Sie|Guests?)\s*:\s*$/i', $syn)[0]);
            if (mb_strlen($syn) > 15) $out['synopsis'] = $syn;
        }

        // "Landmark:"/"Site:" are editor free-text, inconsistently spelled
        // across episodes (a "Sie:" typo exists on at least one) — matched
        // loosely on purpose.
        if (preg_match('/\b(?:Landmark|Site|Sie|Location)\s*:\s*([^\n\/<]{3,150})/i', $html, $m)) {
            $out['location'] = RmNormalizer::text($m[1]);
        }

        if (preg_match('/\bGuests?\s*:\s*([^\n<]{3,250})/i', $html, $m)) {
            $guests = [];
            foreach (preg_split('/\s*[,·&]\s*|\s+and\s+/i', $m[1]) as $g) {
                $n = RmNormalizer::guestName($g);
                if ($n !== null) $guests[] = $n;
            }
            if ($guests) $out['guests'] = $guests;
        }

        $title = $this->meta($html, 'og:title');
        if ($title !== null) {
            $t = RmNormalizer::title($title, $epNum);
            if (str_contains($t, ' - ')) $out['title'] = $t;
        }

        $date = $this->firstMatch($html, [
            '/Air(?:ed)?\s*(?:Date)?\s*:?\s*<\/[^>]+>\s*([A-Za-z]{3,9}\s+\d{1,2},?\s+\d{4}|\d{4}-\d{2}-\d{2})/i',
            '/<time[^>]+datetime=["\'](\d{4}-\d{2}-\d{2})/i',
        ]);
        if ($date) $out['air_date'] = RmNormalizer::date($date);

        return $this->result($out, $url, $res);
    }
}
