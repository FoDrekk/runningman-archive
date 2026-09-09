<?php
// ============================================================
// RmThumbnailEngine — acquire, verify and store episode images.
//
// The old download path trusted HTTP 200 and a substring check on
// Content-Type. That accepts an HTML error page served as
// "image/jpeg", a 40×40 tracking pixel, and a site logo — all of
// which then sit in the archive looking like real thumbnails.
//
// This version validates the BYTES: real image, real dimensions,
// not HTML, not a placeholder; hashes the content so an identical
// image is never re-downloaded or re-encoded; keeps the source URL
// and verification state; and falls back through every candidate
// source in field-priority order before giving up.
// ============================================================
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/scraping.php';
require_once __DIR__ . '/Http.php';
require_once __DIR__ . '/DataValidator.php';
require_once __DIR__ . '/Provenance.php';

class RmThumbnailEngine
{
    private ?PDO $db;
    private RmHttpClient $http;

    public function __construct(?PDO $db = null, ?RmHttpClient $http = null)
    {
        try { $this->db = $db ?: getDBSafe(); } catch (Throwable $e) { $this->db = null; }
        $this->http = $http ?: RmHttpClient::instance();
    }

    private function metaReady(): bool
    {
        if ($this->db === null) return false;
        try { $this->db->query('SELECT 1 FROM thumbnail_meta LIMIT 1'); return true; }
        catch (Throwable $e) { return false; }
    }

    /**
     * Try each candidate URL until one produces a real image.
     * @param array $candidates [['url'=>..., 'source'=>...], ...] in priority order
     * @return array{ok:bool, path:?string, source:?string, url:?string, reason:?string,
     *                width:?int, height:?int, hash:?string, skipped:bool, attempts:array}
     */
    public function acquire(int $epNum, int $year, array $candidates, array $opt = []): array
    {
        $attempts = [];
        $existing = $this->existingMeta($epNum);

        foreach ($candidates as $cand) {
            $url    = is_array($cand) ? ($cand['url'] ?? null) : $cand;
            $source = is_array($cand) ? ($cand['source'] ?? 'unknown') : 'unknown';
            if (!is_string($url) || $url === '') continue;

            $verdict = RmValidator::imageUrl($url);
            if (!$verdict['valid']) { $attempts[] = ['source'=>$source,'url'=>$url,'ok'=>false,'reason'=>$verdict['reason']]; continue; }
            $url = $verdict['value'];

            // Already have this exact URL stored and verified on disk?
            if ($existing && $existing['source_url'] === $url && $this->fileOk($existing['local_path'] ?? null)
                && ($existing['status'] ?? '') === 'ok' && empty($opt['force'])) {
                return ['ok'=>true,'path'=>$existing['local_path'],'source'=>$existing['source_name'],'url'=>$url,
                        'reason'=>'Unchanged — same source URL already stored','width'=>(int)($existing['width']??0),
                        'height'=>(int)($existing['height']??0),'hash'=>$existing['content_hash'],'skipped'=>true,'attempts'=>$attempts];
            }

            $res = $this->http->get($url, [
                'timeout' => 25, 'retries' => 1, 'delay_ms' => 300,
                'headers' => ['Accept: image/avif,image/webp,image/jpeg,image/png,*/*;q=0.8'],
            ]);
            if (!$res->ok) {
                $attempts[] = ['source'=>$source,'url'=>$url,'ok'=>false,'reason'=>$res->error,'class'=>$res->errorClass];
                continue;
            }

            $check = RmValidator::imageBytes($res->body, $res->contentType);
            if (!$check['valid']) {
                $attempts[] = ['source'=>$source,'url'=>$url,'ok'=>false,'reason'=>$check['reason']];
                continue;
            }

            $hash = sha1((string)$res->body);
            // Identical bytes to what we already stored — nothing to do.
            if ($existing && ($existing['content_hash'] ?? null) === $hash && $this->fileOk($existing['local_path'] ?? null) && empty($opt['force'])) {
                $this->touch($epNum);
                return ['ok'=>true,'path'=>$existing['local_path'],'source'=>$source,'url'=>$url,
                        'reason'=>'Identical image already stored — download skipped',
                        'width'=>(int)$check['width'],'height'=>(int)$check['height'],'hash'=>$hash,
                        'skipped'=>true,'attempts'=>$attempts];
            }

            $dup = $this->findDuplicate($hash, $epNum);
            $stored = $this->store($epNum, $year, (string)$res->body, $check);
            $saved  = $stored['path'];
            if ($saved === null) {
                $attempts[] = ['source'=>$source,'url'=>$url,'ok'=>false,'reason'=>'Could not write the image to disk'];
                continue;
            }

            $this->recordMeta($epNum, [
                'source_name'  => $source,
                'source_url'   => $url,
                'local_path'   => $saved,
                'content_hash' => $hash,
                'width'        => (int)$check['width'],
                'height'       => (int)$check['height'],
                'bytes'        => strlen((string)$res->body),
                'content_type' => (string)($check['mime'] ?? $res->contentType),
                'status'       => $dup ? 'duplicate' : 'ok',
            ]);
            if ($dup) {
                // Shared images happen legitimately (a two-part special),
                // so this is a flag for a human, never an auto-deletion.
                (new RmProvenance($this->db))->flag('duplicate_thumbnail', 'episode', null, $epNum,
                    "Thumbnail bytes are identical to EP$dup — one of the two may be wrong");
            }

            $attempts[] = ['source'=>$source,'url'=>$url,'ok'=>true,'reason'=>null];
            $reason = match (true) {
                (bool)$dup            => "Saved, but identical to EP$dup — flagged for review",
                !$stored['changed']   => 'Identical to the stored image — file left untouched',
                default               => null,
            };
            return ['ok'=>true,'path'=>$saved,'source'=>$source,'url'=>$url,
                    'reason'=>$reason,
                    'width'=>(int)$check['width'],'height'=>(int)$check['height'],'hash'=>$hash,
                    'skipped'=>!$stored['changed'],'attempts'=>$attempts];
        }

        // Every candidate failed. Say what was tried and why each failed —
        // and leave any existing thumbnail exactly where it is.
        $reason = $attempts
            ? 'All ' . count($attempts) . ' thumbnail candidates failed: ' .
              implode('; ', array_map(fn($a) => ($a['source'] ?? '?') . ' — ' . ($a['reason'] ?? 'unknown'), array_slice($attempts, 0, 3)))
            : 'No thumbnail candidates offered by any source';
        if ($existing) $this->recordMeta($epNum, ['status' => $this->fileOk($existing['local_path'] ?? null) ? 'ok' : 'broken']);
        return ['ok'=>false,'path'=>null,'source'=>null,'url'=>null,'reason'=>$reason,
                'width'=>null,'height'=>null,'hash'=>null,'skipped'=>false,'attempts'=>$attempts];
    }

