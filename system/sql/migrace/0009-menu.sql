-- Menu webu (Vzhled → Menu): hlavní a v patičce, pro každou jazykovou verzi. Bez řádku se hlavní menu skládá ze stránek „v menu“.
CREATE TABLE ka_menu (
    umisteni VARCHAR(20) NOT NULL,                    -- hlavni | paticka
    jazyk    CHAR(2) NOT NULL DEFAULT '',             -- '' = výchozí jazyk webu
    polozky  MEDIUMTEXT NOT NULL,                     -- JSON [{typ: stranka|odkaz|novinky|skupina, ids, url, text, nove_okno, deti: […]}]
    zmeneno  DATETIME NULL,
    PRIMARY KEY (umisteni, jazyk)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
