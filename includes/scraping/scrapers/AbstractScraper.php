<?php
// ============================================================
// RmScraper — the contract every source adapter implements.
//
// One adapter per source, each independently responsible for:
//   fetch → parse → hand back a plain field array
// and nothing else. Adapters do not merge, do not decide priority,
// do not write to the database and do not know that other sources
// exist. All of that belongs to the engine above them, which is the
// whole point of splitting them out: a broken adapter degrades one
// source instead of taking the run down with it.
//
// An adapter returns only the fields it genuinely found. Absent is
// fine; empty-string is not — the resolver treats a present-but-empty
// field as a source offering nothing, and the diff engine's safety
// rules then protect whatever is already in the database.
// ============================================================
require_once __DIR__ . '/../../../config/scraping.php';
require_once __DIR__ . '/../Http.php';
require_once __DIR__ . '/../Cache.php';
require_once __DIR__ . '/../DataNormalizer.php';

abstract class RmScraper
{
    /** Short registry key, e.g. 'wikipedia'. */
    abstract public function name(): string;

    /** Which fields this source can ever supply. */
    abstract public function fields(): array;

    /**
     * Fetch and parse ONE episode.
     * @return array field => value, plus meta keys: _url, _status, _http,
     *               _ms, _error, _error_class, _hash
     */
    abstract public function episode(int $epNum, array $ctx = []): array;

    /** Bump when the parsing logic changes — stored in provenance. */
    public function parserVersion(): string { return '2.0'; }

    public function label(): string { return (string)rmScrapeConfig('sources.' . $this->name() . '.label', $this->name()); }
    public function tier(): int     { return (int)rmScrapeConfig('sources.' . $this->name() . '.tier', 1); }
    public function isEnabled(): bool { return rmScrapeSourceEnabled($this->name()); }

    /** Highest episode number this source knows about, if it can tell. */
    public function latestEpisode(): ?int { return null; }

    /** Optional: per-person enrichment (used by Wikidata). */
    public function supportsPersonLookup(): bool { return false; }

    /**
     * When true, this adapter may read its cache but must not open a
     * connection. Set by the engine for sources that are in a health
     * cool-down: their cached data is still good, their servers are
     * still to be left alone.
     */
    protected bool $cacheOnly = false;

    public function setCacheOnly(bool $on): void { $this->cacheOnly = $on; }

    protected function http(): RmHttpClient { return RmHttpClient::instance(); }
    protected function cache(): RmCache { return RmCache::instance(); }

    protected function delayMs(): int {
        return (int)rmScrapeConfig('sources.' . $this->name() . '.delay_ms', rmScrapeConfig('http.default_delay_ms', 900));
    }

    /** Standard empty result carrying the failure reason for diagnostics. */
    protected function emptyResult(string $url, string $status = 'empty', ?string $error = null, array $extra = []): array
    {
        return array_merge([
            '_url'         => $url,
            '_status'      => $status,
            '_error'       => $error,
            '_error_class' => $extra['_error_class'] ?? null,
            '_http'        => $extra['_http'] ?? null,
            '_ms'          => $extra['_ms'] ?? 0,
        ], $extra);
    }

    /** Attach standard meta to a populated result. */
    protected function result(array $fields, string $url, RmHttpResponse $res, string $status = 'ok'): array
    {
        $clean = [];
        foreach ($fields as $k => $v) {
            if ($v === null || $v === '' || $v === []) continue;   // absent, not empty
            $clean[$k] = $v;
        }
        // Decide emptiness from the PARSED FIELDS ALONE, before any meta
        // key is added — otherwise _url alone makes every result look
        // populated, and a page whose markup changed reports itself
        // healthy while supplying nothing.
        $hasFields = $clean !== [];

        $clean['_url']         = $url;
        $clean['_status']      = $hasFields ? $status : 'parser_warning';
        $clean['_parser_note'] = $hasFields ? null
            : 'Fetched successfully (' . $res->length() . ' bytes) but no expected field could be parsed — the page structure may have changed';
        $clean['_http']        = $res->status;
        $clean['_ms']          = $res->ms;
        $clean['_error']       = $clean['_parser_note'];
        $clean['_error_class'] = $hasFields ? RmHttpClient::CLASS_OK : 'parser_failure';
        $clean['_hash']        = $res->body !== null ? sha1($res->body) : null;
        $clean['_cached']      = $res->fromCache;
        return $clean;
    }