    /** Re-check a stored thumbnail: file present, decodable, right size. */
    public function verify(int $epNum): array
    {
        $meta = $this->existingMeta($epNum);
        $path = $meta['local_path'] ?? $this->pathFromDb($epNum);
        if (!$path) return ['ok'=>false,'reason'=>'No thumbnail recorded for this episode','status'=>'missing'];

        $abs = $this->absolutePath($path);
        if (!$abs || !is_file($abs)) {
            $this->recordMeta($epNum, ['status'=>'broken']);
            return ['ok'=>false,'reason'=>"File missing on disk: $path",'status'=>'broken'];
        }
        $bytes = @file_get_contents($abs);
        $check = RmValidator::imageBytes($bytes === false ? null : $bytes, '');
        if (!$check['valid']) {
            $this->recordMeta($epNum, ['status'=>'broken']);
            return ['ok'=>false,'reason'=>$check['reason'],'status'=>'broken'];
        }
        $this->recordMeta($epNum, [
            'status'=>'ok','width'=>(int)$check['width'],'height'=>(int)$check['height'],
            'bytes'=>strlen((string)$bytes),'content_hash'=>sha1((string)$bytes),'local_path'=>$path,
        ]);
        return ['ok'=>true,'reason'=>null,'status'=>'ok','width'=>(int)$check['width'],'height'=>(int)$check['height']];
    }

    /**
     * Resize/crop and store at thumbnails/{year}/epNNN.jpg.
     *
     * The encode happens in memory and the file is written only when the
     * result actually differs from what is already there. Two reasons:
     * "don't re-save an identical thumbnail" then works whether or not
     * thumbnail_meta has been installed, and an unchanged image no
     * longer churns its mtime on every verification pass.
     *
     * @return array{path:?string, changed:bool}
     */
    private function store(int $epNum, int $year, string $bytes, array $check): array
    {
        $dir    = __DIR__ . '/../../thumbnails/' . $year;
        $padded = str_pad((string)$epNum, 3, '0', STR_PAD_LEFT);
        $file   = $dir . '/ep' . $padded . '.jpg';
        $web    = (function_exists('bp') ? bp() : '') . '/thumbnails/' . $year . '/ep' . $padded . '.jpg';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) return ['path' => null, 'changed' => false];

        $cfg = rmScrapeConfig('thumbnail');
        $targetW = (int)$cfg['target_w']; $targetH = (int)$cfg['target_h'];
        $encoded = null;

