-- 3.14 (docs/design/4.0-data-model.md §3, the owner's answer 5 – expand): tags get a language version like pages, news and
-- news categories. jazyk '' = the site's default language, so every existing tag keeps meaning what it meant (the code of
-- 3.13 ignores the column). The slug gets the per-language key (jazyk, seo_link) next to the global uq_stitky_seo, which
-- implies it – no row can fail. The global key goes only with "the same address in every language version"
-- (slugs_per_language, Core\Slug::switchPerLanguage), as for the other three tables: a site that has the setting on
-- already loses it here, so its English news items can have a tag with the same slug as a Czech one.
ALTER TABLE ka_stitky ADD COLUMN jazyk VARCHAR(35) CHARACTER SET ascii NOT NULL DEFAULT '' AFTER seo_link, ADD UNIQUE KEY uq_stitky_jazyk_seo (jazyk, seo_link);
SET @kaleta_0095 = IF(EXISTS (SELECT 1 FROM ka_nastaveni WHERE promenna = 'slugs_per_language' AND hodnota = '1')
    AND EXISTS (SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ka_stitky' AND INDEX_NAME = 'uq_stitky_seo'),
    'ALTER TABLE ka_stitky DROP INDEX uq_stitky_seo', 'DO 0');
PREPARE kaleta_0095 FROM @kaleta_0095;
EXECUTE kaleta_0095;
DEALLOCATE PREPARE kaleta_0095;
