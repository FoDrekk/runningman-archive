<?php
// ============================================================
// RmFieldLock — manual protection against casual overwrite.
//
// A locked field (or a whole locked episode, field_name = '*') is
// never touched by research, no matter how confident the evidence: the
// decision is forced to KEEP before the normal thresholds are even
// consulted. This is the mechanism behind "manually verified data must
// be protected" — an editor locks a field once they've confirmed it by
// hand, and the engine leaves it alone from then on.
//
// Degrades to "nothing is locked" when the table is absent, like every
// other PR #4 feature.
// ============================================================

class RmFieldLock
{
    private static ?array $cache = null;

    private static function all(?PDO $db): array
    {
        if (self::$cache !== null) return self::$cache;
        self::$cache = [];
        if ($db === null) return self::$cache;
        try {
            $rows = $db->query('SELECT episode_number, field_name FROM field_locks')->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) self::$cache[(int)$r['episode_number']][(string)$r['field_name']] = true;
        } catch (Throwable $e) { /* table absent — nothing is locked */ }
        return self::$cache;
    }

    /** Reset the in-process cache — tests and long-running workers only. */
    public static function forget(): void { self::$cache = null; }

    public static function isLocked(?PDO $db, int $epNum, string $field): bool
    {
        $rows = self::all($db);
        return isset($rows[$epNum]['*']) || isset($rows[$epNum][$field]);
    }

    public static function lock(?PDO $db, int $epNum, string $field, ?string $by = null, ?string $reason = null): bool
    {
        if ($db === null) return false;
        try {
            $db->prepare(
                'INSERT INTO field_locks (episode_number, field_name, locked_by, reason)
                 VALUES (?,?,?,?)
                 ON DUPLICATE KEY UPDATE locked_by = VALUES(locked_by), reason = VALUES(reason)'
            )->execute([$epNum, $field, $by, $reason]);
            self::forget();
            return true;
        } catch (Throwable $e) { return false; }
    }

    public static function unlock(?PDO $db, int $epNum, string $field): bool
    {
        if ($db === null) return false;
        try {
            $db->prepare('DELETE FROM field_locks WHERE episode_number = ? AND field_name = ?')
               ->execute([$epNum, $field]);
            self::forget();
            return true;
        } catch (Throwable $e) { return false; }
    }

    /** @return array<string,array{locked_by:?string,reason:?string,locked_at:?string}> */
    public static function forEpisode(?PDO $db, int $epNum): array
    {
        if ($db === null) return [];
        try {
            $rows = $db->prepare('SELECT field_name, locked_by, reason, locked_at FROM field_locks WHERE episode_number = ?');
            $rows->execute([$epNum]);
            $out = [];
            foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[$r['field_name']] = ['locked_by' => $r['locked_by'], 'reason' => $r['reason'], 'locked_at' => $r['locked_at']];
            }
            return $out;
        } catch (Throwable $e) { return []; }
    }
}
