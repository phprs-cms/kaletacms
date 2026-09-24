-- Přesměrování: trvalé (301) nebo dočasné (302 – akce, sezónní stránka).
ALTER TABLE mc_presmerovani ADD COLUMN typ SMALLINT UNSIGNED NOT NULL DEFAULT 301 AFTER na_adresu;
