-- Poptávky: interní poznámka a přiřazení kolegovi (přílohy leží ve storage/prilohy/, cesta je v poli data).
ALTER TABLE mc_poptavky
    ADD COLUMN poznamka TEXT NULL AFTER stav,
    ADD COLUMN prirazeno INT UNSIGNED NULL AFTER poznamka;
