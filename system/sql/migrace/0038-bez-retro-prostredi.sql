-- Administrace má jediný vzhled: sloupec a nastavení pro volbu prostředí se ruší.
ALTER TABLE rs_user DROP COLUMN prostredi;
DELETE FROM rs_config WHERE promenna = 'prostredi_admin';