    /** GET with this source's own politeness delay applied. */
    protected function get(string $url, array $opt = []): RmHttpResponse
    {
        $opt['delay_ms']   = $opt['delay_ms'] ?? $this->delayMs();
        $opt['cache_only'] = $opt['cache_only'] ?? $this->cacheOnly;
        return $this->http()->get($url, $opt);
    }

    protected function getJson(string $url, array $opt = []): array
    {
        $opt['delay_ms']   = $opt['delay_ms'] ?? $this->delayMs();
        $opt['cache_only'] = $opt['cache_only'] ?? $this->cacheOnly;
        return $this->http()->getJson($url, $opt);
    }

    /**
     * Try several selectors in turn. Sources redesign their markup; a
     * single hard-coded selector is a scheduled outage. Returns the
     * first non-empty match.
     */
    protected function firstMatch(string $html, array $patterns, int $group = 1): ?string
    {
        foreach ($patterns as $p) {
            if (preg_match($p, $html, $m) && isset($m[$group]) && trim($m[$group]) !== '') {
                return RmNormalizer::text($m[$group]);
            }
        }
        return null;
    }

    /** Meta tag lookup across property=/name= and either attribute order. */
    protected function meta(string $html, string $key): ?string
    {
        $k = preg_quote($key, '/');
        return $this->firstMatch($html, [
            '/<meta[^>]+(?:property|name)=["\']' . $k . '["\'][^>]+content=["\']([^"\']+)["\']/i',
            '/<meta[^>]+content=["\']([^"\']+)["\'][^>]+(?:property|name)=["\']' . $k . '["\']/i',
        ]);
    }

    /**
     * Pull an episode title out of a page, weighing the evidence.
     *
     * og:title is metadata the site itself scoped to this URL, so it is
     * accepted on its own. A bare <h1> is just the largest text on
     * whatever page came back — a maintenance notice, an error, a
     * listing — so it is accepted ONLY when it names this episode.
     * Without that rule, any page a source serves in place of the real
     * one gets canonicalised into a plausible-looking episode title.
     */
    protected function episodeTitleCandidate(string $html, int $epNum): ?string
    {
        $og = $this->meta($html, 'og:title');
        if ($og !== null && trim($og) !== '') return $og;

        $h1 = $this->firstMatch($html, [
            '/<h1[^>]*>([^<]{5,150})<\/h1>/i',
            '/<h1[^>]*>\s*<[^>]+>\s*([^<]{5,150})/i',
        ]);
        if ($h1 === null) return null;
        return $this->mentionsEpisode($h1, $epNum) ? $h1 : null;
    }

    /** Does this text identify the episode — "810", "#810", "Ep. 810", "810회"? */
    protected function mentionsEpisode(string $text, int $epNum): bool
    {
        return (bool)preg_match('/(?<!\d)0*' . $epNum . '(?!\d)/u', $text);
    }

    /** Load HTML into DOMXPath without letting libxml warnings escape. */
    protected function xpath(string $html): ?DOMXPath
    {
        if ($html === '') return null;
        $prev = libxml_use_internal_errors(true);
        $doc  = new DOMDocument();
        $ok   = $doc->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        return $ok ? new DOMXPath($doc) : null;
    }

    /** First non-empty text node for any of several XPath expressions. */
    protected function xpathText(DOMXPath $xp, array $queries): ?string
    {
        foreach ($queries as $q) {
            $nodes = @$xp->query($q);
            if ($nodes === false) continue;
            foreach ($nodes as $n) {
                $t = RmNormalizer::text($n->textContent);
                if ($t !== null && $t !== '') return $t;
            }
        }
        return null;
    }
}
