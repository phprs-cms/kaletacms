echo "== 3.35 B6 (N107, N108): an author-level role works with hidden collection items and leaves tags to editors"
# A role that does not publish (Auth::canPublish false – the author level, the ready-made Writer, a custom author-level role
# with Collections) prepares hidden items and categories and changes hidden ones, in the admin and over MCP; it never
# publishes, changes or deletes what is on the site. Renaming, merging and deleting tags is for editors (Tags::LIVE_ACTIONS).
S335F_HASH=$(php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' -- "$PASSWORD")
sq "INSERT INTO ka_uzivatele (user, password, jmeno, email, jazyk, admin, posledni_login, potvrzeno, blokovat) VALUES ('s335f', '$S335F_HASH', 'Writer S335F', 's335f@example.com', 'en', 0, '$(site_time)', '$(site_time)', 0);
  INSERT INTO ka_uzivatele_prava (fk_id_user, ident_modulu) SELECT idu, m FROM ka_uzivatele, (SELECT 'news' AS m UNION SELECT 'tags' UNION SELECT 'collections') x WHERE user = 's335f';
  DELETE FROM ka_kontrola_ip WHERE typ = 'login';
  INSERT INTO ka_kolekce (nazev, seo_link, pole, detail, zmeneno) VALUES ('N107', 's335f-n107', '[{\"klic\":\"popis\",\"popisek\":\"Description\",\"typ\":\"radky\"}]', 1, '$(site_time)')" > /dev/null
S335F_USER=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 's335f'"); S335F_K=$(sq "SELECT idk FROM ka_kolekce WHERE seo_link = 's335f-n107'")
s335f_item() { sq "INSERT INTO ka_kolekce_polozky (idk, nazev, seo_link, data, zobrazit, jazyk, datum, poradi) VALUES ($S335F_K, '$1', '$2', '{\"popis\":\"original\"}', $3, '', '$(site_time)', 0)"; sq "SELECT idp FROM ka_kolekce_polozky WHERE idk = $S335F_K AND seo_link = '$2'"; }
S335F_LIVE=$(s335f_item Live live 1); S335F_HIDDEN=$(s335f_item Hidden hidden 0); S335F_LIVE2=$(s335f_item Live2 live2 1); S335F_HIDDEN2=$(s335f_item Hidden2 hidden2 0)
S335F_JAR="$WORK/jar-s335f"; rm -f "$S335F_JAR"
curl -s -c "$S335F_JAR" -o "$WORK/response" "$B/admin.php"; curl -s -b "$S335F_JAR" -c "$S335F_JAR" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$(csrf)" -d user=s335f --data-urlencode "password=$PASSWORD"
curl -s -b "$S335F_JAR" -c "$S335F_JAR" -o "$WORK/response" "$B/admin.php?module=collections&action=item&id=$S335F_K&item=$S335F_LIVE"; S335F_CSRF=$(csrf)
expect "3.35 B6 N107: the form of an item on the site is read-only for the role, with why; a new item offers no publishing" \
  "$(grep -c 'only an editor or administrator changes it' "$WORK/response")|$(grep -c 'value="Save item"\|value="Uložit položku"' "$WORK/response")|$(curl -s -b "$S335F_JAR" "$B/admin.php?module=collections&action=item&id=$S335F_K" | grep -c 'name="zobrazit"')" "1|0|0"
s335f_post() { curl -s -b "$S335F_JAR" -c "$S335F_JAR" -o /dev/null -w '%{http_code}' -X POST "$B/admin.php?module=collections&action=$1" -d "_csrf=$S335F_CSRF" -d "idk=$S335F_K" "${@:2}"; }
s335f_row() { sq "SELECT CONCAT(zobrazit, ':', IF(smazano IS NULL, 'here', 'trash'), ':', JSON_UNQUOTE(JSON_EXTRACT(data, '$.popis'))) FROM ka_kolekce_polozky WHERE idp = $1"; }
s335f_post save_item -d idp=0 -d nazev=Fresh -d 'data[popis]=new' -d zobrazit=1 > /dev/null
expect "3.35 B6 N107: admin – a new item of the role is saved hidden even when sent visible; a hidden item changes; one on the site does not" \
  "$(sq "SELECT zobrazit FROM ka_kolekce_polozky WHERE idk = $S335F_K AND nazev = 'Fresh'")|$(s335f_post save_item -d "idp=$S335F_HIDDEN" -d nazev=Hidden -d 'data[popis]=edited' -d zobrazit=1 > /dev/null; s335f_row "$S335F_HIDDEN")|$(s335f_post save_item -d "idp=$S335F_LIVE" -d nazev=Live -d 'data[popis]=defaced' -d zobrazit=1 > /dev/null; s335f_row "$S335F_LIVE")" \
  "0|0:here:edited|1:here:original"
s335f_post bulk_items -d provest=zobrazit -d "oznacene[]=$S335F_HIDDEN" > /dev/null; S335F_PUB=$(s335f_row "$S335F_HIDDEN")
s335f_post bulk_items -d provest=kos -d "oznacene[]=$S335F_LIVE" -d "oznacene[]=$S335F_HIDDEN" > /dev/null
expect "3.35 B6 N107: admin – the list cannot publish; trashing in bulk takes the hidden item only" \
  "$S335F_PUB|$(s335f_row "$S335F_LIVE")|$(s335f_row "$S335F_HIDDEN")" "0:here:edited|1:here:original|0:trash:edited"
expect "3.35 B6 N107: admin – a single item on the site is not trashed and no version restored over it; the trash is never emptied by the role" \
  "$(s335f_post delete_item -d "idp=$S335F_LIVE2" > /dev/null; s335f_row "$S335F_LIVE2")|$(s335f_post restore_item_version -d "idp=$S335F_LIVE" -d idr=1 > /dev/null; s335f_row "$S335F_LIVE")|$(s335f_post delete_item_permanently -d "idp=$S335F_HIDDEN" > /dev/null; s335f_row "$S335F_HIDDEN")|$(s335f_post delete_item -d "idp=$S335F_HIDDEN2" > /dev/null; s335f_row "$S335F_HIDDEN2")" \
  "1:here:original|1:here:original|0:trash:edited|0:trash:original"
# a category on the site: an administrator makes it; the role cannot change or delete it, its own new one is hidden
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=collections&action=category&id=$S335F_K"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=collections&action=save_category" -d "_csrf=$(csrf)" -d "idk=$S335F_K" -d name=Visible335 -d slug=visible335 -d visible=1
S335F_CAT=$(sq "SELECT category_id FROM ka_collection_category_texts WHERE idk = $S335F_K AND slug = 'visible335'")
s335f_post save_category -d "id=$S335F_CAT" -d name=Defaced -d slug=visible335 -d visible=1 > /dev/null
s335f_post delete_category -d "id=$S335F_CAT" > /dev/null
s335f_post save_category -d id=0 -d name=Mine335 -d slug=mine335 -d visible=1 > /dev/null
expect "3.35 B6 N107: admin – a category on the site stays as it is; the role's new category is hidden" \
  "$(sq "SELECT CONCAT(c.visible, ':', t.name) FROM ka_collection_categories c JOIN ka_collection_category_texts t ON t.category_id = c.id WHERE c.id = ${S335F_CAT:-0}")|$(sq "SELECT c.visible FROM ka_collection_categories c JOIN ka_collection_category_texts t ON t.category_id = c.id WHERE t.slug = 'mine335'")" "1:Visible335|0"
# N108: tags – the list without editing, and the actions refused
sq "INSERT INTO ka_stitky (nazev, seo_link, jazyk) VALUES ('Tag335', 'tag335', ''), ('Into335', 'into335', '')" > /dev/null
S335F_TAG=$(sq "SELECT ids FROM ka_stitky WHERE seo_link = 'tag335'"); S335F_INTO=$(sq "SELECT ids FROM ka_stitky WHERE seo_link = 'into335'")
curl -s -b "$S335F_JAR" -c "$S335F_JAR" -o "$WORK/response" "$B/admin.php?module=tags"
expect "3.35 B6 N108: Tags – the role sees the list with why, no edit or delete; an administrator has both" \
  "$(grep -c 'an editor or administrator renames, merges and deletes them' "$WORK/response")|$(grep -c "tags&amp;action=delete\|edit=$S335F_TAG#uprav" "$WORK/response")|$(curl -s -b "$JAR" "$B/admin.php?module=tags" | grep -c "edit=$S335F_TAG#uprav")" "1|0|1"
curl -s -b "$S335F_JAR" -c "$S335F_JAR" -o /dev/null -X POST "$B/admin.php?module=tags&action=save" -d "_csrf=$S335F_CSRF" -d "ids=$S335F_TAG" -d nazev=Renamed335 -d "sloucit_do=$S335F_INTO"
curl -s -b "$S335F_JAR" -c "$S335F_JAR" -o /dev/null -X POST "$B/admin.php?module=tags&action=delete" -d "_csrf=$S335F_CSRF" -d "ids=$S335F_INTO"
expect "3.35 B6 N108: Tags – renaming, merging and deleting by the role change nothing" "$(sq "SELECT GROUP_CONCAT(nazev ORDER BY ids) FROM ka_stitky WHERE ids IN ($S335F_TAG, $S335F_INTO)")" "Tag335,Into335"
# MCP: the same role over a full connection
S335F_TOKEN="kaleta_$(openssl rand -hex 24)"
sq "INSERT INTO ka_api_tokeny (idu, nazev, access, tool_set, otisk, vytvoren) VALUES ($S335F_USER, 's335f full', 'full', 'all', SHA2('$S335F_TOKEN', 256), '$(site_time)')" > /dev/null
s335f_mcp() { curl -s -X POST "$B/mcp" -H "Authorization: Bearer $S335F_TOKEN" -H 'Content-Type: application/json' --data-binary "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/call\",\"params\":{\"name\":\"$1\",\"arguments\":$2}}"; }
s335f_mcp save_collection_item '{"collection":"s335f-n107","name":"Via MCP","visible":true}' > /dev/null
expect "3.35 B6 N107: MCP – a new item of the role is hidden; one on the site is not changed, deleted or restored; the batch refuses it" \
  "$(sq "SELECT zobrazit FROM ka_kolekce_polozky WHERE idk = $S335F_K AND nazev = 'Via MCP'")|$(s335f_mcp save_collection_item "{\"collection\":\"s335f-n107\",\"id\":$S335F_LIVE,\"values\":{\"popis\":\"defaced\"}}" | grep -c "role does not publish")|$(s335f_mcp delete_collection_item "{\"collection\":\"s335f-n107\",\"id\":$S335F_LIVE}" | grep -c "role does not publish")|$(s335f_mcp save_collection_items "{\"collection\":\"s335f-n107\",\"items\":[{\"id\":$S335F_LIVE,\"values\":{\"popis\":\"defaced\"}}]}" | grep -c 'refused')|$(s335f_row "$S335F_LIVE")" \
  "0|1|1|1|1:here:original"
expect "3.35 B6 N107: MCP – the role cannot change or delete a category on the site" \
  "$(s335f_mcp save_collection_category "{\"collection\":\"s335f-n107\",\"id\":${S335F_CAT:-0},\"name\":\"Defaced\"}" | grep -c 'role does not publish')|$(s335f_mcp delete_collection_category "{\"collection\":\"s335f-n107\",\"id\":${S335F_CAT:-0}}" | grep -c 'role does not publish')" "1|1"
sq "DELETE FROM ka_api_tokeny WHERE idu = $S335F_USER; DELETE FROM ka_stitky WHERE ids IN ($S335F_TAG, $S335F_INTO);
  DELETE FROM ka_collection_category_texts WHERE idk = $S335F_K; DELETE FROM ka_collection_categories WHERE idk = $S335F_K; DELETE FROM ka_kolekce_polozky WHERE idk = $S335F_K; DELETE FROM ka_kolekce WHERE idk = $S335F_K;
  DELETE FROM ka_uzivatele_prava WHERE fk_id_user = $S335F_USER; DELETE FROM ka_uzivatele WHERE idu = $S335F_USER" > /dev/null
