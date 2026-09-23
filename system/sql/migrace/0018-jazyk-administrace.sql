-- Jazyk administrace si volí každý uživatel sám (Můj účet); '' = čeština.
ALTER TABLE rs_user ADD COLUMN jazyk CHAR(2) NOT NULL DEFAULT '';
