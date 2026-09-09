<?php
// ============================================================
// tests/ai.php — the AI reasoning layer: availability, grounding,
// synopsis decisions and confidence thresholds.
//
// Hermetic by construction: RM_SCRAPE_OFFLINE is set before the engine
// loads, so RmHttpClient refuses any real call to api.anthropic.com —
// this suite can never spend a token or reach a live network. Every
// GENERATE/REQUEST_REVIEW/REJECT path is exercised with a fake client
// (FakeAiClient below) that returns fixed text instead of calling out,
// exactly the seam RmDecisionProvider already uses for the deterministic
// engine's own reasoning-provider hook.
//
//   php tests/ai.php
// ============================================================
putenv('RM_SCRAPE_OFFLINE=1');
$_ENV['RM_SCRAPE_OFFLINE'] = '1';
putenv('RM_AI_API_KEY');       // make sure no real key leaks in from the environment
putenv('ANTHROPIC_API_KEY');

require_once __DIR__ . '/../includes/scraping/bootstrap.php';

$pass = 0; $fail = 0;
function check(string $name, $actual, $expected = true): void {
    global $pass, $fail;
    $ok = is_array($expected) ? ($actual == $expected) : ($actual === $expected);
    if ($ok) { $pass++; echo "  PASS $name\n"; }
    else { $fail++; echo "  FAIL $name\n       expected " . var_export($expected, true) . ", got " . var_export($actual, true) . "\n"; }
}
function section(string $s): void { echo "\n── " . strtoupper($s) . " ──\n"; }

/** Returns a fixed reply (or a queue of them) instead of calling out. */
class FakeAiClient extends RmAiClient
{
    private int $i = 0;
    public function __construct(private array $replies = [], private bool $isAvailable = true) {}
    public function available(): bool { return $this->isAvailable; }
    public function complete(string $system, string $user): ?string
    {
        $r = $this->replies[$this->i] ?? null;
        $this->i++;
        return $r;
    }
}

// ============================================================
section('availability — an optional feature that costs nothing without a key');
$client = new RmAiClient();
check('with no key configured, the client reports unavailable', $client->available(), false);
check('default mode is review (the safe default)', $client->mode(), 'review');

$provider = new RmAiDecisionProvider($client);
$ctx = ['conflicts' => true, 'candidate_values' => [], 'field_reputation' => [], 'episode' => 1,
        'field' => 'title', 'criticality' => 'critical', 'existing_value' => null, 'existing_confidence' => 0];
check('with no client available the decision provider declines rather than guessing',
      $provider->decide($ctx), null);

// ============================================================
section('grounding — a clean, evidence-backed draft passes');
$evidence = [
    'title' => 'Jeju Island Grand Race', 'guests' => ['Lee Kwang-soo', 'Song Ji-hyo'],
    'mission' => 'Name Tag Elimination', 'location' => 'Jeju Island',
];
$good = 'A trip to Jeju Island turns into an all-day scramble as the members chase down '
      . "each other's name tags across the island's coast. Lee Kwang-soo and Song Ji-hyo "
      . 'find themselves at the center of the chase, with elimination on the line for '
      . 'whoever is caught without a tag when the mission ends.';
$g = RmGroundingValidator::validate($good, $evidence);
check('a grounded, well-formed draft is valid', $g['valid'], true);
check('  → with a high confidence', $g['confidence'] >= 70, true);
check('  → and a realistic word count', $g['word_count'] > 30, true);

section('grounding — a banned generic opener is always rejected');
$generic = RmGroundingValidator::validate(
    'In this exciting episode, the members travel to Jeju Island and race across the coast '
    . 'for two days against the production team in a contest of wits and speed.', $evidence);
check('the banned opener is caught', $generic['valid'], false);
check('  → and named as the reason', str_contains(implode(' ', $generic['issues']), 'banned generic phrase'), true);

section('grounding — an invented guest is caught, not waved through');
$invented = RmGroundingValidator::validate(
    'A trip to Jeju Island turns into an all-day scramble as the members chase down tags '
    . "across the island's coast, with Yoo Jae-suk narrowly avoiding elimination while "
    . 'Kim Jong-kook claims the win in the final round of the mission.', $evidence);
