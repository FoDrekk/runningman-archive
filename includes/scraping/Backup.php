<?php
// ============================================================
// RmDatabaseBackup — a safety net before a bulk write (section 24).
//
// "Fill Missing", a bulk AI generation pass, or a large import can
// touch hundreds of rows in one run. Before one of those starts, this
// takes a plain mysqldump snapshot so the run has something to point
// back to if it needs undoing — the admin identifies which backup goes
// with which run by the label (the run's ref/id) in the filename.
//
// Optional and self-diagnosing, like everything else in this project:
// no `mysqldump` binary, `exec()` disabled by the host, or a
// write-protected directory all report themselves clearly rather than
// silently doing nothing or fataling the run that asked for a backup.
// ============================================================

class RmDatabaseBackup
{
    private static function dir(): string
    {
        $dir = __DIR__ . '/../../backups';
        if (!is_dir($dir)) @mkdir($dir, 0750, true);
        // Backups are database dumps, not public assets — a stray web
        // server config that doesn't already deny dotfiles/PHP-less
        // directories by default should still not serve these.
        $deny = $dir . '/.htaccess';
        if (!is_file($deny)) @file_put_contents($deny, "Require all denied\nDeny from all\n");
        return $dir;
    }

    public static function available(): bool
    {
        if (!function_exists('exec')) return false;
        $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
        if (in_array('exec', $disabled, true)) return false;
        exec('command -v mysqldump 2>/dev/null', $out, $code);
        return $code === 0 && !empty($out);
    }

    /**
     * @param string|null $label usually the run's ref/id, so the backup
     *                           can be matched back to the operation that
     *                           triggered it
     */
    public static function create(?string $label = null): array
    {
        if (!self::available()) {
            return ['ok' => false, 'reason' => 'mysqldump is not available on this host — exec() disabled or the binary is missing'];
        }
        $dir = self::dir();
        if (!is_writable($dir)) {
            return ['ok' => false, 'reason' => "Backup directory is not writable: $dir"];
        }

        $safeLabel = $label !== null ? preg_replace('/[^a-zA-Z0-9_-]/', '', $label) : 'manual';
        $filename  = 'backup-' . ($safeLabel ?: 'manual') . '-' . date('Ymd-His') . '.sql';
        $path      = $dir . '/' . $filename;

        // Credentials go through a temp defaults file, never the command
        // line — a password on argv is visible to every other process on
        // the box via `ps`.
        $iniPath = tempnam(sys_get_temp_dir(), 'rmdb');
        file_put_contents($iniPath, "[client]\nhost=" . DB_HOST . "\nuser=" . DB_USER . "\npassword=" . DB_PASS . "\n");
        chmod($iniPath, 0600);

        $cmd = 'mysqldump --defaults-extra-file=' . escapeshellarg($iniPath) . ' '
             . '--single-transaction --quick ' . escapeshellarg(DB_NAME)
             . ' > ' . escapeshellarg($path) . ' 2>' . escapeshellarg($path . '.err');
        exec($cmd, $out, $code);
        @unlink($iniPath);

        $err = @file_get_contents($path . '.err') ?: '';
        @unlink($path . '.err');

        if ($code !== 0 || !is_file($path) || filesize($path) < 100) {
            @unlink($path);
            return ['ok' => false, 'reason' => 'mysqldump failed: ' . mb_substr(trim($err), 0, 300)];
        }

        return [
            'ok' => true, 'path' => $path, 'filename' => $filename,
            'size' => filesize($path), 'label' => $safeLabel, 'created_at' => date('c'),
        ];
    }

    /** @return array<int,array{filename:string,size:int,created_at:string}> newest first */
    public static function list(int $limit = 50): array
    {
        $dir = self::dir();
        $files = glob($dir . '/backup-*.sql') ?: [];
        usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
        return array_map(fn($f) => [
            'filename' => basename($f), 'size' => filesize($f),
            'created_at' => date('c', filemtime($f)),
        ], array_slice($files, 0, $limit));
    }
}
