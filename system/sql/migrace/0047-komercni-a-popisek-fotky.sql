-- Označení komerčního sdělení u článku a popisek s autorem hlavní fotky; autor fotky i v knihovně médií.
ALTER TABLE rs_clanky
    ADD COLUMN komercni BOOL NOT NULL DEFAULT 0 AFTER externi_autor,
    ADD COLUMN komercni_partner VARCHAR(120) NOT NULL DEFAULT '' AFTER komercni,
    ADD COLUMN obrazek_popis VARCHAR(300) NOT NULL DEFAULT '' AFTER obrazek,
    ADD COLUMN obrazek_autor VARCHAR(120) NOT NULL DEFAULT '' AFTER obrazek_popis;

ALTER TABLE rs_imggal_obr
    ADD COLUMN autor VARCHAR(120) NOT NULL DEFAULT '' AFTER popis;
