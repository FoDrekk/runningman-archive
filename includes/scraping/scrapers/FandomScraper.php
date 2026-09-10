<?php
// ============================================================
// FandomScraper — Running Man Wiki (runningman.fandom.com), PR12.
//
// Role: a fan-editorial community independent of Wikipedia and of
// myrunningman.com/myrm.tv — not a mirror of either, so its agreement
// is real corroboration, not manufactured agreement between sources
// that share an upstream. Registered LOW tier and LAST in every field
// priority list (see config/scraping.php): it may FILL a gap nothing
// else supplied, or corroborate, but it never leads a field until it
// has an actual track record — the same cautious rollout posture the
// project used for every source that started out unverified.
//
// Transport: the standard MediaWiki `action=parse` API only — NOT the
// Wikimedia REST API (`/api/rest_v1/...`) that WikipediaScraper prefers.
// That REST surface is a Wikimedia-specific extension; nothing confirms
// a third-party Fandom wiki runs it, so assuming it would be exactly the
// kind of unverified guess PR12 was asked to avoid. `action=parse` is
// the universal, decades-stable MediaWiki API every install supports.
//
// Field extraction: Fandom's "portable infobox" (<aside
// class="portable-infobox">, pi-data-label/pi-data-value pairs) is a
// PLATFORM-LEVEL convention used across virtually all Fandom wikis, not
// a guess specific to this one — so reading it generically by label text
// (same "match by header label, not by column index" principle already
// used in KoWikipediaScraper) is a verified, structural basis for
// extraction, not speculation about this wiki's particular layout.
// Whichever labels the infobox actually carries survive; whichever don't
// come back absent — never invented, never guessed into an empty string.
//
// PR12 could not confirm from the build sandbox whether Cloudflare (or
// anything else) gates this host in production — outbound access to
// arbitrary hosts is blocked here, the same limitation already on record
// from PR10/PR11. No workaround was attempted: this adapter is a plain
// RmHttpClient GET like every other adapter, respects robots.txt exactly
// like every other adapter, and if the live site blocks it, the existing
// `blocked`/`fetch_failed` classification and SourceHealth cool-down
// handle that exactly as they do for any other adapter — see
// AbstractScraper::classifyFetchStatus().
//
// Fields: title · air_date · guests · location · mission (each present
// only when the infobox actually carries that label on a given page)
// ============================================================
require_once __DIR__ . '/AbstractScraper.php';

class FandomScraper extends RmScraper
{
    public function name(): string { return 'fandom'; }
    public function parserVersion(): string { return 'fandom-1.0'; }

    public function fields(): array { return ['title', 'air_date', 'guests', 'location', 'mission']; }

    private function base(): string { return rtrim((string)rmScrapeConfig('sources.fandom.base', 'https://runningman.fandom.com/'), '/'); }

    /** Confirmed page-title convention: /wiki/Episode/N (see PR12 research). */
    private function pageTitle(int $epNum): string { return "Episode/$epNum"; }

