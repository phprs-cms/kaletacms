-- Novinky a poptávky jsou nově vypínatelná rozšíření. Stávající weby je měly vždy zapnuté – zůstanou zapnuté.
UPDATE ka_nastaveni SET hodnota = IF(hodnota = '-', 'novinky,poptavky', CONCAT('novinky,poptavky,', hodnota)) WHERE promenna = 'rozsireni' AND hodnota <> '';
