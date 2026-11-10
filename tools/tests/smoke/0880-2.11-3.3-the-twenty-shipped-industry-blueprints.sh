echo "== 2.11/3.3: the twenty shipped industry blueprints"
mcp get_blueprint '{}' > "$WORK/response"
BLUEPRINTS_LISTED=0; for key in accommodation agency auto_service beauty_wellness clinic craftsman driving_school farm fitness_studio it_services manufacturer municipality nonprofit photographer professional_services real_estate restaurant retail_shop school_courses software_saas; do contains -q "key\\\\\":\\\\\"$key" "$WORK/response" && BLUEPRINTS_LISTED=$((BLUEPRINTS_LISTED+1)); done
expect "shipped blueprints: get_blueprint lists the twenty as available" "$BLUEPRINTS_LISTED" "20"
BLUEPRINT_IDK0=$(sq "SELECT IFNULL(MAX(idk), 0) FROM ka_kolekce")
BLUEPRINT_MISSING=$(sq "SELECT 5 - COUNT(DISTINCT preset) FROM ka_kolekce WHERE preset IN ('notices', 'documents', 'events', 'people', 'faq')") # the earlier blocks made some of them – apply creates only what the site lacks
mcp apply_blueprint '{"key":"municipality"}' > "$WORK/response"
contains -q 'applied\\":\\"municipality' "$WORK/response" && expect "shipped blueprints: the municipality brings the collections the site lacks ($BLUEPRINT_MISSING of its five) and its facts without values" \
  "$(sq "SELECT CONCAT((SELECT COUNT(DISTINCT preset) FROM ka_kolekce WHERE preset IN ('notices', 'documents', 'events', 'people', 'faq')), '|', (SELECT COUNT(*) FROM ka_kolekce WHERE idk > $BLUEPRINT_IDK0), '|', (SELECT COUNT(*) FROM ka_facts WHERE fact_key IN ('mayor_name', 'population', 'filing_office_email', 'council_meetings') AND value = ''), '|', (SELECT type FROM ka_facts WHERE fact_key = 'population'), '|', (SELECT bkey FROM ka_blueprints))")" "5|$BLUEPRINT_MISSING|4|number|municipality" \
  || { echo "  CHYBA  apply_blueprint municipality"; head -c 500 "$WORK/response"; ERRORS=$((ERRORS+1)); }
check "shipped blueprints: the admin page asks the municipality's questions" 200 "/admin.php?module=blueprints" 'name="answer\[mayor_name\]"'
grep -q 'Kdo je starostou nebo starostkou obce?' "$WORK/response" && grep -q 'Uveďte starostu nebo starostku' "$WORK/response" && echo "  ok     shipped blueprints: the question and the failing check about the mayor are in the admin language" || { echo "  CHYBA  otázky plánu obce v administraci"; ERRORS=$((ERRORS+1)); }
# the site as before: the blueprint off, its empty collections and their hidden pages gone (the facts stay – removing never deletes them); later blocks make their own
mcp remove_blueprint '{"key":"municipality"}' > /dev/null
for slug in $(sq "SELECT seo_link FROM ka_kolekce WHERE idk > $BLUEPRINT_IDK0"); do mcp delete_collection "{\"collection\":\"$slug\"}" > /dev/null; sq "DELETE FROM ka_stranky WHERE seo_link IN ('$slug', '$slug-archive') AND zobrazit = 0" > /dev/null; done
expect "shipped blueprints: removed again, the site has no blueprint and no collection of the municipality" "$(sq "SELECT CONCAT((SELECT COUNT(*) FROM ka_blueprints), '|', (SELECT COUNT(*) FROM ka_kolekce WHERE idk > $BLUEPRINT_IDK0))")" "0|0"
