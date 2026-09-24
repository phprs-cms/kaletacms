-- Varianty záhlaví a patičky pro vybrané stránky (varianta '' = výchozí podoba, stranky = JSON seznam čísel stránek).
ALTER TABLE mc_casti
    ADD COLUMN varianta VARCHAR(40) NOT NULL DEFAULT '' AFTER jazyk,
    ADD COLUMN nazev VARCHAR(100) NOT NULL DEFAULT '' AFTER varianta,
    ADD COLUMN stranky TEXT NULL AFTER nazev,
    DROP PRIMARY KEY,
    ADD PRIMARY KEY (typ, jazyk, varianta);

ALTER TABLE mc_stavba_revize MODIFY cast VARCHAR(80) NULL;
