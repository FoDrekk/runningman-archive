<?php
// ============================================================
// tests/pr14.php — Thumbnail Recovery & Quality.
//
// PR14 did NOT rebuild the thumbnail engine — RmThumbnailEngine already
// validated real bytes (not HTTP 200), fell back across sources in
// field-priority order, never displaced a good file with a bad fetch,
// skipped re-downloading identical bytes, flagged exact-duplicate
// images for review (never auto-deleted), and resolved Windows/XAMPP
// paths correctly. tests/resolution.php and tests/pr10.php already
// cover all of that and are not repeated here.
//
// This file covers what PR14 actually added, all genuinely new:
//
//   - classify(): the six named states (VALID/BROKEN/MISSING/INVALID/
//     DUPLICATE/SUSPECT_DUPLICATE), composed from existing signals —
//     including the BROKEN-vs-INVALID split verify() didn't used to
//     make (a missing file and a corrupted one are different problems).
//   - perceptualHash()/hammingDistance(): a deterministic dHash for
//     near-duplicate (SUSPECT_DUPLICATE) detection — visually similar,
//     not byte-identical, never auto-actioned.
//   - scoreCandidate(): a technically-valid image can still be the
//     wrong one to trust (low resolution, an unranked source, a URL
//     that names a different episode) — acquire() now refuses a
//     candidate that scores below the configured floor, proven here
//     both as a pure function and end-to-end through acquire() itself.
//   - looksRelevant(): the episode-relevance heuristic on candidate URLs.
//   - Aspect-ratio validation in RmValidator::imageBytes().
//   - Dry-run support in acquire(): validates and scores for real,
//     writes nothing.
//   - Path traversal is impossible by construction in absolutePath() —
//     proven explicitly here rather than only implied.
//
// Offline. RM_SCRAPE_OFFLINE=1 is NOT set here because this file uses
// its own local fixture HTTP server on loopback (same technique as
// tests/resolution.php) — no real external site is ever contacted.
//
//   php tests/pr14.php
// ============================================================
require_once __DIR__ . '/../includes/scraping/bootstrap.php';

$pass = 0; $fail = 0;
function check(string $name, $actual, $expected = true): void {
    global $pass, $fail;
    $ok = is_array($expected) ? ($actual == $expected) : ($actual === $expected);
    if ($ok) { $pass++; echo "  PASS $name\n"; }
    else { $fail++; echo "  FAIL $name\n       expected " . var_export($expected, true) . ", got " . var_export($actual, true) . "\n"; }
}
function section(string $s): void { echo "\n── " . strtoupper($s) . " ──\n"; }

// Dedicated range, untouched by any other suite (pr11.php: 1970-1989,
// integration.php: 1950-1965, research.php: 1900-1919, pr13.php: 1800-1819).
const T_LO = 1750, T_HI = 1769;

/** A minimal, decodable JPEG at the given size/fill/quality — for pure-function tests, no HTTP needed. */
function makeJpeg(int $w, int $h, array $rgb = [40, 90, 160], int $quality = 90): string {
    $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, imagecolorallocate($im, ...$rgb));
    ob_start(); imagejpeg($im, null, $quality); $bytes = ob_get_clean(); imagedestroy($im);
    return $bytes;
}

// ============================================================
section('perceptualHash() / hammingDistance() — deterministic, GD-based');

$te = new RmThumbnailEngine(null);

$blueA = makeJpeg(640, 360, [40, 90, 160], 90);
$blueB = makeJpeg(640, 360, [40, 90, 160], 60);   // same pixels, different JPEG quality -> different bytes
/** A horizontal grayscale gradient — dHash encodes GRADIENT, so two solid colours (zero internal gradient) would hash identically; this is the correct way to get two deliberately different gradients. */
function makeGradient(int $w, int $h, bool $reverse): string {
    $im = imagecreatetruecolor($w, $h);
    for ($x = 0; $x < $w; $x++) {
        $v = (int)round(255 * $x / max(1, $w - 1));
        if ($reverse) $v = 255 - $v;
        imagefilledrectangle($im, $x, 0, $x, $h - 1, imagecolorallocate($im, $v, $v, $v));
    }
    ob_start(); imagejpeg($im, null, 90); $bytes = ob_get_clean(); imagedestroy($im);
    return $bytes;
}
$gradientLight = makeGradient(64, 64, false);   // dark-to-light, left to right
$gradientDark  = makeGradient(64, 64, true);    // light-to-dark, left to right — the inverse

