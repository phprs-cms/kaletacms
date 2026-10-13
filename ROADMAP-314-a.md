### 3.14 – work stream A: Ready for 4.0 (readiness check, deprecation log, redirect sources)

- **Ready for 4.0 on System status** (`Core\Readiness`, its own section under the checks table, kept out of `Health::checks`
  so monitoring and the fleet console do not turn amber for a release that is not out): database server (MySQL 8.0+ /
  MariaDB 10.6+), rights of the database user (`SHOW GRANTS FOR CURRENT_USER`: ALTER, CREATE, DROP, INSERT on `*.*` or
  the schema, patterns and `ALL PRIVILEGES` understood; "could not check" when the grants are hidden or a role is
  involved), add-ons and leftover layouts that write old table names (bounded read-only scan of `extensions/` and `layout/`
  – `.php/.phtml/.inc/.sql`, `vendor/` skipped, 2,000 files / 20 MB – plus the runtime log below), tags used by news
  items in several languages (information), redirect sources that collide once compared exactly (list of up to 50
  groups, advice to delete or merge), slugs shared across languages (information). Every check is read-only.
- **Claude:** `get_health` gains `ready_for_4_0` (`status` ok | warning, `checks` with a stable `check` key, `status`
  ok | warning | info, `detail`, `items`); the existing keys are unchanged, no new tool.
- **Deprecation log of old table names** (`Core\LegacyNames`): with add-ons switched on, `Db::sql()` notes a Czech
  placeholder of a table 4.0 renames (`Names::isLegacy`) and, through a short backtrace, the file that wrote it; only
  files outside `system/`, `tools/` and the root scripts are logged, once per file, name and day, in
  `storage/log/legacy-table-names.json` (30 days, 200 files). Without add-ons the hot path costs one static flag check.
  `Db::insert/update/delete` keep the name the caller wrote in the placeholder, so `'news'` is never logged as legacy.
- **Redirect sources normalised (design §1.3, expand):** migration 0094 adds `ka_presmerovani.source_norm`
  (VARCHAR(255) utf8mb4_bin NULL, non-unique key `ix_presmerovani_norm`); the background job `redirect_sources`
  (`Core\RedirectSources::fill`, batches of 500) fills it for existing and later rows with the lookup's normal form
  (`RedirectRules::normalizeFrom` + lower case; language prefix and accents kept). Lookups still use `z_adresy`.
- **Left for 3.15/4.0:** look redirects up by `source_norm`, make its key unique after the collisions are resolved,
  write `source_norm` on insert (today the job fills it within a minute); tags' language column.
