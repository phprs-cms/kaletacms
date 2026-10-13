## 3.14 – work stream B: tags per language version, export format 3

- **Tags get a language (data model §3, the owner's answer 5 – expand).** Migration 0095 adds `ka_stitky.jazyk`
  (`VARCHAR(35)` ASCII, `''` = the default language, like pages, news and categories since 0083) and the key
  `uq_stitky_jazyk_seo (jazyk, seo_link)` next to the global `uq_stitky_seo`. Tags are a fourth table of `Core\Slug::TABLES`,
  so "The same address in every language" drops and restores their global key with the others and refuses to switch off
  while two versions share a tag slug; a site that already had the setting on loses the global tag key in the migration
  itself (a conditional `PREPARE` in 0095). A tag created from a news item (admin, MCP, WordPress and platform imports –
  one helper `Admin\Modules\Tags::findOrCreate`) gets the item's language; the lookup is per version only with the
  setting on, so without it an English item reuses the Czech tag of the same name, as before. The tag page
  (`Front\Kernel::tag`) prefers the tag of the version shown and falls back to any (every tag from before is `''`).
  Tags and topics show the language and link to the tag's own version; the export and import carry the column.
  No MCP tool lists tags, so no MCP contract changed. 4.0: drop `uq_stitky_seo` with the other global keys.
- **Export format 3 (data model §4).** `Core\ExportFormat` defines it: the format-2 structure with English keys from
  `system/sql/names.php` – header `format_version`, `created`, `settings`; tables under their English names; the columns
  the map lists under theirs (others keep their names); a news item's `autor` → `author`, `stitky` → `tag_ids`. Values do
  not change (wall time, Czech build JSON). `SiteImport` reads formats up to 3 by turning format 3 into the format-2 shape
  while splitting. `SiteExport::create($db, $settings, 3)` writes it on request only; the default stays 2, so a 3.14
  export still imports into older 3.x. 4.0: switch the default to 3, keep the format-2 reader; 4.1: instants with `Z`.
- Tests: unit tests for the mapping (every exported table round-trips with no key collision, the reader gives the same
  files for format 2, streamed format 3 and hand-formatted format 3); test.sh: tags with the same slug in two versions
  (off: shared as before; on: two tags, two pages; the switch-off refusal), the admin tag list, and export 3 → clean
  install = export 2 → clean install (counts and hashes of the main rows); test-migrations: 0095 on a site with the
  setting on.
