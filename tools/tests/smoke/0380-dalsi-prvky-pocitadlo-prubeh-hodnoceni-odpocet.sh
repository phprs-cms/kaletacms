echo "== další prvky: počítadlo, průběh, hodnocení, odpočet, sítě, hledání, nahoru, odběr, podmínky"
"${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO ka_nastaveni VALUES ('social_instagram','https://instagram.com/firma') ON DUPLICATE KEY UPDATE hodnota=VALUES(hodnota)"
mcp stavba_uloz "{\"id\":$IDZ,\"publikovat\":true,\"stavba\":{\"v\":1,\"deti\":[{\"typ\":\"sekce\",\"obsah\":{\"video\":\"media/2026/01/pozadi.mp4\"},\"deti\":[
{\"typ\":\"pocitadlo\",\"obsah\":{\"cislo\":1200,\"za\":\"+\"}},
{\"typ\":\"prubeh\",\"obsah\":{\"polozky\":[{\"nazev\":\"Termíny\",\"hodnota\":96}]}},
{\"typ\":\"hodnoceni\",\"obsah\":{\"hodnota\":\"4,5\"}},
{\"typ\":\"odpocet\",\"obsah\":{\"cil\":\"2099-01-01 09:00\"}},
{\"typ\":\"socialni\"},{\"typ\":\"hledani\"},{\"typ\":\"nahoru\"},{\"typ\":\"newsletter\"},
{\"typ\":\"nadpis\",\"obsah\":{\"text\":\"Jen pro redakci\"},\"podminky\":{\"prihlaseni\":\"ano\"}},
{\"typ\":\"nadpis\",\"obsah\":{\"text\":\"Stará akce\"},\"podminky\":{\"do\":\"2000-01-01\"}},
{\"typ\":\"video\",\"obsah\":{\"url\":\"media/2026/01/film.mp4\",\"plakat\":\"media/2026/01/plakat.jpg\"}}
]}]}}" > "$WORK/response"
grep -q 'chyby\\":\[\]' "$WORK/response" && echo "  ok     další prvky projdou validátorem" || { echo "  CHYBA  validace dalších prvků"; head -c 600 "$WORK/response"; ERRORS=$((ERRORS+1)); }
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/z-html"
for pattern in 'data-pocitadlo="1200">1' '<meter min="0" max="100"' 'aria-label="Hodnocení 4,5 z 5' 'data-odpocet="2099-01-01T09:00' 'class="ka-socialni"' 'aria-label="Instagram"' 'role="search"' 'class="ka-nahoru"' 'class="ka-newsletter"' 'name="as_podpis"' 'class="ka-video-pozadi"' 'poster="/media/2026/01/plakat.jpg"' 'image/web.js'; do
  grep -qF -- "$pattern" "$WORK/response" || { echo "  CHYBA  další prvek na webu: chybí $pattern"; ERRORS=$((ERRORS+1)); }
done
! grep -q 'Jen pro redakci\|Stará akce' "$WORK/response" && echo "  ok     podmínky zobrazení skryjí prvek nepřihlášenému i po datu" || { echo "  CHYBA  podmínky zobrazení"; ERRORS=$((ERRORS+1)); }
# 3.5 (UXP-05): the timer role sits on a wrapper – on the <dl> it replaced the list role and orphaned <dt>/<dd>
grep -qF 'role="timer" aria-live="off"><dl><div><dt>' "$WORK/response" && ! grep -q '<dl[^>]*role="timer"' "$WORK/response" \
  && echo "  ok     3.5: countdown – the timer role on a wrapper, the description list keeps its own" || { echo "  CHYBA  3.5: countdown markup"; ERRORS=$((ERRORS+1)); }
curl -s -o /dev/null "$B/z-html"; ls "$WORK"/web/storage/cache/stranky/*.html >/dev/null 2>&1 && { echo "  CHYBA  stránka s podmínkou zobrazení šla do cache"; ERRORS=$((ERRORS+1)); } || echo "  ok     stránka s podmínkou zobrazení se necachuje"
curl -s -b "$JAR" -o "$WORK/response" "$B/z-html"; grep -q 'Jen pro redakci' "$WORK/response" && echo "  ok     přihlášený vidí prvek jen pro redakci" || { echo "  CHYBA  prvek pro přihlášené"; ERRORS=$((ERRORS+1)); }
# 3.5 (UXP-08): the first image of the first section loads at once (usually the hero, the LCP) unless its editor chose
# otherwise; later images stay lazy; builds saved before 3.5 keep working (priority true = at once)
mcp stavba_z_html '{"titulek":"Obrazky 35","html":"<section><div><img src=\"/media/2026/01/hero35.jpg\" alt=\"Hero35\"></div><img src=\"/media/2026/01/druhy35.jpg\" alt=\"Druhy35\"></section><section><img src=\"/media/2026/01/treti35.jpg\" alt=\"Treti35\"></section>"}' > /dev/null
ID35=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT ids FROM ka_stranky WHERE seo_link = 'obrazky-35'")
mcp publikuj_stavbu "{\"id\":$ID35}" > /dev/null; "${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_stranky SET zobrazit = 1 WHERE ids = $ID35"; rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/obrazky-35"
grep -q 'alt="Hero35" fetchpriority="high"' "$WORK/response" && grep -q 'alt="Druhy35" loading="lazy"' "$WORK/response" && grep -q 'alt="Treti35" loading="lazy"' "$WORK/response" \
  && echo "  ok     3.5: the first image of the first section loads at once, the others lazily" || { echo "  CHYBA  3.5: automatic priority of the first image"; grep -o '<img[^>]*>' "$WORK/response"; ERRORS=$((ERRORS+1)); }
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_stranky SET stavba = REPLACE(stavba, '\"priorita\":\"\"', '\"priorita\":false') WHERE ids = $ID35"; rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/obrazky-35"
grep -q 'alt="Hero35" fetchpriority="high"' "$WORK/response" && echo "  ok     3.5: a build saved before 3.5 (priority false) gets the automatic priority too" || { echo "  CHYBA  3.5: an older build's first image"; ERRORS=$((ERRORS+1)); }
mcp get_build "{\"id\":$ID35}" > "$WORK/response"
BUILD35=$(php -r '$t = json_decode(json_decode((string) file_get_contents($argv[1]), true)["result"]["content"][0]["text"], true); $b = $t["build"]; $b["children"][0]["children"][0]["children"][0]["content"]["priority"] = "lazy"; $b["children"][0]["children"][1]["content"]["priority"] = true; echo json_encode($b);' -- "$WORK/response")
mcp save_build "{\"id\":$ID35,\"publish\":true,\"build\":$BUILD35}" > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/obrazky-35"
grep -q 'alt="Hero35" loading="lazy"' "$WORK/response" && grep -q 'alt="Druhy35" fetchpriority="high"' "$WORK/response" && grep -q 'alt="Treti35" loading="lazy"' "$WORK/response" \
  && expect "3.5: Claude chooses the loading in English (lazy, or true from before), stored as '0' and '1'" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(stavba LIKE '%\"priorita\":\"0\"%', stavba LIKE '%\"priorita\":\"1\"%') FROM ka_stranky WHERE ids = $ID35")" "11" \
  || { echo "  CHYBA  3.5: an explicit loading setting wins over the automatic one"; grep -o '<img[^>]*>' "$WORK/response"; ERRORS=$((ERRORS+1)); }
curl -s -o "$WORK/response" "$B/z-html"
NEWSLETTER_FORM=$(tr '\n' ' ' < "$WORK/response" | grep -o 'class="ka-newsletter".*' | sed 's#</form>.*##')
NL_SIGNATURE=$(echo "$NEWSLETTER_FORM" | grep -o 'name="as_podpis" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//'); NL_TIME=$(echo "$NEWSLETTER_FORM" | grep -o 'name="as_cas" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
sleep 5
code=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -X POST "$B/odber" -d "email=Odber@Example.cz" -d zpet=/z-html -d kotva=x -d "as_podpis=$NL_SIGNATURE" -d "as_cas=$NL_TIME" -d web_adresa=)
case "$code" in "303 "*"/z-html?subscription=ok#x") echo "  ok     přihlášení k odběru";; *) echo "  CHYBA  přihlášení k odběru: $code"; ERRORS=$((ERRORS+1));; esac
SUB_TOKEN=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT token FROM ka_odberatele WHERE email = 'odber@example.cz' AND stav = 0")
check "odkaz z e-mailu jen nabídne potvrzení" 200 "/odber?confirm=$SUB_TOKEN" "Potvrdit odběr"
check "old link (potvrdit=) from an e-mail sent before the rename still offers the confirmation" 200 "/odber?potvrdit=$SUB_TOKEN" "Potvrdit odběr"
expect "otevření odkazu (skener pošty) odběr nepotvrdí" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT stav FROM ka_odberatele WHERE email = 'odber@example.cz'")" "0"
curl -s -o "$WORK/response" -X POST "$B/odber?confirm=$SUB_TOKEN"; grep -q "Odběr je potvrzený" "$WORK/response" && echo "  ok     potvrzení odběru tlačítkem" || { echo "  CHYBA  potvrzení odběru"; ERRORS=$((ERRORS+1)); }
expect "odběratel je potvrzený" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT stav FROM ka_odberatele WHERE email = 'odber@example.cz'")" "1"
check "odběratelé v administraci" 200 "/admin.php?module=subscribers" "odber@example.cz"
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=subscribers&action=csv"; grep -q "odber@example.cz;.*/subscription?unsubscribe=$SUB_TOKEN" "$WORK/response" && echo "  ok     export odběratelů s odkazem na odhlášení" || { echo "  CHYBA  export odběratelů"; head -3 "$WORK/response"; ERRORS=$((ERRORS+1)); }
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_nastaveni SET hodnota = REPLACE(hodnota, 'newsletter,', '') WHERE promenna = 'extensions'"
check "odhlášení jde i s vypnutým Newsletterem" 200 "/odber?unsubscribe=$SUB_TOKEN" "Odhlásit odběr"
check "old link (odhlasit=) from an e-mail sent before the rename still offers the unsubscribe" 200 "/odber?odhlasit=$SUB_TOKEN" "Odhlásit odběr"
curl -s -o "$WORK/response" -X POST "$B/odber?unsubscribe=$SUB_TOKEN"; grep -q "Odhlášeno" "$WORK/response" && echo "  ok     odhlášení tlačítkem" || { echo "  CHYBA  odhlášení"; ERRORS=$((ERRORS+1)); }
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_nastaveni SET hodnota = REPLACE(hodnota, 'poptavky,', 'poptavky,newsletter,') WHERE promenna = 'extensions'"
expect "odhlášený je smazaný" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_odberatele")" "0"