        if (extension_loaded('gd')) {
            $src = @imagecreatefromstring($bytes);
            if ($src) {
                $srcW = imagesx($src); $srcH = imagesy($src);
                // Never upscale past the source: that only blurs a small
                // image, it cannot add detail that was never there.
                $w = min($targetW, max($srcW, 854));
                $h = (int)round($w * $targetH / $targetW);

                // Crop-to-fit keeps the real aspect ratio instead of
                // squashing a portrait source into 16:9.
                $dstRatio = $w / $h; $srcRatio = $srcW / max(1, $srcH);
                if ($srcRatio > $dstRatio) { $cropH = $srcH; $cropW = (int)round($srcH * $dstRatio); $cropX = (int)(($srcW - $cropW) / 2); $cropY = 0; }
                else                        { $cropW = $srcW; $cropH = (int)round($srcW / $dstRatio); $cropX = 0; $cropY = (int)(($srcH - $cropH) / 2); }

                $dst = imagecreatetruecolor($w, $h);
                imagecopyresampled($dst, $src, 0, 0, $cropX, $cropY, $w, $h, $cropW, $cropH);
                ob_start();
                imagejpeg($dst, null, (int)$cfg['jpeg_quality']);
                $encoded = ob_get_clean();
                imagedestroy($src); imagedestroy($dst);
            }
        }
        // No GD, or GD could not decode this format — keep the original.
        if ($encoded === null || $encoded === '') $encoded = $bytes;

        $existing = is_file($file) ? @file_get_contents($file) : false;
        if ($existing !== false && $existing === $encoded) {
            return ['path' => $web, 'changed' => false];
        }

