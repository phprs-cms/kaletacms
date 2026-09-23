-- Redakční upozornění e-mailem (článek ke korektuře, vydání, vrácení): každý uživatel si je může vypnout v Můj účet.
ALTER TABLE rs_user ADD COLUMN upozorneni BOOL NOT NULL DEFAULT 1 AFTER jazyk;