check('a guest not in the evidence is flagged', $invented['valid'], false);
check('  → naming the unsupported claim',
      (bool)array_filter($invented['issues'], fn($i) => str_contains($i, 'Kim Jong-kook') || str_contains($i, 'Yoo Jae-suk')), true);

section('grounding — a fragment is not a synopsis');
$stub = RmGroundingValidator::validate('A short race.', $evidence);
check('a stub is rejected for length, not silently accepted', $stub['valid'], false);

// ============================================================
section('AI synopsis — a usable existing synopsis is never rewritten');
$svc = new RmAiSynopsisService(null, new FakeAiClient(['should never be called']));
$existingGood = 'The members travel to Jeju Island for a two-day race against the production team, with a penalty for the losers.';
$r = $svc->consider(810, $existingGood, ['title' => 'A Title', 'guests' => ['Someone']]);
check('KEEP_EXISTING when a usable synopsis is already there', $r['decision'], 'KEEP_EXISTING');
check('  → and no value is proposed to replace it', $r['value'], null);

section('AI synopsis — not enough evidence to write from');
$r = $svc->consider(810, null, ['title' => 'A Title']);
check('INSUFFICIENT_EVIDENCE with only one usable fact', $r['decision'], 'INSUFFICIENT_EVIDENCE');

section('AI synopsis — no key configured degrades to DISABLED, not a crash');
$svc2 = new RmAiSynopsisService(null, new RmAiClient());   // real client, still keyless in this test env
$r = $svc2->consider(810, null, ['title' => 'A Title', 'guests' => ['Lee Kwang-soo', 'Song Ji-hyo']]);
check('DISABLED (permanent, not RETRY_LATER) when no key is configured', $r['decision'], 'DISABLED');
check('  → and no value is fabricated', $r['value'], null);

section('AI synopsis — a failed request is RETRY_LATER, never treated as no evidence');
$flaky = new class extends RmAiClient { public function available(): bool { return true; } public function complete(string $s, string $u): ?string { return null; } };
$svc3 = new RmAiSynopsisService(null, $flaky);
$r = $svc3->consider(810, null, ['title' => 'A Title', 'guests' => ['Lee Kwang-soo', 'Song Ji-hyo']]);
check('a failed AI call is RETRY_LATER', $r['decision'], 'RETRY_LATER');

section('AI synopsis — a grounded, high-confidence draft is generated');
$evGood = ['title' => 'Jeju Island Grand Race', 'guests' => ['Lee Kwang-soo', 'Song Ji-hyo'], 'location' => 'Jeju Island'];
$goodDraft = 'A trip to Jeju Island turns into an all-day scramble as the members chase down '
      . "each other's name tags across the island's coast. Lee Kwang-soo and Song Ji-hyo "
      . 'find themselves at the center of the chase, with elimination on the line for '
      . 'whoever is caught without a tag when the mission ends.';
$svc4 = new RmAiSynopsisService(null, new FakeAiClient([$goodDraft]));
$r = $svc4->consider(810, null, $evGood);
check('GENERATE only in auto mode with high confidence — default mode is review, so this holds for review',
      $r['decision'], 'REQUEST_REVIEW');
check('  → but it is grounded and confident', $r['confidence'] >= 70, true);
check('  → and carries the drafted text', !empty($r['value']), true);

section('AI synopsis — a hallucinated draft is revised once, then rejected if still bad');
$badTwice = new FakeAiClient([
    'The members travel to Jeju Island where Kim Jong-kook wins the race and Haha is eliminated first.',
    'The members travel to Jeju Island where Kim Jong-kook wins the race and Haha is eliminated first anyway.',
]);
$svc5 = new RmAiSynopsisService(null, $badTwice);
$r = $svc5->consider(810, null, $evGood);
check('still-unsupported after one revision is REJECT, not published',
      $r['decision'], 'REJECT');
check('  → the grounding status says rejected', $r['grounding_status'], 'rejected');

