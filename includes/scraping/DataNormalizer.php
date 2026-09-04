<?php
// ============================================================
// RmNormalizer — turn whatever a source returned into the archive's
// canonical shape, without destroying information.
//
// The rule that governs this whole file: normalisation decides whether
// two values are THE SAME THING, it does not decide what gets shown.
// "Lee Kwang-soo", "Lee Kwang Soo" and "Lee Kwangsoo" collapse to one
// identity key so they can't create three guests — but the canonical
// display name is chosen deliberately (best-formatted variant), never
// flattened to the key. Anything merely SIMILAR (one edit apart) is
// reported for review instead of merged, because "Kim Jong-kook" and
// "Kim Jong-kuk" being the same person is a guess, not a fact.
// ============================================================

class RmNormalizer
{
    // ── Text ──────────────────────────────────────────────────────
    public static function text(?string $s): ?string {
        if ($s === null) return null;
        $s = (string)$s;
        if (!mb_check_encoding($s, 'UTF-8')) {
            $conv = @mb_convert_encoding($s, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');
            $s = $conv !== false ? $conv : '';
        }
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = preg_replace('/\[\d+\]|\[[a-z]\]/u', '', $s);            // wiki footnote markers
        $s = str_replace(["\xC2\xA0", "\xE2\x80\x8B", "\xEF\xBB\xBF"], [' ', '', ''], $s); // nbsp, ZWSP, BOM
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s); // control chars
        $s = preg_replace('/\s+/u', ' ', $s);
        return trim($s);
    }

