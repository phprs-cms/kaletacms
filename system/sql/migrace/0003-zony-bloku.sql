-- Bloky se místo do číslovaných sloupců zařazují do pojmenovaných zón stránky:
-- hlavicka, leva, nad (nad obsahem), pod (pod obsahem), prava, paticka.
-- "Hlavní blok" (hlb) mizí - obsah stránky má v rozvržení pevné místo.
ALTER TABLE rs_bloky ADD COLUMN zona VARCHAR(20) NOT NULL DEFAULT 'prava' AFTER zobrazit_kde;
UPDATE rs_bloky SET zona = 'pod';
UPDATE rs_bloky SET zona = 'leva' WHERE id_sloupec = (SELECT MIN(ids) FROM rs_sloupce);
UPDATE rs_bloky SET zona = 'prava' WHERE id_sloupec = (SELECT MAX(ids) FROM rs_sloupce) AND (SELECT COUNT(*) FROM rs_sloupce) > 1;
DELETE FROM rs_bloky WHERE sys_funkce = 'hlb';
ALTER TABLE rs_bloky DROP FOREIGN KEY fk_bloky_sloupec;
ALTER TABLE rs_bloky DROP KEY ix_bloky_sloupec, DROP COLUMN id_sloupec, ADD KEY ix_bloky_zona (zona, hodnost);
DROP TABLE rs_sloupce;
