<?php
// ============================================================
// RmSourceReputation — how much a source's word is worth, per field.
//
// The config file declares a tier: an editorial judgement made once,
// by a person, about a whole site. That is a fine starting point and a
// poor finishing one. SBS is the broadcaster and its air dates are
// definitive; its synopses are marketing copy. Wikidata is excellent
// on person identity and has nothing to say about a mission. A single
// number per source cannot express any of that.
//
// So reputation has two halves:
//   · a declared prior, from tier and class in config/scraping.php
//   · an earned adjustment, from what the source has actually done —
//     how often it agreed with the eventual answer, how often its
//     parser came back empty, how often it could not be reached
//
// The prior dominates until a source has a real track record, which
// keeps a fresh install sensible without pretending to knowledge it
// has not got yet.
// ============================================================
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/scraping.php';

class RmSourceReputation
{
    private ?PDO $db;
    /** @var array<string,int> "source|field" => reputation */
    private array $memo = [];

    public function __construct(?PDO $db = null)
    {
        try { $this->db = $db ?: getDBSafe(); } catch (Throwable $e) { $this->db = null; }
    }

    public static function instance(): self
    {
        static $i = null;
        return $i ?: ($i = new self());
    }

    private function ready(): bool
    {
        return $this->db !== null && function_exists('rmResearchTablesExist') && rmResearchTablesExist();
    }

    // ────────────────────────────────────────────────────────────
    // The declared prior
    // ────────────────────────────────────────────────────────────
    /**
     * What config says a source is worth before it has proved anything.
     * Tier carries most of it; class adjusts, because a metadata site
     * is by definition supplementary however carefully it is edited.
     */
    public function prior(string $source, string $field = '*'): int
    {
        $tier  = (int)rmScrapeConfig("sources.$source.tier", 1);
        $class = (string)rmScrapeConfig("sources.$source.class", 'metadata');

        $base = match ($tier) { 3 => 80, 2 => 68, default => 55 };
        $base += match ($class) { 'primary' => 6, 'secondary' => 2, 'identity' => 0, default => -6 };

        // Per-field editorial overrides: the specification's example is
        // exactly right — SBS's air dates and Wikidata's guest identity
        // deserve to outrank their own global scores.
        $override = rmScrapeConfig("field_reputation.$field.$source");
        if ($override !== null) return max(0, min(100, (int)$override));

        // A source that is not even listed for a field has no standing on
        // it, whatever its global tier.
        $priority = (array)rmScrapeConfig("field_priority.$field", []);
        if ($field !== '*' && $priority) {
            $rank = array_search($source, $priority, true);
            if ($rank === false) return max(0, $base - 25);
            // First choice for the field keeps its full score; each step
            // down the list costs a little.
            $base -= min(18, 4 * (int)$rank);
        }
        return max(0, min(100, $base));
    }

    // ────────────────────────────────────────────────────────────
    // The earned adjustment
    // ────────────────────────────────────────────────────────────
    /**
     * The number the decision engine actually uses: the prior, moved
     * towards the observed record in proportion to how much record
     * there is.
     */
    public function reliability(string $source, string $field = '*'): int
    {
        $key = "$source|$field";
        if (isset($this->memo[$key])) return $this->memo[$key];

        $prior = $this->prior($source, $field);
        $row   = $this->row($source, $field) ?? $this->row($source, '*');
        if ($row === null || (int)$row['samples'] < 3) return $this->memo[$key] = $prior;

        $samples = max(1, (int)$row['samples']);
        $agree   = (int)$row['agreements'];
        $dis     = (int)$row['disagreements'];
        $observed = $agree + $dis > 0 ? (int)round($agree / ($agree + $dis) * 100) : $prior;

        // Being unreachable or unparseable is a reliability problem too,
        // not only a health problem: a source that answers half the time
        // is worth less as a witness.
        $failRate = ($samples > 0)
            ? ((int)$row['parser_failures'] + (int)$row['fetch_failures']) / $samples : 0.0;
        $observed = (int)round($observed * (1 - min(0.5, $failRate)));

        // Confidence in the observation itself grows with the sample and
        // never fully displaces the editorial prior.
        $weight = min(0.65, $samples / 60);
        $this->memo[$key] = max(0, min(100, (int)round($prior * (1 - $weight) + $observed * $weight)));
        return $this->memo[$key];
    }

