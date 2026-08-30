-- ============================================================
-- One-time cleanup: remove synopses that merely DUPLICATE the
-- main_mission (the EP807 pattern).
-- Run once in phpMyAdmin -> runningman_archive -> SQL tab.
--
-- WHY: before the dedup fix, the scraper copied Wikipedia's terse
-- "Mission" text into the synopsis column whenever no real
-- description was scraped. That made the on-page "Description" box
-- restate the "Main Mission" row word-for-word. The code no longer
-- does this, but rows synced earlier still carry the duplicated
-- text. This nulls out synopsis ONLY where it is effectively
-- identical to main_mission, so:
--   * the Description box falls back to the live, non-redundant
--     auto-summary (or N/A) instead of echoing the mission, and
--   * the episode reappears in the "incomplete" list so a future
--     sync can try to fetch a real description for it.
--
-- It is intentionally conservative: it leaves any synopsis that
-- differs from the mission (i.e. a genuine description) untouched.
-- ============================================================

UPDATE episodes
SET synopsis = NULL
WHERE synopsis IS NOT NULL
  AND main_mission IS NOT NULL
  AND TRIM(TRAILING '.' FROM TRIM(synopsis)) = TRIM(TRAILING '.' FROM TRIM(main_mission));

-- Also clear the older synthesized summaries that began with the
-- mission prefix "The mission: ..." which likewise just restated it.
UPDATE episodes
SET synopsis = NULL
WHERE synopsis LIKE 'The mission:%'
  AND main_mission IS NOT NULL
  AND synopsis LIKE CONCAT('The mission: %', TRIM(TRAILING '.' FROM TRIM(main_mission)), '%');

SELECT ROW_COUNT() AS rows_cleaned_in_last_statement;
