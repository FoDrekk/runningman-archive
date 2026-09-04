<?php
// Admin Config — no password (localhost only)
require_once __DIR__ . '/../includes/json_api.php';

define('ADMIN_SESSION_KEY', 'rm_admin');

// ── Arm the JSON guard for AJAX requests ──────────────────────
// Every admin response the browser parses as JSON is identified here,
// in one place, rather than at each of the ~30 handler branches spread
// over ten files — one missed branch is all it takes to reproduce
// `Unexpected token '<', "<br /><b>"...`, and a missed branch is
// invisible until it happens in production.
//
// Deliberately NOT armed for admin/export.php (?type=…), which streams
// a file download rather than an AJAX payload.
if (
    isset($_GET['a'])                                   // auto_sync, diagnostics, fetch, health, scraper, thumbnails
    || isset($_GET['s'])                                // dashboard stats
    || (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
        && in_array($_POST['action'] ?? '', ['save', 'import_row'], true))
) {
    rmJsonBegin();
}

function adminAuth(): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION[ADMIN_SESSION_KEY] = true;
}

function adminCheck(): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION[ADMIN_SESSION_KEY])) {
        $_SESSION[ADMIN_SESSION_KEY] = true; // auto-login localhost
    }
}
