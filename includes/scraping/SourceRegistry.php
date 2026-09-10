<?php
// ============================================================
// RmSourceRegistry — the list of source adapters, and the answer to
// "which sources do I actually need to call for THIS episode?"
//
// That second question is the whole reason incremental scraping
// works. If an episode is only missing a thumbnail, calling five
// sources for title/air_date/guests it already has is wasted traffic
// on someone else's server. The registry maps fields → the sources
// that can supply them (in that field's priority order), so the
// engine can ask for exactly the sources the gap requires.
// ============================================================
require_once __DIR__ . '/../../config/scraping.php';
require_once __DIR__ . '/SourceHealth.php';
require_once __DIR__ . '/scrapers/AbstractScraper.php';
require_once __DIR__ . '/scrapers/WikipediaScraper.php';
require_once __DIR__ . '/scrapers/KoWikipediaScraper.php';
require_once __DIR__ . '/scrapers/MyRunningManScraper.php';
require_once __DIR__ . '/scrapers/SbsScraper.php';
require_once __DIR__ . '/scrapers/TheTvdbScraper.php';
require_once __DIR__ . '/scrapers/KShow123Scraper.php';
require_once __DIR__ . '/scrapers/ImdbScraper.php';
require_once __DIR__ . '/scrapers/FandomScraper.php';
require_once __DIR__ . '/scrapers/TvMazeScraper.php';

class RmSourceRegistry
{
    /** @var array<string,RmScraper> */
    private array $scrapers = [];

    public function __construct()
    {
        foreach ([
            new SbsScraper(),
            new WikipediaScraper(),
            new KoWikipediaScraper(),
            new MyRunningManScraper(),
            new MyRMtvScraper(),
            new TheTvdbScraper(),
            new KShow123Scraper(),
            new ImdbScraper(),
            new FandomScraper(),
            new TvMazeScraper(),
        ] as $s) {
            $this->scrapers[$s->name()] = $s;
        }
    }

    public static function instance(): self
    {
        static $i = null;
        return $i ?: ($i = new self());
    }

    /** @return array<string,RmScraper> */
    public function all(): array { return $this->scrapers; }

    public function get(string $name): ?RmScraper { return $this->scrapers[$name] ?? null; }

    public function has(string $name): bool { return isset($this->scrapers[$name]); }

    /** Registered, config-enabled, and not in a health cool-down. */
    public function usable(string $name, bool $ignoreHealth = false): bool
    {
        $s = $this->get($name);
        if (!$s || !$s->isEnabled()) return false;
        if ($ignoreHealth) return true;
        return !RmSourceHealth::instance()->isSuppressed($name);
    }

    /** @return array<string,RmScraper> every currently usable adapter */
    public function active(bool $ignoreHealth = false): array
    {
        $out = [];
        foreach ($this->scrapers as $name => $s) if ($this->usable($name, $ignoreHealth)) $out[$name] = $s;
        return $out;
    }

    /**
     * Which sources can supply these fields, ordered so the highest
     * priority source for the most-wanted field comes first.
     *
     * @param string[] $fields
     * @return string[] source names, deduplicated, in call order
     */
    public function sourcesForFields(array $fields, bool $ignoreHealth = false): array
    {
        $priority = (array)rmScrapeConfig('field_priority', []);
        $ranked = [];
        foreach ($fields as $field) {
            foreach ((array)($priority[$field] ?? []) as $rank => $source) {
                if (!$this->usable($source, $ignoreHealth)) continue;
                $adapter = $this->get($source);
                if (!$adapter || !in_array($field, $adapter->fields(), true)) continue;
                // Best (lowest) rank this source holds across the wanted fields.
                $ranked[$source] = min($ranked[$source] ?? PHP_INT_MAX, $rank);
            }
        }
        asort($ranked);
        return array_keys($ranked);
    }

    /** Every field the registry can currently supply from some source. */
    public function coveredFields(): array
    {
        $out = [];
        foreach ($this->active() as $s) foreach ($s->fields() as $f) $out[$f] = true;
        return array_keys($out);
    }

    /** field => [source names, in priority order] — for the Admin display. */
    public function fieldMatrix(): array
    {
        $matrix = [];
        foreach ((array)rmScrapeConfig('field_priority', []) as $field => $order) {
            foreach ($order as $source) {
                $a = $this->get($source);
                if ($a && in_array($field, $a->fields(), true)) $matrix[$field][] = $source;
            }
        }
        return $matrix;
    }

    /** The Wikidata adapter, when person enrichment is wanted. */
    public function personLookup(): ?RmScraper
    {
        foreach ($this->active() as $s) if ($s->supportsPersonLookup()) return $s;
        return null;
    }
}