    public function episode(int $epNum, array $ctx = []): array
    {
        $title = $this->pageTitle($epNum);
        $url   = $this->base() . '/wiki/' . rawurlencode(str_replace(' ', '_', $title));

        [$data, $res] = $this->getJson($this->base() . '/api.php?' . http_build_query([
            'action' => 'parse', 'page' => $title, 'prop' => 'text|displaytitle',
            'format' => 'json', 'disablelimitreport' => 1, 'disableeditsection' => 1,
        ]), [
            'timeout'      => 15,
            'cache_ttl'    => RmCache::episodeTtl($epNum, $ctx['latest'] ?? null, 'api'),
            'cache_key'    => "fandom:ep:$epNum",
            'bypass_cache' => !empty($ctx['bypass_cache']),
        ]);

        if (!$res->ok) {
            return $this->emptyResult($url, self::classifyFetchStatus($res->errorClass), $res->error,
                ['_error_class' => $res->errorClass, '_http' => $res->status, '_ms' => $res->ms]);
        }
        if (!is_array($data) || isset($data['error'])) {
            // "missingtitle" and friends — this episode has no article yet,
            // a fact, not a fetch failure.
            $reason = is_array($data) ? (string)($data['error']['info'] ?? 'No article for this episode') : 'Malformed API response';
            return $this->emptyResult($url, 'missing_episode', $reason,
                ['_error_class' => 'missing_episode', '_http' => $res->status, '_ms' => $res->ms]);
        }

        // The RESOLVED title (after redirects) is the identity check —
        // more reliable here than scanning the body for the episode
        // number, since action=parse returns a bare content fragment
        // with no <head>/og:title for episodeTitleCandidate() to read.
        $resolvedTitle = (string)($data['parse']['title'] ?? '');
        if (!$this->mentionsEpisode($resolvedTitle, $epNum)) {
            return $this->emptyResult($url, 'missing_episode',
                "Resolved page \"$resolvedTitle\" does not identify episode $epNum",
                ['_error_class' => 'missing_episode', '_http' => $res->status, '_ms' => $res->ms]);
        }

        $html = (string)($data['parse']['text']['*'] ?? '');
        if (trim($html) === '') {
            return $this->emptyResult($url, 'parser_warning',
                'Page resolved but returned no body content', ['_error_class' => 'parser_failure', '_http' => $res->status, '_ms' => $res->ms]);
        }

        $out = $this->parseInfobox($html);

        $r = $this->result($out, $url, $res);
        if (isset($r['title'])) $r['title'] = RmNormalizer::title((string)$r['title'], $epNum);
        return $r;
    }

    /**
     * Generic portable-infobox reader: label text decides the field, not
     * position — an infobox missing a row, or carrying an extra one,
     * degrades one field instead of shifting every value into the wrong
     * one. Only labels this project can act on are mapped; anything else
     * in the infobox is left alone.
     */
    private function parseInfobox(string $html): array
    {
        $xp = $this->xpath($html);
        if ($xp === null) return [];

        $items = $xp->query("//aside[contains(concat(' ', normalize-space(@class), ' '), ' portable-infobox ')]//div[contains(concat(' ', normalize-space(@class), ' '), ' pi-item ')]");
        if ($items === false || $items->length === 0) return [];

        $out = [];
        foreach ($items as $item) {
            $labelNode = $xp->query(".//h3[contains(@class,'pi-data-label')]", $item)->item(0) ?? null;
            $valueNode = $xp->query(".//div[contains(@class,'pi-data-value')]", $item)->item(0) ?? null;
            if ($labelNode === null || $valueNode === null) continue;

            $label = mb_strtolower(trim(RmNormalizer::text($labelNode->textContent) ?? ''), 'UTF-8');
            if ($label === '') continue;

            if (preg_match('/air.?date|broadcast|original air/i', $label)) {
                $d = RmNormalizer::date($valueNode->textContent);
                if ($d) $out['air_date'] = $d;
            } elseif (preg_match('/^(episode\s*)?title$|sub.?title/i', $label)) {
                $t = RmNormalizer::text($valueNode->textContent);
                if ($t !== null && mb_strlen($t) > 1) $out['title'] = $t;
            } elseif (preg_match('/guest/i', $label)) {
                $guests = $this->extractListValues($xp, $valueNode);
                $guests = array_values(array_filter(array_map([RmNormalizer::class, 'guestName'], $guests)));
                if ($guests) $out['guests'] = $guests;
            } elseif (preg_match('/location|filmed|place/i', $label)) {
                $loc = RmNormalizer::text($valueNode->textContent);
                if ($loc !== null && mb_strlen($loc) > 1) $out['location'] = $loc;
            } elseif (preg_match('/mission/i', $label)) {
                $m = RmNormalizer::text($valueNode->textContent);
                if ($m !== null && mb_strlen($m) > 3) $out['mission'] = $m;
            }
        }
        return $out;
    }

    /** A pi-data-value is either a list of links (one per item) or plain delimited text. */
    private function extractListValues(DOMXPath $xp, DOMNode $valueNode): array
    {
        $links = $xp->query('.//a', $valueNode);
        if ($links !== false && $links->length > 0) {
            $out = [];
            foreach ($links as $a) { $t = RmNormalizer::text($a->textContent); if ($t) $out[] = $t; }
            if ($out) return $out;
        }
        $raw = RmNormalizer::text($valueNode->textContent) ?? '';
        return preg_split('/\s*[,&·]\s*|\s+and\s+/i', $raw) ?: [];
    }
}
