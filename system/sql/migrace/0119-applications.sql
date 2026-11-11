-- 3.34: job applications apart from enquiries (Core\Applications, the feature "applications", the section Applications).
-- An application form (a Form on a job's item page, or with the property Purpose = job application) stores here instead of
-- ka_poptavky: no customer record, no deal, its own six statuses, its own retention, CVs sealed at rest outside the web root
-- (storage/applications/, each file under its own random key kept in the sealed attachments column). No foreign keys: the
-- position, the responsible person and the comments, tasks and notifications (target application:<id>) follow in code. The
-- move of the applications that were enquiries is the data migration 0120. A table that already exists (an earlier
-- attempt) is skipped by the migration runner (1050).
CREATE TABLE ka_applications (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    received_at       DATETIME NOT NULL,
    job_collection_id INT UNSIGNED NULL,                      -- the position: a jobs collection and its item; NULL = a spontaneous application
    job_item_id       INT UNSIGNED NULL,
    job_title         VARCHAR(200) NOT NULL DEFAULT '',       -- a copy of the job's title, so the list survives a deleted job
    form              VARCHAR(120) NOT NULL DEFAULT '',
    source            VARCHAR(40) NOT NULL DEFAULT '',        -- as ka_poptavky.zdroj (kolekce:<idk>, stranka:<ids>, popup:<id>…)
    element           VARCHAR(16) NOT NULL DEFAULT '',
    page              VARCHAR(255) NOT NULL DEFAULT '',
    language          VARCHAR(35) CHARACTER SET ascii NOT NULL DEFAULT '',
    name              VARCHAR(150) NOT NULL DEFAULT '',
    email             VARCHAR(190) NOT NULL DEFAULT '',
    email_norm        VARCHAR(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,   -- PersonalData::normalise(email)
    phone             VARCHAR(40) NOT NULL DEFAULT '',
    data              MEDIUMTEXT NOT NULL,                    -- JSON list of [label, value], as ka_poptavky.data (never a file path)
    attachments       TEXT NULL,                              -- sealed (Core\Vault, purpose applications) JSON [{field, name, size, path, key, sealed}]
    status            VARCHAR(10) CHARACTER SET ascii NOT NULL DEFAULT 'new',   -- new | reviewed | interview | offer | hired | rejected
    status_at         DATETIME NOT NULL,
    decided_at        DATETIME NULL,                          -- set by hired and rejected, cleared when reopened
    reject_reason     VARCHAR(20) CHARACTER SET ascii NULL,   -- not_suitable | position_filled | withdrew | no_response | other
    assignee_id       INT UNSIGNED NULL,                      -- the responsible person (no foreign key)
    replies           MEDIUMTEXT NULL,                        -- JSON, the format of ka_poptavky.replies
    history           TEXT NULL,                              -- JSON list of status changes {at, by, from, to} (at most 50)
    late              TINYINT(1) NOT NULL DEFAULT 0,          -- arrived after the job's closing date
    pool_consent_at   DATETIME NULL,                          -- ticked the consent to keep it for future openings
    pool_consent_text VARCHAR(500) NULL,
    pool_until        DATE NULL,
    last_activity_at  DATETIME NOT NULL,                      -- receipt, reply, status change, note, task
    source_enquiry_id INT UNSIGNED NULL,                      -- moved from this enquiry (migration 0120); makes the move repeatable
    PRIMARY KEY (id),
    UNIQUE KEY ux_applications_enquiry (source_enquiry_id),
    KEY ix_applications_status (status, id),
    KEY ix_applications_job (job_item_id, status),
    KEY ix_applications_assignee (assignee_id, status),
    KEY ix_applications_email (email_norm),
    KEY ix_applications_decided (decided_at),
    KEY ix_applications_activity (last_activity_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
