-- Stránky témat: štítek může mít popis a obrázek - jeho stránka pak slouží jako "speciál" (volby, kauza, festival).
ALTER TABLE rs_stitky
    ADD COLUMN popis TEXT NULL,
    ADD COLUMN obrazek VARCHAR(255) NOT NULL DEFAULT '';
