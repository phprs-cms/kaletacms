echo "== stránky: SEO, koš, duplikace"
IDS=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT ids FROM ka_stranky WHERE seo_link = 'kontakty'")
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=pages&action=save" -d "_csrf=$TOKEN" -d "ids=$IDS" -d titulek=Kontakt -d seo_link=kontakty -d zobrazit=1 -d v_menu=1 -d "text=<p>Adresa.</p>" \
  --data-urlencode "seo_titulek=Kontakt na truhlárnu" -d obrazek=media/2026/01/sdileni.jpg -d noindex=1
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/kontakty"
grep -q '<title>Kontakt na truhlárnu' "$WORK/response" && grep -q 'og:image" content="http[^"]*/media/2026/01/sdileni.jpg"' "$WORK/response" && grep -q 'noindex, follow' "$WORK/response" \
  && echo "  ok     stránka: vlastní titulek, úplná adresa obrázku pro sdílení, noindex" || { echo "  CHYBA  SEO stránky"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=pages&action=duplicate" -d "_csrf=$TOKEN" -d "ids=$IDS"
expect "duplikát stránky je skrytý a má volnou adresu" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(zobrazit, '/', seo_link) FROM ka_stranky ORDER BY ids DESC LIMIT 1")" "0/kontakty-kopie"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=pages&action=delete" -d "_csrf=$TOKEN" -d "ids=$IDS"
check "stránka v koši není na webu" 404 /kontakty
check "záložka Koš u stránek" 200 "/admin.php?module=pages&status=kos" "Kontakt"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=pages&action=restore" -d "_csrf=$TOKEN" -d "ids=$IDS"
expect "obnovená stránka je skrytá" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(zobrazit, '/', smazano IS NULL) FROM ka_stranky WHERE ids = $IDS")" "0/1"
IDU=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT ids FROM ka_stranky WHERE seo_link = 'o-nas'")
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_nastaveni SET hodnota='$IDU' WHERE promenna='home_page'"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=pages&action=delete" -d "_csrf=$TOKEN" -d "ids=$IDU"
expect "úvodní stránku nejde smazat" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT smazano IS NULL FROM ka_stranky WHERE ids = $IDU")" "1"
# a language version in progress (without a published translation of the home page) is not offered in the switcher, hreflang or the sitemap
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_stranky SET zobrazit = 1, smazano = NULL WHERE ids = $IDU"; rm -f "$WORK"/web/storage/cache/stranky/*.html
# the pages are saved first: „curl | grep -q“ with pipefail fails when grep exits before curl finishes writing (SIGPIPE)
curl -s -o "$WORK/response" "$B/"; curl -s -o "$WORK/mapa.xml" "$B/sitemap.xml"
! grep -q 'hreflang="en"' "$WORK/response" && ! grep -q '/en/</loc>' "$WORK/mapa.xml" \
  && echo "  ok     jazyk bez zveřejněného překladu úvodu se návštěvníkům nenabízí" || { echo "  CHYBA  rozpracovaný jazyk v přepínači nebo mapě webu"; ERRORS=$((ERRORS+1)); }
mcp vytvor_stranku "{\"titulek\":\"About home\",\"adresa\":\"about-home\",\"jazyk\":\"en\",\"preklad_z\":$IDU,\"text\":\"<p>Home</p>\",\"zobrazit\":1}" > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/"; curl -s -o "$WORK/mapa.xml" "$B/sitemap.xml"
grep -q 'hreflang="en"' "$WORK/response" && grep -q '/en/</loc>' "$WORK/mapa.xml" \
  && echo "  ok     se zveřejněným překladem úvodu se jazyk nabízí" || { echo "  CHYBA  hotový jazyk chybí v přepínači nebo mapě webu"; ERRORS=$((ERRORS+1)); }
mcp stavba_uloz '{"cast":"paticka","publikovat":true,"stavba":{"v":1,"deti":[{"typ":"sekce","znacka":"footer","deti":[{"typ":"udaje","obsah":{"udaj":"copyright"}},{"typ":"jazyky"}]}]}}' > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/"
grep -q 'ka-jazyky-vyber--nahoru ka-jazyky-prvek' "$WORK/response" && grep -q 'hreflang="en" lang="en"' "$WORK/response" && grep -q 'image/web.js' "$WORK/response" \
  && echo "  ok     prvek Přepínač jazyků v patičce (nabídka nahoru) a web.js pro jazyk prohlížeče" || { echo "  CHYBA  prvek Přepínač jazyků"; ERRORS=$((ERRORS+1)); }
mcp stavba_uloz '{"cast":"hlavicka","publikovat":true,"stavba":{"v":1,"deti":[{"typ":"sekce","znacka":"header","deti":[{"typ":"navigace","obsah":{"jazyky":false}},{"typ":"navigace","obsah":{"menu":"paticka"}}]}]}}' > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/"
expect "Navigace s vypnutým přepínačem jazyků ho nemá, ostatní navigace ano" "$(grep -o '<nav class="ka-jazyky"' "$WORK/response" | wc -l | tr -d ' ')" 1
# 2.7: a header transparent at the top and smaller after scrolling (English vocabulary): fixed after its own sticky style, scroll-driven animation, CSS only now
mcp save_build '{"part":"header","publish":true,"build":{"v":1,"children":[{"type":"section","tag":"header","content":{"on_scroll":"transparent_shrink","text_at_top":"light"},"style":{"base":{"position":"sticky","background":"background"}},"children":[{"type":"navigation","content":{"mega_menu":true}}]}]}}' > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/"
grep -q '<header id="s-[a-z0-9]*" class="ka-hlavicka-rolovani ka-hlavicka-rolovani--pruhledna">' "$WORK/response" && grep -q 'position: sticky; background-color: var(--ka-barva-pozadi); position: fixed; top: 0; inset-inline: 0; animation: ka-hlavicka-svetla linear both, ka-hlavicka-mensi linear both; animation-timeline: scroll(root); animation-range: 0 120px;' "$WORK/response" \
  && grep -q '@keyframes ka-hlavicka-svetla' "$WORK/response" && grep -q 'prefers-reduced-motion: reduce) { .ka-hlavicka-rolovani { animation: none !important; } }' "$WORK/response" \
  && echo "  ok     záhlaví nahoře průhledné a po odrolování menší (animace podle posuvu stránky, bez skriptu)" || { echo "  CHYBA  záhlaví při rolování"; ERRORS=$((ERRORS+1)); }
"${MYSQL[@]}" "$DB_NAME" -e "DELETE FROM ka_casti WHERE typ = 'hlavicka'"; rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/"; ! grep -q 'ka-hlavicka-' "$WORK/response" && echo "  ok     bez takového záhlaví se CSS rolování nevypisuje" || { echo "  CHYBA  CSS rolování záhlaví i bez záhlaví"; ERRORS=$((ERRORS+1)); }
mcp stavba_uloz '{"cast":"paticka","publikovat":true,"stavba":{"v":1,"deti":[{"typ":"sekce","znacka":"footer","deti":[{"typ":"udaje","obsah":{"udaj":"copyright"}}]}]}}' > /dev/null
"${MYSQL[@]}" "$DB_NAME" -e "DELETE FROM ka_stranky WHERE seo_link = 'about-home'"
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_nastaveni SET hodnota='0' WHERE promenna='home_page'"

