-- Uložené články přihlášených čtenářů ("Uložit na později").
CREATE TABLE rs_ctenari_ulozene (
    idct INT UNSIGNED NOT NULL,
    idc  INT UNSIGNED NOT NULL,
    cas  DATETIME NOT NULL,
    PRIMARY KEY (idct, idc),
    CONSTRAINT fk_ulozene_ctenar FOREIGN KEY (idct) REFERENCES rs_ctenari (idct) ON DELETE CASCADE,
    CONSTRAINT fk_ulozene_clanek FOREIGN KEY (idc) REFERENCES rs_clanky (idc) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
