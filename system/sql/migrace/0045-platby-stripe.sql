-- Placené předplatné přes Stripe: u čtenáře zákazník a předplatné ve Stripe a jeho stav, zvlášť přijaté platby.
-- Neukládají se žádné údaje o kartě ani adresy - ty zůstávají ve Stripe.
ALTER TABLE rs_ctenari
    ADD COLUMN stripe_zakaznik VARCHAR(64) NULL AFTER predplatne_do,
    ADD COLUMN stripe_predplatne VARCHAR(64) NULL AFTER stripe_zakaznik,
    ADD COLUMN predplatne_stav VARCHAR(20) NOT NULL DEFAULT '' AFTER stripe_predplatne,
    ADD KEY ix_ctenari_stripe (stripe_zakaznik);

CREATE TABLE rs_platby (
    id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    udalost   VARCHAR(80) NOT NULL,
    idct      INT UNSIGNED NULL,
    castka    INT NOT NULL,
    mena      CHAR(3) NOT NULL,
    typ       VARCHAR(20) NOT NULL,
    vytvoreno DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_platby_udalost (udalost),
    KEY ix_platby_cas (vytvoreno),
    CONSTRAINT fk_platby_ctenar FOREIGN KEY (idct) REFERENCES rs_ctenari (idct) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
