-- Převládající barva obrázku (podklad, než se fotka načte) a rychlé dohledání obrázku podle cesty.
ALTER TABLE rs_imggal_obr ADD COLUMN barva CHAR(7) NOT NULL DEFAULT '' AFTER nahl_height, ADD KEY ix_imggal_poloha (obr_poloha);
