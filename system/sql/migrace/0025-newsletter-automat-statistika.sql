-- Newsletter: automatický výběr článků, naplánované odeslání a souhrnná statistika (počty, žádné údaje o jednotlivcích).
ALTER TABLE rs_newsletter
    ADD COLUMN auto BOOL NOT NULL DEFAULT 0,               -- vydání vzniklo samo z nových článků
    ADD COLUMN odeslat_v DATETIME NULL,                    -- naplánovaná rozesílka na pozadí; NULL = ruční rozesílka z administrace
    ADD COLUMN otevreno INT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN prokliku INT UNSIGNED NOT NULL DEFAULT 0;
