<?php
// ============================================================
// Scraping engine bootstrap — one require for the whole subsystem.
//
//   require_once __DIR__ . '/bootstrap.php';
//
// Load order matters only in that config comes first; everything
// below guards its own requires, so including this file twice, or
// including a single component directly, both work.
// ============================================================

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/scraping.php';

// ── Episode → broadcast year (user-verified boundaries) ────────
// Defined here rather than in a scraper so every layer (cache TTLs,
// thumbnail paths, validators, adapters) can use it without pulling
// in the legacy scraper file.
if (!function_exists('rmYear')) {
    function rmYear(int $n): int {
        if ($n <= 25)  return 2010; if ($n <= 74)  return 2011;
        if ($n <= 126) return 2012; if ($n <= 178) return 2013;
        if ($n <= 227) return 2014; if ($n <= 279) return 2015;
        if ($n <= 331) return 2016; if ($n <= 382) return 2017;
        if ($n <= 433) return 2018; if ($n <= 485) return 2019;
        if ($n <= 537) return 2020; if ($n <= 589) return 2021;
        if ($n <= 634) return 2022; if ($n <= 685) return 2023;
        if ($n <= 733) return 2024; if ($n <= 783) return 2025;
        return 2026;
    }
}

require_once __DIR__ . '/Cache.php';
require_once __DIR__ . '/Http.php';
require_once __DIR__ . '/DataNormalizer.php';
require_once __DIR__ . '/DataValidator.php';
require_once __DIR__ . '/FieldResolver.php';
require_once __DIR__ . '/DiffEngine.php';
require_once __DIR__ . '/Provenance.php';
require_once __DIR__ . '/SourceHealth.php';
require_once __DIR__ . '/RunLog.php';
require_once __DIR__ . '/SourceRegistry.php';
require_once __DIR__ . '/ThumbnailEngine.php';
require_once __DIR__ . '/MissingData.php';
require_once __DIR__ . '/Integrity.php';
require_once __DIR__ . '/ScrapingEngine.php';

/** Shared engine instance for page code. */
function rmEngine(): RmScrapingEngine {
    static $e = null;
    return $e ?: ($e = new RmScrapingEngine());
}

/**
 * Execute a .sql migration file statement by statement.
 *
 * Uses query() + closeCursor() rather than exec(): these files end with a
 * "SELECT '… created' AS status" line, and exec() leaves that result set
 * open, so the following statement — or the very next query anywhere in
 * the request — dies with "Cannot execute queries while other unbuffered
 * queries are active". Draining each result keeps the connection usable.
 *
 * Splitting is deliberately simple because these files are plain DDL with
 * no stored procedures, triggers or DELIMITER blocks. If that ever changes,
 * this needs a real parser rather than a smarter regex.
 *
 * @return array{ok:bool, executed:int, errors:string[]}
 */
function rmRunSqlFile(PDO $db, string $path): array {
    $sql = @file_get_contents($path);
    if ($sql === false) return ['ok' => false, 'executed' => 0, 'errors' => ["Could not read $path"]];

    // Strip full-line comments so they can't be mistaken for statements.
    $lines = [];
    foreach (preg_split('/\r?\n/', $sql) as $line) {
        if (preg_match('/^\s*--/', $line)) continue;
        $lines[] = $line;
    }
    $clean = implode("\n", $lines);

    $executed = 0; $errors = [];
    foreach (explode(';', $clean) as $stmt) {
        $stmt = trim($stmt);
        if ($stmt === '') continue;
        try {
            $result = $db->query($stmt);
            if ($result instanceof PDOStatement) { $result->fetchAll(); $result->closeCursor(); }
            $executed++;
        } catch (Throwable $e) {
            $errors[] = mb_substr($e->getMessage(), 0, 300);
        }
    }
    return ['ok' => $errors === [], 'executed' => $executed, 'errors' => $errors];
}
