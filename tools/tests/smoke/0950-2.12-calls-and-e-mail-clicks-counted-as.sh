echo "== 2.12: calls and e-mail clicks counted as conversions (Core\\Conversions)"
# a page with nothing but a phone number keeps image/web.js while the statistics are on – the click counter needs it and learns the endpoint from data-konverze; never for signed-in users
mcp vytvor_stranku '{"titulek":"Volejte 212","zobrazit":true,"text":"<p>Zavolejte: <a href=\"tel:+420777000212\">+420 777 000 212</a></p>"}' > /dev/null
rm -f "$WORK"/web/storage/cache/stranky/*.html; curl -s -o "$WORK/response" -A 'Mozilla/5.0 test' "$B/volejte-212"
grep -q 'image/web.js?v=[^"]*" defer blocking="render"[^>]* data-konverze="/conversion"></script>' "$WORK/response" && echo "  ok     2.12: a page with only a tel: link keeps web.js with the click endpoint (3.7: /conversion) when the statistics are on" || { echo "  CHYBA  web.js on a page with a tel: link"; ERRORS=$((ERRORS+1)); }
hit_from "$WORK/response" > /dev/null # 3.29: the browser confirms the page view – clicks count only on pages the statistics have seen
curl -s -b "$JAR" -o "$WORK/response" "$B/volejte-212"; ! grep -q 'data-konverze' "$WORK/response" && echo "  ok     2.12: no click counter for signed-in users" || { echo "  CHYBA  data-konverze for a signed-in user"; ERRORS=$((ERRORS+1)); }
# the beacon: once per visitor, type and page a day – the visitor is the statistics' daily fingerprint (IP and browser), nothing of it is stored with the count
beacon() { curl -s -o /dev/null -w '%{http_code}' -X POST "$B/konverze" -A "${3:-Mozilla/5.0 test}" -d "type=$1" -d "path=$2"; }
expect "2.12: a click beacon answers 204" "$(beacon tel /volejte-212)" 204
beacon tel /volejte-212 > /dev/null                                   # the same visitor again – one call, not two
beacon tel '/volejte-212?utm_source=x#telefon' > /dev/null            # the same page with a query string and a fragment – still the same page and visitor
curl -s -o /dev/null -X POST "$B/conversion" -A 'Mozilla/5.0 test' -d type=mailto -d path=/volejte-212   # another type counts on its own (3.7: through /conversion)
beacon tel /volejte-212 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148' > /dev/null  # another browser = another visitor
beacon fax /volejte-212 > /dev/null                                   # an unknown type
beacon tel /volejte-212 'curl/8.0' > /dev/null                        # a bot
beacon tel /neexistuje-212 > /dev/null                                # a page the statistics never saw
beacon tel 'volejte-212' > /dev/null                                  # not a path
curl -s -o /dev/null -b "$JAR" -X POST "$B/konverze" -A 'Mozilla/5.0 test' -d type=whatsapp -d path=/volejte-212   # signed in – never counted
expect "2.12: once per visitor, type and page a day; unknown types, bots, made-up pages and signed-in users are not counted" "$(sq "SELECT GROUP_CONCAT(CONCAT(cesta, ':', typ, ':', pocet) ORDER BY typ) FROM ka_stat_konverze")" "/volejte-212:mailto:1,/volejte-212:tel:2"
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=stats&days=7"
grep -q 'href="/volejte-212"' "$WORK/response" && grep -q '<td class="cislo">2 / 1 / 0</td>' "$WORK/response" && grep -q 'Kontaktní kliknutí (hovory, e-maily, WhatsApp)' "$WORK/response" && echo "  ok     2.12: Statistics show calls, e-mails and WhatsApp per page and in total" || { echo "  CHYBA  Statistics: contact clicks"; ERRORS=$((ERRORS+1)); }
mcp get_stats '{"days":7}' > "$WORK/response"; mcp_text
contains -q '"contact_clicks":{"calls":2,"emails":1,"whatsapp":0,"by_page":\[{"path":"/volejte-212","calls":2,"emails":1,"whatsapp":0}\]}' "$WORK/text" && contains -q '"path":"/volejte-212","views":[0-9]*,"enquiries":0,"signups":0,"calls":2,"emails":1,"whatsapp":0' "$WORK/text" \
  && echo "  ok     2.12: get_stats carries contact_clicks and the clicks of every page" || { echo "  CHYBA  get_stats contact_clicks"; head -c 600 "$WORK/text"; ERRORS=$((ERRORS+1)); }
# the monthly report mentions calls and e-mails when the month had any
sq "INSERT INTO ka_stat_konverze (den, cesta, typ, pocet) VALUES ('$(php -r 'echo (new DateTimeImmutable("first day of last month"))->format("Y-m-d");')', '/volejte-212', 'tel', 4), ('$(php -r 'echo (new DateTimeImmutable("first day of last month"))->format("Y-m-d");')', '/volejte-212', 'mailto', 2)" > /dev/null
sq "DELETE FROM ka_monthly_reports; UPDATE ka_jobs SET last_run = '$(site_time)' WHERE name = 'report_archive'" > /dev/null # 3.16: without the archived month the e-mail is built with the clicks above (the job held back)
check "2.12: the monthly report mentions the calls and e-mails of the month" 200 "/admin.php?module=settings&action=report_preview" "Hovory – kliknutí na telefonní číslo"
grep -q 'E-maily – kliknutí na e-mailovou adresu' "$WORK/response" && ! grep -q 'WhatsApp – kliknutí' "$WORK/response" && echo "  ok     2.12: the report lists only the kinds of clicks there were" || { echo "  CHYBA  monthly report: contact clicks"; ERRORS=$((ERRORS+1)); }
expect "3.16 N316-7: the preview (GET) builds a missing month in memory and stores nothing" "$(sq "SELECT COUNT(*) FROM ka_monthly_reports")" "0"
# statistics off: no endpoint on the page and no counting
stats_feature 0
curl -s -o "$WORK/response" -A 'Mozilla/5.0 test' "$B/volejte-212"; ! grep -q 'data-konverze' "$WORK/response" && echo "  ok     2.12: statistics off – the page carries no click endpoint" || { echo "  CHYBA  data-konverze with the statistics off"; ERRORS=$((ERRORS+1)); }
beacon tel /volejte-212 'Mozilla/5.0 (X11; Linux x86_64) third' > /dev/null
expect "2.12: statistics off – a click is not counted" "$(sq "SELECT SUM(pocet) FROM ka_stat_konverze WHERE den = '$(site_date today)'")" 3
stats_feature 1

