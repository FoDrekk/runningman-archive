# Manual Import — EP1-15 & EP734-783

Two ready-to-import CSV files are in this folder:

- `import_ep1-15_myrm.csv` — episodes 1-15 (from MyRM)
  Columns: ep_number, title, location, air_date, guests, tags, synopsis
- `import_ep734-783_wikipedia.csv` — episodes 734-783, 2025 (from Wikipedia)
  Columns: ep_number, title, air_date, guests, teams, main_mission, results, is_special, special_type

## Before importing
Run `database/add_teams_results.sql` once in phpMyAdmin if you haven't —
otherwise the Teams/Result columns won't exist and those fields are skipped.

## How to import (per file)
1. Admin -> Import
2. Drop the CSV (or Choose File)
3. Step 2 auto-maps the columns. The upgraded importer now recognises
   location, location_country, tags, teams, and results in addition to the
   original fields. Double-check the mapping dropdowns look right.
4. Choose import mode:
   - "Update existing" — only touches episodes already in the DB (recommended)
   - "Create + update" — also inserts episodes that don't exist yet
5. Start Import.

## Replace behaviour (important)
- Scalar fields (title, synopsis, mission, teams, results, location, etc.)
  OVERWRITE the existing value whenever the CSV cell is non-empty. An empty
  CSV cell leaves the existing value untouched.
- Guests and Tags now fully REPLACE the episode's existing set: the old
  links are deleted first, then exactly what's in the CSV cell is added.
  (This also clears any earlier garbage guest rows for that episode.)

## Notes on the data
- EP1-15 titles double as the location for those early episodes (that's how
  MyRM labels them), so both `title` and `location` are filled from it.
- EP734-783 "results" text is condensed from Wikipedia's much longer prose;
  it captures the winner/penalty outcome, not every sentence.
- The unnumbered May 18 2025 "Special" between EP752 and EP753 is omitted
  because it has no episode number to import against.
- EP780 ("Isn't this the real deal 2~") had no teams/mission/results filled
  in the Wikipedia source, so only title + air_date are set for it.
