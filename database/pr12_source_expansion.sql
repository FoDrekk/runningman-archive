-- ============================================================
-- Running Man Archive — PR12 source expansion
--
-- Run once in phpMyAdmin → runningman_archive → SQL tab, after
-- database/research_engine.sql. ADDITIVE ONLY: seeds source_health and
-- source_reputation rows for the two sources PR12 adds (Running Man
-- Wiki/Fandom, TVmaze) so they are never scored from an empty row on a
-- database that installed research_engine.sql/scraping_engine.sql
-- before this PR. A fresh install already has these rows from the
-- updated base files — this file exists only for upgrading one that
-- doesn't, exactly like database/pr4_source_cleanup.sql did for TheTVDB
-- and KShow123.
--
-- Nothing about the episodes/guests/thumbnails archive tables is
-- touched. Safe to run more than once.
-- ============================================================

SET NAMES utf8mb4;

INSERT IGNORE INTO source_reputation (source_name, field_name, reputation) VALUES
    ('fandom', '*', 50),
    ('tvmaze', '*', 50);
INSERT IGNORE INTO source_health (source_name, status) VALUES
    ('fandom', 'unknown'),
    ('tvmaze', 'unknown');

SELECT 'PR12 source expansion applied' AS status;
