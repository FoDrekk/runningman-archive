-- ============================================================
-- STEP 1: Delete wrong episodes (EP806-EP850 yang tak wujud lagi)
-- ============================================================
DELETE FROM episode_tags   WHERE episode_id IN (SELECT episode_id FROM episodes WHERE episode_number > 805);
DELETE FROM episode_guests WHERE episode_id IN (SELECT episode_id FROM episodes WHERE episode_number > 805);
DELETE FROM thumbnails     WHERE episode_number > 805;
DELETE FROM episodes       WHERE episode_number > 805;

-- Verify
SELECT COUNT(*) as total, MAX(episode_number) as latest FROM episodes;
