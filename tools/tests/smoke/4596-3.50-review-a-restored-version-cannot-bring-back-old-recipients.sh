echo "== 3.50 review: an older version cannot bring back other recipients of an application form – an editor without Applications cannot publish it, the administrator can (SECURITY-1)"
r596_login() { rm -f "$2"; curl -s -c "$2" -b "$2" -o "$WORK/response" "$B/admin.php"; curl -s -b "$2" -c "$2" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$(csrf)" -d "user=$1" --data-urlencode "password=$PASSWORD"; }
r596_mcp() { mcp_call "$1" "$2" "$3" | php -r '$r = json_decode(stream_get_contents(STDIN), true); echo $r["result"]["content"][0]["text"] ?? json_encode($r["error"] ?? $r);'; }
r596_post() { curl -s -b "$1" -c "$1" -o "$WORK/response" "$B/admin.php?module=pages&action=builder&id=$R596_PAGE"; local token; token=$(csrf)
  curl -s -b "$1" -c "$1" -o "$WORK/response" -w '%{http_code}' -X POST "$B/admin.php?module=pages&action=$2&id=$R596_PAGE" -d "_csrf=$token" "${@:3}"; }
R596_EXT=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'extensions'")
features +applications +poptavky
R596_NOW=$(site_time); R596_ADMIN=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'admin'")
R596_TOKEN="kaleta_$(openssl rand -hex 24)"; R596_ED_TOKEN="kaleta_$(openssl rand -hex 24)"
R596_HASH=$(php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' -- "$PASSWORD")
sq "INSERT INTO ka_uzivatele (user, password, jmeno, email, jazyk, admin, posledni_login, potvrzeno, blokovat, notify_email) VALUES ('r596-editor', '$R596_HASH', 'Editor R596', 'editor-r596@example.com', 'en', 1, '$R596_NOW', '$R596_NOW', 0, 'off')" > /dev/null
R596_ED=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'r596-editor'")
sq "INSERT INTO ka_uzivatele_prava (fk_id_user, ident_modulu) VALUES ($R596_ED, 'pages');
  INSERT INTO ka_api_tokeny (idu, nazev, access, tool_set, otisk, vytvoren) VALUES ($R596_ADMIN, 'r596 admin', 'full', 'all', SHA2('$R596_TOKEN', 256), '$R596_NOW'), ($R596_ED, 'r596 editor', 'full', 'all', SHA2('$R596_ED_TOKEN', 256), '$R596_NOW')" > /dev/null

r596_mcp "$R596_TOKEN" create_page '{"title":"Careers R596","slug":"careers-r596"}' > /dev/null
R596_PAGE=$(sq "SELECT IFNULL(MAX(ids), 0) FROM ka_stranky WHERE seo_link = 'careers-r596'")
r596_build() { echo "{\"v\":1,\"deti\":[{\"id\":\"sek596\",\"typ\":\"sekce\",\"deti\":[{\"id\":\"frm596\",\"typ\":\"formular\",\"obsah\":{\"nazev\":\"Apply\",\"ucel\":\"uchazec\",\"prijemce\":\"hr-$1-r596@example.com\",\"skryta_kopie\":\"boss-$1-r596@example.com\",\"pole\":[{\"typ\":\"text\",\"popisek\":\"Name\",\"povinne\":true}]}}]}]}"; }
# the administrator published the old recipients, later the new ones – the old version stays in the history
r596_mcp "$R596_TOKEN" save_build "{\"id\":$R596_PAGE,\"publish\":true,\"build\":$(r596_build old)}" > /dev/null
r596_mcp "$R596_TOKEN" save_build "{\"id\":$R596_PAGE,\"publish\":true,\"build\":$(r596_build new)}" > /dev/null
R596_IDR=$(sq "SELECT IFNULL(MAX(idr), 0) FROM ka_stavba_revize WHERE ids = $R596_PAGE AND cast IS NULL AND stavba LIKE '%hr-old-r596%'")
r596_state() { sq "SELECT CONCAT(IFNULL(stavba, '') LIKE '%hr-new-r596%', IFNULL(stavba, '') LIKE '%boss-new-r596%', '|', IFNULL(stavba_koncept, '') LIKE '%hr-old-r596%', IFNULL(stavba_koncept, '') LIKE '%boss-old-r596%') FROM ka_stranky WHERE ids = $R596_PAGE"; }
expect "3.50 review: the new recipients are published, the old version is in the history" "$(r596_state)|$([ "$R596_IDR" -gt 0 ] && echo history)" "11|00|history"

R596_EJAR="$WORK/r596-editor.jar"; r596_login r596-editor "$R596_EJAR"
R596_AJAR="$WORK/r596-admin.jar"; r596_login admin "$R596_AJAR"
expect "3.50 review: the editor's restore of the old version keeps the recipients of the draft (sanitize, like any draft write)" \
  "$(r596_post "$R596_EJAR" build_restore -d "idr=$R596_IDR")|$(r596_state)" "200|11|00"
# a draft with the old recipients – restored by someone with the section (or left from before the lock)
expect "3.50 review: the administrator's restore brings the old recipients into the draft" "$(r596_post "$R596_AJAR" build_restore -d "idr=$R596_IDR")|$(r596_state)" "200|11|11"
expect "3.50 review (SECURITY-1): the editor without Applications cannot publish that draft (said why) – the new recipients stay on the site" \
  "$(r596_post "$R596_EJAR" build_publish)|$(grep -c 'can change the recipients of a job application form' "$WORK/response")|$(r596_state)" "403|1|11|11"
expect "3.50 review (SECURITY-1): nor over the editor's own Claude connection (publish_build, in English)" \
  "$(r596_mcp "$R596_ED_TOKEN" publish_build "{\"id\":$R596_PAGE}" | grep -c 'can change the recipients of a job application form')|$(r596_state)" "1|11|11"
expect "3.50 review: the administrator (who has Applications) publishes the old recipients" \
  "$(r596_post "$R596_AJAR" build_publish)|$(sq "SELECT CONCAT(stavba LIKE '%hr-old-r596%', stavba LIKE '%boss-old-r596%', '|', stavba_koncept IS NULL) FROM ka_stranky WHERE ids = $R596_PAGE")" "200|11|1"

# clean-up
sq "DELETE FROM ka_stavba_revize WHERE ids = ${R596_PAGE:-0}; DELETE FROM ka_stranky WHERE ids = ${R596_PAGE:-0}; DELETE FROM ka_api_tokeny WHERE nazev IN ('r596 admin', 'r596 editor');
  DELETE FROM ka_uzivatele_prava WHERE fk_id_user = ${R596_ED:-0}; DELETE FROM ka_uzivatele WHERE user = 'r596-editor'" > /dev/null
features --exact "$R596_EXT"
rm -f "$WORK"/web/storage/cache/stranky/*.html "$R596_EJAR" "$R596_AJAR"
