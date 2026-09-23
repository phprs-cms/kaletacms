-- Média: složky (MiroCMS 2: rs_imggal_sekce) a evidence, ve kterých článcích je obrázek použitý.
CREATE TABLE rs_imggal_sekce (
    ids   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nazev VARCHAR(100) NOT NULL,
    PRIMARY KEY (ids)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
ALTER TABLE rs_imggal_obr ADD COLUMN sekce INT UNSIGNED NULL AFTER vlastnik, ADD KEY ix_imggal_sekce (sekce), ADD CONSTRAINT fk_imggal_sekce FOREIGN KEY (sekce) REFERENCES rs_imggal_sekce (ids) ON DELETE SET NULL;
CREATE TABLE rs_imggal_pouziti (
    ido INT UNSIGNED NOT NULL,
    idc INT UNSIGNED NOT NULL,
    PRIMARY KEY (ido, idc),
    KEY ix_pouziti_clanek (idc),
    CONSTRAINT fk_pouziti_obr FOREIGN KEY (ido) REFERENCES rs_imggal_obr (ido) ON DELETE CASCADE,
    CONSTRAINT fk_pouziti_clanek FOREIGN KEY (idc) REFERENCES rs_clanky (idc) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
