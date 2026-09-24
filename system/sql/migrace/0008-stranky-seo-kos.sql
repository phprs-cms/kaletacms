-- Stránky dostávají totéž co novinky: vlastní titulek pro vyhledávače, obrázek pro sdílení, noindex a koš.
ALTER TABLE mc_stranky
    ADD COLUMN seo_titulek VARCHAR(200) NOT NULL DEFAULT '' AFTER popis,
    ADD COLUMN obrazek VARCHAR(255) NOT NULL DEFAULT '' AFTER seo_titulek,
    ADD COLUMN noindex BOOL NOT NULL DEFAULT 0 AFTER obrazek,
    ADD COLUMN smazano DATETIME NULL AFTER stavba_koncept,
    ADD KEY ix_stranky_smazano (smazano);
