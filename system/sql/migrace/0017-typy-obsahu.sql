-- Typy obsahu: živá reportáž, zvuk/video s přehrávačem, recenze s hodnocením.
ALTER TABLE rs_clanky
    ADD COLUMN zive TINYINT UNSIGNED NOT NULL DEFAULT 0,                   -- 0 běžný článek | 1 živá reportáž běží | 2 reportáž skončila
    ADD COLUMN medium_url VARCHAR(255) NOT NULL DEFAULT '',                -- zvuk nebo video: soubor z Médií, YouTube, Vimeo, Spotify
    ADD COLUMN recenze_predmet VARCHAR(160) NOT NULL DEFAULT '',           -- co se hodnotí (název filmu, knihy, výrobku…)
    ADD COLUMN recenze_hodnoceni TINYINT UNSIGNED NULL;                    -- hodnocení v procentech, NULL = článek není recenze
CREATE TABLE rs_zive (
    idz      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    idc      INT UNSIGNED NOT NULL,
    cas      DATETIME NOT NULL,
    text     TEXT NOT NULL,                               -- HTML zápisu (píše redakce)
    dulezite BOOL NOT NULL DEFAULT 0,
    autor    INT UNSIGNED NULL,
    PRIMARY KEY (idz),
    KEY ix_zive_clanek (idc, idz),
    CONSTRAINT fk_zive_clanek FOREIGN KEY (idc) REFERENCES rs_clanky (idc) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
