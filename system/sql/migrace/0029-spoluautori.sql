-- Více autorů u článku a externí autor (host, agentura) bez účtu v administraci.
ALTER TABLE rs_clanky ADD COLUMN externi_autor VARCHAR(120) NOT NULL DEFAULT '';   -- zobrazí se místo / vedle autora z redakce
CREATE TABLE rs_clanky_autori (
    idc INT UNSIGNED NOT NULL,
    idu INT UNSIGNED NOT NULL,
    PRIMARY KEY (idc, idu),
    KEY ix_clanky_autori_idu (idu),
    CONSTRAINT fk_clanky_autori_clanek FOREIGN KEY (idc) REFERENCES rs_clanky (idc) ON DELETE CASCADE,
    CONSTRAINT fk_clanky_autori_autor FOREIGN KEY (idu) REFERENCES rs_user (idu) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
