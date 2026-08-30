<?php
// ============================================================
// WikidataScraper — structured Wikimedia data.
//
// Wikidata does NOT have per-episode Running Man records, so adding
// it as an episode source would be padding the source count for its
// own sake. It earns inclusion for something else entirely: PERSON
// IDENTITY. Guest names arrive from five sources in four spellings,
// and Wikidata is the one place that authoritatively links a
// romanised name to a Korean name, a profession and a nationality.
//
// That makes it the enrichment source for the guests table
// (name_korean / profession / nationality), and a corroborating
// source for the show's own metadata. Uses the public REST +
// wbsearchentities APIs: no key, generous limits, cached hard
// because a person's Korean name does not change.
//
// Fields (episode-level): title_ko for the series only.
// Primary role: enrichPerson().
// ============================================================
require_once __DIR__ . '/AbstractScraper.php';

class WikidataScraper extends RmScraper
{
    public function name(): string { return 'wikidata'; }
    public function parserVersion(): string { return 'wd-1.0'; }

    public function fields(): array { return []; }   // contributes no episode fields
    public function supportsPersonLookup(): bool { return true; }

    /** Wikidata has no per-episode entities for this show. Say so plainly. */
    public function episode(int $epNum, array $ctx = []): array
    {
        return $this->emptyResult('https://www.wikidata.org/wiki/Q485576', 'not_applicable',
            'Wikidata carries no per-episode records for Running Man — used for guest identity enrichment instead',
            ['_error_class' => 'not_applicable']);
    }

    /**
     * Look up one person and return whatever identity facts exist.
     * Returns null when nothing confident was found — an unmatched name
     * must never be filled in with a guess.
     *
     * @return array{name_korean:?string, profession:?string, nationality:?string, qid:string, url:string}|null
     */
    public function enrichPerson(string $name): ?array
    {
        $name = RmNormalizer::guestName($name) ?? '';
        if ($name === '' || mb_strlen($name) < 3) return null;

        $cacheKey = 'wd:person:' . RmNormalizer::guestKey($name);
        $hit = $this->cache()->get($cacheKey, false);
        if ($hit !== false) return is_array($hit) ? $hit : null;

        $searchUrl = 'https://www.wikidata.org/w/api.php?' . http_build_query([
            'action'=>'wbsearchentities','search'=>$name,'language'=>'en',
            'uselang'=>'en','type'=>'item','limit'=>5,'format'=>'json',
        ]);
        [$search, $res] = $this->getJson($searchUrl, [
            'timeout'=>12,'retries'=>1,
            'cache_ttl'=>(int)rmScrapeConfig('cache.ttl_reference', 604800),
            'cache_key'=>"wd:search:" . RmNormalizer::guestKey($name),
        ]);
        if (!$res->ok || empty($search['search'])) { $this->cache()->set($cacheKey, null, 86400, 'api'); return null; }

        // Only accept a hit whose label matches the queried name on the
        // same identity key we use everywhere else. A fuzzy match here
        // would silently attach the wrong person's Korean name.
        $qid = null;
        foreach ($search['search'] as $cand) {
            $label = (string)($cand['label'] ?? '');
            if (RmNormalizer::guestKey($label) === RmNormalizer::guestKey($name)) { $qid = (string)($cand['id'] ?? ''); break; }
        }
        if (!$qid) { $this->cache()->set($cacheKey, null, 86400, 'api'); return null; }

        $entUrl = "https://www.wikidata.org/wiki/Special:EntityData/$qid.json";
        [$ent, $eres] = $this->getJson($entUrl, [
            'timeout'=>12,'retries'=>1,
            'cache_ttl'=>(int)rmScrapeConfig('cache.ttl_reference', 604800),
            'cache_key'=>"wd:entity:$qid",
        ]);
        if (!$eres->ok || empty($ent['entities'][$qid])) { $this->cache()->set($cacheKey, null, 86400, 'api'); return null; }
        $e = $ent['entities'][$qid];

        // P31 = instance of; Q5 = human. Anything else is not a guest.
        $isHuman = false;
        foreach ((array)($e['claims']['P31'] ?? []) as $claim) {
            if (($claim['mainsnak']['datavalue']['value']['id'] ?? null) === 'Q5') { $isHuman = true; break; }
        }
        if (!$isHuman) { $this->cache()->set($cacheKey, null, 604800, 'api'); return null; }

        $out = [
            'name_korean' => $e['labels']['ko']['value'] ?? null,
            'profession'  => null,
            'nationality' => null,
            'qid'         => $qid,
            'url'         => "https://www.wikidata.org/wiki/$qid",
        ];

        // P106 = occupation, P27 = country of citizenship. Both are entity
        // references, so resolve the first one's English label.
        $occId = $e['claims']['P106'][0]['mainsnak']['datavalue']['value']['id'] ?? null;
        if ($occId) $out['profession'] = $this->labelFor($occId);
        $natId = $e['claims']['P27'][0]['mainsnak']['datavalue']['value']['id'] ?? null;
        if ($natId) {
            $country = $this->labelFor($natId);
            $out['nationality'] = $country === 'South Korea' ? 'Korean' : $country;
        }

        $this->cache()->set($cacheKey, $out, (int)rmScrapeConfig('cache.ttl_reference', 604800), 'api');
        return $out;
    }

    private function labelFor(string $qid): ?string
    {
        $url = 'https://www.wikidata.org/w/api.php?' . http_build_query([
            'action'=>'wbgetentities','ids'=>$qid,'props'=>'labels','languages'=>'en','format'=>'json',
        ]);
        [$data, $res] = $this->getJson($url, [
            'timeout'=>10,'retries'=>1,
            'cache_ttl'=>(int)rmScrapeConfig('cache.ttl_reference', 604800),
            'cache_key'=>"wd:label:$qid",
        ]);
        if (!$res->ok) return null;
        $label = $data['entities'][$qid]['labels']['en']['value'] ?? null;
        return $label ? RmNormalizer::text($label) : null;
    }
}
