-- Rozepsaný článek se průběžně ukládá i na server (vedle prohlížeče): dá se v něm pokračovat z jiného zařízení.
-- Jeden rozepsaný stav na uživatele a článek (idc 0 = nový článek); po uložení článku se maže.
CREATE TABLE rs_clanky_koncepty (
    kdo  INT UNSIGNED NOT NULL,
    idc  INT UNSIGNED NOT NULL DEFAULT 0,
    cas  DATETIME NOT NULL,
    data MEDIUMTEXT NOT NULL,                             -- JSON {název pole formuláře: hodnota}
    PRIMARY KEY (kdo, idc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
