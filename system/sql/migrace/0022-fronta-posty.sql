-- Fronta a protokol e-mailů: nepovedené odeslání se opakuje (5 min, 30 min, 2 h, 12 h), odeslané zprávy zůstávají
-- 30 dní jako záznam bez obsahu.
CREATE TABLE rs_posta (
    idp         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    komu        VARCHAR(190) NOT NULL,
    predmet     VARCHAR(255) NOT NULL,
    telo        MEDIUMTEXT NULL,                          -- JSON {text, html, hlavicky}; po odeslání se maže
    vytvoreno   DATETIME NOT NULL,
    odeslano    DATETIME NULL,
    pokusu      TINYINT UNSIGNED NOT NULL DEFAULT 0,
    dalsi_pokus DATETIME NULL,
    chyba       VARCHAR(255) NOT NULL DEFAULT '',
    PRIMARY KEY (idp),
    KEY ix_posta_fronta (odeslano, dalsi_pokus)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