    /** Multi-paragraph text: collapse runs of blank lines but keep breaks. */
    public static function paragraphText(?string $s): ?string {
        if ($s === null) return null;
        $s = html_entity_decode((string)$s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = preg_replace('/\[\d+\]/u', '', $s);
        $s = preg_replace('/[ \t\x{00A0}]+/u', ' ', $s);
        $s = preg_replace('/(\s*\n\s*){2,}/u', "\n\n", $s);
        return trim($s);
    }

    // ── Episode titles ────────────────────────────────────────────
    // The archive's canonical form is "Episode #NNN - Descriptor".
    // Preserved verbatim from the original rmCleanTitle() behaviour so
    // existing titles keep round-tripping identically.
    public static function title(string $raw, int $epNum): string {
        $padded = str_pad((string)$epNum, 3, '0', STR_PAD_LEFT);
        $raw    = (string)self::text($raw);
        if ($raw === '') return "Episode #$padded";
        if (preg_match('/Episodes?\s*[-–]\s*Page\s*\d+/i', $raw)) return "Episode #$padded";
        if (mb_strlen(trim($raw)) < 4) return "Episode #$padded";

        $raw = preg_replace('/\s*[-–|]\s*(?:Wikipedia|위키백과|myrm\.tv|myRunningMan|My Running Man.*|MyRM.*|MyDramaList|SBS.*)$/iu', '', $raw);
        $raw = trim($raw);

        // Strip the leading show name so the number is at the front,
        // whichever way the source spells it.
        $raw = trim(preg_replace('/^(?:Running\s*Man|런닝맨)\s*[–\-—:|]?\s*/iu', '', $raw));

        // Every spelling of an episode number we have actually seen from a
        // source: "Episode #813", "Episode 813", "Ep. 813", "E813", "#813"
        // and the Korean "813회".
        $numbered = '/^(?:(?:Episodes?|Eps?\.?|E)\s*#?\s*(\d{1,4})|#\s*(\d{1,4})|(\d{1,4})\s*회)\s*(?:[–\-—:|.]\s*)?(.*)$/iu';

        if (preg_match($numbered, $raw, $m)) {
            $found = (int)($m[1] !== '' ? $m[1] : ($m[2] !== '' ? $m[2] : $m[3]));
            // A title that names a DIFFERENT episode belongs to a different
            // episode. Renumbering it would launder a mismatched row — a
            // neighbouring entry from an off-by-one parse, or a source that
            // paginates differently — into this episode under a
            // confident-looking name. Drop the descriptor and let the
            // validator reject a title that then says nothing: losing a
            // title is recoverable, writing someone else's is not.
            if ($found !== $epNum) return "Episode #$padded";
            $desc = trim((string)$m[4], " \t\n\r\0\x0B-–—:|.");
            return (mb_strlen($desc) > 3 && !preg_match('/^(?:Page\s*\d+|Episodes?)/i', $desc))
                ? "Episode #$padded - $desc"
                : "Episode #$padded";
        }

        $desc = trim($raw, " \t\n\r\0\x0B-–—:");
        if (mb_strlen($desc) > 4 && !preg_match('/^(?:Episodes?|Page\s*\d+)/i', $desc)) return "Episode #$padded - $desc";
        return "Episode #$padded";
    }

    /** The part after "Episode #NNN - ", or null for a bare numbered title. */
    public static function titleDescriptor(?string $title): ?string {
        if (!$title) return null;
        return preg_match('/^Episode\s*#\d+\s*-\s*(.+)$/u', trim($title), $m) ? trim($m[1]) : null;
    }

    /** Comparison key for titles: identity, not display. */
    public static function titleKey(?string $t): string {
        $d = self::titleDescriptor($t) ?? (string)$t;
        $d = mb_strtolower((string)self::text($d), 'UTF-8');
        return trim(preg_replace('/[^\p{L}\p{N}]+/u', '', $d));
    }

    // ── Dates ─────────────────────────────────────────────────────
    // Sources spell dates every way imaginable: "23 August 2026",
    // "August 23, 2026", "2026-08-23", "2026.08.23", "2026년 8월 23일".
    public static function date(?string $raw): ?string {
        if ($raw === null) return null;
        $s = (string)self::text($raw);
        if ($s === '') return null;

        // Strip a parenthesised filming date — "2026-08-23 (filmed 2026-08-01)"
        $s = preg_replace('/\((?:filmed|촬영)[^)]*\)/iu', '', $s);

        if (preg_match('/(\d{4})\s*년\s*(\d{1,2})\s*월\s*(\d{1,2})\s*일/u', $s, $m)) {
            return self::assembleDate((int)$m[1], (int)$m[2], (int)$m[3]);
        }
        if (preg_match('/\b(\d{4})[-.\/](\d{1,2})[-.\/](\d{1,2})\b/', $s, $m)) {
            return self::assembleDate((int)$m[1], (int)$m[2], (int)$m[3]);
        }
        if (preg_match('/\b(\d{1,2})\s+([A-Za-z]{3,9})\s+(\d{4})\b/', $s, $m)) {
            $mon = self::monthNumber($m[2]);
            if ($mon) return self::assembleDate((int)$m[3], $mon, (int)$m[1]);
        }
        if (preg_match('/\b([A-Za-z]{3,9})\s+(\d{1,2}),?\s+(\d{4})\b/', $s, $m)) {
            $mon = self::monthNumber($m[1]);
            if ($mon) return self::assembleDate((int)$m[3], $mon, (int)$m[2]);
        }
        return null;
    }

    private static function assembleDate(int $y, int $m, int $d): ?string {
        if ($y < 2009 || $y > (int)date('Y') + 2) return null;
        if (!checkdate($m, $d, $y)) return null;
        return sprintf('%04d-%02d-%02d', $y, $m, $d);
    }

    private static function monthNumber(string $name): ?int {
        $n = strtolower(substr($name, 0, 3));
        $map = ['jan'=>1,'feb'=>2,'mar'=>3,'apr'=>4,'may'=>5,'jun'=>6,
                'jul'=>7,'aug'=>8,'sep'=>9,'oct'=>10,'nov'=>11,'dec'=>12];
        return $map[$n] ?? null;
    }

    // ── Guests ────────────────────────────────────────────────────
    private const GUEST_NOISE = [
        'guest','guests','none','n/a','na','tbd','tba','unknown','no guest','no guests',
        'cast','members','running man','episode','host','hosts','and','etc','others','various',
    ];

    /** Canonical DISPLAY name — cleaned, but still human-readable. */
    public static function guestName(?string $raw): ?string {
        if ($raw === null) return null;
        $s = (string)self::text($raw);
        if ($s === '') return null;

        $s = strip_tags($s);
        $s = preg_replace('/^[\s\-–—•*·,;:]+|[\s\-–—•*·,;:]+$/u', '', $s);
        $s = preg_replace('/^\d+[.)]\s*/u', '', $s);                   // "1. Name"
        $s = preg_replace('/\s*\((?:[^)]*)\)\s*$/u', '', $s);          // trailing "(actor)"
        $s = preg_replace('/\s*\[[^\]]*\]\s*$/u', '', $s);
        // Trailing role/affiliation after a dash: "Jeon So-min – actress"
        $s = preg_replace('/\s+[–—]\s+.*$/u', '', $s);
        $s = trim(preg_replace('/\s+/u', ' ', $s));

        if ($s === '' || mb_strlen($s) < 2 || mb_strlen($s) > 60) return null;
        if (in_array(mb_strtolower($s, 'UTF-8'), self::GUEST_NOISE, true)) return null;
        if (preg_match('/^[\p{P}\p{S}\d\s]+$/u', $s)) return null;      // punctuation/number soup
        if (preg_match('/https?:|<[a-z]|\{|\}|\|/i', $s)) return null;  // markup/URL leakage
        return $s;
    }

    /**
     * Identity key. Case, spacing, hyphens, apostrophes and diacritics
     * are removed — the three spellings of "Lee Kwang-soo" all land on
     * "leekwangsoo". Nothing fuzzier than that happens here on purpose.
     */
    public static function guestKey(?string $name): string {
        $n = self::guestName($name);
        if ($n === null) return '';
        $n = mb_strtolower($n, 'UTF-8');
        if (class_exists('Transliterator')) {
            $t = Transliterator::create('Any-Latin; Latin-ASCII; Lower()');
            if ($t) { $conv = $t->transliterate($n); if (is_string($conv)) $n = $conv; }
        } elseif (function_exists('iconv')) {
            $conv = @iconv('UTF-8', 'ASCII//TRANSLIT', $n);
            if (is_string($conv)) $n = strtolower($conv);
        }
        return preg_replace('/[^\p{L}\p{N}]+/u', '', $n) ?? '';
    }

    /**
     * Union-merge guest lists across sources, deduplicated by identity
     * key, keeping the best-formatted spelling as the display name.
     * @param array<string,string[]> $bySource source => names
     * @return array{names:string[], keys:array<string,string>, byKeySources:array<string,string[]>, review:array}
     */
    public static function mergeGuests(array $bySource): array {
        $best = [];      // key => display name
        $srcs = [];      // key => [source,...]
        foreach ($bySource as $source => $names) {
            foreach ((array)$names as $raw) {
                $disp = self::guestName(is_string($raw) ? $raw : (string)($raw['name'] ?? ''));
                if ($disp === null) continue;
                $key = self::guestKey($disp);
                if ($key === '') continue;
                if (!isset($best[$key]) || self::betterGuestSpelling($disp, $best[$key])) $best[$key] = $disp;
                $srcs[$key][] = (string)$source;
            }
        }
        foreach ($srcs as $k => $v) $srcs[$k] = array_values(array_unique($v));

        // Near-miss detection: report, never merge.
        $review = [];
        $keys = array_keys($best);
        for ($i = 0; $i < count($keys); $i++) {
            for ($j = $i + 1; $j < count($keys); $j++) {
                $a = $keys[$i]; $b = $keys[$j];
                if (abs(strlen($a) - strlen($b)) > 2) continue;
                if (strlen($a) < 6) continue;                 // short names collide too easily
                // Romanisation variants are typically two edits apart
                // ("kimjongkook" vs "kimjongkuk"), so longer keys get a
                // slightly wider review window. Short keys stay at one
                // edit, where a single character often marks a different
                // person entirely.
                $tolerance = min(strlen($a), strlen($b)) >= 8 ? 2 : 1;
                $d = levenshtein($a, $b);
                if ($d > 0 && $d <= $tolerance) {
                    $review[] = ['a' => $best[$a], 'b' => $best[$b], 'distance' => $d,
                                 'reason' => "Names differ by $d character(s) — same person or two people?"];
                }
            }
        }
        return ['names' => array_values($best), 'keys' => $best, 'byKeySources' => $srcs, 'review' => $review];
    }

    /** Prefer hyphenated Korean romanisation and proper capitalisation. */
    private static function betterGuestSpelling(string $a, string $b): bool {
        $score = function (string $s): int {
            $n = 0;
            if (preg_match('/^\p{Lu}/u', $s)) $n += 2;                 // starts capitalised
            if (str_contains($s, '-')) $n += 3;                        // "Kwang-soo" over "Kwangsoo"
            if (preg_match('/^\p{Lu}[\p{Ll}-]+(\s\p{Lu}[\p{Ll}-]+)+$/u', $s)) $n += 3; // Proper Case Words
            if ($s === mb_strtoupper($s, 'UTF-8')) $n -= 3;            // SHOUTING
            if ($s === mb_strtolower($s, 'UTF-8')) $n -= 2;
            return $n;
        };
        return $score($a) > $score($b);
    }

    // ── Locations ─────────────────────────────────────────────────
    // "Seoul", "Seoul, South Korea" and "Seoul City" are one place.
    // Structured parts (city / country / overseas) are DERIVED, never
    // dropped: existing country/lat/long in the DB are left untouched
    // by the writer unless it can genuinely improve them.
    private const KR_ALIASES = [
        'south korea','korea','republic of korea','korea, south','대한민국','한국','s. korea','rok',
    ];

    public static function location(?string $raw): ?array {
        if ($raw === null) return null;
        $s = (string)self::text($raw);
        if ($s === '') return null;
        $s = strip_tags($s);
        $s = preg_replace('/^(?:filmed\s+(?:at|in)|location|landmark|site)\s*:\s*/iu', '', $s);
        $s = trim($s, " \t\n\r,;·-–—");
        if ($s === '' || mb_strlen($s) < 2 || mb_strlen($s) > 150) return null;
        if (preg_match('/^(?:unknown|n\/a|na|tbd|various|none)$/i', $s)) return null;

        $parts = array_values(array_filter(array_map('trim', explode(',', $s)), fn($p) => $p !== ''));
        $country = null; $city = null;

        if (count($parts) > 1) {
            $tail = mb_strtolower(end($parts), 'UTF-8');
            $country = in_array($tail, self::KR_ALIASES, true) ? 'South Korea' : self::titleCase(end($parts));
            $city    = self::stripCitySuffix($parts[0]);
        } else {
            $city = self::stripCitySuffix($parts[0]);
        }

        // Korean administrative areas imply the country even unqualified.
        $krHints = '/\b(seoul|busan|incheon|daegu|daejeon|gwangju|ulsan|jeju|gyeonggi|gangwon|chungcheong|jeolla|gyeongsang|sejong|suwon|paju|gapyeong|yangpyeong|namyangju|goyang)\b/i';
        if ($country === null && preg_match($krHints, $city ?? '')) $country = 'South Korea';

        $name = $city ?: self::titleCase($parts[0]);
        $name = trim(preg_replace('/\s+/u', ' ', $name));
        if ($name === '') return null;

        return [
            'name'        => $name,
            'city'        => $city ?: null,
            'country'     => $country,
            'is_overseas' => $country === null ? null : ($country !== 'South Korea' ? 1 : 0),
            'raw'         => $s,
        ];
    }

    /** Identity key for locations — "Seoul City" == "seoul". */
    public static function locationKey(?string $name): string {
        $n = self::location($name);
        if ($n === null) return '';
        $k = mb_strtolower($n['name'], 'UTF-8');
        return preg_replace('/[^\p{L}\p{N}]+/u', '', $k) ?? '';
    }

    private static function stripCitySuffix(string $s): string {
        $s = trim($s);
        $s = preg_replace('/\s+(?:city|si|-si|metropolitan\s+city|special\s+city|province|-do|do)$/iu', '', $s);
        return self::titleCase(trim($s));
    }

    private static function titleCase(string $s): string {
        $s = trim($s);
        if ($s === '') return $s;
        // Leave already-mixed-case and non-Latin strings alone.
        if (preg_match('/\p{Hangul}|\p{Han}/u', $s)) return $s;
        if ($s !== mb_strtolower($s, 'UTF-8') && $s !== mb_strtoupper($s, 'UTF-8')) return $s;
        return mb_convert_case(mb_strtolower($s, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
    }

    // ── Misc ──────────────────────────────────────────────────────
    public static function url(?string $raw, ?string $base = null): ?string {
        if ($raw === null) return null;
        $u = trim(html_entity_decode((string)$raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($u === '') return null;
        if (str_starts_with($u, '//')) $u = 'https:' . $u;
        elseif ($base && !preg_match('~^https?://~i', $u)) {
            $u = rtrim($base, '/') . '/' . ltrim($u, '/');
        }
        if (!preg_match('~^https?://[^\s"\'<>]+$~i', $u)) return null;
        return $u;
    }

    public static function tag(?string $raw): ?string {
        $t = self::text($raw);
        if ($t === null || $t === '') return null;
        $t = trim($t, " \t\n\r#·-–—");
        if (mb_strlen($t) < 2 || mb_strlen($t) > 40) return null;
        if (preg_match('/^(?:home|episodes?|guests?|tags?|search|login|register|more)$/i', $t)) return null;
        return $t;
    }

    /** Stable hash used for provenance + change detection. */
    public static function hash($value): string {
        if (is_array($value)) {
            $norm = array_map(fn($v) => is_string($v) ? mb_strtolower(trim($v), 'UTF-8') : $v, $value);
            sort($norm);
            $value = implode('|', array_map(fn($v) => is_scalar($v) ? (string)$v : json_encode($v), $norm));
        }
        return sha1(mb_strtolower(trim((string)$value), 'UTF-8'));
    }
}
