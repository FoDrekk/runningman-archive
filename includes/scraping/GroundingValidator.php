<?php
// ============================================================
// RmGroundingValidator — anti-hallucination check for AI-generated text.
//
// Pure and offline: no network, no database. Given a piece of generated
// prose and the evidence it was supposed to be grounded in, it looks
// for proper-noun-shaped claims (names, places) the text makes that
// the evidence does not support, and for the generic "AI voice" the
// specification explicitly asks us to avoid.
//
// This is a heuristic, not a fact-checker — it cannot know that a
// sentence is TRUE, only that it introduces something the evidence
// never mentioned. That is exactly the failure mode worth catching:
// an invented guest, a fabricated location, a made-up winner.
// ============================================================
require_once __DIR__ . '/../../config/scraping.php';
require_once __DIR__ . '/DataNormalizer.php';

class RmGroundingValidator
{
    /** Words that are proper-noun-shaped but never worth flagging. */
    private const SAFE_WORDS = [
        'running man', 'episode', 'monday', 'tuesday', 'wednesday', 'thursday',
        'friday', 'saturday', 'sunday', 'january', 'february', 'march', 'april',
        'may', 'june', 'july', 'august', 'september', 'october', 'november',
        'december', 'korea', 'korean', 'sbs', 'the', 'this', 'their', 'members',
        'race', 'mission', 'challenge', 'team', 'teams',
        // Function words that can get swept into a joined capitalised
        // phrase (e.g. "Lee Kwang-soo and Song Ji-hyo") — never claims
        // on their own, so never worth flagging as unsupported.
        'and', 'or', 'of', 'a', 'an', 'for', 'with', 'is', 'was', 'in', 'on', 'to', 'at', 'as',
    ];

    /**
     * @param string $text     the generated synopsis
     * @param array  $evidence title, guests[], theme, mission, location,
     *                         special_notes — whatever verified facts the
     *                         draft was allowed to use
     * @return array{valid:bool, confidence:int, issues:string[], word_count:int}
     */
    public static function validate(string $text, array $evidence): array
    {
        $text = trim($text);
        $issues = [];
        $cfg = (array)rmScrapeConfig('ai.synopsis', []);

        $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        $wordCount = count($words);
        $minW = (int)($cfg['min_words'] ?? 50);
        $maxW = (int)($cfg['max_words'] ?? 100);

        $confidence = 96;

        if ($wordCount < max(1, (int)round($minW * 0.6))) {
            $issues[] = "Too short ($wordCount words) to be a real synopsis, not a fragment of one";
            $confidence -= 40;
        } elseif ($wordCount < $minW || $wordCount > $maxW * 1.4) {
            // A miss on the target LENGTH is a quality signal, not a
            // hallucination — it costs confidence but does not by itself
            // fail grounding (that would send a merely-a-bit-short but
            // perfectly true draft through a pointless revision cycle).
            $confidence -= 15;
        }

        foreach ((array)($cfg['banned_openers'] ?? []) as $opener) {
            if (stripos($text, $opener) === 0 || stripos(ltrim($text, '"“'), $opener) === 0) {
                $issues[] = 'Opens with a banned generic phrase: "' . $opener . '"';
                $confidence -= 25;
                break;
            }
        }

        // Build the vocabulary the text is allowed to draw proper nouns
        // from: every evidence field, normalised into loose word tokens.
        $vocab = [];
        foreach (self::flattenEvidence($evidence) as $chunk) {
            foreach (preg_split('/[^\p{L}\p{N}\'-]+/u', mb_strtolower($chunk, 'UTF-8')) as $w) {
                if ($w !== '') $vocab[$w] = true;
            }
            // Guest identity comparisons ignore spelling/spacing entirely.
            $vocab[RmNormalizer::guestKey($chunk)] = true;
        }

        foreach (self::properNounPhrases($text) as $phrase) {
            $norm = mb_strtolower($phrase, 'UTF-8');
            if (in_array($norm, self::SAFE_WORDS, true)) continue;
            if (self::isSupported($phrase, $norm, $vocab)) continue;
            $issues[] = 'Unsupported claim not found in the evidence: "' . $phrase . '"';
            $confidence -= 18;
        }

        $confidence = max(0, min(99, $confidence));
        return [
            'valid'      => empty($issues),
            'confidence' => $confidence,
            'issues'     => $issues,
            'word_count' => $wordCount,
        ];
    }

    /** Every evidence value, as flat strings, whatever shape it arrived in. */
    private static function flattenEvidence(array $evidence): array
    {
        $out = [];
        foreach ($evidence as $v) {
            if ($v === null || $v === '') continue;
            if (is_array($v)) { foreach ($v as $x) if (is_scalar($x)) $out[] = (string)$x; }
            elseif (is_scalar($v)) $out[] = (string)$v;
        }
        return $out;
    }

    /** Capitalised word sequences of 1–4 words — names, places, titles. */
    private static function properNounPhrases(string $text): array
    {
        if (!preg_match_all(
            '/\b(?:[A-Z][a-zA-Z\'-]*)(?:\s+(?:[A-Z][a-zA-Z\'-]*|of|the|and))*(?:\s+[A-Z][a-zA-Z\'-]*)?\b/u',
            $text, $m
        )) return [];
        $out = [];
        foreach ($m[0] as $p) {
            $p = trim($p, " \t.,");
            // A single word at a sentence start is usually just grammar,
            // not a claim — only multi-word phrases or words that recur
            // mid-sentence are worth checking.
            if ($p !== '' && (str_contains($p, ' ') || !self::looksLikeSentenceStart($text, $p))) {
                $out[] = $p;
            }
        }
        return array_values(array_unique($out));
    }

    private static function looksLikeSentenceStart(string $text, string $word): bool
    {
        return (bool)preg_match('/(?:^|[.!?]\s+)' . preg_quote($word, '/') . '\b/u', $text);
    }

    private static function isSupported(string $phrase, string $norm, array $vocab): bool
    {
        if (isset($vocab[$norm])) return true;
        if (isset($vocab[RmNormalizer::guestKey($phrase)])) return true;
        // Partial credit: every word of the phrase appears somewhere in
        // the evidence (order-independent) — catches "Jeju Island" being
        // supported by evidence that separately says "Jeju".
        $words = preg_split('/\s+/u', $norm, -1, PREG_SPLIT_NO_EMPTY);
        if (count($words) > 1) {
            $allFound = true;
            foreach ($words as $w) {
                if (in_array($w, self::SAFE_WORDS, true)) continue;
                if (!isset($vocab[$w])) { $allFound = false; break; }
            }
            if ($allFound) return true;
        }
        return false;
    }
}
