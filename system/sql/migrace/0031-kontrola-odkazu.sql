-- Kontrola nefunkčních odkazů v článcích: na pozadí, po jednom článku; ukládají se jen odkazy, které nefungují.
ALTER TABLE rs_clanky ADD COLUMN odkazy_cas DATETIME NULL;      -- kdy se odkazy článku naposledy kontrolovaly
CREATE TABLE rs_odkazy_vadne (
    ido  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    idc  INT UNSIGNED NOT NULL,
    url  VARCHAR(500) NOT NULL,
    stav SMALLINT UNSIGNED NOT NULL DEFAULT 0,             -- kód odpovědi; 0 = server neodpověděl, 404 u vlastního článku = neexistuje
    cas  DATETIME NOT NULL,
    PRIMARY KEY (ido),
    KEY ix_odkazy_clanek (idc),
    CONSTRAINT fk_odkazy_clanek FOREIGN KEY (idc) REFERENCES rs_clanky (idc) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
