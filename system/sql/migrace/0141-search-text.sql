-- 3.53 (A2): pages and collection items get a stored, normalized search text, as news already have (Core\SearchIndex).
-- NULL = to index: the background job search_index fills the column in batches (no long request during the update), and
-- until a row has its text the site search reads it the 3.52 way. utf8mb4_bin because the text is already normalized
-- (Core\Search::normalize): SQL and PHP then compare the same way. Each statement is skipped when it already ran
-- (Migration::ALREADY_APPLIED – 1060 the column exists, 1091 the index is gone), so the migration can run again.
ALTER TABLE ka_stranky ADD COLUMN hledani MEDIUMTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL;
ALTER TABLE ka_kolekce_polozky ADD COLUMN hledani MEDIUMTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL;
-- the FULLTEXT index over the news index has had no MATCH since 3.52 (B1); last on purpose: the only step whose time grows
-- with the size of ka_novinky, and the columns above exist even when it takes a while
ALTER TABLE ka_novinky DROP INDEX ft_clanky_hledani;