    private function row(string $source, string $field): ?array
    {
        if (!$this->ready()) return null;
        try {
            $s = $this->db->prepare('SELECT * FROM source_reputation WHERE source_name = ? AND field_name = ?');
            $s->execute([$source, $field]);
            $r = $s->fetch(PDO::FETCH_ASSOC);
            return $r ?: null;
        } catch (Throwable $e) { return null; }
    }

    // ────────────────────────────────────────────────────────────
    // Learning
    // ────────────────────────────────────────────────────────────
    /**
     * Record what one round of evidence revealed: for each field, which
     * sources backed the value that won and which backed something else.
     *
     * This is deliberately not "was the source right" — nobody knows
     * that. It is "did the source agree with the conclusion the whole
     * body of evidence supported", which is measurable and, over
     * hundreds of episodes, informative.
     */
    public function observe(string $source, string $field, string $outcome): void
    {
        if (!$this->ready()) return;
        $column = match ($outcome) {
            'agree'        => 'agreements',
            'disagree'     => 'disagreements',
            'parser_error' => 'parser_failures',
            'fetch_error'  => 'fetch_failures',
            'contributed'  => 'contributions',
            default        => null,
        };
        if ($column === null) return;

        foreach ([$field, '*'] as $f) {
            try {
                $this->db->prepare(
                    "INSERT INTO source_reputation (source_name, field_name, samples, `$column`)
                     VALUES (?,?,1,1)
                     ON DUPLICATE KEY UPDATE samples = samples + 1, `$column` = `$column` + 1"
                )->execute([mb_substr($source, 0, 40), mb_substr($f, 0, 40)]);
            } catch (Throwable $e) { }
        }
        unset($this->memo["$source|$field"], $this->memo["$source|*"]);
    }

    /** Persist the recomputed score so the admin view can show it without recomputing. */
    public function flush(): void
    {
        if (!$this->ready()) return;
        try {
            $rows = $this->db->query('SELECT source_name, field_name FROM source_reputation')->fetchAll();
            $up = $this->db->prepare('UPDATE source_reputation SET reputation = ? WHERE source_name = ? AND field_name = ?');
            foreach ($rows as $r) {
                $up->execute([$this->reliability($r['source_name'], $r['field_name']),
                              $r['source_name'], $r['field_name']]);
            }
        } catch (Throwable $e) { }
    }

    /**
     * Everything known about one source, for the source-detail panel.
     */
    public function describe(string $source): array
    {
        $out = ['source' => $source, 'global' => $this->reliability($source, '*'), 'fields' => []];
        foreach (array_keys((array)rmScrapeConfig('field_priority', [])) as $field) {
            $priority = (array)rmScrapeConfig("field_priority.$field", []);
            if (!in_array($source, $priority, true)) continue;
            $out['fields'][$field] = [
                'reliability' => $this->reliability($source, $field),
                'rank'        => (int)array_search($source, $priority, true) + 1,
                'of'          => count($priority),
            ];
        }
        if ($this->ready()) {
            try {
                $s = $this->db->prepare('SELECT * FROM source_reputation WHERE source_name = ? AND field_name = ?');
                $s->execute([$source, '*']);
                $out['record'] = $s->fetch(PDO::FETCH_ASSOC) ?: null;
            } catch (Throwable $e) { }
        }
        return $out;
    }

    /** A plain word for a number, for the source list. */
    public static function band(int $reliability): string
    {
        return match (true) {
            $reliability >= 85 => 'VERY HIGH',
            $reliability >= 72 => 'HIGH',
            $reliability >= 55 => 'MEDIUM',
            $reliability >= 35 => 'LOW',
            default            => 'VERY LOW',
        };
    }
}
