<?php
// ============================================================
// AsianWikiScraper — asianwiki.com Running Man episode pages.
//
// Added because it answers questions the existing set answers badly,
// not to lengthen the source list. AsianWiki is a server-rendered
// MediaWiki with per-episode pages carrying guest lists, Korean
// titles and a short episode description — three fields where the
// current set is thin for anything but the most recent episodes.
// Every page is public HTML with no login, no interstitial and no
// API key.
//
// It is also editorially independent of the Wikimedia family, so its
// agreement with Wikipedia is worth something, which is precisely
// what the independence grouping is there to measure.
//
// Fields: title · title_ko · air_date · synopsis · guests · tags
// ============================================================
require_once __DIR__ . '/AbstractScraper.php';

class AsianWikiScraper extends RmScraper
{
    public function name(): string { return 'asianwiki'; }
    public function parserVersion(): string { return 'asianwiki-1.0'; }

    public function fields(): array
    {
        return ['title', 'title_ko', 'air_date', 'synopsis', 'guests', 'tags'];
    }

    /** The URL forms AsianWiki has actually used for these pages. */
    private function candidates(int $epNum): array
    {
        return [
            "https://asianwiki.com/Running_Man_Episode_$epNum",
            "https://asianwiki.com/Running_Man_(Episode_$epNum)",
            "https://asianwiki.com/index.php?title=Running_Man_Episode_$epNum",
        ];
    }

    public function episode(int $epNum, array $ctx = []): array
    {
        $t0 = microtime(true);
        $lastUrl = ''; $lastErr = null; $lastClass = null; $reached = false;

        // Discovery, when the run asked for it, gets first refusal: a URL
        // the site itself published beats a pattern we guessed.
        $urls = array_merge((array)($ctx['discovered'][$this->name()] ?? []), $this->candidates($epNum));

        foreach (array_unique($urls) as $url) {
            $lastUrl = $url;
            $res = $this->get($url, [
                'timeout'      => 14,
                'min_bytes'    => 400,
                'cache_ttl'    => RmCache::episodeTtl($epNum, $ctx['latest'] ?? null),
                'cache_key'    => 'asianwiki:ep:' . md5($url),
                'bypass_cache' => !empty($ctx['bypass_cache']),
                'retries'      => 1,
            ]);
            if (!$res->ok) {
                $lastErr = $res->error; $lastClass = $res->errorClass;
                continue;
            }
            $reached = true;
            $html = (string)$res->body;

            // MediaWiki serves a 200 for a page that does not exist, with
            // a "no text in this page" body. That is NO_DATA, not a parse
            // failure, and must not count against the parser.
            if (preg_match('/There is currently no text in this page|Search results|noarticletext/i', $html)) {
                continue;
            }
            if (!$this->mentionsEpisode($html, $epNum)) continue;

            $out = $this->parse($html, $epNum);
            if (count($out) > 0) {
                return $this->result($out, $url, $res);
            }
        }

        $ms = (int)round((microtime(true) - $t0) * 1000);
        if ($reached) {
            return $this->emptyResult($lastUrl, 'missing_episode',
                "AsianWiki responded but has no page for episode $epNum",
                ['_ms' => $ms, '_error_class' => 'missing_episode', '_http' => 200]);
        }
        return $this->emptyResult($lastUrl, 'fetch_failed',
            $lastErr ?? 'Could not reach AsianWiki',
            ['_ms' => $ms, '_error_class' => $lastClass ?? RmHttpClient::CLASS_OTHER]);
    }

