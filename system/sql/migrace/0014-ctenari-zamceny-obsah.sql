-- Registrace čtenářů a uzamčený obsah.
ALTER TABLE rs_clanky
    ADD COLUMN pristup TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER noindex;   -- 0 všichni | 1 přihlášení čtenáři | 2 předplatitelé
CREATE TABLE rs_ctenari (
    idct         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    email        VARCHAR(190) NOT NULL,
    jmeno        VARCHAR(80) NOT NULL DEFAULT '',
    heslo        VARCHAR(255) NOT NULL,
    token        CHAR(32) NOT NULL,                       -- potvrzení e-mailu a obnova hesla
    token_cas    DATETIME NULL,                           -- kdy byl odeslán odkaz pro obnovu hesla (platí 2 hodiny)
    potvrzen     BOOL NOT NULL DEFAULT 0,
    predplatne_do DATE NULL,                              -- do kdy má čtenář předplatné; zapisuje administrátor
    vytvoren     DATETIME NOT NULL,
    naposledy    DATETIME NULL,
    PRIMARY KEY (idct),
    UNIQUE KEY uq_ctenari_email (email),
    KEY ix_ctenari_token (token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
