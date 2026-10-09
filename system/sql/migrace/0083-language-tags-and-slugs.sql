-- 3.9 (docs/design/4.0-data-model.md §2 and §3, step 1 – expand). Nothing an existing site has changes:
-- 1. Language columns take a BCP 47 tag (pt-br, zh-hant): VARCHAR(35) in the ASCII character set instead of CHAR(2) /
--    VARCHAR(2). Every stored value is a two-letter code or '' and stays as it is; the code of 3.8 reads the wider
--    columns unchanged. The primary keys that contain the language are rebuilt by the same statement.
-- 2. Slugs of pages, news and news categories get a unique key per language, (jazyk, seo_link), next to the global key
--    (uq_stranky_seo, uq_clanky_seo, uq_topic_seo), which implies it – no row can fail. The global keys stay; they are
--    dropped only when the administrator switches on "the same address in every language version" (setting
--    slugs_per_language, Core\Slug::switchPerLanguage), and put back when it is switched off.
ALTER TABLE ka_uzivatele MODIFY jazyk VARCHAR(35) CHARACTER SET ascii NOT NULL DEFAULT '';
ALTER TABLE ka_kategorie MODIFY jazyk VARCHAR(35) CHARACTER SET ascii NOT NULL DEFAULT '', ADD UNIQUE KEY uq_topic_jazyk_seo (jazyk, seo_link);
ALTER TABLE ka_novinky MODIFY jazyk VARCHAR(35) CHARACTER SET ascii NOT NULL DEFAULT '', ADD UNIQUE KEY uq_clanky_jazyk_seo (jazyk, seo_link);
ALTER TABLE ka_stranky MODIFY jazyk VARCHAR(35) CHARACTER SET ascii NOT NULL DEFAULT '', ADD UNIQUE KEY uq_stranky_jazyk_seo (jazyk, seo_link);
ALTER TABLE ka_casti MODIFY jazyk VARCHAR(35) CHARACTER SET ascii NOT NULL DEFAULT '';
ALTER TABLE ka_kolekce_polozky MODIFY jazyk VARCHAR(35) CHARACTER SET ascii NOT NULL DEFAULT '';
ALTER TABLE ka_kolekce_sablony MODIFY jazyk VARCHAR(35) CHARACTER SET ascii NOT NULL;
ALTER TABLE ka_collection_category_texts MODIFY language VARCHAR(35) CHARACTER SET ascii NOT NULL DEFAULT '';
ALTER TABLE ka_collection_category_templates MODIFY jazyk VARCHAR(35) CHARACTER SET ascii NOT NULL DEFAULT '';
ALTER TABLE ka_menu MODIFY jazyk VARCHAR(35) CHARACTER SET ascii NOT NULL DEFAULT '';
ALTER TABLE ka_newsletters MODIFY language VARCHAR(35) CHARACTER SET ascii NOT NULL DEFAULT '';
ALTER TABLE ka_facts MODIFY language VARCHAR(35) CHARACTER SET ascii NOT NULL DEFAULT '';
ALTER TABLE ka_fact_history MODIFY language VARCHAR(35) CHARACTER SET ascii NOT NULL DEFAULT '';
ALTER TABLE ka_bookings MODIFY language VARCHAR(35) CHARACTER SET ascii NOT NULL DEFAULT '';
