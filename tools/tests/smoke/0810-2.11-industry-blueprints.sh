echo "== 2.11: industry blueprints"
cat > "$WORK/blueprint.json" <<'JSON'
{"kaleta_blueprint":1,"key":"dental_test","name":{"en":"Dental clinic","cs":"Zubní ordinace"},"description":"For dentists","presets":["people","faq_unknown_is_refused"],
 "facts":[{"key":"insurers","label":"Insurers","type":"text"}],"questions":[{"question":"Which insurers do you have contracts with?","fact":"insurers"}],
 "audit":[{"check":"fact","fact":"insurers","message":"Say which insurers you work with."},{"check":"preset_items","preset":"people","min":1,"message":"Add the doctors."}],
 "claude":"Patients look for insurers first; never give medical advice."}
JSON
mcp apply_blueprint "{\"manifest\":$(cat "$WORK/blueprint.json")}" > "$WORK/response"
contains -q 'faq_unknown_is_refused' "$WORK/response" && expect "blueprints: a manifest with an unknown preset is refused whole" "$(sq "SELECT COUNT(*) FROM ka_blueprints")" "0" || { echo "  CHYBA  neplatný plán"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
sed -i.bak 's/,"faq_unknown_is_refused"//' "$WORK/blueprint.json"
sq "UPDATE ka_kolekce SET preset = '' WHERE preset = 'people'" > /dev/null # the earlier tests made teams; this site has none from the preset
mcp apply_blueprint "{\"manifest\":$(cat "$WORK/blueprint.json")}" > "$WORK/response"
contains -q 'created_facts.*insurers' "$WORK/response" && expect "blueprints: applying creates the team collection, the fact without a value and keeps the manifest" "$(sq "SELECT CONCAT((SELECT COUNT(*) FROM ka_kolekce WHERE preset = 'people'), '|', (SELECT CONCAT(label, '=', value) FROM ka_facts WHERE fact_key = 'insurers'), '|', (SELECT bkey FROM ka_blueprints WHERE nazev IN ('Zubní ordinace', 'Dental clinic')))")" "1|Insurers=|dental_test" \
  || { echo "  CHYBA  apply_blueprint"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp get_blueprint '{}' > "$WORK/response"
contains -q 'Which insurers do you have contracts with' "$WORK/response" && contains -q 'Say which insurers you work with' "$WORK/response" && contains -q 'Add the doctors' "$WORK/response" \
  && echo "  ok     blueprints: Claude sees the open question and the failing checks" || { echo "  CHYBA  get_blueprint"; head -c 500 "$WORK/response"; ERRORS=$((ERRORS+1)); }
curl -s -X POST "$B/mcp" -H "Authorization: Bearer $API_TOKEN" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}' | contains 'never give medical advice' \
  && echo "  ok     blueprints: the instructions of every Claude connection include the blueprint's" || { echo "  CHYBA  pokyny plánu v MCP"; ERRORS=$((ERRORS+1)); }
mcp site_audit '{"kind":"blueprint"}' > "$WORK/response"
contains -q 'Say which insurers you work with' "$WORK/response" && echo "  ok     blueprints: the site audit runs its checks" || { echo "  CHYBA  audit plánu"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp save_fact '{"key":"insurers","value":"VZP, OZP"}' > /dev/null
mcp save_collection_item "{\"collection\":\"$(sq "SELECT seo_link FROM ka_kolekce WHERE preset = 'people' LIMIT 1")\",\"name\":\"MUDr. Test\",\"visible\":true}" > /dev/null
mcp site_audit '{"kind":"blueprint"}' > "$WORK/response"
! contains -q 'Say which insurers' "$WORK/response" && ! contains -q 'Add the doctors' "$WORK/response" && echo "  ok     blueprints: answered and filled in, the checks pass" || { echo "  CHYBA  kontroly plánu po doplnění"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
check "blueprints: the admin page shows the applied blueprint and its question with the answer" 200 "/admin.php?module=blueprints" 'value="VZP, OZP"'
mcp export_blueprint '{"key":"my_clinic","name":"My clinic"}' > "$WORK/response"
contains -q 'kaleta_blueprint' "$WORK/response" && contains -q 'insurers' "$WORK/response" && ! contains -q 'VZP' "$WORK/response" && contains -q 'people' "$WORK/response" \
  && echo "  ok     blueprints: the export has the presets and the facts, never their values" || { echo "  CHYBA  export_blueprint"; head -c 500 "$WORK/response"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -D "$WORK/headers" -o "$WORK/response" "$B/admin.php?module=blueprints&action=export&key=my_clinic&name=Moje"
grep -qi 'filename="my_clinic.blueprint.json"' "$WORK/headers" && php -r 'exit(json_decode(file_get_contents($argv[1]), true)["kaleta_blueprint"] === 1 ? 0 : 1);' -- "$WORK/response" \
  && echo "  ok     blueprints: the admin downloads the site as a blueprint file" || { echo "  CHYBA  stažení plánu"; ERRORS=$((ERRORS+1)); }
mcp remove_blueprint '{"key":"dental_test"}' > /dev/null
expect "blueprints: removing keeps the collection and the fact" "$(sq "SELECT CONCAT((SELECT COUNT(*) FROM ka_blueprints), '|', (SELECT COUNT(*) FROM ka_kolekce WHERE preset = 'people'), '|', (SELECT value FROM ka_facts WHERE fact_key = 'insurers'))")" "0|1|VZP, OZP"
# 3.3: twenty blueprints in groups with a search; get_blueprint tells Claude what a manifest of its own may contain
check "3.3 blueprints: the screen groups the shipped blueprints, has a search and an Ask Claude box" 200 "/admin.php?module=blueprints" 'data-filtr-karet'
[ "$(grep -o 'data-filtr-skupina' "$WORK/response" | wc -l | tr -d ' ')" -ge 5 ] && grep -q 'name="quick" value="1"' "$WORK/response" && echo "  ok     3.3 blueprints: at least five groups and the request form for a blueprint of one's own" || { echo "  CHYBA  3.3 blueprint groups"; ERRORS=$((ERRORS+1)); }
mcp get_blueprint '{}' > "$WORK/response"
contains -q 'manifest_format' "$WORK/response" && contains -q 'audit_rules' "$WORK/response" && contains -q 'group.*software_saas\|software_saas.*group' "$WORK/response" \
  && echo "  ok     3.3 blueprints: get_blueprint lists the groups and the manifest format for draft_blueprint" || { echo "  CHYBA  get_blueprint 3.3"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
SAAS_IDK0=$(sq "SELECT IFNULL(MAX(idk), 0) FROM ka_kolekce")
mcp apply_blueprint '{"key":"software_saas"}' > "$WORK/response"
expect "3.3 blueprints: the software company blueprint creates the pricing plans collection" "$(sq "SELECT COUNT(*) FROM ka_kolekce WHERE preset = 'plans'")" "1"
# the site as before (later blocks make their own collections): the blueprint off, its collections and their hidden pages gone
mcp remove_blueprint '{"key":"software_saas"}' > /dev/null
for slug in $(sq "SELECT seo_link FROM ka_kolekce WHERE idk > $SAAS_IDK0"); do mcp delete_collection "{\"collection\":\"$slug\"}" > /dev/null; sq "DELETE FROM ka_stranky WHERE seo_link IN ('$slug', '$slug-archive') AND zobrazit = 0" > /dev/null; done
