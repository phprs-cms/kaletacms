-- Stavitel stránek: stavba stránky (publikovaná a koncept), verze staveb a sdílené třídy.
ALTER TABLE rs_stranky ADD COLUMN stavba MEDIUMTEXT NULL, ADD COLUMN stavba_koncept MEDIUMTEXT NULL;

CREATE TABLE rs_stavba_revize (
    idr    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    ids    INT UNSIGNED NOT NULL,
    datum  DATETIME NOT NULL,
    kdo    INT UNSIGNED NULL,
    stavba MEDIUMTEXT NOT NULL,
    PRIMARY KEY (idr),
    KEY ix_stavba_revize (ids, idr),
    CONSTRAINT fk_stavba_revize_stranka FOREIGN KEY (ids) REFERENCES rs_stranky (ids) ON DELETE CASCADE,
    CONSTRAINT fk_stavba_revize_kdo FOREIGN KEY (kdo) REFERENCES rs_user (idu) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE rs_tridy (
    nazev  VARCHAR(60) NOT NULL,
    styl   TEXT NOT NULL,
    css    TEXT NULL,
    zmeneno DATETIME NULL,
    PRIMARY KEY (nazev)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
