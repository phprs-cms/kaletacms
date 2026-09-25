-- Kampaň, ze které poptávka přišla: parametry utm_* adresy stránky s formulářem (utm_source=…&utm_medium=…).
ALTER TABLE ka_poptavky ADD COLUMN kampan VARCHAR(255) NOT NULL DEFAULT '' AFTER stranka;
