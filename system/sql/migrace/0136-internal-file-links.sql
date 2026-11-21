-- 3.49 B: files on a record (Core\InternalFileLinks) – an internal file linked to a customer, a company, a deal or a quote.
-- A row holds the file, the record's kind and id and who linked it – no personal data, no copy of the file. Links follow in
-- code (no foreign keys, as 0131): deleting a file or a record, merging contacts and anonymising a contact or a quote remove
-- or move them. Only Core\InternalFileLinks names this table. A table that already exists (an earlier attempt) is skipped
-- by the migration runner (1050), so the file can run twice.
CREATE TABLE ka_internal_file_links (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    file_id    INT UNSIGNED NOT NULL,                            -- ka_internal_files.id
    kind       VARCHAR(20) CHARACTER SET ascii NOT NULL,         -- contact | company | deal | quote (3.49)
    record_id  INT UNSIGNED NOT NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY ux_internal_file_links (file_id, kind, record_id),
    KEY ix_internal_file_links_record (kind, record_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
