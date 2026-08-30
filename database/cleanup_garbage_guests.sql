-- ============================================================
-- One-time cleanup: remove garbage guest rows that leaked from
-- Wikipedia collapsible/{{hidden}} templates (the EP272-area
-- 100-vs-100 athlete specials).
-- Run once in phpMyAdmin -> runningman_archive -> SQL tab.
--
-- WHY: before the guest-parser fix, large-cast special episodes
-- whose guest cell used a {{hidden}} template leaked raw CSS and
-- template markup into the guests table, producing fake "guests"
-- like:
--   "Jung Doo-hongKim Ki-taeLee Won-heeNoh Ji-simTaemi
--    [ko].mw-parser-output .hidden-begin{box-sizing:border-box;...}"
-- The parser now strips/splits these, but rows already stored need
-- removing. This deletes the episode_guests links first (FK-safe),
-- then the orphaned garbage guest rows themselves.
--
-- Matches ONLY rows that contain markup/CSS signatures or are
-- absurdly long — real romanized names never contain { } < > ;,
-- "mw-parser", "box-sizing", etc., and are short. Genuine guests
-- are left untouched. After running this, re-sync the affected
-- episodes to repopulate them with clean names.
-- ============================================================

-- 1) Remove the episode<->guest links pointing at garbage guests.
DELETE eg FROM episode_guests eg
JOIN guests g ON g.guest_id = eg.guest_id
WHERE g.name_romanized REGEXP '[{}<>;]'
   OR g.name_romanized LIKE '%mw-parser%'
   OR g.name_romanized LIKE '%box-sizing%'
   OR g.name_romanized LIKE '%padding%'
   OR g.name_romanized LIKE '%hidden-begin%'
   OR g.name_romanized LIKE '%.mw-%'
   OR CHAR_LENGTH(g.name_romanized) > 60;

-- 2) Delete the garbage guest rows themselves.
DELETE FROM guests
WHERE name_romanized REGEXP '[{}<>;]'
   OR name_romanized LIKE '%mw-parser%'
   OR name_romanized LIKE '%box-sizing%'
   OR name_romanized LIKE '%padding%'
   OR name_romanized LIKE '%hidden-begin%'
   OR name_romanized LIKE '%.mw-%'
   OR CHAR_LENGTH(name_romanized) > 60;

SELECT ROW_COUNT() AS garbage_guest_rows_deleted;
