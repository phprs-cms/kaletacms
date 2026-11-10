echo "== 3.7: English system paths next to the Czech ones (GitHub #17)"
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/z-html"
contains -q 'class="ka-newsletter" method="post" action="/subscription"' "$WORK/response" && echo "  ok     3.7: the newsletter sign-up posts to /subscription" || { echo "  CHYBA  3.7: sign-up action: $(grep -o 'ka-newsletter[^>]*' "$WORK/response" | head -1)"; ERRORS=$((ERRORS+1)); }
expect "3.7: cron answers at /tasks and at the older /ulohy, a wrong token at neither" \
  "$(curl -s "$B/tasks?token=testtoken123" | cut -c1-3)|$(curl -s "$B/ulohy?token=testtoken123" | cut -c1-3)|$(curl -s -o /dev/null -w '%{http_code}' "$B/tasks?token=wrong")|$(curl -s -o /dev/null -w '%{http_code}' "$B/tasks/?token=testtoken123")" "OK |OK |403|200"
HEALTH37=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'health_token'")
expect "3.7: monitoring answers at /status.json and at the older /stav.json" \
  "$(curl -s "$B/status.json?token=$HEALTH37" | grep -o '"verze":"[^"]*"' | head -1 | cut -c1-8)|$(curl -s "$B/stav.json?token=$HEALTH37" | grep -o '"verze":"[^"]*"' | head -1 | cut -c1-8)|$(curl -s -o /dev/null -w '%{http_code}' "$B/status.json?token=wrong")" '"verze":|"verze":|403'
check "3.7: System status shows the English cron and monitoring addresses" 200 "/admin.php?module=status" "tasks?token=testtoken123"
contains -q "status.json?token=$HEALTH37" "$WORK/response" && contains -q 'starší adresu …/ulohy?token=' "$WORK/response" && echo "  ok     3.7: … and says the older cron address keeps working" || { echo "  CHYBA  3.7: status addresses"; ERRORS=$((ERRORS+1)); }
COOKIES_LOG37=$(sq "SELECT COALESCE((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'cookies_log'), '0')")
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('cookies_log', '1')" > /dev/null
expect "3.7: the consent log takes POST /consent and the older /souhlas, both stored" \
  "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$B/consent" -d id=37373737373737373737373737373737 -d kategorie=analytika)|$(curl -s -o /dev/null -w '%{http_code}' -X POST "$B/souhlas" -d id=37373737373737373737373737373738 -d kategorie=marketing)|$(sq "SELECT COUNT(*) FROM ka_souhlasy WHERE id_souhlasu LIKE '3737373737373737373737373737373%'")" "204|204|2"
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('cookies_log', '$COOKIES_LOG37'); DELETE FROM ka_souhlasy WHERE id_souhlasu LIKE '3737373737373737373737373737373%'" > /dev/null
# a page that already had the English word before 3.7 keeps it; the site's forms then keep posting to /formular
mcp vytvor_stranku '{"titulek":"Formular 37","zobrazit":true}' > /dev/null
F37=$(sq "SELECT ids FROM ka_stranky WHERE titulek = 'Formular 37'")
mcp stavba_uloz "{\"id\":$F37,\"publikovat\":true,\"stavba\":{\"v\":1,\"deti\":[{\"typ\":\"sekce\",\"deti\":[{\"typ\":\"formular\",\"obsah\":{\"nazev\":\"Form 37\"}}]}]}}" > /dev/null
mcp vytvor_stranku '{"titulek":"Form page 37","zobrazit":true,"text":"<p>Our own form page</p>"}' > /dev/null
P37=$(sq "SELECT ids FROM ka_stranky WHERE titulek = 'Form page 37'")
sq "UPDATE ka_stranky SET seo_link = 'form' WHERE ids = $P37" > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html
check "3.7: a page that held /form before 3.7 keeps it" 200 /form "Our own form page"
curl -s -o "$WORK/response" "$B/formular-37"
contains -q 'method="post" action="/formular"' "$WORK/response" && ! contains -q 'action="/form"' "$WORK/response" && echo "  ok     3.7: … and the site's forms keep posting to /formular" || { echo "  CHYBA  3.7: form action next to a page /form"; ERRORS=$((ERRORS+1)); }
check "3.7: System status names the address the page holds" 200 "/admin.php?module=status" "Anglické systémové adresy"
mcp update_page "{\"id\":$P37,\"slug\":\"form\",\"title\":\"Form page 37b\"}" > "$WORK/response"
expect "3.7: the page keeps its slug when it is saved again with it (a reserved word it had before)" "$(grep -c 'unknown_parameters\|isError' "$WORK/response" || true)|$(sq "SELECT CONCAT(seo_link, '|', titulek) FROM ka_stranky WHERE ids = $P37")" "0|form|Form page 37b"
mcp create_page '{"title":"Tasks 37","slug":"tasks"}' > "$WORK/response"
contains -q 'používá systém\|used by the system' "$WORK/response" && expect "3.7: a new page cannot take an English system path" "$(sq "SELECT COUNT(*) FROM ka_stranky WHERE seo_link = 'tasks'")" "0" || { echo "  CHYBA  3.7: a new page took /tasks: $(head -c 300 "$WORK/response")"; ERRORS=$((ERRORS+1)); }
sq "DELETE FROM ka_stranky WHERE ids = $P37" > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/formular-37"
contains -q 'method="post" action="/form"' "$WORK/response" && echo "  ok     3.7: without the page the forms post to /form again" || { echo "  CHYBA  3.7: form action after the page is gone"; ERRORS=$((ERRORS+1)); }
sq "DELETE FROM ka_stranky WHERE ids = $F37" > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html
# 3.7 security review N37-2: a page import, a restore from the trash and the page form never give a page an English system
# path – the page gets a numbered slug and the result says so; cron, the one-click unsubscribe and forms keep their path
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=pages"; TOKEN37=$(csrf)
for title in Subscription Tasks; do
  printf '{"format":"kaleta-stranka","verze":2,"titulek":"%s","popis":"","text":"<p>Imported 37</p>"}' "$title" > "$WORK/page37.json"
  curl -s -L -b "$JAR" -c "$JAR" -o "$WORK/response" -X POST "$B/admin.php?module=pages&action=import" -F "_csrf=$TOKEN37" -F "soubor=@$WORK/page37.json;type=application/json"
