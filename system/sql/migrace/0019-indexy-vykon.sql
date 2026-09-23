-- Indexy podle skutečných dotazů webu: hlavní stránka filtruje i jazyk, oznámení hledá dosud neoznámené články,
-- mapa webu, výpis v administraci a archiv řadí a vybírají podle data.
ALTER TABLE rs_clanky
    DROP KEY ix_clanky_index,
    ADD KEY ix_clanky_index (jazyk, visible, zobr_na_indexu, priority, datum),
    DROP KEY ix_clanky_jazyk,
    ADD KEY ix_clanky_jazyk (jazyk, visible, datum),
    ADD KEY ix_clanky_oznameno (oznameno, visible, datum),
    ADD KEY ix_clanky_datum (datum);
-- Dočasný zámek účtu po řadě chybných přihlášení (místo trvalého zablokování, které šlo zneužít k vyřazení redakce).
ALTER TABLE rs_user ADD COLUMN zamceno_do DATETIME NULL;
