-- 3.22 (C13): an optional canonical address under "Search engines and sharing" on pages, news items and collection items.
-- Empty (the default, every existing row) = the address of the page itself, as before; otherwise an absolute http(s)
-- address or a path on the site (Core\CanonicalUrl validates it), printed as <link rel="canonical"> instead of the own
-- address – for a duplicate (a campaign copy, a print page, a syndicated article) that points to the original. The WordPress
-- importer fills it from the SEO plugins (Yoast, Rank Math, SmartCrawl). The code of 3.21 does not read the columns. A column
-- that already exists (an earlier attempt) is skipped by the migration runner (error 1060).
ALTER TABLE ka_stranky ADD COLUMN canonical_url VARCHAR(500) NOT NULL DEFAULT '' AFTER noindex;
ALTER TABLE ka_novinky ADD COLUMN canonical_url VARCHAR(500) NOT NULL DEFAULT '' AFTER noindex;
ALTER TABLE ka_kolekce_polozky ADD COLUMN canonical_url VARCHAR(500) NOT NULL DEFAULT '' AFTER noindex;
