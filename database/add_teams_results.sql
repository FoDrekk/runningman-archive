-- ============================================================
-- Add teams + results columns to episodes
-- Run this once in phpMyAdmin → runningman_archive → SQL tab
--
-- WHY THIS FILE NEEDED TO EXIST:
-- includes/scraper.php (rmWikiParseHtml, rmScrapeEpisode) and
-- admin/auto_sync.php both already read and try to SAVE Wikipedia's
-- "Teams" / "Results" table columns into episodes.teams / episodes.results.
-- includes/functions.php even has a defensive helper
-- (rmTeamsResultsColumnsExist) specifically to detect whether this
-- migration has been run yet, and several code comments reference
-- "database/add_teams_results.sql" by name — but the file itself was
-- never created. Net effect: every sync silently extracted team/result
-- data from Wikipedia and then threw it away, because there was no
-- column to put it in. This file is that missing migration.
-- ============================================================

SET NAMES utf8mb4;

ALTER TABLE episodes
    ADD COLUMN IF NOT EXISTS teams   VARCHAR(300) NULL AFTER main_mission,
    ADD COLUMN IF NOT EXISTS results VARCHAR(300) NULL AFTER teams;

SELECT 'teams/results columns added' AS status;
