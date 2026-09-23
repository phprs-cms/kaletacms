-- Oznámení o vydání článku (webhook, IndexNow, Web Push) i pro články naplánované do budoucna; odběry Web Push.
ALTER TABLE rs_clanky
    ADD COLUMN oznameno DATETIME NULL AFTER aktualizovano;                  -- kdy systém vydání oznámil; NULL = ještě ne
UPDATE rs_clanky SET oznameno = NOW() WHERE visible = 1 AND datum <= NOW(); -- už vydané články se zpětně neoznamují
CREATE TABLE rs_push (
    idp       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    endpoint  VARCHAR(700) NOT NULL,                      -- adresa u služby prohlížeče (Google, Mozilla, Apple, Microsoft)
    otisk     CHAR(64) NOT NULL,                          -- sha256 adresy kvůli jedinečnosti
    p256dh    VARCHAR(120) NOT NULL DEFAULT '',
    auth      VARCHAR(40) NOT NULL DEFAULT '',
    vytvoreno DATETIME NOT NULL,
    PRIMARY KEY (idp),
    UNIQUE KEY uq_push_otisk (otisk)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
