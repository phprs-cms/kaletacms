-- Přejmenování na Kaleta: tokeny a třídy systému ve stavbách a vlastním CSS tříd (--mc-… → --ka-…, mc-tlacitko → ka-tlacitko).
-- Názvy tabulek se nemění – předpona je v config.php každé instalace.
UPDATE ka_tridy SET css = REPLACE(REPLACE(css, '--mc-', '--ka-'), 'mc-tlacitko', 'ka-tlacitko');
UPDATE ka_stranky SET stavba = REPLACE(REPLACE(stavba, '--mc-', '--ka-'), 'mc-tlacitko', 'ka-tlacitko'), stavba_koncept = REPLACE(REPLACE(stavba_koncept, '--mc-', '--ka-'), 'mc-tlacitko', 'ka-tlacitko');
UPDATE ka_casti SET stavba = REPLACE(REPLACE(stavba, '--mc-', '--ka-'), 'mc-tlacitko', 'ka-tlacitko'), stavba_koncept = REPLACE(REPLACE(stavba_koncept, '--mc-', '--ka-'), 'mc-tlacitko', 'ka-tlacitko');
UPDATE ka_kolekce SET stavba = REPLACE(REPLACE(stavba, '--mc-', '--ka-'), 'mc-tlacitko', 'ka-tlacitko'), stavba_koncept = REPLACE(REPLACE(stavba_koncept, '--mc-', '--ka-'), 'mc-tlacitko', 'ka-tlacitko');
UPDATE ka_komponenty SET stavba = REPLACE(REPLACE(stavba, '--mc-', '--ka-'), 'mc-tlacitko', 'ka-tlacitko'), stavba_koncept = REPLACE(REPLACE(stavba_koncept, '--mc-', '--ka-'), 'mc-tlacitko', 'ka-tlacitko');
UPDATE ka_sekce SET prvek = REPLACE(REPLACE(prvek, '--mc-', '--ka-'), 'mc-tlacitko', 'ka-tlacitko');
UPDATE ka_stavba_revize SET stavba = REPLACE(REPLACE(stavba, '--mc-', '--ka-'), 'mc-tlacitko', 'ka-tlacitko');
