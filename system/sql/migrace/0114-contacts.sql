-- 3.28: contacts and companies – the customer memory of the site (Core\Contacts). One contact per customer across the
-- enquiries, bookings and newsletter subscription that share a normalised e-mail address (email_norm, unique; NULL once
-- a contact is anonymised or has no address). Two different addresses are never merged by Kaleta itself – only a person
-- merges them (Contacts::merge). A company is attached from the e-mail domain only when it already exists and never for a
-- free-mail domain. Activities are the team's own log of calls, meetings, e-mails and notes. No foreign keys: a removed
-- account never cascades and the links follow their records in code (Contacts::forget, the clean-up job).
-- The existing tables only gain a nullable contact_id with an index (additive, like owner_id in 0110); a batched job links
-- the rows that are already there. The code of 3.27 does not read any of it. A table, column or key that already exists
-- (an earlier attempt) is skipped by the migration runner (1050/1060/1061).
CREATE TABLE ka_contacts (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    email            VARCHAR(190) NOT NULL DEFAULT '',
    email_norm       VARCHAR(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
    name             VARCHAR(150) NOT NULL DEFAULT '',
    phone            VARCHAR(40) NOT NULL DEFAULT '',
    company_id       INT UNSIGNED NULL,
    owner_id         INT UNSIGNED NULL,
    language         VARCHAR(35) CHARACTER SET ascii NOT NULL DEFAULT '',
    source           VARCHAR(12) NOT NULL DEFAULT 'manual',
    summary          TEXT NULL,
    first_seen_at    DATETIME NULL,
    last_activity_at DATETIME NULL,
    created_at       DATETIME NOT NULL,
    updated_at       DATETIME NOT NULL,
    anonymised_at    DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY ux_contacts_email (email_norm),
    KEY ix_contacts_owner (owner_id),
    KEY ix_contacts_company (company_id),
    KEY ix_contacts_activity (last_activity_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
CREATE TABLE ka_companies (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name            VARCHAR(150) NOT NULL,
    domain          VARCHAR(190) NOT NULL DEFAULT '',
    registration_no VARCHAR(40) NOT NULL DEFAULT '',
    vat_id          VARCHAR(40) NOT NULL DEFAULT '',
    owner_id        INT UNSIGNED NULL,
    note            TEXT NULL,
    created_at      DATETIME NOT NULL,
    updated_at      DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY ix_companies_domain (domain),
    KEY ix_companies_owner (owner_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
CREATE TABLE ka_contact_activities (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    contact_id  INT UNSIGNED NOT NULL,
    kind        VARCHAR(10) NOT NULL DEFAULT 'note',
    text        TEXT NOT NULL,
    author_id   INT UNSIGNED NULL,
    author_name VARCHAR(100) NOT NULL DEFAULT '',
    happened_at DATETIME NOT NULL,
    created_at  DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY ix_contact_activities_contact (contact_id, happened_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
ALTER TABLE ka_poptavky ADD COLUMN contact_id INT UNSIGNED NULL DEFAULT NULL, ADD KEY ix_poptavky_contact (contact_id);
ALTER TABLE ka_bookings ADD COLUMN contact_id INT UNSIGNED NULL DEFAULT NULL, ADD KEY ix_bookings_contact (contact_id);
ALTER TABLE ka_odberatele ADD COLUMN contact_id INT UNSIGNED NULL DEFAULT NULL, ADD KEY ix_odberatele_contact (contact_id);
-- the contacts' retention starts as the enquiries' (owner decision of 3 Nov 2026); a site that never changed it keeps the
-- default of 24 months (Settings::DEFAULTS). The source is a derived table (an INSERT … SELECT from the same table, 0020).
INSERT IGNORE INTO ka_nastaveni (promenna, hodnota)
SELECT 'contacts_months', e.hodnota FROM (SELECT hodnota FROM ka_nastaveni WHERE promenna = 'enquiries_months') AS e;
-- whoever may open Enquiries may open the customers they come from (the new section contacts, Admin\Modules\Contacts):
-- accounts and the custom roles that have Enquiries get it, so saving a role later keeps it
INSERT IGNORE INTO ka_uzivatele_prava (fk_id_user, ident_modulu)
SELECT p.fk_id_user, 'contacts' FROM (SELECT fk_id_user FROM ka_uzivatele_prava WHERE ident_modulu = 'enquiries') AS p;
UPDATE ka_role SET moduly = CONCAT(moduly, ',contacts') WHERE FIND_IN_SET('enquiries', moduly) > 0 AND FIND_IN_SET('contacts', moduly) = 0;
