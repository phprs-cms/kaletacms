-- SEO a GEO u článku, přesměrování starých adres, evidence souhlasů s cookies.
ALTER TABLE rs_clanky
    ADD COLUMN seo_titulek VARCHAR(255) NOT NULL DEFAULT '' AFTER t_slova,
    ADD COLUMN seo_popis VARCHAR(320) NOT NULL DEFAULT '' AFTER seo_titulek,
    ADD COLUMN noindex BOOL NOT NULL DEFAULT 0 AFTER seo_popis,
    ADD COLUMN shrnuti TEXT NULL AFTER noindex,
    ADD COLUMN faq TEXT NULL AFTER shrnuti;
CREATE TABLE rs_presmerovani (
    idp       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    z_adresy  VARCHAR(255) NOT NULL,                     -- cesta na webu bez úvodního lomítka: clanek/stara-adresa
    na_adresu VARCHAR(255) NOT NULL,                     -- cesta na webu, nebo celá adresa https://...
    pocet     INT UNSIGNED NOT NULL DEFAULT 0,           -- kolikrát bylo přesměrování použito
    vytvoreno DATETIME NOT NULL,
    PRIMARY KEY (idp),
    UNIQUE KEY uq_presmerovani (z_adresy)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
CREATE TABLE rs_souhlasy (
    ids         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_souhlasu CHAR(32) NOT NULL,                       -- náhodný identifikátor uložený v cookie návštěvníka
    cas         DATETIME NOT NULL,
    kategorie   VARCHAR(60) NOT NULL,                    -- "analytika,marketing" nebo "nic"
    PRIMARY KEY (ids),
    KEY ix_souhlasy_cas (cas),
    KEY ix_souhlasy_id (id_souhlasu)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