    /** MediaWiki infobox rows plus the opening paragraph. */
    private function parse(string $html, int $epNum): array
    {
        $out = [];

        $title = $this->firstMatch($html, [
            '~<h1[^>]*id=["\']firstHeading["\'][^>]*>(.{3,200}?)</h1>~is',
            '~<h1[^>]*class=["\'][^"\']*firstHeading[^"\']*["\'][^>]*>(.{3,200}?)</h1>~is',
        ]);
        if ($title !== null) {
            $clean = RmNormalizer::title(strip_tags($title), $epNum);
            if (RmNormalizer::titleDescriptor($clean) !== null) $out['title'] = $clean;
        }

        // Infobox rows: "<b>Korean Title:</b> 런닝맨" and friends.
        $rows = $this->infoboxRows($html);
        foreach (['korean title', 'original title', 'hangul'] as $k) {
            if (!empty($rows[$k])) { $out['title_ko'] = $rows[$k]; break; }
        }
        foreach (['release date', 'broadcast date', 'air date', 'aired'] as $k) {
            if (empty($rows[$k])) continue;
            $d = RmNormalizer::date($rows[$k]);
            if ($d !== null) { $out['air_date'] = $d; break; }
        }
        foreach (['guest', 'guests', 'guest cast', 'featuring'] as $k) {
            if (empty($rows[$k])) continue;
            $guests = $this->splitPeople($rows[$k]);
            if ($guests) { $out['guests'] = $guests; break; }
        }
        foreach (['genre', 'genres', 'tags'] as $k) {
            if (empty($rows[$k])) continue;
            $tags = array_values(array_filter(array_map(
                fn($t) => RmNormalizer::tag($t), preg_split('/[,\/·]+/u', $rows[$k]) ?: [])));
            if ($tags) { $out['tags'] = array_slice($tags, 0, 8); break; }
        }

        // The lead paragraph of the article body, which is the episode
        // description when there is one.
        $body = $this->firstMatch($html, [
            '~<div[^>]+class=["\'][^"\']*mw-parser-output[^"\']*["\'][^>]*>(.*?)<div[^>]+id=["\']toc~is',
            '~<div[^>]+id=["\']mw-content-text["\'][^>]*>(.*?)</div>\s*<div~is',
        ]);
        if ($body !== null && preg_match_all('~<p[^>]*>(.*?)</p>~is', $body, $ps)) {
            foreach ($ps[1] as $p) {
                $text = RmNormalizer::text(strip_tags($p));
                if ($text === null) continue;
                if (mb_strlen($text) < (int)rmScrapeConfig('safety.min_synopsis_chars', 15)) continue;
                if (preg_match('/^(?:this article|please help|stub)/i', $text)) continue;
                $out['synopsis'] = mb_substr($text, 0, (int)rmScrapeConfig('safety.max_synopsis_chars', 4000));
                break;
            }
        }
        return $out;
    }

    /** @return array<string,string> lower-cased label => value */
    private function infoboxRows(string $html): array
    {
        $rows = [];
        // AsianWiki writes these as bold-label paragraphs rather than a
        // table, so both shapes are read.
        if (preg_match_all('~<(?:b|strong)[^>]*>\s*([A-Za-z ]{3,30}?)\s*:?\s*</(?:b|strong)>\s*:?\s*(.{1,400}?)(?:<br|</p|</li)~is',
                           $html, $m, PREG_SET_ORDER)) {
            foreach ($m as $r) {
                $label = mb_strtolower(trim($r[1]));
                $value = RmNormalizer::text(strip_tags($r[2]));
                if ($label !== '' && $value !== null && $value !== '') $rows[$label] ??= $value;
            }
        }
        if (preg_match_all('~<tr[^>]*>\s*<t[hd][^>]*>\s*(.{2,40}?)\s*:?\s*</t[hd]>\s*<t[hd][^>]*>(.{1,400}?)</t[hd]>~is',
                           $html, $m2, PREG_SET_ORDER)) {
            foreach ($m2 as $r) {
                $label = mb_strtolower(trim(strip_tags($r[1])));
                $value = RmNormalizer::text(strip_tags($r[2]));
                if ($label !== '' && $value !== null && $value !== '') $rows[$label] ??= $value;
            }
        }
        return $rows;
    }

    /** @return string[] */
    private function splitPeople(string $raw): array
    {
        $out = [];
        foreach (preg_split('/\s*(?:,|;|·|\||\band\b|&)\s*/iu', $raw) ?: [] as $part) {
            // Strip a trailing role note: "Kim Jong-kook (as himself)".
            $name = RmNormalizer::guestName(preg_replace('/\s*\([^)]*\)\s*$/u', '', $part));
            if ($name !== null && $name !== '') $out[] = $name;
        }
        return array_slice(array_values(array_unique($out)),
                           0, (int)rmScrapeConfig('safety.max_guests_per_ep', 30));
    }
}
