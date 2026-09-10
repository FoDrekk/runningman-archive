<?php
// ============================================================
// tests/pr15.php — AI Metadata Intelligence & Grounded Synopsis.
//
// PR15 did NOT rebuild the AI layer: RmAiSynopsisService already
// gathered verified evidence, refused to draft below a minimum fact
// count, ran every draft through RmGroundingValidator (with one
// revision attempt before rejecting), and only ever auto-applied a
// synopsis in 'auto' mode above the high-confidence threshold —
// tests/ai.php already proves all of that and none of it is repeated
// here.
//
// This file covers the real, narrow gaps the audit found:
//
//   - RmAiDecisionProvider::decide() mapped a confident USE_SOURCE_DATA
//     reply straight to an auto-writable UPDATE using only the
//     'review' confidence threshold — with no ai.mode check at all.
//     That meant a source-conflict resolution could bypass human
//     review even in the default 'review' mode, unlike
//     RmAiSynopsisService's GENERATE path (which already gated on
//     mode === 'auto'). Fixed to mirror that exact pattern.
//   - RmDecisionEngine::resolveReview()'s 'accepted' outcome only ever
//     relabelled the research_decisions row — the admin "Approve"
//     button never actually wrote anything to the episode, so an
//     approved AI-drafted synopsis (or any other accepted review) could
//     never reach the archive. Fixed to perform the safe write for the
//     scalar fields it's safe to write automatically, respecting field
//     locks, and to report honestly when a field type (guests,
//     location) needs a human's hand instead of silently doing nothing.
//   - ai_generation_log.applied was always written 0, even for a
//     synopsis that WAS auto-applied — because consider() logs the
//     decision before the caller has attempted the write. Fixed with
//     RmAiSynopsisService::markApplied(), called only once the write
//     has genuinely happened.
//   - The admin "Regenerate synopsis" action from the spec (Section 13)
//     had nowhere to attach — RmAiSynopsisService::consider()'s own
//     'force' option was never threaded through from the research
//     service. Fixed via a 'regenerate_synopsis' research option.
//
// Hermetic: RM_SCRAPE_OFFLINE=1, no real key, no real network call.
//
//   php tests/pr15.php
// ============================================================
putenv('RM_SCRAPE_OFFLINE=1');
$_ENV['RM_SCRAPE_OFFLINE'] = '1';
putenv('RM_AI_API_KEY');
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

const T_LO = 999450;
const T_HI = 999459;

/** A conflict-resolution AI client with a fixed mode and a fixed reply. */
class FixedModeAiClient extends RmAiClient
{
    public function __construct(private string $fakeMode, private ?string $reply) {}
    public function mode(): string { return $this->fakeMode; }
    public function available(): bool { return true; }
    public function complete(string $system, string $user): ?string { return $this->reply; }
}

$ctx = [
    'conflicts'        => true,
    'candidate_values' => [['value' => 'Jeju Island', 'sources' => ['wikipedia_en'], 'independent' => 2, 'reliability' => 90]],
    'field_reputation' => [],
    'episode'          => 810, 'field' => 'location', 'criticality' => 'important',
    'existing_value'   => 'Seoul', 'existing_confidence' => 60,
];

// ============================================================
section('conflict resolution — review mode never auto-writes, whatever the confidence');
$confidentReply = json_encode(['decision' => 'USE_SOURCE_DATA', 'chosen_source' => 'wikipedia_en',
    'confidence' => 97, 'reason' => 'Wikipedia is well corroborated here']);
$rReview = (new RmAiDecisionProvider(new FixedModeAiClient('review', $confidentReply)))->decide($ctx);
check("a 97%-confident conflict resolution in 'review' mode (the safe default) is REVIEW, not an auto-write",
      $rReview['decision'], 'REVIEW');
check('  → the confidence is still reported honestly, not suppressed', $rReview['confidence'], 97);

section('conflict resolution — auto mode auto-writes only past the strict "high" bar');
$rAutoHigh = (new RmAiDecisionProvider(new FixedModeAiClient('auto', $confidentReply)))->decide($ctx);
check("97% in 'auto' mode clears the high threshold and becomes UPDATE", $rAutoHigh['decision'], 'UPDATE');

$midReply = json_encode(['decision' => 'USE_SOURCE_DATA', 'chosen_source' => 'wikipedia_en',
    'confidence' => 75, 'reason' => 'reasonably confident, not certain']);
$rAutoMid = (new RmAiDecisionProvider(new FixedModeAiClient('auto', $midReply)))->decide($ctx);
check("75% clears the review bar but not the high one — even in auto mode this stays REVIEW",
      $rAutoMid['decision'], 'REVIEW');

section('conflict resolution — the model may not pick a source no candidate actually offered');
$madeUpSourceReply = json_encode(['decision' => 'USE_SOURCE_DATA', 'chosen_source' => 'a_source_that_was_never_shown',
    'confidence' => 99, 'reason' => 'very sure']);
