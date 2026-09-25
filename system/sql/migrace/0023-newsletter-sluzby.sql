-- Odběratelé do mailingové služby (Brevo, MailerLite, Mailchimp, Ecomail, SmartEmailing, webhook): stav u odběratele
-- a fronta přidání a odebrání, kterou odesílá úklid na pozadí (návštěvník na službu nečeká).
ALTER TABLE ka_odberatele ADD COLUMN sync VARCHAR(10) NOT NULL DEFAULT '' AFTER potvrzeno, ADD COLUMN sync_chyba VARCHAR(255) NOT NULL DEFAULT '' AFTER sync;
CREATE TABLE IF NOT EXISTS ka_odber_fronta (
    idf       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    email     VARCHAR(190) NOT NULL,
    akce      VARCHAR(10)  NOT NULL,
    pokusy    TINYINT UNSIGNED NOT NULL DEFAULT 0,
    dalsi     DATETIME NULL,
    chyba     VARCHAR(255) NOT NULL DEFAULT '',
    vytvoreno DATETIME NOT NULL,
    PRIMARY KEY (idf),
    KEY ix_odber_fronta_dalsi (dalsi)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
