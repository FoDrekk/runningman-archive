-- ============================================================
-- Running Man Archive — PR #4 source cleanup
--
-- Run once in phpMyAdmin → runningman_archive → SQL tab, after
-- database/research_engine.sql. ADDITIVE ONLY: it seeds reputation
-- rows for the two sources PR #4 adds (TheTVDB, KShow123) so they are
-- never scored from an empty row, and removes the reputation history
-- of adapters PR #4 deleted (MyDramaList, TMDB, Wikidata, AsianWiki),
-- which would otherwise sit in the table forever describing sources
-- that no longer exist and can never run again.
--
-- Nothing about the episodes/guests/thumbnails archive tables is
-- touched. Safe to run more than once.
-- ============================================================

SET NAMES utf8mb4;

DELETE FROM source_reputation WHERE source_name IN ('mydramalist', 'tmdb', 'wikidata', 'asianwiki');
DELETE FROM source_health     WHERE source_name IN ('mydramalist', 'tmdb', 'wikidata', 'asianwiki');

INSERT IGNORE INTO source_reputation (source_name, field_name, reputation) VALUES
    ('tvdb', '*', 65),
    ('kshow123', '*', 55);
INSERT IGNORE INTO source_health (source_name, status) VALUES
    ('tvdb', 'unknown'),
    ('kshow123', 'unknown');

-- IMDb stays registered (diagnostics/verification only — see
-- config/scraping.php `sources.imdb.verification_only`); its history is
-- kept, not deleted, since it is still contacted and still corroborates.

SELECT 'PR #4 source cleanup applied' AS status;