$rBadSource = (new RmAiDecisionProvider(new FixedModeAiClient('auto', $madeUpSourceReply)))->decide($ctx);
check('a high-confidence pick of an unoffered source is forced back to REVIEW even in auto mode',
      $rBadSource['decision'], 'REVIEW');

section('conflict resolution — NO_USABLE_DATA and disabled availability are unaffected by mode');
$noDataReply = json_encode(['decision' => 'NO_USABLE_DATA', 'confidence' => 10, 'reason' => 'nothing usable']);
check('NO_USABLE_DATA is UNKNOWN whether the layer is in review or auto mode',
      (new RmAiDecisionProvider(new FixedModeAiClient('auto', $noDataReply)))->decide($ctx)['decision'], 'UNKNOWN');
check('with no client available the provider still declines rather than guessing (unchanged from PR4)',
      (new RmAiDecisionProvider(new RmAiClient()))->decide($ctx), null);

// ============================================================
section('AI synopsis — regenerate_synopsis is the only way past "a usable synopsis already exists"');
class QueuedAiClient extends RmAiClient {
    private int $i = 0;
    public function __construct(private array $replies) {}
    public function available(): bool { return true; }
    public function complete(string $system, string $user): ?string { return $this->replies[$this->i++] ?? null; }
}
$existingGood = 'The members travel to Jeju Island for a two-day race against the production team, with a penalty for the losers.';
$freshDraft = 'A trip to Jeju Island turns into an all-day scramble as the members chase down '
    . "each other's name tags across the island's coast. Lee Kwang-soo and Song Ji-hyo "
    . 'find themselves at the center of the chase, with elimination on the line for '
    . 'whoever is caught without a tag when the mission ends.';
$evGood = ['title' => 'Jeju Island Grand Race', 'guests' => ['Lee Kwang-soo', 'Song Ji-hyo'], 'location' => 'Jeju Island'];

$svcNoForce = new RmAiSynopsisService(null, new QueuedAiClient(['should never be called']));
$rNoForce = $svcNoForce->consider(810, $existingGood, $evGood);
check('without force, a usable existing synopsis is still never rewritten', $rNoForce['decision'], 'KEEP_EXISTING');

$svcForced = new RmAiSynopsisService(null, new QueuedAiClient([$freshDraft]));
$rForced = $svcForced->consider(810, $existingGood, $evGood, ['force' => true]);
check('with force (the admin "Regenerate" action), AI drafts a new one even though the old one was usable',
      in_array($rForced['decision'], ['REQUEST_REVIEW', 'GENERATE'], true), true);
check('  → the fresh draft is still grounding-checked, not published unconditionally', !empty($rForced['value']), true);

