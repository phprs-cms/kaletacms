-- Obnova zapomenutého hesla do administrace odkazem z e-mailu: ukládá se jen otisk jednorázového tokenu a čas odeslání.
ALTER TABLE rs_user
    ADD COLUMN obnova_otisk CHAR(64) NOT NULL DEFAULT '' AFTER zamceno_do,
    ADD COLUMN obnova_cas DATETIME NULL AFTER obnova_otisk;
