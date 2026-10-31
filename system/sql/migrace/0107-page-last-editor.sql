-- 3.20 (C11): the Pages list shows who edited a page last, next to when (zmeneno). The person's id goes to zmenil wherever
-- zmeneno is written by a person's action (Modules\Pages::edited – the admin form, the text editor, bulk actions, a publish
-- from the builder or MCP, update_page); NULL = nobody yet (the starter pages of the installer, an import). First steps
-- count a page as prepared only when a person edited it (zmenil), because the installer stamps zmeneno since 3.20.
-- Existing pages take the last person from their history (the text versions and the published builds, the newer of the
-- two); a page edited only in its settings stays without a name. No foreign key: a removed account leaves the id, the list
-- then shows the date alone (like ka_protokol.kdo). The code of 3.19.2 does not read the column. A column that already
-- exists (an earlier attempt) is skipped by the migration runner (error 1060); the UPDATE fills only empty values, so a
-- second run changes nothing.
ALTER TABLE ka_stranky ADD COLUMN zmenil INT UNSIGNED NULL AFTER zmeneno;
UPDATE ka_stranky s SET s.zmenil = (
    SELECT x.kdo FROM (
        SELECT r.ids, r.kdo, r.datum FROM ka_stranky_revize r WHERE r.kdo IS NOT NULL
        UNION ALL
        SELECT v.ids, v.kdo, v.datum FROM ka_stavba_revize v WHERE v.ids IS NOT NULL AND v.kdo IS NOT NULL
    ) x WHERE x.ids = s.ids ORDER BY x.datum DESC LIMIT 1)
WHERE s.zmeneno IS NOT NULL AND s.zmenil IS NULL;
