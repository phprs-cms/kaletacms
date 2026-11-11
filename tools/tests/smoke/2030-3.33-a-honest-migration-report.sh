echo "== 3.33 part A: an honest migration report – sample pages, a site-wide form once, title changes, an unreachable old site"
s3_install firemni
NEWSLETTER333='<form action="/subscribe"><input type="email" name="email"><button>Odebírat</button></form>'
rm -rf "$WORK/old333"; mkdir -p "$WORK/old333/kontakt" "$WORK/old333/o-nas" "$WORK/old333/sluzby" "$WORK/old333/reference"
old333page "Vítejte u Dubu" "Kotle a topení" "$NEWSLETTER333" > "$WORK/old333/index.html"
old333page "Kontaktujte nás" "Napište nám" "<form action=\"/send\"><input name=\"jmeno\"><textarea name=\"zprava\"></textarea></form>$NEWSLETTER333" > "$WORK/old333/kontakt/index.html"
old333page "O firmě" "Kdo jsme" "$NEWSLETTER333" > "$WORK/old333/o-nas/index.html"
old333page "Naše služby" "Co děláme" "$NEWSLETTER333" > "$WORK/old333/sluzby/index.html"
old333page "Reference" "Co jsme udělali" "$NEWSLETTER333" > "$WORK/old333/reference/index.html"
old333_sitemap / /kontakt/ /o-nas/ /sluzby/ /reference/
printf 'User-agent: *\nSitemap: %s/sitemap.xml\n' "$OLD333" > "$WORK/old333/robots.txt"
(cd "$WORK/old333" && exec php -S "127.0.0.1:$OLD333_PORT" > /dev/null 2>&1) & OLD333_PID=$!
for i in $(seq 1 30); do curl -s -o /dev/null "$OLD333/" && break; sleep 0.3; done
s3_report() { s3mcp migration_report "{\"url\":\"$1\"}" > "$WORK/response"; S3_REPORT=$(s3_field report_id)
  for i in $(seq 1 20); do [ "$(s3_field phase)" = done ] && break; s3mcp migration_report "{\"report_id\":\"$S3_REPORT\"}" > "$WORK/response"; done; }
s3_report "$OLD333"
expect "3.33 M5 migration_report: old addresses on untouched sample pages are not ok – four on sample pages, one missing" \
  "$(s3_field summary ok)|$(s3_field on_sample_pages)|$(s3_field summary missing)|$(s3_field problems | php -r 'echo implode(",", array_map(fn ($r) => $r["old"] . ":" . $r["status"], array_filter(json_decode(stream_get_contents(STDIN), true), fn ($r) => $r["status"] === "sample")));')" \
  "0|4|1|/:sample,/kontakt:sample,/o-nas:sample,/sluzby:sample"
contains -q 'sample_page' "$WORK/response" && echo "  ok     3.33 M5: the problem says the address leads to a sample page" || { echo "  CHYBA  3.33 M5 sample_page problem"; ERRORS=$((ERRORS+1)); }
expect "3.33 M5 migration_report: the newsletter form on every old page is one site-wide form (with its fields) and one site check; the starter contact page has a form of its own, so nothing is missing yet" \
  "$(s3_field site_wide_forms)|$(s3_field site_checks | grep -o 'A form is on 5 of the old pages' | head -1)|$(s3_field problems | php -r 'echo implode(",", array_map(fn ($r) => $r["old"], array_filter(json_decode(stream_get_contents(STDIN), true), fn ($r) => in_array("form_missing", array_column($r["problems"], "code"), true))));')" \
  '[{"fields":"email","old_pages":5}]|A form is on 5 of the old pages|'
curl -s -b "$S3_JAR" -o "$WORK/response" "$S3/admin.php?module=transfer&action=report&id=$S3_REPORT"
contains -q 'Na ukázkové stránce' "$WORK/response" && contains -q 'Formulář celého webu: na 5 starých stránkách' "$WORK/response" \
  && echo "  ok     3.33 M5: the admin report counts the sample pages and names the site-wide form once" || { echo "  CHYBA  3.33 M5 admin report"; ERRORS=$((ERRORS+1)); }
# after the import (the sample pages replaced, everything hidden) and one title changed by hand: no sample page, the title change listed
s3mcp import_website "{\"url\":\"$OLD333\",\"images\":false}" > "$WORK/response"; S3_IMPORT=$(s3_field import_id)
for i in $(seq 1 20); do [ "$(s3_field phase)" = finding ] || break; s3mcp import_website "{\"import_id\":\"$S3_IMPORT\"}" > "$WORK/response"; done
s3mcp import_website "{\"import_id\":\"$S3_IMPORT\",\"confirm\":true}" > "$WORK/response"
for i in $(seq 1 20); do [ "$(s3_field phase)" = importing ] || break; s3mcp import_website "{\"import_id\":\"$S3_IMPORT\"}" > "$WORK/response"; done
s3mcp update_page "{\"id\":$(s3q "SELECT ids FROM ka_stranky WHERE seo_link = 'reference' AND smazano IS NULL"),\"title\":\"Naše zakázky\"}" > /dev/null
s3_report "$OLD333"
expect "3.33 M5 migration_report after the import: no address on a sample page, all five not published yet, the changed title listed" \
  "$(s3_field on_sample_pages)|$(s3_field summary not_published)|$(s3_field title_changes)" \
  '0|5|[{"old":"/reference","old_title":"Reference – Topení Dub","new_title":"Naše zakázky"}]'
kill "$OLD333_PID" 2>/dev/null || true; OLD333_PID=

# an old site that does not answer: said plainly, never an empty site
DEAD333="http://127.0.0.1:$((PORT + 11))"
s3_report "$DEAD333"
expect "3.33 M6 migration_report: an unreachable old site is said plainly" \
  "$(s3_field old_site_reachable)|$(s3_field notes | grep -c 'could not be reached' || true)" "false|1"
s3mcp import_website "{\"url\":\"$DEAD333\"}" > "$WORK/response"; S3_IMPORT=$(s3_field import_id)
for i in $(seq 1 20); do [ "$(s3_field phase)" = finding ] || break; s3mcp import_website "{\"import_id\":\"$S3_IMPORT\"}" > "$WORK/response"; done
expect "3.33 M6 import_website: nothing found because nothing answered – the answer says so" \
  "$(s3_field phase)|$(s3_field old_site_reachable)|$(s3_field notes | grep -c 'could not be reached' || true)" "preview|false|1"
