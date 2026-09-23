-- Galerie obrázků (MiroCMS 2: rs_imggal_obr). Soubory leží ve složce media/RRRR/MM/.
CREATE TABLE rs_imggal_obr (
    ido         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    vlastnik    INT UNSIGNED NULL,
    nazev       VARCHAR(150) NOT NULL DEFAULT '',          -- slouží i jako alternativní text (alt)
    popis       VARCHAR(500) NOT NULL DEFAULT '',          -- popisek pod obrázkem
    obr_poloha  VARCHAR(255) NOT NULL,                     -- cesta od kořene webu: media/2026/09/foto.jpg
    obr_width   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    obr_height  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    obr_vel     INT UNSIGNED NOT NULL DEFAULT 0,           -- velikost souboru v bajtech
    nahl_poloha VARCHAR(255) NOT NULL DEFAULT '',
    nahl_width  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    nahl_height SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    datum       DATETIME NOT NULL,
    PRIMARY KEY (ido),
    KEY ix_imggal_datum (datum),
    CONSTRAINT fk_imggal_vlastnik FOREIGN KEY (vlastnik) REFERENCES rs_user (idu) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
