echo "== 3.50 review: without Applications the hidden copy (Bcc) of an application form stays, and \"Return page to text\" cannot drop the form (SECURITY-1, SECURITY-2)"
r592_login() { rm -f "$2"; curl -s -c "$2" -b "$2" -o "$WORK/response" "$B/admin.php"; curl -s -b "$2" -c "$2" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$(csrf)" -d "user=$1" --data-urlencode "password=$PASSWORD"; }
r592_mcp() { mcp_call "$1" "$2" "$3" | php -r '$r = json_decode(stream_get_contents(STDIN), true); echo $r["result"]["content"][0]["text"] ?? json_encode($r["error"] ?? $r);'; }
R592_EXT=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'extensions'")
features +applications +poptavky
R592_NOW=$(site_time); R592_ADMIN=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'admin'")
R592_TOKEN="kaleta_$(openssl rand -hex 24)"; R592_ED_TOKEN="kaleta_$(openssl rand -hex 24)"
R592_HASH=$(php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' -- "$PASSWORD")
sq "INSERT INTO ka_uzivatele (user, password, jmeno, email, jazyk, admin, posledni_login, potvrzeno, blokovat, notify_email) VALUES ('r592-editor', '$R592_HASH', 'Editor R592', 'editor-r592@example.com', 'en', 1, '$R592_NOW', '$R592_NOW', 0, 'off')" > /dev/null
R592_ED=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'r592-editor'")
sq "INSERT INTO ka_uzivatele_prava (fk_id_user, ident_modulu) VALUES ($R592_ED, 'pages');
  INSERT INTO ka_api_tokeny (idu, nazev, access, tool_set, otisk, vytvoren) VALUES ($R592_ADMIN, 'r592 admin', 'full', 'all', SHA2('$R592_TOKEN', 256), '$R592_NOW'), ($R592_ED, 'r592 editor', 'full', 'all', SHA2('$R592_ED_TOKEN', 256), '$R592_NOW')" > /dev/null

r592_mcp "$R592_TOKEN" create_page '{"title":"Join us R592","slug":"join-r592"}' > /dev/null
R592_PAGE=$(sq "SELECT IFNULL(MAX(ids), 0) FROM ka_stranky WHERE seo_link = 'join-r592'")
r592_build() { echo "{\"v\":1,\"deti\":[{\"id\":\"sek592\",\"typ\":\"sekce\",\"deti\":[{\"id\":\"nad592\",\"typ\":\"nadpis\",\"znacka\":\"h1\",\"obsah\":{\"text\":\"Join us\"}}$1]}]}"; }
R592_FORM=',{"id":"frm592","typ":"formular","obsah":{"nazev":"Apply","ucel":"uchazec","prijemce":"hr-r592@example.com","skryta_kopie":"boss-r592@example.com","pole":[{"typ":"text","popisek":"Name","povinne":true}]}}'
r592_mcp "$R592_TOKEN" save_build "{\"id\":$R592_PAGE,\"publish\":true,\"build\":$(r592_build "$R592_FORM")}" > /dev/null
r592_published() { sq "SELECT CONCAT(IFNULL(stavba, '') LIKE '%\"ucel\":\"uchazec\"%', '|', IFNULL(stavba, '') LIKE '%boss-r592@example.com%') FROM ka_stranky WHERE ids = $R592_PAGE"; }
expect "3.50 review: the administrator published the page with its application form and its hidden copy" "$(r592_published)" "1|1"

R592_EJAR="$WORK/r592-editor.jar"; r592_login r592-editor "$R592_EJAR"
curl -s -b "$R592_EJAR" -c "$R592_EJAR" -o "$WORK/response" "$B/admin.php?module=pages&action=builder&id=$R592_PAGE"; R592_CSRF=$(csrf)
R592_CHANGED=$(echo "$R592_FORM" | sed 's/boss-r592@example.com/me-r592@evil.example/')
expect "3.50 review (SECURITY-1): the editor's draft keeps the hidden copy of the application form (with a notice)" \
  "$(curl -s -b "$R592_EJAR" -c "$R592_EJAR" -o "$WORK/response" -w '%{http_code}' -X POST "$B/admin.php?module=pages&action=build_save&id=$R592_PAGE" -d "_csrf=$R592_CSRF" --data-urlencode "stavba=$(r592_build "$R592_CHANGED")")|$(sq "SELECT CONCAT(stavba_koncept LIKE '%boss-r592@example.com%', '|', stavba_koncept LIKE '%evil.example%') FROM ka_stranky WHERE ids = $R592_PAGE")|$(grep -c 'recipients of a job application form' "$WORK/response")" \
  "200|1|0|1"
expect "3.50 review (SECURITY-1): edit_build over the editor's own connection cannot set the hidden copy either" \
  "$(r592_mcp "$R592_ED_TOKEN" edit_build "{\"id\":$R592_PAGE,\"operations\":[{\"op\":\"update\",\"id\":\"frm592\",\"content\":{\"bcc\":\"me-r592@evil.example\"}}]}" | grep -c 'recipients of a job application form')|$(sq "SELECT CONCAT(stavba_koncept LIKE '%boss-r592@example.com%', '|', stavba_koncept LIKE '%evil.example%') FROM ka_stranky WHERE ids = $R592_PAGE")" \
  "1|1|0"

curl -s -b "$R592_EJAR" -c "$R592_EJAR" -o "$WORK/response" "$B/admin.php?module=pages&action=edit&id=$R592_PAGE"; R592_CSRF=$(csrf)
curl -s -b "$R592_EJAR" -c "$R592_EJAR" -o /dev/null -X POST "$B/admin.php?module=pages&action=build_text" -d "_csrf=$R592_CSRF" -d "ids=$R592_PAGE"
curl -s -b "$R592_EJAR" -c "$R592_EJAR" -o "$WORK/response" "$B/admin.php?module=pages&action=edit&id=$R592_PAGE"
expect "3.50 review (SECURITY-2): \"Return page to text\" by the editor is refused (said why) and the published application form stays" \
  "$(grep -c 'can remove a job application form' "$WORK/response")|$(r592_published)" "1|1|1"
R592_AJAR="$WORK/r592-admin.jar"; r592_login admin "$R592_AJAR"
curl -s -b "$R592_AJAR" -c "$R592_AJAR" -o "$WORK/response" "$B/admin.php?module=pages&action=edit&id=$R592_PAGE"; R592_CSRF=$(csrf)
curl -s -b "$R592_AJAR" -c "$R592_AJAR" -o /dev/null -X POST "$B/admin.php?module=pages&action=build_text" -d "_csrf=$R592_CSRF" -d "ids=$R592_PAGE"
expect "3.50 review (SECURITY-2): the administrator (who has Applications) returns the page to text" "$(r592_published)|$(sq "SELECT stavba IS NULL FROM ka_stranky WHERE ids = $R592_PAGE")" "0|0|1"

# clean-up
sq "DELETE FROM ka_stavba_revize WHERE ids = ${R592_PAGE:-0}; DELETE FROM ka_stranky WHERE ids = ${R592_PAGE:-0}; DELETE FROM ka_api_tokeny WHERE nazev IN ('r592 admin', 'r592 editor');
  DELETE FROM ka_uzivatele_prava WHERE fk_id_user = ${R592_ED:-0}; DELETE FROM ka_uzivatele WHERE user = 'r592-editor'" > /dev/null
features --exact "$R592_EXT"
rm -f "$WORK"/web/storage/cache/stranky/*.html "$R592_EJAR" "$R592_AJAR"
