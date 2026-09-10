-- ============================================================
-- Running Man Archive — PR14 thumbnail recovery & quality
--
-- Run once in phpMyAdmin → runningman_archive → SQL tab, after
-- database/scraping_engine.sql. ADDITIVE ONLY: adds the perceptual_hash
-- column (and its index) that RmThumbnailEngine::perceptualHash()/
-- findNearDuplicate() need for near-duplicate (SUSPECT_DUPLICATE)
-- detection, for a database that installed scraping_engine.sql before
-- this PR. A fresh install already has this column from the updated
-- base file — this exists only for upgrading one that doesn't, exactly
-- like database/pr4_source_cleanup.sql and pr12_source_expansion.sql
-- did for their own additions.
--
-- Existing rows are untouched: perceptual_hash starts NULL for every
-- thumbnail already recorded (near-duplicate detection simply has
-- nothing to compare them against until they are next verified/
-- re-acquired, which recomputes it) — nothing here scans or re-downloads
-- a single existing thumbnail.
-- ============================================================

SET NAMES utf8mb4;

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'thumbnail_meta' AND COLUMN_NAME = 'perceptual_hash'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE thumbnail_meta ADD COLUMN perceptual_hash CHAR(16) NULL AFTER content_hash, ADD INDEX idx_phash (perceptual_hash)',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT 'PR14 thumbnail quality migration applied' AS status;
