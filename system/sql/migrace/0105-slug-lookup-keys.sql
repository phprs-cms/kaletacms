-- 3.19 (docs/design/4.0-data-model.md §3 step 2): the global unique slug keys of pages, news, news categories and tags go
-- with the data migration 0106 – the same address may then be used once in each language version. Lookups that ask for a
-- slug in any version (a news item of another version still answers and redirects to its own, the reserved addresses, the
-- site audit) keep an index: a plain key on seo_link, added here on every site. Next to a global unique key (a site that
-- cannot switch yet) it is redundant and harmless; the code of 3.18.3 ignores it.
ALTER TABLE ka_stranky ADD KEY ix_stranky_seo (seo_link);
ALTER TABLE ka_novinky ADD KEY ix_clanky_seo (seo_link);
ALTER TABLE ka_kategorie ADD KEY ix_topic_seo (seo_link);
ALTER TABLE ka_stitky ADD KEY ix_stitky_seo (seo_link);
