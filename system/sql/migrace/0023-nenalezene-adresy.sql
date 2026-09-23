-- Přehled adres, které skončily chybou 404 - podklad pro přesměrování (modul Přesměrování).
CREATE TABLE rs_nenalezeno (
    cesta     VARCHAR(255) NOT NULL,
    pocet     INT UNSIGNED NOT NULL DEFAULT 1,
    naposledy DATETIME NOT NULL,
    PRIMARY KEY (cesta)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