        if (@file_put_contents($file, $encoded) === false) return ['path' => null, 'changed' => false];
        return (is_file($file) && filesize($file) > 1000)
            ? ['path' => $web, 'changed' => true]
            : ['path' => null, 'changed' => false];
    }

    /**
     * Web/DB path (e.g. "/runningman_archive/thumbnails/2026/ep810.jpg",
     * with or without a BASE_PATH prefix) → real filesystem path.
     *
     * Deliberately NOT $_SERVER['DOCUMENT_ROOT'] + $webPath: DOCUMENT_ROOT
     * is only correct when the vhost root exactly equals this app's
     * parent folder, which XAMPP subfolder installs, reverse proxies and
     * per-vhost DocumentRoot overrides all routinely violate. Anchoring
     * on __DIR__ instead resolves relative to where these PHP files
     * actually live on disk, which is true regardless of how the web
     * server maps URLs — and works identically on Windows, since PHP's
     * filesystem functions accept forward slashes on every platform.
     */
    public function absolutePath(?string $webPath): ?string
    {
        if (!$webPath) return null;
        if (preg_match('~/thumbnails/(\d{4})/(ep\d+\.[a-z0-9]+)$~i', $webPath, $m)) {
            return __DIR__ . '/../../thumbnails/' . $m[1] . '/' . $m[2];
        }
        return null;
    }

    private function fileOk(?string $webPath): bool
    {
        $abs = $this->absolutePath($webPath);
        return $abs !== null && is_file($abs) && filesize($abs) > 1000;
    }

    private function pathFromDb(int $epNum): ?string
    {
        if ($this->db === null) return null;
        try {
            $s = $this->db->prepare('SELECT local_path FROM thumbnails WHERE episode_number=? LIMIT 1');
            $s->execute([$epNum]);
            $p = $s->fetchColumn();
            return $p ?: null;
        } catch (Throwable $e) { return null; }
    }

    public function existingMeta(int $epNum): ?array
    {
        if (!$this->metaReady()) return null;
        try {
            $s = $this->db->prepare('SELECT * FROM thumbnail_meta WHERE episode_number=?');
            $s->execute([$epNum]);
            $r = $s->fetch();
            return $r ?: null;
        } catch (Throwable $e) { return null; }
    }

    private function recordMeta(int $epNum, array $fields): void
    {
        if (!$this->metaReady() || !$fields) return;
        $allowed = ['source_name','source_url','local_path','content_hash','width','height','bytes','content_type','status'];
        $cols = array_values(array_intersect(array_keys($fields), $allowed));
        if (!$cols) return;
        try {
            $placeholders = implode(',', array_fill(0, count($cols) + 1, '?'));
            $updates = implode(', ', array_map(fn($c) => "$c=VALUES($c)", $cols));
            $params = [$epNum];
            foreach ($cols as $c) $params[] = $fields[$c];
            $this->db->prepare(
                'INSERT INTO thumbnail_meta (episode_number, ' . implode(',', $cols) . ', last_checked_at) ' .
                "VALUES ($placeholders, NOW()) ON DUPLICATE KEY UPDATE $updates, last_checked_at=NOW()"
            )->execute($params);
        } catch (Throwable $e) { }
    }

    private function touch(int $epNum): void
    {
        if (!$this->metaReady()) return;
        try { $this->db->prepare('UPDATE thumbnail_meta SET last_checked_at=NOW() WHERE episode_number=?')->execute([$epNum]); }
        catch (Throwable $e) { }
    }

    /**
     * Classify a group of episodes that share one byte-identical image
     * (they were already grouped by exact content_hash, so "are these
     * the same file" is settled — this answers "is that legitimate"):
     *
     *   PLACEHOLDER_DUPLICATE    tiny file/dimensions — a generic
     *                            placeholder or missing-image graphic,
     *                            not a real per-episode photo
     *   LEGITIMATE_SHARED_IMAGE  a short run of adjacent episode numbers
     *                            — the real "two-part special shares its
     *                            key art" case
     *   LIKELY_WRONG_EPISODE     neither of the above — the case that
     *                            actually warrants a human's review
     *
     * Never deletes anything itself — purely a label for admin/thumbnails.php
     * to show as a repair suggestion.
     *
     * @param int[] $episodeNumbers
     */
    public static function classifyDuplicate(array $episodeNumbers, ?int $width, ?int $height, ?int $bytes): string
    {
        $eps = array_values(array_unique(array_map('intval', $episodeNumbers)));
        sort($eps);
        $span = $eps ? max($eps) - min($eps) : 0;
        $tinyFile  = $bytes !== null && $bytes > 0 && $bytes < 5000;
        $tinyImage = $width !== null && $height !== null && $width > 0 && $width < 200 && $height < 200;
        return match (true) {
            $tinyFile || $tinyImage => 'PLACEHOLDER_DUPLICATE',
            count($eps) === ($span + 1) && $span <= 2 => 'LEGITIMATE_SHARED_IMAGE',
            default => 'LIKELY_WRONG_EPISODE',
        };
    }

    /** Another episode already stores byte-identical image data. */
    private function findDuplicate(string $hash, int $exceptEp): ?int
    {
        if (!$this->metaReady()) return null;
        try {
            $s = $this->db->prepare('SELECT episode_number FROM thumbnail_meta WHERE content_hash=? AND episode_number<>? LIMIT 1');
            $s->execute([$hash, $exceptEp]);
            $n = $s->fetchColumn();
            return $n ? (int)$n : null;
        } catch (Throwable $e) { return null; }
    }

    /** Write the acquired image into the existing thumbnails/episodes tables. */
    public function link(int $epNum, string $webPath, ?string $sourceUrl): bool
    {
        if ($this->db === null) return false;
        try {
            $this->db->prepare(
                'INSERT INTO thumbnails (episode_number, local_path, thumbnail_url, verified) VALUES (?,?,?,1)
                 ON DUPLICATE KEY UPDATE local_path=VALUES(local_path), thumbnail_url=VALUES(thumbnail_url), verified=1'
            )->execute([$epNum, $webPath, $sourceUrl]);
            $this->db->prepare(
                'UPDATE episodes SET thumbnail_id=(SELECT thumbnail_id FROM thumbnails WHERE episode_number=? LIMIT 1)
                  WHERE episode_number=?'
            )->execute([$epNum, $epNum]);
            return true;
        } catch (Throwable $e) { return false; }
    }

    /** Episodes whose thumbnail should be re-checked. */
    public function needingVerification(int $limit = 100): array
    {
        if ($this->db === null) return [];
        $days = (int)rmScrapeConfig('thumbnail.recheck_days', 45);
        try {
            if ($this->metaReady()) {
                $sql = "SELECT e.episode_number FROM episodes e
                          LEFT JOIN thumbnail_meta m ON m.episode_number = e.episode_number
                         WHERE m.episode_number IS NULL
                            OR m.status <> 'ok'
                            OR m.last_checked_at IS NULL
                            OR m.last_checked_at < DATE_SUB(NOW(), INTERVAL $days DAY)
                         ORDER BY e.episode_number DESC LIMIT " . max(1, min(1000, $limit));
            } else {
                $sql = "SELECT e.episode_number FROM episodes e
                          LEFT JOIN thumbnails t ON t.thumbnail_id = e.thumbnail_id
                         WHERE t.thumbnail_id IS NULL OR t.verified = 0
                         ORDER BY e.episode_number DESC LIMIT " . max(1, min(1000, $limit));
            }
            return array_map('intval', $this->db->query($sql)->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable $e) { return []; }
    }
}
