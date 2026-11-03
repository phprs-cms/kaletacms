-- 3.31: the lean pipeline (Core\Deals) – enquiries and customers become deals that move through the stages of the one
-- pipeline (the setting pipeline_stages: New, Qualified, Proposal, Won, Lost by default). A deal may name its contact,
-- company, responsible person (owner_id) and the enquiry it came from (source_enquiry_id – one open deal per enquiry); a
-- value is a number with its currency (no products, price maths or forecasts). Won and lost close a deal (closed_at; lost
-- with a reason from a short list, lost_reason, and an optional note, lost_note); reopening clears them. Every move is kept
-- in ka_deal_stage_history (from_stage NULL = created). No foreign keys: a removed account or contact never cascades – the
-- links follow their records in code (Deals::unlinkContacts when a contact is erased or anonymised). A table that already
-- exists (an earlier attempt) is skipped by the migration runner (1050/1061).
CREATE TABLE ka_deals (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    title             VARCHAR(200) NOT NULL,
    contact_id        INT UNSIGNED NULL,
    company_id        INT UNSIGNED NULL,
    owner_id          INT UNSIGNED NULL,
    stage             VARCHAR(20) CHARACTER SET ascii NOT NULL,
    value             DECIMAL(12,2) NULL,
    currency          CHAR(3) CHARACTER SET ascii NULL,
    source_enquiry_id INT UNSIGNED NULL,
    expected_close    DATE NULL,
    closed_at         DATETIME NULL,
    lost_reason       VARCHAR(20) CHARACTER SET ascii NULL,
    lost_note         VARCHAR(500) NULL,
    created_by        INT UNSIGNED NULL,
    created_at        DATETIME NOT NULL,
    updated_at        DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY ix_deals_stage (stage, closed_at),
    KEY ix_deals_owner (owner_id, closed_at),
    KEY ix_deals_contact (contact_id),
    KEY ix_deals_company (company_id),
    KEY ix_deals_enquiry (source_enquiry_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
CREATE TABLE ka_deal_stage_history (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    deal_id    INT UNSIGNED NOT NULL,
    from_stage VARCHAR(20) CHARACTER SET ascii NULL,
    to_stage   VARCHAR(20) CHARACTER SET ascii NOT NULL,
    at         DATETIME NOT NULL,
    user_id    INT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY ix_deal_stage_history_deal (deal_id, at),
    KEY ix_deal_stage_history_at (at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
-- whoever may open Customers may open the pipeline of their deals (the new section pipeline, Admin\Modules\Pipeline):
-- accounts and the custom roles that have Customers get it, so saving a role later keeps it
INSERT IGNORE INTO ka_uzivatele_prava (fk_id_user, ident_modulu)
SELECT p.fk_id_user, 'pipeline' FROM (SELECT fk_id_user FROM ka_uzivatele_prava WHERE ident_modulu = 'contacts') AS p;
UPDATE ka_role SET moduly = CONCAT(moduly, ',pipeline') WHERE FIND_IN_SET('contacts', moduly) > 0 AND FIND_IN_SET('pipeline', moduly) = 0;