$hashA1 = $te->perceptualHash($blueA);
$hashA2 = $te->perceptualHash($blueA);
check('same bytes -> same hash (determinism)', $hashA1, $hashA2);
check('hash is 16 hex chars (64-bit dHash)', $hashA1 !== null && preg_match('/^[0-9A-F]{16}$/', $hashA1) === 1, true);

$hashB = $te->perceptualHash($blueB);
check('same pixels, different compression -> hash still computed', $hashB !== null, true);
check('  → and near-identical to the original (dHash tolerates re-compression)',
    RmThumbnailEngine::hammingDistance($hashA1, $hashB) <= 8, true);

$hashLight = $te->perceptualHash($gradientLight);
$hashDark  = $te->perceptualHash($gradientDark);
check('two inverse gradients hash very differently (dHash sees the gradient, not the palette)',
    RmThumbnailEngine::hammingDistance($hashLight, $hashDark) > 20, true);

check('hammingDistance of identical hashes is 0', RmThumbnailEngine::hammingDistance($hashA1, $hashA1), 0);
check('hammingDistance of mismatched-length hashes is "never equal" (PHP_INT_MAX)',
    RmThumbnailEngine::hammingDistance('ABCD', 'ABCDEF'), PHP_INT_MAX);
check('hammingDistance of two empty strings is "never equal"',
    RmThumbnailEngine::hammingDistance('', ''), PHP_INT_MAX);

// ============================================================
section('scoreCandidate() — ranks trust among already-valid images');

$hi = $te->scoreCandidate('sbs', 1280, 720, true, false);
$lo = $te->scoreCandidate('sbs', 1280, 720, true, false);
check('scoreCandidate() is deterministic for the same inputs', $hi, $lo);

check('a tier-3 official source outranks a totally unranked one, all else equal',
    $te->scoreCandidate('sbs', 640, 360, true, false) > $te->scoreCandidate('unranked_test_source_xyz', 640, 360, true, false), true);
check('full target resolution outranks well-below-target, all else equal',
    $te->scoreCandidate('myrunningman', 1280, 720, true, false) > $te->scoreCandidate('myrunningman', 200, 112, true, false), true);
check('a relevant URL outranks a flagged-irrelevant one, all else equal',
    $te->scoreCandidate('myrunningman', 640, 360, true, false) > $te->scoreCandidate('myrunningman', 640, 360, false, false), true);
check('a non-duplicate outranks an already-duplicated image, all else equal',
    $te->scoreCandidate('myrunningman', 640, 360, true, false) > $te->scoreCandidate('myrunningman', 640, 360, true, true), true);
check('score is always within [0, 1]',
    $te->scoreCandidate('nonexistent', 50000, 1, false, true) >= 0.0
    && $te->scoreCandidate('sbs', 1280, 720, true, false) <= 1.0, true);

// The exact combination the end-to-end acquire() test below relies on —
// asserted directly here so that test's expectation is never a guess.
$lowScore = $te->scoreCandidate('totally_unranked_test_source', 200, 112, false, false);
check('a small, unranked, irrelevant candidate scores below the configured floor',
    $lowScore < (float)rmScrapeConfig('thumbnail.min_score', 0.40), true);

// ============================================================
section('looksRelevant() — episode-relevance heuristic on candidate URLs');

check('URL path naming the requested episode -> relevant',
    $te->looksRelevant('https://cdn.example.com/thumbs/ep813.jpg', 813), true);
check('URL path naming a clearly different, non-adjacent episode -> NOT relevant',
    $te->looksRelevant('https://cdn.example.com/thumbs/ep100.jpg', 813), false);
check('URL path with an adjacent episode number (shared special) -> relevant',
    $te->looksRelevant('https://cdn.example.com/thumbs/ep814.jpg', 813), true);
check('URL path with a year, not an episode number -> relevant (not penalised)',
    $te->looksRelevant('https://cdn.example.com/2026/keyart.jpg', 813), true);
check('URL path with a resolution marker, not an episode number -> relevant',
    $te->looksRelevant('https://cdn.example.com/img-1280.jpg', 813), true);
check('URL path with no digits at all -> relevant (benefit of the doubt)',
    $te->looksRelevant('https://cdn.example.com/thumbs/keyart.jpg', 813), true);

// ============================================================
section('RmValidator::imageBytes() — aspect-ratio sanity (PR14)');

