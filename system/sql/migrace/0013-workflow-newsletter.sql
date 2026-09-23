-- Redakční workflow, označení aktualizace článku a newsletter.
ALTER TABLE rs_clanky
    ADD COLUMN stav_redakce VARCHAR(12) NOT NULL DEFAULT '' AFTER visible,   -- '' rozepsáno | korektura | schvaleno (jen u nevydaných)
    ADD COLUMN poznamka TEXT NULL AFTER stav_redakce,                       -- interní poznámka redakce, na webu se neukazuje
    ADD COLUMN aktualizovano DATETIME NULL AFTER zmeneno;                   -- kdy byl vydaný článek podstatně doplněn
CREATE TABLE rs_odberatele (
    ido       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    email     VARCHAR(190) NOT NULL,
    token     CHAR(32) NOT NULL,                          -- pro potvrzení a odhlášení odkazem z e-mailu
    potvrzen  BOOL NOT NULL DEFAULT 0,
    prihlasen DATETIME NOT NULL,
    PRIMARY KEY (ido),
    UNIQUE KEY uq_odberatele_email (email),
    KEY ix_odberatele_token (token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
CREATE TABLE rs_newsletter (
    idn       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    predmet   VARCHAR(200) NOT NULL,
    uvod      TEXT NOT NULL,
    clanky    VARCHAR(255) NOT NULL DEFAULT '',           -- idc článků oddělená čárkou
    vytvoreno DATETIME NOT NULL,
    odeslano  DATETIME NULL,                              -- NULL = rozesílka ještě nedoběhla
    posledni  INT UNSIGNED NOT NULL DEFAULT 0,            -- ido posledního obslouženého odběratele
    pocet     INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (idn)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