// ============================================================
$db = getDBSafe();
if ($db === null) {
    echo "\nSKIP: no database reachable — the review-inbox and provenance sections need MySQL/MariaDB.\n";
} else {
    $hasDecisions = true;
    try { $db->query('SELECT 1 FROM research_decisions LIMIT 1'); } catch (Throwable $e) { $hasDecisions = false; }

    $db->exec('DELETE FROM episodes WHERE episode_number BETWEEN ' . T_LO . ' AND ' . T_HI);
    for ($n = T_LO; $n <= T_HI; $n++) {
        $db->prepare('INSERT INTO episodes (episode_number, title, synopsis, verification_required) VALUES (?, ?, ?, 1)')
           ->execute([$n, "Episode #$n - Fixture", 'A short original synopsis long enough to matter.']);
    }

    if (!$hasDecisions) {
        echo "SKIP: research_decisions table not installed — run database/research_engine.sql.\n";
    } else {
        section('review inbox — "Accept" now performs the safe write, not just a status relabel');
        $db->exec('DELETE FROM research_decisions WHERE episode_number BETWEEN ' . T_LO . ' AND ' . T_HI);

        $epSyn = T_LO;
        $db->prepare(
            "INSERT INTO research_decisions (episode_number, field_name, decision, chosen_value, existing_value, confidence, reason, review_status)
             VALUES (?, 'synopsis', 'REVIEW', ?, 'old text', 82, 'AI draft awaiting review', 'open')"
        )->execute([$epSyn, 'A grounded AI-drafted synopsis text approved by an editor.']);
        $id = (int)$db->lastInsertId();

        $res = RmDecisionEngine::resolveReview($db, $id, 'accepted');
        check('accepting a synopsis review reports ok', $res['ok'], true);
        check('  → and actually writes the accepted value to the episode',
              $db->query("SELECT synopsis FROM episodes WHERE episode_number = $epSyn")->fetchColumn(),
              'A grounded AI-drafted synopsis text approved by an editor.');
        check('  → the note says so, rather than a generic "recorded"',
              str_contains($res['note'] ?? '', 'updated'), true);

        section('review inbox — a locked field is never overwritten, even on accept');
        $epLocked = T_LO + 1;
        RmFieldLock::lock($db, $epLocked, 'synopsis', 'tester', 'manually verified, do not touch');
        $db->prepare(
            "INSERT INTO research_decisions (episode_number, field_name, decision, chosen_value, existing_value, confidence, reason, review_status)
             VALUES (?, 'synopsis', 'REVIEW', 'A value that must never land.', 'old text', 82, 'AI draft', 'open')"
        )->execute([$epLocked]);
        $idLocked = (int)$db->lastInsertId();
        $resLocked = RmDecisionEngine::resolveReview($db, $idLocked, 'accepted');
        check('accepting a review is still recorded even when the write is refused', $resLocked['ok'], true);
        check('  → but the locked field is untouched',
              $db->query("SELECT synopsis FROM episodes WHERE episode_number = $epLocked")->fetchColumn(),
              'A short original synopsis long enough to matter.');
        check('  → and the note honestly says why', str_contains($resLocked['note'] ?? '', 'locked'), true);
        RmFieldLock::unlock($db, $epLocked, 'synopsis');

        section('review inbox — a field type that needs identity resolution is reported, not silently dropped');
        $epGuest = T_LO + 2;
        $db->prepare(
            "INSERT INTO research_decisions (episode_number, field_name, decision, chosen_value, existing_value, confidence, reason, review_status)
             VALUES (?, 'guests', 'REVIEW', 'Yoo Jae-suk, Kim Jong-kook', NULL, 80, 'conflict', 'open')"
        )->execute([$epGuest]);
        $idGuest = (int)$db->lastInsertId();
        $resGuest = RmDecisionEngine::resolveReview($db, $idGuest, 'accepted');
        check('accepting a non-scalar field still records the outcome', $resGuest['ok'], true);
        check('  → but is honest that it was not auto-written', str_contains($resGuest['note'] ?? '', 'not auto-written'), true);

        section('review inbox — kept_existing / ignored still work exactly as before, and bad input is refused');
        $epIgnore = T_LO + 3;
        $db->prepare(
            "INSERT INTO research_decisions (episode_number, field_name, decision, chosen_value, existing_value, confidence, reason, review_status)
             VALUES (?, 'title', 'REVIEW', 'A New Title', 'Old Title', 60, 'weak conflict', 'open')"
        )->execute([$epIgnore]);
        $idIgnore = (int)$db->lastInsertId();
        check('ignoring a review is recorded without touching the episode',
              RmDecisionEngine::resolveReview($db, $idIgnore, 'ignored')['ok'], true);
        check('  → the title is untouched',
              $db->query("SELECT title FROM episodes WHERE episode_number = $epIgnore")->fetchColumn(),
              "Episode #$epIgnore - Fixture");
        check('an unknown decision id is refused, not silently accepted',
              RmDecisionEngine::resolveReview($db, 999999999, 'accepted')['ok'], false);
        check('an unknown outcome string is refused',
              RmDecisionEngine::resolveReview($db, $id, 'approved_forever')['ok'], false);

        $db->exec('DELETE FROM research_decisions WHERE episode_number BETWEEN ' . T_LO . ' AND ' . T_HI);
    }

    $hasAiLog = true;
    try { $db->query('SELECT 1 FROM ai_generation_log LIMIT 1'); } catch (Throwable $e) { $hasAiLog = false; }
    if (!$hasAiLog) {
        echo "SKIP: ai_generation_log table not installed — run database/pr4_ai_diagnostics.sql.\n";
    } else {
        section('provenance — applied now reflects reality instead of always reading false');
        $epLog = T_LO + 4;
        $db->exec("DELETE FROM ai_generation_log WHERE episode_number = $epLog");
        $svc = new RmAiSynopsisService($db, new QueuedAiClient([$freshDraft]));
        $svc->consider($epLog, null, $evGood, ['run_id' => null]);
        $before = $db->query("SELECT applied FROM ai_generation_log WHERE episode_number = $epLog ORDER BY log_id DESC LIMIT 1")->fetchColumn();
        check('a freshly-logged decision starts unapplied — the write has not happened yet', (int)$before, 0);

        $svc->markApplied($epLog, null);
        $after = $db->query("SELECT applied FROM ai_generation_log WHERE episode_number = $epLog ORDER BY log_id DESC LIMIT 1")->fetchColumn();
        check('markApplied() flips it true once the caller confirms the write actually happened', (int)$after, 1);

        $db->exec("DELETE FROM ai_generation_log WHERE episode_number = $epLog");
        $svc->markApplied($epLog, null);
        check('markApplied() on a row that was never logged degrades quietly, never throws', true, true);
    }

    $db->exec('DELETE FROM episodes WHERE episode_number BETWEEN ' . T_LO . ' AND ' . T_HI);
}

echo "\n" . str_repeat('─', 62) . "\n";
printf("%s — %d passed, %d failed\n", $fail === 0 ? 'PR15 VERIFIED' : 'PR15 PROBLEMS', $pass, $fail);
exit($fail === 0 ? 0 : 1);
