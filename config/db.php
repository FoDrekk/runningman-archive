<?php
// ============================================================
// Running Man Archive — Database Config
// Auto-detects BASE_PATH for XAMPP subfolder installs
// ============================================================

// PHP defaults to UTC unless configured otherwise in php.ini.
// Set explicitly here so every date()/time() call across the
// whole app (timestamps, cron logs, activity log, "last checked")
// shows correct Malaysia local time instead of UTC.
date_default_timezone_set('Asia/Kuala_Lumpur');

if (!defined('BASE_PATH')) {
    $docRoot  = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
    $selfPath = str_replace('\\', '/', dirname(__DIR__));
    $webPath  = str_replace($docRoot, '', $selfPath);
    define('BASE_PATH', $webPath === '' ? '' : '/' . trim($webPath, '/'));
}

define('DB_HOST', 'localhost');
define('DB_NAME', 'runningman_archive');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHAR', 'utf8mb4');

function getDB(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    try {
        $pdo = new PDO(
            'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset='.DB_CHAR,
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
             PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
             PDO::ATTR_EMULATE_PREPARES => false]
        );
    } catch (PDOException $e) {
        die('<div style="font-family:sans-serif;padding:2rem;background:#080c12;color:#fca5a5">
            <strong>Database Error:</strong> '.$e->getMessage().'<br><br>
            Make sure MySQL is running and database <code>runningman_archive</code> exists.
        </div>');
    }
    return $pdo;
}
