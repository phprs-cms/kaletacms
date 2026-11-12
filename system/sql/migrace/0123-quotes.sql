-- 3.38: internal prices and quotes (Core\Prices, Core\Quotes, the feature "quotes" – off until an administrator switches it
-- on – and the section Quotes, given to nobody by this migration). Prices live in their own tables, never in the item's data,
-- so no read path of the site, search, sitemap, llms.txt, exports or blueprints can print them. A price list has one
-- currency; the first default list is created by Core\Prices::defaultList on first use (in the site's default currency).
-- A quote copies names and prices into its own lines, gets its number at the first send or Finalise (ka_quote_counters,
-- one counter per year, taken with SELECT … FOR UPDATE) and keeps its status changes in history. No foreign keys: the
-- links to items, contacts, companies, enquiries, deals and users follow in code. A table that already exists (an earlier
-- attempt) is skipped by the migration runner (1050).
CREATE TABLE ka_price_lists (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name        VARCHAR(100) NOT NULL,
    currency    CHAR(3) CHARACTER SET ascii NOT NULL,        -- one of Deals::CURRENCIES; fixes the currency of its prices and quotes
    is_default  TINYINT(1) NOT NULL DEFAULT 0,               -- exactly one default list (Core\Prices)
    note        VARCHAR(300) NOT NULL DEFAULT '',
    archived_at DATETIME NULL,                               -- an archived list is not offered for new quotes
    created_at  DATETIME NOT NULL,
    updated_at  DATETIME NOT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE ka_prices (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    price_list_id INT UNSIGNED NOT NULL,
    item_id       INT UNSIGNED NULL,                         -- ka_kolekce_polozky.idp; NULL = an own row that is not a product (installation, transport)
    variant_code  VARCHAR(60) NOT NULL DEFAULT '',           -- '' = the product itself; otherwise the code of a variant line
    code          VARCHAR(60) NOT NULL DEFAULT '',           -- the product code when saved, or the code of an own row
    label         VARCHAR(200) NOT NULL DEFAULT '',          -- the name of an own row; empty for products (the item's name is read live)
    unit_price    DECIMAL(12,2) NOT NULL,                    -- without VAT
    unit          VARCHAR(20) NOT NULL DEFAULT '',
    vat_rate      DECIMAL(5,2) NULL,                         -- NULL = the default rate (quote_vat_rate)
    note          VARCHAR(200) NOT NULL DEFAULT '',          -- internal
    updated_at    DATETIME NOT NULL,
    updated_by    INT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY ux_prices_item (price_list_id, item_id, variant_code),
    KEY ix_prices_code (price_list_id, code),
    KEY ix_prices_item (item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE ka_quotes (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    number           VARCHAR(24) NULL,                       -- NULL while a draft; assigned at the first send or Finalise
    revision_of      INT UNSIGNED NULL,                      -- the quote this one replaces
    status           VARCHAR(10) CHARACTER SET ascii NOT NULL DEFAULT 'draft',   -- draft | sent | accepted | declined | expired
    contact_id       INT UNSIGNED NULL,
    company_id       INT UNSIGNED NULL,
    enquiry_id       INT UNSIGNED NULL,
    deal_id          INT UNSIGNED NULL,
    owner_id         INT UNSIGNED NULL,                      -- the responsible person
    price_list_id    INT UNSIGNED NOT NULL,
    currency         CHAR(3) CHARACTER SET ascii NOT NULL,   -- copied from the price list
    language         VARCHAR(35) CHARACTER SET ascii NOT NULL DEFAULT '',   -- the language of the document ('' = default)
    customer_name    VARCHAR(150) NOT NULL DEFAULT '',
    customer_company VARCHAR(150) NOT NULL DEFAULT '',
    customer_address VARCHAR(500) NOT NULL DEFAULT '',
    customer_reg_no  VARCHAR(40) NOT NULL DEFAULT '',
    customer_vat_id  VARCHAR(40) NOT NULL DEFAULT '',
    customer_email   VARCHAR(190) NOT NULL DEFAULT '',       -- the only address the quote can be sent to
    title            VARCHAR(200) NOT NULL DEFAULT '',
    intro            TEXT NULL,
    terms            TEXT NULL,
    valid_until      DATE NULL,
    discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00,     -- on the whole quote
    vat_mode         VARCHAR(14) CHARACTER SET ascii NOT NULL DEFAULT 'standard',   -- standard | reverse_charge | none
    total_net        DECIMAL(14,2) NOT NULL DEFAULT 0.00,    -- computed by the server on every save (Core\Money)
    total_vat        DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    total_gross      DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    manual_prices    TINYINT(1) NOT NULL DEFAULT 0,          -- a line's price differs from the list or has no list price
    snapshot         MEDIUMTEXT NULL,                        -- the document frozen when sent (JSON)
    token_hash       CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,   -- sha256 of the customer's link token
    token_expires_at DATETIME NULL,
    sent_at          DATETIME NULL,
    sent_by          INT UNSIGNED NULL,
    sent_mail        INT UNSIGNED NULL,                      -- the ka_posta row
    decided_at       DATETIME NULL,                          -- accepted or declined
    decided_by       INT UNSIGNED NULL,
    decline_reason   VARCHAR(20) CHARACTER SET ascii NULL,   -- Deals::LOST_REASONS
    history          TEXT NULL,                              -- JSON list {at, by, action, from, to, note} (at most 50)
    created_by       INT UNSIGNED NULL,
    created_at       DATETIME NOT NULL,
    updated_at       DATETIME NOT NULL,
    anonymised_at    DATETIME NULL,                          -- the customer block blanked
    PRIMARY KEY (id),
    UNIQUE KEY ux_quotes_number (number),
    UNIQUE KEY ux_quotes_token (token_hash),
    KEY ix_quotes_status (status, valid_until),
    KEY ix_quotes_contact (contact_id),
    KEY ix_quotes_company (company_id),
    KEY ix_quotes_deal (deal_id),
    KEY ix_quotes_enquiry (enquiry_id),
    KEY ix_quotes_owner (owner_id, status),
    KEY ix_quotes_revision (revision_of)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE ka_quote_lines (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    quote_id         INT UNSIGNED NOT NULL,
    position         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    item_id          INT UNSIGNED NULL,                      -- the product it was made from; NULL = a free line
    variant_code     VARCHAR(60) NOT NULL DEFAULT '',
    code             VARCHAR(60) NOT NULL DEFAULT '',
    name             VARCHAR(250) NOT NULL DEFAULT '',
    description      VARCHAR(1000) NOT NULL DEFAULT '',
    quantity         DECIMAL(10,3) NOT NULL DEFAULT 1.000,
    unit             VARCHAR(20) NOT NULL DEFAULT '',
    unit_price       DECIMAL(12,2) NULL,                     -- NULL = not priced yet; such a quote cannot be sent
    list_price       DECIMAL(12,2) NULL,                     -- the list price when the line was added ("manual price")
    discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    vat_rate         DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    line_net         DECIMAL(14,2) NOT NULL DEFAULT 0.00,    -- after the line's own discount, before the whole-quote discount
    PRIMARY KEY (id),
    KEY ix_quote_lines_quote (quote_id, position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE ka_quote_counters (
    year SMALLINT UNSIGNED NOT NULL,
    last INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
