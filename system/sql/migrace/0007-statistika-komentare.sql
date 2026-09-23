-- M3: vlastní statistika bez cookies a index pro moderaci komentářů.
ALTER TABLE rs_komentare ADD KEY ix_komentare_stav (zobrazit, datum);
CREATE TABLE rs_stat_dny (
    den       DATE NOT NULL,
    navstevy  INT UNSIGNED NOT NULL DEFAULT 0,            -- unikátní návštěvníci dne
    zobrazeni INT UNSIGNED NOT NULL DEFAULT 0,            -- zobrazené stránky
    PRIMARY KEY (den)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
-- Otisk návštěvníka = hash(IP + prohlížeč + denní sůl). Druhý den už nejde spojit s předchozím; starší řádky se mažou.
CREATE TABLE rs_stat_navstevnici (
    den   DATE NOT NULL,
    otisk CHAR(32) NOT NULL,
    PRIMARY KEY (den, otisk)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
CREATE TABLE rs_stat_clanky (
    den   DATE NOT NULL,
    idc   INT UNSIGNED NOT NULL,
    pocet INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (den, idc),
    KEY ix_stat_clanky_idc (idc),
    CONSTRAINT fk_stat_clanek FOREIGN KEY (idc) REFERENCES rs_clanky (idc) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
CREATE TABLE rs_stat_zdroje (
    den   DATE NOT NULL,
    zdroj VARCHAR(100) NOT NULL,                          -- doména, ze které návštěvník přišel
    pocet INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (den, zdroj)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
