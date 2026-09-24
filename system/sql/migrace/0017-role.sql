-- Vlastní role: pojmenovaná sada sekcí administrace a úroveň (0 = píše vlastní novinky, 1 = vydává a spravuje obsah všech).
CREATE TABLE ka_role (
    idr     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nazev   VARCHAR(60)  NOT NULL,
    popis   VARCHAR(200) NOT NULL DEFAULT '',
    uroven  TINYINT UNSIGNED NOT NULL DEFAULT 0,
    moduly  VARCHAR(1000) NOT NULL DEFAULT '',            -- identifikátory sekcí oddělené čárkou
    PRIMARY KEY (idr),
    UNIQUE KEY uq_role_nazev (nazev)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
ALTER TABLE ka_uzivatele ADD COLUMN role INT UNSIGNED NULL AFTER admin;
