-- Štítky článků, historie verzí článku a statické stránky (O nás, Kontakt...).
CREATE TABLE rs_stitky (
    ids      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nazev    VARCHAR(80) NOT NULL,
    seo_link VARCHAR(100) NOT NULL,
    PRIMARY KEY (ids),
    UNIQUE KEY uq_stitky_seo (seo_link)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
CREATE TABLE rs_clanky_stitky (
    idc INT UNSIGNED NOT NULL,
    ids INT UNSIGNED NOT NULL,
    PRIMARY KEY (idc, ids),
    KEY ix_clanky_stitky_stitek (ids),
    CONSTRAINT fk_cs_clanek FOREIGN KEY (idc) REFERENCES rs_clanky (idc) ON DELETE CASCADE,
    CONSTRAINT fk_cs_stitek FOREIGN KEY (ids) REFERENCES rs_stitky (ids) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
CREATE TABLE rs_clanky_revize (
    idr     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    idc     INT UNSIGNED NOT NULL,
    datum   DATETIME NOT NULL,
    kdo     INT UNSIGNED NULL,
    titulek VARCHAR(255) NOT NULL,
    uvod    MEDIUMTEXT NOT NULL,
    text    MEDIUMTEXT NOT NULL,
    PRIMARY KEY (idr),
    KEY ix_revize_clanek (idc, datum),
    CONSTRAINT fk_revize_clanek FOREIGN KEY (idc) REFERENCES rs_clanky (idc) ON DELETE CASCADE,
    CONSTRAINT fk_revize_kdo FOREIGN KEY (kdo) REFERENCES rs_user (idu) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
CREATE TABLE rs_stranky (
    ids      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    seo_link VARCHAR(120) NOT NULL,
    titulek  VARCHAR(200) NOT NULL,
    popis    VARCHAR(300) NOT NULL DEFAULT '',           -- meta description
    text     MEDIUMTEXT NOT NULL,
    zobrazit BOOL NOT NULL DEFAULT 1,
    v_menu   BOOL NOT NULL DEFAULT 1,                     -- odkaz v patičce / navigaci webu
    poradi   SMALLINT UNSIGNED NOT NULL DEFAULT 100,
    zmeneno  DATETIME NULL,
    PRIMARY KEY (ids),
    UNIQUE KEY uq_stranky_seo (seo_link)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
