-- Hledání bez diakritiky: text článku malými písmeny bez háčků a čárek ("nabrezi" najde "nábřeží").
-- Plní ho Core\Hledani při uložení článku; starší články se doplní samy po dávkách. U zamčených článků obsahuje jen titulek a perex.
ALTER TABLE rs_clanky
    ADD COLUMN hledani MEDIUMTEXT NULL,
    ADD FULLTEXT KEY ft_clanky_hledani (hledani);
