<?php
// ============================================================
// Running Man Archive — System Stability Helpers
// Lock mechanism · Activity logging · Persistent sync state ·
// Health checks for external data sources
//
// DESIGN RULE: nothing in this file may fatal-error a page.
// If the stability tables (sync_state / activity_log) haven't
// been created yet, every function degrades to a safe default
// instead of throwing — admin pages stay usable and show a
// one-line setup notice instead of a blank screen.
// ============================================================
require_once __DIR__ . '/../config/db.php';

// ── One-time cached check: do the stability tables exist? ────
function stabilityTablesExist(): bool {
    static $exists = null;
    if ($exists !== null) return $exists;
    try {
        $db = getDB();
        $db->query("SELECT 1 FROM sync_state LIMIT 1");
        $db->query("SELECT 1 FROM activity_log LIMIT 1");
        $exists = true;
    } catch (Exception $e) {
        $exists = false;
    }
    return $exists;
}

// ── Persistent key-value state (survives refresh / browser close) ──
function stateGet(string $key, $default = null) {
    if (!stabilityTablesExist()) return $default;
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT state_value FROM sync_state WHERE state_key=?");
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        return $val === false ? $default : $val;
    } catch (Exception $e) {
        return $default;
    }
}

function stateSet(string $key, $value): bool {
    if (!stabilityTablesExist()) return false;
    try {
        $db = getDB();
        $db->prepare("INSERT INTO sync_state (state_key, state_value) VALUES (?,?)
                      ON DUPLICATE KEY UPDATE state_value=VALUES(state_value)")
           ->execute([$key, (string)$value]);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

// ── Lock mechanism — prevent two operations running together ──
// Degrades to "always unlocked" if tables are missing, so sync/cron
// still WORK (just without the double-run protection) rather than crash.
function lockAcquire(string $lockName, int $maxAgeSeconds = 1800): bool {
    if (!stabilityTablesExist()) return true; // can't track locks, but don't block the action
    try {
        $lockKey = $lockName . '_lock';
        $tsKey   = $lockName . '_started_at';
        $current   = stateGet($lockKey, '0');
        $startedAt = stateGet($tsKey, '');

        if ($current === '1' && $startedAt) {
            $age = time() - strtotime($startedAt);
            if ($age < $maxAgeSeconds) return false; // genuinely locked
        }
        stateSet($lockKey, '1');
        stateSet($tsKey, date('Y-m-d H:i:s'));
        return true;
    } catch (Exception $e) {
        return true;
    }
}

function lockRelease(string $lockName): void {
    if (!stabilityTablesExist()) return;
    try { stateSet($lockName . '_lock', '0'); } catch (Exception $e) {}
}

function lockIsHeld(string $lockName, int $maxAgeSeconds = 1800): bool {
    if (!stabilityTablesExist()) return false;
    try {
        $current = stateGet($lockName . '_lock', '0');
        if ($current !== '1') return false;
        $startedAt = stateGet($lockName . '_started_at', '');
        if (!$startedAt) return false;
        return (time() - strtotime($startedAt)) < $maxAgeSeconds;
    } catch (Exception $e) {
        return false;
    }
}

// ── Activity log — queryable, replaces plain-text cron.log ──
function logActivity(string $actionType, ?int $epNum, string $status, string $message = '', int $durationMs = 0): void {
    if (!stabilityTablesExist()) return;
    try {
        $db = getDB();
        $db->prepare("INSERT INTO activity_log (action_type, episode_number, status, message, duration_ms) VALUES (?,?,?,?,?)")
           ->execute([$actionType, $epNum, $status, mb_substr($message, 0, 500), $durationMs]);
    } catch (Exception $e) {
        @file_put_contents(sys_get_temp_dir().'/rm_log_fallback.txt', date('c')." LOG FAIL: ".$e->getMessage()."\n", FILE_APPEND);
    }
}

function getRecentActivity(int $limit = 30, ?string $actionType = null): array {
    if (!stabilityTablesExist()) return [];
    try {
        $db = getDB();
        if ($actionType) {
            $stmt = $db->prepare("SELECT * FROM activity_log WHERE action_type=? ORDER BY log_id DESC LIMIT ?");
            $stmt->bindValue(1, $actionType);
            $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        } else {
            $stmt = $db->prepare("SELECT * FROM activity_log ORDER BY log_id DESC LIMIT ?");
            $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        }
        $stmt->execute();
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

function getActivityStats(int $hours = 24): array {
    if (!stabilityTablesExist()) return [];
    try {
        $db = getDB();
        $stmt = $db->prepare("
            SELECT action_type, status, COUNT(*) as cnt
            FROM activity_log
            WHERE created_at > DATE_SUB(NOW(), INTERVAL ? HOUR)
            GROUP BY action_type, status
        ");
        $stmt->execute([$hours]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

function getRecentFailures(int $days = 7, int $limit = 20): array {
    if (!stabilityTablesExist()) return [];
    try {
        $db = getDB();
        $stmt = $db->prepare("
            SELECT * FROM activity_log
            WHERE status='failed' AND created_at > DATE_SUB(NOW(), INTERVAL ? DAY)
            ORDER BY log_id DESC LIMIT ?
        ");
        $stmt->bindValue(1, $days, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

// ── External source health check — ping each data source, measure latency ──
function checkSourceHealth(string $name, string $url, int $timeout = 5): array {
    $start = microtime(true);
    $code = 0; $err = null;
    try {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_NOBODY         => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,      // hard cap per source — keeps the 3-source
            CURLOPT_CONNECTTIMEOUT => 3,              // total well under PHP's execution limit
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
        ]);
        $ok = curl_exec($ch);
        if ($ok === false) $err = curl_error($ch) ?: 'curl request failed';
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    } catch (Throwable $e) {
        $code = 0; $err = $e->getMessage();
    }
    $ms  = round((microtime(true) - $start) * 1000);
    $ok2 = $code >= 200 && $code < 400;
    return ['name'=>$name,'url'=>$url,'ok'=>$ok2,'code'=>$code,'ms'=>$ms,'error'=>$ok2?null:($err?:"HTTP $code")];
}

function runHealthCheck(): array {
    $results = [
        checkSourceHealth('Wikipedia API',     'https://en.wikipedia.org/w/api.php?action=query&format=json'),
        checkSourceHealth('myrm.tv',           'https://myrm.tv/'),
        checkSourceHealth('myrunningman.com',  'https://www.myrunningman.com/'),
        checkSourceHealth('MyDramaList',       'https://mydramalist.com/25565-running-man'),
    ];
    $start = microtime(true);
    try { getDB()->query('SELECT 1'); $dbOk = true; $dbErr = null; }
    catch (Exception $e) { $dbOk = false; $dbErr = $e->getMessage(); }
    $results[] = ['name'=>'MySQL Database','url'=>'localhost','ok'=>$dbOk,'code'=>$dbOk?200:0,'ms'=>round((microtime(true)-$start)*1000),'error'=>$dbErr];
    if (stabilityTablesExist()) stateSet('last_health_check', date('Y-m-d H:i:s'));
    return $results;
}
