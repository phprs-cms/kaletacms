-- Další šablony článku. Layout je vykreslí standardní šablonou s třídou "sablona-<soubor>" (vzhled je v image/web.css),
-- vlastní layout je může nahradit souborem cla_<soubor>.php.
INSERT INTO rs_cla_sab (nazev_cla_sab, soubor_cla_sab)
    SELECT 'Dlouhé čtení', 'dlouhe-cteni' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM rs_cla_sab WHERE soubor_cla_sab = 'dlouhe-cteni');
INSERT INTO rs_cla_sab (nazev_cla_sab, soubor_cla_sab)
    SELECT 'Fotoreportáž', 'fotoreportaz' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM rs_cla_sab WHERE soubor_cla_sab = 'fotoreportaz');
INSERT INTO rs_cla_sab (nazev_cla_sab, soubor_cla_sab)
    SELECT 'Rozhovor', 'rozhovor' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM rs_cla_sab WHERE soubor_cla_sab = 'rozhovor');
