-- Části webu ze stavitele (záhlaví, patička, obálky novinky, výpisu a 404) a jejich verze.
CREATE TABLE mc_casti (
    typ            VARCHAR(20) NOT NULL,
    jazyk          CHAR(2) NOT NULL DEFAULT '',
    stavba         MEDIUMTEXT NULL,
    stavba_koncept MEDIUMTEXT NULL,
    zmeneno        DATETIME NULL,
    PRIMARY KEY (typ, jazyk)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

ALTER TABLE mc_stavba_revize
    MODIFY ids INT UNSIGNED NULL,
    ADD COLUMN cast VARCHAR(30) NULL AFTER ids,
    ADD KEY ix_stavba_revize_cast (cast, idr);
