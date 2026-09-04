<?php
// ============================================================
// tests/fixtures/json_probe.php — deliberately misbehaving JSON
// endpoints, used to prove includes/json_api.php actually holds.
//
// Each mode reproduces one real way an admin endpoint has corrupted,
// or could corrupt, its own response:
//   warning  a notice printed before the body (the reported bug:
//            `Unexpected token '<', "<br /><b>"...`)
//   stray    a handler that echoes something before its JSON
//   fatal    an uncaught Error — no return path runs at all
//   throw    an exception escaping the handler
//   both     a warning AND stray output together
//   clean    the control case
//
// Lives under tests/, not admin/, so it is never part of the app's
// reachable surface.
// ============================================================
require_once __DIR__ . '/../../includes/json_api.php';

$mode = $_GET['mode'] ?? 'clean';

// ?guard=0 reproduces the BEFORE state: the same handler with no guard,
// which is what produced `Unexpected token '<', "<br /><b>"...` in the
// control centre. Kept so the test can prove the fix is the fix rather
// than assert it.
if (($_GET['guard'] ?? '1') !== '0') {
    rmJsonBegin();
} else {
    @ini_set('display_errors', '1');
    @ini_set('html_errors', '1');
}

switch ($mode) {
    case 'warning':
        $arr = ['present' => 1];
        $x = $arr['missing_key'];        // E_WARNING: Undefined array key
        echo json_encode(['ok' => true, 'mode' => 'warning']);
        exit;

    case 'stray':
        echo "<br /><b>Notice</b>: something printed before the body<br />\n";
        echo json_encode(['ok' => true, 'mode' => 'stray']);
        exit;

    case 'fatal':
        rmJsonOutNoSuchFunction();       // E_ERROR: undefined function

    case 'throw':
        throw new RuntimeException('handler blew up');

    case 'both':
        $arr = [];
        $y = $arr['nope'];               // warning
        echo "leaked text ";             // stray
        rmJsonOut(['ok' => true, 'mode' => 'both']);

    case 'via_out':
        $arr = [];
        $z = $arr['nope'];
        rmJsonOut(['ok' => true, 'mode' => 'via_out']);

    default:
        rmJsonOut(['ok' => true, 'mode' => 'clean']);
}