// Both dimensions individually clear min_width(200)/min_height(112) —
// only the RATIO is the problem, so the aspect check (not the plain
// dimension check) is what's actually being exercised here.
$wide = makeJpeg(3000, 150);   // ratio 20 — a banner strip, not a thumbnail
$tall = makeJpeg(220, 3000);   // ratio 0.073
$normal = makeJpeg(640, 360);  // ratio 1.78 — the archive's own target shape

$checkWide = RmValidator::imageBytes($wide, 'image/jpeg');
check('an extremely wide image is rejected', $checkWide['valid'], false);
check('  → and the reason names the aspect ratio', str_contains($checkWide['reason'] ?? '', 'aspect ratio'), true);

$checkTall = RmValidator::imageBytes($tall, 'image/jpeg');
check('an extremely tall image is rejected', $checkTall['valid'], false);

$checkNormal = RmValidator::imageBytes($normal, 'image/jpeg');
check('a normally-proportioned image still passes', $checkNormal['valid'], true);

// ============================================================
section('RmThumbnailEngine::absolutePath() — path traversal is impossible by construction');

check('a path-traversal attempt resolves to nothing (no match, not a "clever" escape)',
    $te->absolutePath('/thumbnails/2026/../../../../etc/passwd'), null);
check('an encoded traversal attempt also resolves to nothing',
    $te->absolutePath('/thumbnails/2026/..%2f..%2fetc%2fpasswd'), null);
check('a filename outside the ep<digits>.<ext> pattern resolves to nothing',
    $te->absolutePath('/thumbnails/2026/not-an-episode-file.jpg'), null);
check('a well-formed path still resolves normally (the guard is not just refusing everything)',
    $te->absolutePath('/thumbnails/2026/ep813.jpg') !== null, true);

// ============================================================
section('acquire() dry-run and score-gate — fixture HTTP server');

$PORT = 8903;
$srv = @proc_open("php -S 127.0.0.1:$PORT " . escapeshellarg(__DIR__ . '/fixtures/server.php'),
    [['pipe','r'], ['file','/tmp/rm_fixture_server.log','a'], ['file','/tmp/rm_fixture_server.log','a']], $pipes);
for ($i = 0; $i < 40; $i++) { $fp = @fsockopen('127.0.0.1', $PORT, $e, $s, 0.25); if ($fp) { fclose($fp); break; } usleep(250_000); }
$up = (bool)@fsockopen('127.0.0.1', $PORT, $e, $s, 0.5);
register_shutdown_function(function () use ($srv) { if (is_resource($srv)) { proc_terminate($srv); proc_close($srv); } });

if (!$up) {
    echo "  SKIP: fixture server would not start on port $PORT\n";
} else {
    $B = "http://127.0.0.1:$PORT";
    $teDb = new RmThumbnailEngine(getDBSafe());
    $year = 2026;
    $dryEp = 999820;
    $dryFile = __DIR__ . "/../thumbnails/$year/ep" . str_pad((string)$dryEp, 3, '0', STR_PAD_LEFT) . '.jpg';
    @unlink($dryFile);

    section('acquire() — dry_run validates and scores for real, writes nothing');
    $before = is_file($dryFile);
    $res = $teDb->acquire($dryEp, $year, [['url' => "$B/?body=real_image", 'source' => 'fixture']], ['dry_run' => true, 'force' => true]);
    check('dry run reports ok (a valid, sufficiently-scored candidate was found)', $res['ok'], true);
    check('  → decision is REPLACE', $res['decision'] ?? null, 'REPLACE');
    check('  → a real score was computed', is_float($res['score'] ?? null) && $res['score'] > 0, true);
    check('  → nothing was written to disk', is_file($dryFile), $before);
    check('  → the response says it was a dry run', $res['dry_run'] ?? false, true);

    $metaAfterDry = $teDb->existingMeta($dryEp);
    check('  → and no thumbnail_meta row was created for it either',
        $metaAfterDry === null || ($metaAfterDry['status'] ?? null) === null || empty($metaAfterDry['local_path']), true);

    section("acquire() — a real, decodable, but unreliable candidate is refused (score gate)");
    $lowEp = 999821;
    $lowFile = __DIR__ . "/../thumbnails/$year/ep" . str_pad((string)$lowEp, 3, '0', STR_PAD_LEFT) . '.jpg';
    @unlink($lowFile);
    // Deliberately the exact combination scored above: bare-minimum
    // dimensions, an unranked source, and a URL naming a distant,
    // unrelated episode number.
    $res2 = $teDb->acquire($lowEp, $year, [
        ['url' => "$B/thumbs/ep100.jpg?body=small_image", 'source' => 'totally_unranked_test_source'],
    ], ['force' => true]);
    check('a technically-valid but low-scoring candidate is refused', $res2['ok'], false);
    check('  → the reason cites the score', str_contains((string)($res2['reason'] ?? ''), 'scored'), true);
    check('  → nothing was written to disk', is_file($lowFile), false);
    check('  → no thumbnail_meta row was created for it',
        $teDb->existingMeta($lowEp) === null, true);

    @unlink($dryFile);
    @unlink($lowFile);
}

