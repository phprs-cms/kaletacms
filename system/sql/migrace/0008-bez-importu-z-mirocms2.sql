-- Import ze starého MiroCMS 2 se nepodporuje: mizí sloupce a tabulky, které existovaly jen kvůli němu.
ALTER TABLE rs_clanky DROP KEY uq_clanky_link, DROP COLUMN link, DROP COLUMN znacky;
DROP TABLE rs_alias;
DROP TABLE rs_moduly;
