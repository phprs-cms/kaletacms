-- Stránky: nadřazená stránka (adresa /nadrazena/stranka), plánované zveřejnění a historie textu; vlastní sekce knihovny.
ALTER TABLE ka_stranky
    ADD COLUMN nadrazena INT UNSIGNED NULL AFTER preklad_z,
    ADD COLUMN zverejnit_od DATETIME NULL AFTER zobrazit,
    ADD KEY ix_stranky_zverejnit (zverejnit_od);

CREATE TABLE ka_stranky_revize (
    idr     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    ids     INT UNSIGNED NOT NULL,
    datum   DATETIME NOT NULL,
    kdo     INT UNSIGNED NULL,
    titulek VARCHAR(200) NOT NULL,
    text    MEDIUMTEXT NOT NULL,
    PRIMARY KEY (idr),
    KEY ix_stranky_revize (ids, idr),
    CONSTRAINT fk_stranky_revize_stranka FOREIGN KEY (ids) REFERENCES ka_stranky (ids) ON DELETE CASCADE,
    CONSTRAINT fk_stranky_revize_kdo FOREIGN KEY (kdo) REFERENCES ka_uzivatele (idu) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- Sekce, které si web uložil ze stavitele do vlastní knihovny (panel Přidat → Moje sekce).
CREATE TABLE ka_sekce (
    idx     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nazev   VARCHAR(100) NOT NULL,
    prvek   MEDIUMTEXT NOT NULL,                      -- JSON jednoho prvku (obvykle sekce) i s vnitřkem
    zmeneno DATETIME NULL,
    PRIMARY KEY (idx)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
