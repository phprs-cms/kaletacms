-- Import z jiných systémů (WordPress): co z cizího webu už bylo převedeno a na který náš záznam.
-- Díky tomu jde stejný soubor pustit znovu bez duplicit a dají se přepsat odkazy na obrázky.
CREATE TABLE rs_import_mapa (
    zdroj   VARCHAR(40) NOT NULL,                        -- odkud záznam pochází: wp:<doména starého webu>
    typ     VARCHAR(20) NOT NULL,                        -- clanek | stranka | rubrika | stitek | obrazek | komentar
    cizi_id VARCHAR(190) NOT NULL,                       -- identifikátor ve zdroji (číslo příspěvku, adresa rubriky, otisk adresy obrázku)
    nase_id INT UNSIGNED NOT NULL,                       -- číslo našeho záznamu; 0 u obrázku = stažení se nepovedlo
    PRIMARY KEY (zdroj, typ, cizi_id),
    KEY ix_import_mapa_nase (zdroj, typ, nase_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
