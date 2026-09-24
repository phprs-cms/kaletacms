-- Statistika po stránkách: zobrazení každé adresy za den (bez cookies, jako ostatní statistika).
CREATE TABLE ka_stat_stranky (
    den   DATE NOT NULL,
    cesta VARCHAR(255) NOT NULL,
    pocet INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (den, cesta)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
