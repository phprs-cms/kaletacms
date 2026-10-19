# 3.15 – work stream B: the bridge to 4.0 (roadmap note)

- **Both table names.** 3.15 runs on today's tables and on the English names 4.0 gives them (`system/sql/names.php`,
  design §4 option C), so it is the release 4.0 rolls back to. `Core\Db` assumes today's names and keeps a marker file in
  `storage/cache` (`tables-english-<hash>`) only while the database is renamed: no query on a normal site. A statement that
  misses a table (MySQL 1146) makes it look once at `information_schema` (the settings table under either name; today's
  wins when both exist), update the marker and retry the statement once – after a rename or a rollback the site notices
  by itself. `Names::physical()` gives the existing name, `Names::table()` the name 3.x code knows a table by (journal,
  export, backup). Migrations write the files' Czech names as English ones on a renamed database; a 4.0 database rolled
  back has a higher `db_version`, so nothing runs again. A backup restore drops the renamed twin of each table it
  restores (the rollback from 4.0 leaves no English copy) and looks at the names again. Raw names in core go through
  `Db::tableName()`; a unit test forbids `ka_<table>` in code strings and `prefix . $table`. Ready for 4.0 says which
  names the tables have. `tools/rename-tables.php` renames a test site; `tools/test.sh` proves front, sign-in, admin,
  MCP and migrations on both names, there and back.
- **4.0 on its own update source.** Each major line has its own manifest: `aktualizace.json` is 3.x for good (sites before
  3.15 read only it, so they first get 3.15), `aktualizace-4.json` is 4.x (`Updater::manifestFile`, `lineUrl`). 3.15
  looks it up with the regular check (12 h, also on 404) and shows "Kaleta 4.0 is available – read what changes, back
  up, then update" on Backups and updates and System status (Ready for 4.0), MCP `get_health.update.major_available`.
  Never installed by itself (not as a security release, not by the fleet console): only *Back up and update to 4.0.0*,
  with a confirmation, from the newest 3.x, after a fresh backup. The regular update offers and installs only its own
  major (defence in depth). `tools/release.php` writes `aktualizace-4.json` for a 4.x version (`--major` confirms X.0.0);
  the daily check fails if `aktualizace.json` offers a major above 3 and checks `aktualizace-4.json`. docs/RELEASING.md,
  "Major versions".
- **Prompt to update.** No support window: when a newer release of the line has waited 14 days (`Updater::BEHIND_DAYS`,
  since first seen or its signed date), the dashboard shows a quiet warning with *Update*. No e-mail.

For 4.0-rc: `Updater::url()` already maps the configured `…/aktualizace.json` to the line's own file, so 4.0 reads
`aktualizace-4.json` with no change; the 4.0 rename migration must be one `RENAME TABLE` (all or nothing) and the 4.0
code must keep resolving both placeholders; keep re-signing the last 3.15.x in `aktualizace.json` (freshness, 60 days);
the rollback drill = restore the pre-update backup (twins dropped) or keep the renamed database and put 3.15 back.