section('AI synopsis — a revision that fixes itself is accepted');
$fixedOnRevision = new FakeAiClient([
    'The members travel to Jeju Island where Kim Jong-kook wins the race outright.',
    'A trip to Jeju Island turns into an all-day scramble as the members chase down tags '
        . "across the island's coast, with elimination on the line for whoever is caught "
        . 'without a tag when the mission ends at last light.',
]);
$svc6 = new RmAiSynopsisService(null, $fixedOnRevision);
$r = $svc6->consider(810, null, $evGood);
check('a corrected revision is accepted', in_array($r['decision'], ['REQUEST_REVIEW', 'GENERATE'], true), true);
check('  → and the grounding status records the revision', $r['grounding_status'], 'revised');

// ============================================================
section('field locks — a locked field is never touched, whatever the config table says');
check('with no database, nothing is ever reported locked', RmFieldLock::isLocked(null, 810, 'synopsis'), false);

$db = getDBSafe();
if ($db === null) {
    echo "  SKIP: no database reachable — the DB-backed AI/lock checks need MySQL/MariaDB.\n";
} else {
    $hasLockTable = true;
    try { $db->query('SELECT 1 FROM field_locks LIMIT 1'); } catch (Throwable $e) { $hasLockTable = false; }
    if (!$hasLockTable) {
        echo "  SKIP: field_locks table not installed — run database/pr4_ai_diagnostics.sql.\n";
    } else {
        $testEp = 999444;
        $db->prepare('DELETE FROM field_locks WHERE episode_number = ?')->execute([$testEp]);
        RmFieldLock::forget();

        check('a field starts unlocked', RmFieldLock::isLocked($db, $testEp, 'synopsis'), false);
        check('locking succeeds', RmFieldLock::lock($db, $testEp, 'synopsis', 'tester', 'manually verified'), true);
        check('  → and is now reported locked', RmFieldLock::isLocked($db, $testEp, 'synopsis'), true);
        check('  → a different field on the same episode is unaffected', RmFieldLock::isLocked($db, $testEp, 'title'), false);
        check('  → the lock detail is readable back',
              isset(RmFieldLock::forEpisode($db, $testEp)['synopsis']), true);
        check('unlocking releases it', RmFieldLock::unlock($db, $testEp, 'synopsis'), true);
        check('  → and it reports unlocked again', RmFieldLock::isLocked($db, $testEp, 'synopsis'), false);

        RmFieldLock::lock($db, $testEp, '*', 'tester', 'whole episode frozen');
        check('a whole-episode lock (field_name = *) covers every field',
              RmFieldLock::isLocked($db, $testEp, 'air_date'), true);
        $db->prepare('DELETE FROM field_locks WHERE episode_number = ?')->execute([$testEp]);
        RmFieldLock::forget();
    }

    $hasAiTable = true;
    try { $db->query('SELECT 1 FROM ai_generation_log LIMIT 1'); } catch (Throwable $e) { $hasAiTable = false; }
    if (!$hasAiTable) {
        echo "  SKIP: ai_generation_log table not installed — run database/pr4_ai_diagnostics.sql.\n";
    } else {
        section('AI synopsis — every decision is logged for later audit, even a refusal');
        $testEp2 = 999445;
        $db->prepare('DELETE FROM ai_generation_log WHERE episode_number = ?')->execute([$testEp2]);
        $svc7 = new RmAiSynopsisService($db, new FakeAiClient([]));
        $svc7->consider($testEp2, null, ['title' => 'Only One Fact']);
        $row = $db->prepare('SELECT decision, confidence FROM ai_generation_log WHERE episode_number = ? ORDER BY log_id DESC LIMIT 1');
        $row->execute([$testEp2]);
        $logged = $row->fetch(PDO::FETCH_ASSOC);
        check('an INSUFFICIENT_EVIDENCE decision is written to the log', $logged['decision'] ?? null, 'INSUFFICIENT_EVIDENCE');
        check('  → with its confidence recorded', (int)($logged['confidence'] ?? -1), 0);
        $db->prepare('DELETE FROM ai_generation_log WHERE episode_number = ?')->execute([$testEp2]);
    }
}

echo "\n" . str_repeat('─', 62) . "\n";
printf("%s — %d passed, %d failed\n", $fail === 0 ? 'AI LAYER VERIFIED' : 'AI LAYER PROBLEMS', $pass, $fail);
exit($fail === 0 ? 0 : 1);
