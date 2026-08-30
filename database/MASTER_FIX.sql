-- ============================================================
-- MASTER FIX SQL — Run this in phpMyAdmin SQL tab
-- This handles EVERYTHING in correct order
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ── STEP 1: Delete junk episodes EP806-EP850 ─────────────────
DELETE FROM episode_tags   WHERE episode_id IN (SELECT episode_id FROM episodes WHERE episode_number > 805);
DELETE FROM episode_guests WHERE episode_id IN (SELECT episode_id FROM episodes WHERE episode_number > 805);
DELETE FROM thumbnails     WHERE episode_number > 805;
DELETE FROM episodes       WHERE episode_number > 805;

-- ── STEP 2: Fix year_id for all episodes (user-verified data) ─
-- 2010: EP1-25
UPDATE episodes SET year_id=(SELECT year_id FROM years WHERE year_label=2010) WHERE episode_number BETWEEN 1 AND 25;
-- 2011: EP26-74
UPDATE episodes SET year_id=(SELECT year_id FROM years WHERE year_label=2011) WHERE episode_number BETWEEN 26 AND 74;
-- 2012: EP75-126
UPDATE episodes SET year_id=(SELECT year_id FROM years WHERE year_label=2012) WHERE episode_number BETWEEN 75 AND 126;
-- 2013: EP127-178
UPDATE episodes SET year_id=(SELECT year_id FROM years WHERE year_label=2013) WHERE episode_number BETWEEN 127 AND 178;
-- 2014: EP179-227
UPDATE episodes SET year_id=(SELECT year_id FROM years WHERE year_label=2014) WHERE episode_number BETWEEN 179 AND 227;
-- 2015: EP228-279
UPDATE episodes SET year_id=(SELECT year_id FROM years WHERE year_label=2015) WHERE episode_number BETWEEN 228 AND 279;
-- 2016: EP280-331
UPDATE episodes SET year_id=(SELECT year_id FROM years WHERE year_label=2016) WHERE episode_number BETWEEN 280 AND 331;
-- 2017: EP332-382
UPDATE episodes SET year_id=(SELECT year_id FROM years WHERE year_label=2017) WHERE episode_number BETWEEN 332 AND 382;
-- 2018: EP383-433
UPDATE episodes SET year_id=(SELECT year_id FROM years WHERE year_label=2018) WHERE episode_number BETWEEN 383 AND 433;
-- 2019: EP434-485
UPDATE episodes SET year_id=(SELECT year_id FROM years WHERE year_label=2019) WHERE episode_number BETWEEN 434 AND 485;
-- 2020: EP486-537
UPDATE episodes SET year_id=(SELECT year_id FROM years WHERE year_label=2020) WHERE episode_number BETWEEN 486 AND 537;
-- 2021: EP538-589
UPDATE episodes SET year_id=(SELECT year_id FROM years WHERE year_label=2021) WHERE episode_number BETWEEN 538 AND 589;
-- 2022: EP590-634
UPDATE episodes SET year_id=(SELECT year_id FROM years WHERE year_label=2022) WHERE episode_number BETWEEN 590 AND 634;
-- 2023: EP635-685
UPDATE episodes SET year_id=(SELECT year_id FROM years WHERE year_label=2023) WHERE episode_number BETWEEN 635 AND 685;
-- 2024: EP686-733
UPDATE episodes SET year_id=(SELECT year_id FROM years WHERE year_label=2024) WHERE episode_number BETWEEN 686 AND 733;
-- 2025: EP734-783
UPDATE episodes SET year_id=(SELECT year_id FROM years WHERE year_label=2025) WHERE episode_number BETWEEN 734 AND 783;
-- 2026: EP784+
UPDATE episodes SET year_id=(SELECT year_id FROM years WHERE year_label=2026) WHERE episode_number >= 784;

-- ── STEP 3: Refresh year totals ───────────────────────────────
UPDATE years SET total_eps=(SELECT COUNT(*) FROM episodes WHERE year_id=years.year_id);

-- ── STEP 4: Fix thumbnail verified flag ───────────────────────
-- Placeholder paths (not actually downloaded) should be verified=0
UPDATE thumbnails SET verified=0 WHERE local_path LIKE '%/thumbnails/%' 
  AND NOT EXISTS (
    SELECT 1 FROM (
      SELECT thumbnail_id FROM thumbnails WHERE verified=1
    ) t WHERE t.thumbnail_id = thumbnails.thumbnail_id
  );
-- Reset ALL placeholder thumbnails to verified=0 (they haven't been downloaded yet)
UPDATE thumbnails SET verified=0;

-- ── STEP 5: Verify results ────────────────────────────────────
SELECT 
  COUNT(*) as total_episodes,
  MAX(episode_number) as latest_ep,
  MIN(episode_number) as first_ep,
  SUM(synopsis IS NOT NULL AND synopsis != '') as has_synopsis,
  SUM(title LIKE 'Episode #% - %') as has_real_title
FROM episodes;

SELECT y.year_label, COUNT(e.episode_id) as eps,
       MIN(e.episode_number) as first_ep, MAX(e.episode_number) as last_ep
FROM years y JOIN episodes e ON e.year_id=y.year_id
GROUP BY y.year_id ORDER BY y.year_label;

SET FOREIGN_KEY_CHECKS = 1;
