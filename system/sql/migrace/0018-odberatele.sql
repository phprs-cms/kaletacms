-- Rozšíření Newsletter: odběratelé přihlášení prvkem Odběr novinek (double opt-in).
CREATE TABLE ka_odberatele (
    ido       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    email     VARCHAR(190) NOT NULL,
    stav      TINYINT UNSIGNED NOT NULL DEFAULT 0,       -- 0 čeká na potvrzení, 1 potvrzený
    token     CHAR(32)     NOT NULL,                     -- potvrzení a odhlášení odkazem
    zdroj     VARCHAR(255) NOT NULL DEFAULT '',          -- stránka, ze které se přihlásil
    datum     DATETIME     NOT NULL,
    potvrzeno DATETIME     NULL,
    PRIMARY KEY (ido),
    UNIQUE KEY uq_odberatel_email (email),
    UNIQUE KEY uq_odberatel_token (token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
