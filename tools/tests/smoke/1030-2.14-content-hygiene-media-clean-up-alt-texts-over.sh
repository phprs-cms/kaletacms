echo "== 2.14: content hygiene – media clean-up, alt texts over MCP, content check, translation overview, bulk actions"
# an unused upload is listed in the clean-up and deleted from there after a confirmation (Media has no trash)
mcp upload_file "{\"filename\":\"nepouzity-f16.png\",\"data\":\"$PNG\"}" > /dev/null; F16_IDO=$(sq "SELECT ido FROM ka_media WHERE obr_poloha LIKE '%nepouzity-f16%' ORDER BY ido DESC LIMIT 1")
check "clean-up: the unused upload is listed with a checkbox of the delete form" 200 "/admin.php?module=media&action=cleanup" "name=\"oznacene\[\]\" value=\"$F16_IDO\" form=\"smazani\""
TOKEN=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=media&action=bulk" -d "_csrf=$TOKEN" -d provest=smaz -d zpet=cleanup -d "oznacene[]=$F16_IDO"
expect "clean-up: the unused file is deleted, the change is logged" "$(sq "SELECT COUNT(*) FROM ka_media WHERE ido = $F16_IDO")|$(sq "SELECT COUNT(*) FROM ka_protokol WHERE modul = 'media' AND akce = 'deleted'")" "0|1"
# an image without a description: listed over MCP, described with update_media; the same in bulk from the clean-up form
mcp upload_file "{\"filename\":\"bez-popisu-f16.png\",\"data\":\"$PNG\"}" > /dev/null; F16_ALT=$(sq "SELECT ido FROM ka_media WHERE obr_poloha LIKE '%bez-popisu-f16%' ORDER BY ido DESC LIMIT 1")
sq "UPDATE ka_media SET nazev = '' WHERE ido = $F16_ALT" > /dev/null
mcp list_media_without_alt '{"limit":200}' > "$WORK/response"
mcp_value images | grep -q "\"id\":$F16_ALT,\"path\":\"media/" && echo "  ok     MCP: list_media_without_alt lists the image without a description" || { echo "  CHYBA  list_media_without_alt"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp update_media "{\"id\":$F16_ALT,\"alt\":\"Modrý čtverec\"}" > /dev/null
expect "MCP: update_media writes the description (alt)" "$(sq "SELECT nazev FROM ka_media WHERE ido = $F16_ALT")" "Modrý čtverec"
mcp list_media_without_alt '{"limit":200}' > "$WORK/response"
mcp_value images | grep -q "\"id\":$F16_ALT," && { echo "  CHYBA  a described image is still listed"; ERRORS=$((ERRORS+1)); } || echo "  ok     MCP: a described image leaves the list"
sq "UPDATE ka_media SET nazev = '' WHERE ido = $F16_ALT" > /dev/null
check "clean-up: the image without a description has an input" 200 "/admin.php?module=media&action=cleanup" "name=\"alt\[$F16_ALT\]\""
TOKEN=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=media&action=save_alts" -d "_csrf=$TOKEN" --data-urlencode "alt[$F16_ALT]=Ctverec z formulare"
expect "clean-up: descriptions saved in bulk" "$(sq "SELECT nazev FROM ka_media WHERE ido = $F16_ALT")" "Ctverec z formulare"
# 3.15 (N49): a user who is not an administrator sees only their own files in the clean-up
mcp upload_file "{\"filename\":\"spravcuv-n49.png\",\"data\":\"$PNG\"}" > /dev/null
sq "INSERT INTO ka_uzivatele (user, password, jmeno, admin, posledni_login, potvrzeno) VALUES ('n49-author', '$(php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' -- "$PASSWORD")', 'Author N49', 0, '$(site_time)', '$(site_time)');
  INSERT INTO ka_uzivatele_prava (fk_id_user, ident_modulu) SELECT idu, 'media' FROM ka_uzivatele WHERE user = 'n49-author'" > /dev/null
JAR_N49="$WORK/cookies-n49.txt"; curl -s -c "$JAR_N49" -o "$WORK/response" "$B/admin.php"
curl -s -b "$JAR_N49" -c "$JAR_N49" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$(csrf)" -d user=n49-author --data-urlencode "password=$PASSWORD"
expect "3.15 N49: the clean-up of a user who is not an administrator lists none of the administrator's files; the administrator's lists them" \
  "$(curl -s -b "$JAR_N49" -o "$WORK/response" -w '%{http_code}' "$B/admin.php?module=media&action=cleanup")|$(grep -q 'spravcuv-n49' "$WORK/response" && echo 1 || echo 0)|$(curl -s -b "$JAR" -o "$WORK/response2" "$B/admin.php?module=media&action=cleanup"; grep -q 'spravcuv-n49' "$WORK/response2" && echo 1 || echo 0)" "200|0|1"
sq "DELETE FROM ka_uzivatele WHERE user = 'n49-author'" > /dev/null
# content check: a text page with a short title, no description, a skipped heading level and an image without alt
mcp create_page '{"title":"Kontrola obsahu F16","content":"<h2>Co nabízíme</h2><p>Text o kuchyních.</p><h4>Skok</h4><img src=\"/media/x.jpg\">"}' > /dev/null; F16_PAGE=$(sq "SELECT ids FROM ka_stranky WHERE seo_link = 'kontrola-obsahu-f16'")
mcp get_page "{\"id\":$F16_PAGE}" > "$WORK/response"; mcp_value content_check > "$WORK/check.json"
expect "MCP: get_page content_check – one H1 (the title), a skipped level, an image without alt, a short title, no description" \
  "$(php -r '$c = array_column(json_decode(file_get_contents($argv[1]), true), "ok", "check"); echo (int) $c["single_h1"], (int) $c["heading_order"], (int) $c["images_alt"], (int) $c["title_length"], (int) $c["description_length"];' -- "$WORK/check.json")" "10000"
check "the page editor shows the content check of the saved version" 200 "/admin.php?module=pages&action=edit&id=$F16_PAGE" 'data-kontrola="heading_order"'
# translation overview: the page has no English version – missing in the admin matrix and over MCP; after translating and changing the original – outdated
check "translations: the overview offers to create the missing English version" 200 "/admin.php?module=pages&action=translations" "translation_of=$F16_PAGE"
grep -q 'data-stav="missing"' "$WORK/response" && echo "  ok     translations: the cell says missing" || { echo "  CHYBA  translation matrix cell"; ERRORS=$((ERRORS+1)); }
mcp translation_status '{"type":"page"}' > "$WORK/response"; mcp_value items > "$WORK/items.json"
f16_status() { php -r '$v = json_decode(file_get_contents($argv[1]), true) ?: []; foreach ($v as $i) { if ((int) $i["id"] === (int) $argv[2]) { echo $i["type"], "|", $i["translations"]["en"]["status"]; } }' -- "$WORK/items.json" "$F16_PAGE"; }
expect "MCP: translation_status reports the missing English version" "$(f16_status)" "page|missing"
mcp create_page "{\"title\":\"Content check F16\",\"language\":\"en\",\"translation_of\":$F16_PAGE}" > /dev/null
mcp translation_status '{"type":"page"}' > "$WORK/response"; mcp_value items > "$WORK/items.json"
expect "MCP: a translated page is not reported" "$(f16_status)" ""
sleep 1; mcp update_page "{\"id\":$F16_PAGE,\"description\":\"Originál se změnil po překladu.\"}" > /dev/null
mcp translation_status '{"status":"outdated"}' > "$WORK/response"; mcp_value items > "$WORK/items.json"
expect "MCP: the original changed after the translation – outdated" "$(f16_status)" "page|outdated"
check "translations: the matrix marks the older translation" 200 "/admin.php?module=pages&action=translations" 'data-stav="outdated"'
# 3.10.1 (N33): an author's connection gets the overview its list tools give – no hidden page, no other author's draft,
# no hidden collection item, its own draft yes; the administrator (and the admin screen) still sees all of them
mcp create_page '{"title":"N33 hidden page"}' > /dev/null; sq "UPDATE ka_stranky SET zobrazit = 0 WHERE titulek = 'N33 hidden page'" > /dev/null
mcp create_news "{\"title\":\"N33 other author draft\",\"category\":\"$CATEGORY\"}" > /dev/null
mcp create_collection '{"name":"N33","slug":"n33","fields":[{"label":"Popis","type":"text"}]}' > /dev/null
mcp save_collection_item '{"collection":"n33","name":"N33 hidden item"}' > /dev/null; mcp save_collection_item '{"collection":"n33","name":"N33 shown item","visible":true}' > /dev/null
sq "INSERT INTO ka_uzivatele (user, password, jmeno, admin, posledni_login, potvrzeno) VALUES ('n33-author', '', 'Author N33', 0, '$(site_time)', '$(site_time)');
  INSERT INTO ka_uzivatele_prava (fk_id_user, ident_modulu) SELECT idu, 'news' FROM ka_uzivatele WHERE user = 'n33-author'" > /dev/null
AUTHOR33_TOKEN="kaleta_$(printf 'c%.0s' $(seq 1 48))"
sq "INSERT INTO ka_api_tokeny (idu, nazev, otisk, vytvoren) SELECT idu, 'author 33', '$(php -r 'echo hash("sha256", $argv[1]);' -- "$AUTHOR33_TOKEN")', '$(site_time)' FROM ka_uzivatele WHERE user = 'n33-author'" > /dev/null
mcp_as "$AUTHOR33_TOKEN" create_news "{\"title\":\"N33 own draft\",\"category\":\"$CATEGORY\"}" > /dev/null
mcp_as "$AUTHOR33_TOKEN" translation_status '{"status":"all"}' > "$WORK/response"; mcp_text
expect "3.10.1 N33: translation_status for an author lists its own draft, but no hidden page, other author's draft or hidden collection item" \
  "$(grep -c 'N33 own draft' "$WORK/text")|$(grep -c 'N33 shown item' "$WORK/text")|$(grep -c 'N33 hidden page\|N33 other author draft\|N33 hidden item' "$WORK/text")" "1|1|0"
mcp translation_status '{"status":"all"}' > "$WORK/response"; mcp_text
expect "3.10.1 N33: the administrator's translation_status lists all of them" \
  "$(grep -o 'N33 own draft\|N33 shown item\|N33 hidden page\|N33 other author draft\|N33 hidden item' "$WORK/text" | sort -u | wc -l | tr -d ' ')" "5"
check "3.10.1 N33: the admin translation overview still lists the hidden page for the administrator" 200 "/admin.php?module=pages&action=translations" "N33 hidden page"
mcp delete_collection '{"collection":"n33"}' > /dev/null
sq "DELETE FROM ka_novinky WHERE titulek LIKE 'N33 %'; DELETE FROM ka_stranky WHERE titulek = 'N33 hidden page'; DELETE FROM ka_uzivatele WHERE user = 'n33-author'" > /dev/null
# bulk actions in the pages list: two pages hidden at once, one moved to a language version, one to the trash
mcp create_page '{"title":"Hromadně A","visible":true}' > /dev/null; mcp create_page '{"title":"Hromadně B","visible":true}' > /dev/null
F16_A=$(sq "SELECT ids FROM ka_stranky WHERE seo_link = 'hromadne-a'"); F16_B=$(sq "SELECT ids FROM ka_stranky WHERE seo_link = 'hromadne-b'")
check "pages list: row checkboxes belong to the bulk form" 200 "/admin.php?module=pages" "name=\"oznacene\[\]\" value=\"$F16_A\" form=\"hromadne\""
TOKEN=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=pages&action=bulk" -d "_csrf=$TOKEN" -d provest=skryt -d "oznacene[]=$F16_A" -d "oznacene[]=$F16_B"
expect "bulk: two pages hidden at once, a change log entry each" "$(sq "SELECT GROUP_CONCAT(zobrazit ORDER BY ids) FROM ka_stranky WHERE ids IN ($F16_A, $F16_B)")|$(sq "SELECT COUNT(*) FROM ka_protokol WHERE modul = 'pages' AND akce = 'bulk hidden'")" "0,0|2"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=pages&action=bulk" -d "_csrf=$TOKEN" -d provest=jazyk -d jazyk=en -d "oznacene[]=$F16_A"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=pages&action=bulk" -d "_csrf=$TOKEN" -d provest=kos -d "oznacene[]=$F16_B"
expect "bulk: a page moved to the English version, another to the trash" "$(sq "SELECT CONCAT((SELECT jazyk FROM ka_stranky WHERE ids = $F16_A), '|', (SELECT smazano IS NOT NULL FROM ka_stranky WHERE ids = $F16_B))")" "en|1"
code=$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' -X POST "$B/admin.php?module=pages&action=bulk" -d provest=kos -d "oznacene[]=$F16_A"); expect "bulk: a POST without CSRF is refused" "$code" "400"
sq "UPDATE ka_stranky SET smazano = '$(site_time)' WHERE ids IN ($F16_A, $F16_PAGE) OR preklad_z = $F16_PAGE" > /dev/null
