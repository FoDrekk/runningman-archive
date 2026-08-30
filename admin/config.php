<?php
// Admin Config — no password (localhost only)
define('ADMIN_SESSION_KEY', 'rm_admin');

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
