-- 3.49 A: saved replies of the firm (Core\SavedReplies) – texts a person offers when replying to an enquiry, per language
-- version of the site ('' = the default language). category is the seam of a later Support tab ('' in 3.49). No personal
-- data, never written over MCP. The code of 3.48 reads none of it.
CREATE TABLE IF NOT EXISTS ka_saved_replies (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    language   VARCHAR(35) CHARACTER SET ascii NOT NULL DEFAULT '',
    category   VARCHAR(20) CHARACTER SET ascii NOT NULL DEFAULT '',
    name       VARCHAR(80) NOT NULL,
    subject    VARCHAR(200) NOT NULL DEFAULT '',
    text       TEXT NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    updated_by INT UNSIGNED NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY ix_saved_replies_language (language, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
