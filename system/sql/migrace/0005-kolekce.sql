-- Kolekce: vlastní typy obsahu (reference, tým, produkty, pobočky…). pole = JSON [{klic, popisek, typ}], typ: text | radky | html | obrazek | odkaz | cislo | datum.
-- detail = položky mají vlastní stránku /<seo_link>/<seo položky> se šablonou ze stavitele (stavba, stavba_koncept).
CREATE TABLE mc_kolekce (
    idk            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nazev          VARCHAR(100) NOT NULL,
    seo_link       VARCHAR(110) NOT NULL,
    pole           TEXT NOT NULL,
    detail         TINYINT(1) NOT NULL DEFAULT 0,
    stavba         MEDIUMTEXT NULL,
    stavba_koncept MEDIUMTEXT NULL,
    zmeneno        DATETIME NULL,
    PRIMARY KEY (idk),
    UNIQUE KEY ux_kolekce_seo (seo_link)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE mc_kolekce_polozky (
    idp      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    idk      INT UNSIGNED NOT NULL,
    nazev    VARCHAR(200) NOT NULL,
    seo_link VARCHAR(160) NOT NULL,
    data     MEDIUMTEXT NOT NULL,
    poradi   INT NOT NULL DEFAULT 100,
    zobrazit TINYINT(1) NOT NULL DEFAULT 1,
    jazyk    CHAR(2) NOT NULL DEFAULT '',
    datum    DATETIME NOT NULL,
    zmeneno  DATETIME NULL,
    PRIMARY KEY (idp),
    UNIQUE KEY ux_kolekce_polozky_seo (idk, seo_link),
    KEY ix_kolekce_polozky (idk, zobrazit, poradi),
    CONSTRAINT fk_kolekce_polozky FOREIGN KEY (idk) REFERENCES mc_kolekce (idk) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
