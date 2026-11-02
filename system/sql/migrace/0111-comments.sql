-- 3.25: internal notes as a history on every enquiry (Core\Comments). A note is appended as a row and never overwritten;
-- the table is generic (target 'enquiry:<idp>', later pages and collection items) so comments need no second table.
-- No data migration: ka_poptavky.poznamka stays where it is and the enquiry detail shows it as the first entry (the
-- summary, still editable, still written by MCP update_enquiry.note) – a rollback to 3.24 loses nothing. The code of 3.24
-- does not read the table. A table that already exists (an earlier attempt) is skipped by the migration runner (1050).
CREATE TABLE ka_comments (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    target      VARCHAR(40) NOT NULL,
    kind        VARCHAR(20) NOT NULL DEFAULT 'note',
    author_id   INT UNSIGNED NULL,
    author_name VARCHAR(100) NOT NULL DEFAULT '',
    text        TEXT NOT NULL,
    via         VARCHAR(10) NOT NULL DEFAULT '',
    mentions    TEXT NULL,
    created_at  DATETIME NOT NULL,
    edited_at   DATETIME NULL,
    deleted_at  DATETIME NULL,
    PRIMARY KEY (id),
    KEY ix_comments_target (target, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
