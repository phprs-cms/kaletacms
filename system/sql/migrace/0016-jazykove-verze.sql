-- Jazykové verze webu: každý další jazyk má své rubriky, články a stránky; '' = výchozí jazyk webu.
ALTER TABLE rs_topic   ADD COLUMN jazyk CHAR(2) NOT NULL DEFAULT '';
ALTER TABLE rs_stranky ADD COLUMN jazyk CHAR(2) NOT NULL DEFAULT '';
ALTER TABLE rs_bloky   ADD COLUMN jen_jazyk CHAR(2) NOT NULL DEFAULT '';   -- '' = blok je vidět ve všech jazycích, 'vy' = jen ve výchozím
ALTER TABLE rs_clanky
    ADD COLUMN jazyk CHAR(2) NOT NULL DEFAULT '',                          -- přebírá se z rubriky při uložení článku
    ADD COLUMN preklad_z INT UNSIGNED NULL,                                -- idc článku, jehož je tento překladem (hreflang, přepínač jazyků)
    ADD KEY ix_clanky_jazyk (jazyk, visible, datum);
