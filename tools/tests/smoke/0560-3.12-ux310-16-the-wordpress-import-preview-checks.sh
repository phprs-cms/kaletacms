echo "== 3.12 (UX310-16): the WordPress import preview checks the export's language against the site"
sq "DROP TABLE IF EXISTS ka_test_312; CREATE TABLE ka_test_312 AS SELECT * FROM ka_nastaveni WHERE promenna IN ('additional_languages', 'extensions')" > /dev/null
cat > "$WORK/ux16-de.xml" <<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0" xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:wp="http://wordpress.org/export/1.2/" xmlns:dc="http://purl.org/dc/elements/1.1/">
<channel><title>UX16 GmbH</title><link>https://ux16.example</link><language>de-DE</language><wp:wxr_version>1.2</wp:wxr_version><wp:base_site_url>https://ux16.example</wp:base_site_url>
<item><title>Über uns</title><link>https://ux16.example/ueber-uns-ux16/</link><dc:creator>admin</dc:creator><content:encoded><![CDATA[<p>Wir sind ein kleines Team.</p>]]></content:encoded><wp:post_id>9101</wp:post_id><wp:post_name>ueber-uns-ux16</wp:post_name><wp:status>publish</wp:status><wp:post_type>page</wp:post_type></item>
</channel></rss>
XML
ux16_read() { curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=transfer&action=progress&file=ux16-de.xml" -d "_csrf=$TOKEN"; }
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=transfer"; TOKEN=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=transfer&action=upload" -F "_csrf=$TOKEN" -F "soubor=@$WORK/ux16-de.xml"
ux16_read
check "3.12 UX310-16: a German export into a Czech site – the preview warns and preselects adding German" 200 "/admin.php?module=transfer&action=preview&file=ux16-de.xml" 'value="add:de" selected'
contains -q 'data-import-jazyk="missing"' "$WORK/response" && echo "  ok     3.12 UX310-16: the language mismatch is a warning" || { echo "  CHYBA  3.12 UX310-16: no language mismatch warning"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=transfer&action=run" -d "_csrf=$TOKEN" -d soubor=ux16-de.xml -d jazyk=add:de -d stranky=1 -d stavitel=1
ux16_read
expect "3.12 UX310-16: “add German” adds the language version and the page lands in it" \
  "$(sq "SELECT FIND_IN_SET('de', hodnota) > 0 FROM ka_nastaveni WHERE promenna = 'additional_languages'")|$(sq "SELECT jazyk FROM ka_stranky WHERE seo_link = 'ueber-uns-ux16' AND smazano IS NULL")" "1|de"
check "3.12 UX310-16: the result names the added language version" 200 "/admin.php?module=transfer&action=progress&file=ux16-de.xml" 'Deutsch'
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=transfer&action=select" -d "_csrf=$TOKEN" -d soubor=ux16-de.xml
ux16_read
check "3.12 UX310-16: with German on the site, the next preview sends the export into the German version" 200 "/admin.php?module=transfer&action=preview&file=ux16-de.xml" '<option value="de" selected>'
contains -q 'data-import-jazyk="version"' "$WORK/response" && ! contains -q 'value="add:de"' "$WORK/response" && echo "  ok     3.12 UX310-16: no warning and no offer to add a language the site has" || { echo "  CHYBA  3.12 UX310-16: language version case"; ERRORS=$((ERRORS+1)); }
check "3.12 UX310-17: Import and export keeps the last import with a way back to its next steps" 200 "/admin.php?module=transfer" 'class="hlaska import-posledni"'
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=transfer&action=delete_file" -d "_csrf=$TOKEN" -d soubor=ux16-de.xml
sq "DELETE FROM ka_stranky WHERE seo_link LIKE 'ueber-uns-ux16%'; DELETE FROM ka_nastaveni WHERE promenna IN ('additional_languages', 'extensions'); INSERT INTO ka_nastaveni SELECT * FROM ka_test_312; DROP TABLE ka_test_312" > /dev/null

