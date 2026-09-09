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

// ── The research layer (PR #4) ────────────────────────────────
// Sits on top of the engine rather than beside it: evidence,
// explainable decisions, per-episode research memory and the
// persistent run that the Auto Sync command centre drives.
require_once __DIR__ . '/SourceReputation.php';
require_once __DIR__ . '/ResearchState.php';
require_once __DIR__ . '/ResearchRun.php';
require_once __DIR__ . '/Evidence.php';
require_once __DIR__ . '/Decision.php';
require_once __DIR__ . '/Anomaly.php';
require_once __DIR__ . '/Discovery.php';
require_once __DIR__ . '/FieldLock.php';
require_once __DIR__ . '/GroundingValidator.php';
require_once __DIR__ . '/AiReasoning.php';
require_once __DIR__ . '/AiSynopsis.php';
require_once __DIR__ . '/DiagnosticReport.php';
require_once __DIR__ . '/Backup.php';
require_once __DIR__ . '/ResearchService.php';

/** Shared engine instance for page code. */
function rmEngine(): RmScrapingEngine {
    static $e = null;
    return $e ?: ($e = new RmScrapingEngine());
}

/** Shared research service for page code. */
function rmResearch(): RmResearchService {
    static $r = null;
    return $r ?: ($r = new RmResearchService(null, rmEngine()));
}

/**
 * Remove SQL line comments outside string literals.
 *
 * Deliberately not a regex: `--` is only a comment when it is not inside
 * a quoted string, and a regex that ignores quoting will happily gut a
 * value like 'a -- b'. These files are plain DDL, so a single pass
 * tracking ' " and ` is enough.
 */
function rmStripSqlComments(string $sql): string {
    $out = ''; $len = strlen($sql); $quote = null;
    for ($i = 0; $i < $len; $i++) {
        $c = $sql[$i];
        if ($quote !== null) {
            $out .= $c;
            if ($c === '\\' && $i + 1 < $len) { $out .= $sql[++$i]; continue; }
            if ($c === $quote) $quote = null;
            continue;
        }
        if ($c === "'" || $c === '"' || $c === '`') { $quote = $c; $out .= $c; continue; }
        if ($c === '-' && ($sql[$i + 1] ?? '') === '-') {
            // Runs to the end of the line; keep the newline so line-based
            // formatting (and any error line numbers) stay meaningful.
            while ($i < $len && $sql[$i] !== "\n") $i++;
            $out .= "\n";
            continue;
        }
        $out .= $c;
    }
    return $out;
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

    // Strip comments so they can't be mistaken for statements — INCLUDING
    // trailing ones. A "-- comma-separated; empty = everything" at the end
    // of a column definition puts a semicolon inside a comment, and the
    // naive split below then cuts the CREATE TABLE in half and reports a
    // syntax error pointing at the comment text. Quote state is tracked so
    // a legitimate "--" inside a string literal survives.
    $clean = rmStripSqlComments($sql);

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
