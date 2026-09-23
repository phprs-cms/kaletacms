-- Koš článků: smazaný článek se 30 dní drží v koši a jde obnovit (jako koncept), potom se smaže natrvalo.
ALTER TABLE rs_clanky
    ADD COLUMN smazano DATETIME NULL AFTER odkazy_cas,
    ADD COLUMN smazal INT UNSIGNED NULL AFTER smazano,
    ADD COLUMN smazano_vydany BOOL NOT NULL DEFAULT 0 AFTER smazal,
    ADD KEY ix_clanky_smazano (smazano);
