-- 3.40 B (quotes II, design A12 "Release Q2"): a price list per customer, the customer's own decision on the quote page,
-- "first opened on" and saved line sets. A column that already exists (an earlier attempt) is skipped by the runner (1060).
-- The internal price list a new quote for this contact or company takes (ka_price_lists.id; NULL = the default list).
ALTER TABLE ka_contacts ADD COLUMN price_list_id INT UNSIGNED NULL;
ALTER TABLE ka_companies ADD COLUMN price_list_id INT UNSIGNED NULL;
-- When the customer's link was first opened – only the time, never an address or a browser; recorded only with the
-- setting quote_track_opened (off by default), forgotten when it is switched off.
ALTER TABLE ka_quotes ADD COLUMN first_opened_at DATETIME NULL;
-- The customer's own words when they declined on the quote page (optional; blanked with the customer block).
ALTER TABLE ka_quotes ADD COLUMN customer_reason VARCHAR(1000) NULL;
-- Saved line sets ("Laser with installation"): lines of a draft kept under a name and inserted into another draft; the
-- prices of products and own rows are read from the quote's price list again at insertion (only free lines keep theirs).
CREATE TABLE IF NOT EXISTS ka_quote_line_sets (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name        VARCHAR(120) NOT NULL,
    content     MEDIUMTEXT NOT NULL,
    created_by  INT UNSIGNED NULL,
    created_at  DATETIME NOT NULL,
    updated_at  DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY ux_quote_line_sets_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
