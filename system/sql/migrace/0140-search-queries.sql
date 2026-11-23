-- 3.52 (B5): what visitors search for on the site – per day, language version and query, how many searches and how many
-- found nothing (Core\SiteSearch::record, Statistics and MCP get_stats). No IP and nothing about the visitor. The code of
-- 3.51 reads none of it.
CREATE TABLE IF NOT EXISTS ka_search_queries (
    day            DATE NOT NULL,
    language       VARCHAR(35) CHARACTER SET ascii NOT NULL DEFAULT '',
    query          VARCHAR(60) NOT NULL,
    searches       INT UNSIGNED NOT NULL DEFAULT 0,
    without_result INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (day, language, query)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
-- the FULLTEXT index over the news columns has had no MATCH since the site search moved to Core\SiteSearch (3.52, B1);
-- ft_clanky_hledani stays for now. Dropped only while it is there, so the migration can run again.
SET @kaleta_0140 = IF(EXISTS (SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ka_novinky' AND INDEX_NAME = 'ft_clanky'),
    'ALTER TABLE ka_novinky DROP INDEX ft_clanky', 'DO 0');
PREPARE kaleta_0140 FROM @kaleta_0140;
EXECUTE kaleta_0140;
DEALLOCATE PREPARE kaleta_0140;
