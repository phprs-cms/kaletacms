-- Reklamní systém: bannery a reklamní kódy přiřazené k pozicím na webu.
CREATE TABLE rs_reklama (
    idr           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nazev         VARCHAR(150) NOT NULL,                  -- interní název (klient, kampaň)
    pozice        VARCHAR(40) NOT NULL,                   -- sloupec | hlavicka | pod-clankem | paticka
    typ           VARCHAR(10) NOT NULL DEFAULT 'obrazek', -- obrazek | kod
    obrazek       VARCHAR(255) NOT NULL DEFAULT '',
    cil_url       VARCHAR(500) NOT NULL DEFAULT '',
    kod           MEDIUMTEXT NULL,                        -- HTML/JS reklamní sítě
    platna_od     DATETIME NULL,
    platna_do     DATETIME NULL,
    aktivni       BOOL NOT NULL DEFAULT 1,
    vaha          TINYINT UNSIGNED NOT NULL DEFAULT 1,    -- vyšší = zobrazuje se častěji
    max_zobrazeni INT UNSIGNED NULL,                      -- strop kampaně, NULL = bez omezení
    zobrazeni     INT UNSIGNED NOT NULL DEFAULT 0,
    kliky         INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (idr),
    KEY ix_reklama_pozice (pozice, aktivni)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