// ============================================================
section('classify() — the six named states, composed from existing signals');

$db = getDBSafe();
if ($db === null) {
    echo "SKIP: no database reachable — this section needs MySQL/MariaDB.\n";
} else {
    foreach (['thumbnail_meta'] as $t) {
        try { $db->exec("DELETE FROM `$t` WHERE episode_number BETWEEN " . T_LO . " AND " . T_HI); } catch (Throwable $e) {}
    }
    foreach (['thumbnails'] as $t) {
        try { $db->exec("DELETE FROM `$t` WHERE episode_number BETWEEN " . T_LO . " AND " . T_HI); } catch (Throwable $e) {}
    }
    $db->exec("DELETE FROM episodes WHERE episode_number BETWEEN " . T_LO . " AND " . T_HI);
    for ($n = T_LO; $n <= T_HI; $n++) {
        $db->prepare("INSERT INTO episodes (episode_number, title, verification_required) VALUES (?, ?, 1)")
           ->execute([$n, "Episode #$n - Fixture"]);
    }

    $tc = new RmThumbnailEngine($db);
    $year = 2026;
    $dir = __DIR__ . "/../thumbnails/$year";
    @mkdir($dir, 0755, true);

    $epMissing = T_LO;
    check('no thumbnail recorded at all -> MISSING', $tc->classify($epMissing)['state'], 'MISSING');

    $epBroken = T_LO + 1;
    $db->prepare("INSERT INTO thumbnail_meta (episode_number, local_path, status) VALUES (?, ?, 'ok')")
       ->execute([$epBroken, "/thumbnails/$year/ep" . str_pad((string)$epBroken, 3, '0', STR_PAD_LEFT) . '.jpg']);
    // File genuinely does not exist on disk.
    check('recorded path, no file on disk -> BROKEN', $tc->classify($epBroken)['state'], 'BROKEN');

    $epInvalid = T_LO + 2;
    $invalidFile = $dir . '/ep' . str_pad((string)$epInvalid, 3, '0', STR_PAD_LEFT) . '.jpg';
    file_put_contents($invalidFile, 'this is not an image, just text pretending to be one, padded out ' . str_repeat('x', 3000));
    $db->prepare("INSERT INTO thumbnail_meta (episode_number, local_path, status) VALUES (?, ?, 'ok')")
       ->execute([$epInvalid, "/thumbnails/$year/ep" . str_pad((string)$epInvalid, 3, '0', STR_PAD_LEFT) . '.jpg']);
    check('file exists but fails image validation -> INVALID (not BROKEN)', $tc->classify($epInvalid)['state'], 'INVALID');
    @unlink($invalidFile);

    $epValid = T_LO + 3;
    $validFile = $dir . '/ep' . str_pad((string)$epValid, 3, '0', STR_PAD_LEFT) . '.jpg';
    $goodBytes = makeJpeg(640, 360, [10, 20, 30]);
    file_put_contents($validFile, $goodBytes);
    $db->prepare("INSERT INTO thumbnail_meta (episode_number, local_path, content_hash, status) VALUES (?, ?, ?, 'ok')")
       ->execute([$epValid, "/thumbnails/$year/ep" . str_pad((string)$epValid, 3, '0', STR_PAD_LEFT) . '.jpg', sha1($goodBytes)]);
    check('a genuinely valid, unique file -> VALID', $tc->classify($epValid)['state'], 'VALID');

    // Exact duplicate, far apart -> classifyDuplicate() says LIKELY_WRONG_EPISODE -> DUPLICATE.
    $epDupA = T_LO + 4; $epDupB = T_LO + 15;   // span 11, far apart
    $dupBytes = makeJpeg(640, 360, [200, 50, 50]);
    $dupHash = sha1($dupBytes);
    foreach ([$epDupA, $epDupB] as $ep) {
        $f = $dir . '/ep' . str_pad((string)$ep, 3, '0', STR_PAD_LEFT) . '.jpg';
        file_put_contents($f, $dupBytes);
        $db->prepare("INSERT INTO thumbnail_meta (episode_number, local_path, content_hash, width, height, bytes, status) VALUES (?, ?, ?, 640, 360, ?, 'ok')")
           ->execute([$ep, "/thumbnails/$year/ep" . str_pad((string)$ep, 3, '0', STR_PAD_LEFT) . '.jpg', $dupHash, strlen($dupBytes)]);
    }
    check('byte-identical image, far-apart episodes -> DUPLICATE', $tc->classify($epDupA)['state'], 'DUPLICATE');
    check('  → names the other episode', $tc->classify($epDupA)['duplicate_of'], $epDupB);

    // Exact duplicate, ADJACENT (a real two-part special) -> legitimate,
    // reported as VALID, never surfaced as a problem.
    $epSharedA = T_LO + 6; $epSharedB = T_LO + 7;
    $sharedBytes = makeJpeg(640, 360, [50, 200, 50]);
    $sharedHash = sha1($sharedBytes);
    foreach ([$epSharedA, $epSharedB] as $ep) {
        $f = $dir . '/ep' . str_pad((string)$ep, 3, '0', STR_PAD_LEFT) . '.jpg';
        file_put_contents($f, $sharedBytes);
        $db->prepare("INSERT INTO thumbnail_meta (episode_number, local_path, content_hash, width, height, bytes, status) VALUES (?, ?, ?, 640, 360, ?, 'ok')")
           ->execute([$ep, "/thumbnails/$year/ep" . str_pad((string)$ep, 3, '0', STR_PAD_LEFT) . '.jpg', $sharedHash, strlen($sharedBytes)]);
    }
    check('byte-identical image, ADJACENT episodes (a real shared special) -> VALID, not flagged',
        $tc->classify($epSharedA)['state'], 'VALID');

    // Near-duplicate: different bytes (different JPEG quality), same
    // pixels -> different content_hash, near-identical perceptual hash.
    $epNearA = T_LO + 9; $epNearB = T_LO + 10;
    $nearBytesA = makeJpeg(640, 360, [80, 80, 200], 90);
    $nearBytesB = makeJpeg(640, 360, [80, 80, 200], 60);
    $phashA = $tc->perceptualHash($nearBytesA);
    $phashB = $tc->perceptualHash($nearBytesB);
    foreach ([[$epNearA, $nearBytesA, $phashA], [$epNearB, $nearBytesB, $phashB]] as [$ep, $bytes, $phash]) {
        $f = $dir . '/ep' . str_pad((string)$ep, 3, '0', STR_PAD_LEFT) . '.jpg';
        file_put_contents($f, $bytes);
        $db->prepare("INSERT INTO thumbnail_meta (episode_number, local_path, content_hash, perceptual_hash, width, height, bytes, status) VALUES (?, ?, ?, ?, 640, 360, ?, 'ok')")
           ->execute([$ep, "/thumbnails/$year/ep" . str_pad((string)$ep, 3, '0', STR_PAD_LEFT) . '.jpg', sha1($bytes), $phash, strlen($bytes)]);
    }
    check('visually similar, NOT byte-identical images -> SUSPECT_DUPLICATE',
        $tc->classify($epNearA)['state'], 'SUSPECT_DUPLICATE');
    check('  → names the visually-similar episode', $tc->classify($epNearA)['duplicate_of'], $epNearB);

    // Clean up only what this file created.
    foreach ([$epBroken, $epInvalid, $epValid, $epDupA, $epDupB, $epSharedA, $epSharedB, $epNearA, $epNearB] as $ep) {
        $f = $dir . '/ep' . str_pad((string)$ep, 3, '0', STR_PAD_LEFT) . '.jpg';
        @unlink($f);
    }
    try { $db->exec("DELETE FROM thumbnail_meta WHERE episode_number BETWEEN " . T_LO . " AND " . T_HI); } catch (Throwable $e) {}
    $db->exec("DELETE FROM episodes WHERE episode_number BETWEEN " . T_LO . " AND " . T_HI);
}

echo "\n" . str_repeat('─', 62) . "\n";
printf("%s — %d passed, %d failed\n", $fail === 0 ? 'PR14 VERIFIED' : 'PR14 FAILURES', $pass, $fail);
exit($fail === 0 ? 0 : 1);
