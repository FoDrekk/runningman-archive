<?php
// ============================================================
// RmAiClient / RmAiDecisionProvider — the AI reasoning layer.
//
// AI here is a REASONING SERVICE, not a chatbot: it is consulted only
// for cases the deterministic engine leaves open (a genuine source
// conflict — RmDecisionEngine calls this only when its own rules
// already produced REVIEW) or to draft a synopsis when evidence exists
// but no source wrote one (see AiSynopsis.php). It never runs against
// complete, trusted data, and it never gets the last word on its own:
// every AI decision still passes through the same confidence
// thresholds and safety guards as a human source would.
//
// Strictly optional, exactly like every keyed source in this project:
//   · the key is read from the environment (RM_AI_API_KEY or
//     ANTHROPIC_API_KEY) or config/scraping.local.php — never hardcoded
//   · with no key, or ai.mode = 'disabled', the layer reports itself
//     unavailable and every caller falls back to its own default
//     (RETRY_LATER / INSUFFICIENT_EVIDENCE) — nothing is ever blocked
//     on it
//   · calls go through RmHttpClient, so RM_SCRAPE_OFFLINE blocks them
//     in tests exactly like every other external source
// ============================================================
require_once __DIR__ . '/../../config/scraping.php';
require_once __DIR__ . '/Http.php';
require_once __DIR__ . '/Decision.php';

class RmAiClient
{
    public function mode(): string { return (string)rmScrapeConfig('ai.mode', 'review'); }

    private function key(): ?string
    {
        $k = rmScrapeConfig('ai.api_key');
        return (is_string($k) && trim($k) !== '') ? trim($k) : null;
    }

    public function available(): bool
    {
        return $this->mode() !== 'disabled' && $this->key() !== null;
    }

    /**
     * One request/response round trip. Returns the model's raw text
     * reply, or null on any failure — a failure here is always
     * RETRY_LATER to the caller, never treated as "the evidence was
     * insufficient".
     */
    public function complete(string $system, string $user): ?string
    {
        if (!$this->available()) return null;

        $url = (string)rmScrapeConfig('ai.api_base', 'https://api.anthropic.com/v1/messages');
        $body = [
            'model'      => (string)rmScrapeConfig('ai.model', 'claude-sonnet-5'),
            'max_tokens' => (int)rmScrapeConfig('ai.max_tokens', 400),
            'system'     => $system,
            'messages'   => [['role' => 'user', 'content' => $user]],
        ];
        [$data, $res] = RmHttpClient::instance()->postJson($url, $body, [
            'timeout' => (int)rmScrapeConfig('ai.timeout', 30),
            'headers' => [
                'x-api-key: ' . $this->key(),
                'anthropic-version: 2023-06-01',
            ],
        ]);
        if (!$res->ok || !is_array($data)) return null;

        $text = '';
        foreach ((array)($data['content'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'text') $text .= (string)($block['text'] ?? '');
        }
        $text = trim($text);
        return $text !== '' ? $text : null;
    }
}

/**
 * Plugs into RmDecisionEngine's existing provider seam. Consulted ONLY
 * for decisions the deterministic rules already classified REVIEW — a
 * genuine SOURCE_CONFLICT between well-supported candidates.
 */
class RmAiDecisionProvider implements RmDecisionProvider
{
    public function __construct(private RmAiClient $client = new RmAiClient()) {}

    public function decide(array $context): ?array
    {
        if (!$this->client->available()) return null;
        if (empty($context['conflicts'])) return null;

        $thresholds = (array)rmScrapeConfig('ai.thresholds', ['high' => 90, 'review' => 70]);

        $system = 'You are a careful research assistant for a TV episode archive. '
            . 'You are shown conflicting values from independent sources for one field of one episode. '
            . 'Decide which value (if any) is correct, or say the conflict needs a human. '
            . 'Reply with ONLY a JSON object: {"decision":"USE_SOURCE_DATA"|"REQUEST_REVIEW"|"NO_USABLE_DATA",'
            . '"chosen_source":"<source name or null>","confidence":<0-100 integer>,"reason":"<one sentence>"}. '
            . 'Never invent a value that none of the sources offered. When genuinely unsure, choose REQUEST_REVIEW.';

        $user = json_encode([
            'episode'          => $context['episode'],
            'field'            => $context['field'],
            'criticality'      => $context['criticality'],
            'candidates'       => $context['candidate_values'],
            'source_reputation'=> $context['field_reputation'],
            'existing_value'   => $context['existing_value'],
            'existing_confidence' => $context['existing_confidence'],
        ], JSON_UNESCAPED_UNICODE);

        $reply = $this->client->complete($system, (string)$user);
        if ($reply === null) return null;

        $parsed = json_decode(self::extractJson($reply), true);
        if (!is_array($parsed) || empty($parsed['decision'])) return null;

        $confidence = max(0, min(100, (int)($parsed['confidence'] ?? 0)));
        $chosenSource = is_string($parsed['chosen_source'] ?? null) ? $parsed['chosen_source'] : null;

        $decision = match ((string)$parsed['decision']) {
            'USE_SOURCE_DATA' => $confidence >= (int)($thresholds['review'] ?? 70) ? 'UPDATE' : 'REVIEW',
            'NO_USABLE_DATA'  => 'UNKNOWN',
            default           => 'REVIEW',
        };

        // Never let the model pick a value no real source actually
        // offered — it may only choose among the candidates it was shown.
        if ($decision === 'UPDATE') {
            $known = array_column((array)($context['candidate_values'] ?? []), 'sources');
            $allSources = array_merge([], ...$known);
            if ($chosenSource === null || !in_array($chosenSource, $allSources, true)) {
                $decision = 'REVIEW';
            }
        }

        return [
            'decision'   => $decision,
            'confidence' => $confidence,
            'reason'     => 'AI reasoning: ' . (string)($parsed['reason'] ?? 'no reason given')
                          . ($chosenSource ? " (favouring $chosenSource)" : ''),
        ];
    }

    /** The model may wrap JSON in prose or a code fence despite instructions. */
    private static function extractJson(string $text): string
    {
        if (preg_match('/\{.*\}/s', $text, $m)) return $m[0];
        return $text;
    }
}
