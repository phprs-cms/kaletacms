-- Pop-up okna jako části webu: vlastní stavba v builderu, spouštěč, pravidla zobrazení, četnost a počitadla bez cookies.
CREATE TABLE IF NOT EXISTS ka_popupy (
    idpp           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nazev          VARCHAR(100) NOT NULL,
    adresa         VARCHAR(60)  NOT NULL,
    typ            VARCHAR(20)  NOT NULL DEFAULT 'okno',
    spoustec       VARCHAR(20)  NOT NULL DEFAULT 'cas',
    hodnota        SMALLINT UNSIGNED NOT NULL DEFAULT 5,
    pravidla       TEXT NOT NULL,
    cetnost        VARCHAR(20)  NOT NULL DEFAULT 'relace',
    dni            SMALLINT UNSIGNED NOT NULL DEFAULT 7,
    aktivni        TINYINT(1) NOT NULL DEFAULT 0,
    poradi         SMALLINT NOT NULL DEFAULT 100,
    stavba         MEDIUMTEXT NULL,
    stavba_koncept MEDIUMTEXT NULL,
    zobrazeni      INT UNSIGNED NOT NULL DEFAULT 0,
    zavreni        INT UNSIGNED NOT NULL DEFAULT 0,
    konverze       INT UNSIGNED NOT NULL DEFAULT 0,
    zmeneno        DATETIME NULL,
    PRIMARY KEY (idpp),
    UNIQUE KEY ux_popupy_adresa (adresa)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
