-- Nové instalace mají štíhlejší výchozí sadu rozšíření (bez Novinek a Anket). Web, který dosud běžel na výchozí sadě
-- (prázdná hodnota = výchozí), si tu původní zapíše výslovně, aby mu nic nezmizelo.
UPDATE rs_config SET hodnota = 'novinky,komentare,ankety,statistika,presmerovani' WHERE promenna = 'rozsireni' AND hodnota = '';
INSERT INTO rs_config (promenna, hodnota)
    SELECT 'rozsireni', 'novinky,komentare,ankety,statistika,presmerovani' FROM DUAL
    WHERE NOT EXISTS (SELECT 1 FROM rs_config WHERE promenna = 'rozsireni');
