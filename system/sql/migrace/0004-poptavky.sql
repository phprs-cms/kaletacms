-- Poptávky a zprávy z formulářů webu (prvek Formulář ve staviteli). Data = JSON [[popisek, hodnota], …].
CREATE TABLE mc_poptavky (
    idp      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    datum    DATETIME NOT NULL,
    formular VARCHAR(120) NOT NULL DEFAULT '',
    zdroj    VARCHAR(40) NOT NULL DEFAULT '',
    prvek    VARCHAR(16) NOT NULL DEFAULT '',
    stranka  VARCHAR(255) NOT NULL DEFAULT '',
    email    VARCHAR(190) NOT NULL DEFAULT '',
    data     MEDIUMTEXT NOT NULL,
    stav     TINYINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (idp),
    KEY ix_poptavky_stav (stav, idp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