done
expect "3.7 N37-2: an imported page \"Tasks\" or \"Subscription\" gets a numbered slug, and the import says why" \
  "$(sq "SELECT GROUP_CONCAT(seo_link ORDER BY seo_link) FROM ka_stranky WHERE titulek IN ('Subscription', 'Tasks') AND smazano IS NULL")|$(grep -c 'Adresu /tasks používá systém, proto stránka dostala /tasks-2.' "$WORK/response" || true)" "subscription-2,tasks-2|1"
sq "INSERT INTO ka_odberatele (email, stav, token, datum) VALUES ('n37@example.cz', 0, '37373737373737373737373737373737', '$(site_time)')" > /dev/null
curl -s -o /dev/null -X POST "$B/subscription?unsubscribe=37373737373737373737373737373737" -H 'Content-Type: application/x-www-form-urlencoded' -d 'List-Unsubscribe=One-Click'
expect "3.7 N37-2: … so cron still runs at /tasks and the one-click unsubscribe at /subscription still unsubscribes" \
  "$(curl -s "$B/tasks?token=testtoken123" | cut -c1-3)|$(sq "SELECT COUNT(*) FROM ka_odberatele WHERE email = 'n37@example.cz'")" "OK |0"
mcp create_page '{"title":"Consent 37","text":"<p>Consent page 37</p>"}' > /dev/null; mcp create_page '{"title":"Conversion 37","text":"<p>Conversion page 37</p>"}' > /dev/null
C37=$(sq "SELECT ids FROM ka_stranky WHERE titulek = 'Consent 37'"); V37=$(sq "SELECT ids FROM ka_stranky WHERE titulek = 'Conversion 37'")
# pages "consent" and "conversion" from before 3.7, in the trash (while there, the system took the English address back)
sq "UPDATE ka_stranky SET seo_link = 'consent', smazano = '$(site_time)' WHERE ids = $C37; UPDATE ka_stranky SET seo_link = 'conversion', smazano = '$(site_time)' WHERE ids = $V37" > /dev/null
mcp restore_from_trash "{\"type\":\"page\",\"id\":$C37}" > "$WORK/response"
expect "3.7 N37-2: restore_from_trash brings a page back under a free slug when the system uses its old one" \
  "$(sq "SELECT seo_link FROM ka_stranky WHERE ids = $C37")|$(mcp_value address)|$(mcp_value note)" "consent-2|/consent-2|The system uses the old address /consent of the page, so the page got a new one."
curl -s -L -b "$JAR" -c "$JAR" -o "$WORK/response" -X POST "$B/admin.php?module=pages&action=restore" -d "_csrf=$TOKEN37" -d "ids=$V37"
expect "3.7 N37-2: … and so does Pages → Trash → Restore, with the reason" \
  "$(sq "SELECT seo_link FROM ka_stranky WHERE ids = $V37")|$(grep -c 'Stránka je obnovená s adresou /conversion-2, protože její dřívější adresu /conversion používá systém' "$WORK/response" || true)" "conversion-2|1"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=pages&action=save" -d "_csrf=$TOKEN37" -d ids=0 --data-urlencode "titulek=Form" -d seo_link= -d zobrazit=0 -d v_menu=0 -d "text=<p>Form 37</p>"
expect "3.7 N37-2: the page form makes a free slug from a title that is a system word" "$(sq "SELECT seo_link FROM ka_stranky WHERE text = '<p>Form 37</p>'")" "form-2"
sq "DELETE FROM ka_stranky WHERE titulek IN ('Subscription', 'Tasks') OR ids IN ($C37, $V37) OR text = '<p>Form 37</p>'" > /dev/null
# a page that holds /tasks from before 3.7 while cron is not running: System status says why cron may be calling the page
TASKS_LAST37=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'tasks_last_run'")
sq "INSERT INTO ka_stranky (titulek, seo_link, text, zobrazit, v_menu, zmeneno) VALUES ('Tasks 37', 'tasks', '<p>Our tasks</p>', 1, 0, '$(site_time)'); UPDATE ka_nastaveni SET hodnota = '1' WHERE promenna = 'tasks_last_run'" > /dev/null
check "3.7 N37-2: System status warns when a page holds /tasks and cron does not run" 200 "/admin.php?module=status" "Cron, který volá /tasks, se dostane na tu stránku a nic nespustí – nasměrujte ho na /ulohy."
expect "3.7 N37-2: … the page answers at /tasks, cron at /ulohy" "$(curl -s "$B/tasks?token=testtoken123" | grep -c 'Our tasks' || true)|$(curl -s "$B/ulohy?token=testtoken123" | cut -c1-3)" "1|OK "
sq "DELETE FROM ka_stranky WHERE titulek = 'Tasks 37'; UPDATE ka_nastaveni SET hodnota = '$TASKS_LAST37' WHERE promenna = 'tasks_last_run'" > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html

