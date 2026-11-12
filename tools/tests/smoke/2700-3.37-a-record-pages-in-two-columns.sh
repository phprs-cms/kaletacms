echo "== 3.37 (A): the enquiry, deal and customer pages – one bar, two columns, one Handling form; lighter board cards"
A337_ADMIN=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'admin'")
A337_CONTACT=$(sq "INSERT INTO ka_contacts (email, email_norm, name, phone, source, first_seen_at, last_activity_at, created_at, updated_at) VALUES ('olga-a337@example.com', 'olga-a337@example.com', 'Olga A337', '+420 777 337 337', 'manual', '$(site_time)', '$(site_time)', '$(site_time)', '$(site_time)'); SELECT LAST_INSERT_ID();")
A337_ENQ=$(sq "INSERT INTO ka_poptavky (datum, formular, email, data, stav, contact_id, kategorie, priorita, navrh_odpovedi, triaged_by) VALUES ('$(site_time)', 'Formulář A337', 'olga-a337@example.com', '[[\"Zpráva\",\"Potřebuji nabídku A337.\"]]', 1, $A337_CONTACT, 'sales', 3, 'Návrh A337', 'claude'); SELECT LAST_INSERT_ID();")
A337_DEAL=$(sq "INSERT INTO ka_deals (title, contact_id, owner_id, stage, value, currency, created_at, updated_at) VALUES ('Obchod A337', $A337_CONTACT, $A337_ADMIN, 'new', '1250.00', 'EUR', '$(site_time)', '$(site_time)'); SELECT LAST_INSERT_ID();")
sq "INSERT INTO ka_deal_stage_history (deal_id, from_stage, to_stage, at) VALUES ($A337_DEAL, NULL, 'new', '$(site_time)')" > /dev/null

check "3.37: the enquiry detail opens" 200 "/admin.php?module=enquiries&action=detail&id=$A337_ENQ" 'data-record-bar'
expect "3.37 (U26): one bar (state, Reply, Mark as resolved, More with Delete), the main column and the side column; one form posts who handles it with the triage; no Save triage" \
  "$(grep -c 'data-record-state' "$WORK/response")|$(grep -c 'href="#reply" data-record-reply' "$WORK/response")|$(grep -c 'data-record-status' "$WORK/response")|$(sed -n '/data-record-more/,/<\/details>/p' "$WORK/response" | grep -c "action=delete")|$(grep -c 'class="zaznam-hlavni"' "$WORK/response")|$(grep -c 'class="zaznam-bok"' "$WORK/response")|$(sed -n '/data-enquiry-handling/,/<\/section>/p' "$WORK/response" | grep -c 'name="prirazeno"\|name="kategorie"\|name="poznamka"\|name="navrh_odpovedi"')|$(sed -n '/action=note/,/<\/form>/p' "$WORK/response" | grep -c 'name="poznamka"')|$(grep -c 'Save triage' "$WORK/response")" \
  "1|1|1|1|1|1|5|0|0"
# the Handling form posted as the page sends it: a person and a summary, the triage unchanged – Claude's sorting stays Claude's
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=enquiries&action=triage" -d "_csrf=$(csrf)" -d "id=$A337_ENQ" -d "prirazeno=$A337_ADMIN" --data-urlencode "poznamka=Shrnutí A337" -d kategorie=sales -d priorita=3 --data-urlencode "navrh_odpovedi=Návrh A337"
expect "3.37: one Save keeps who handles it and the summary; an unchanged triage stays Claude's" \
  "$(sq "SELECT CONCAT_WS('|', prirazeno, poznamka, kategorie, priorita, triaged_by) FROM ka_poptavky WHERE idp = $A337_ENQ")" "$A337_ADMIN|Shrnutí A337|sales|3|claude"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=enquiries&action=detail&id=$A337_ENQ"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=enquiries&action=triage" -d "_csrf=$(csrf)" -d "id=$A337_ENQ" -d "prirazeno=0" --data-urlencode "poznamka=" -d kategorie=support -d priorita=3 --data-urlencode "navrh_odpovedi=Návrh A337"
expect "3.37: a changed kind is the person's triage; “—” clears who handles it" \
  "$(sq "SELECT CONCAT_WS('|', IFNULL(prirazeno, '-'), poznamka, kategorie, triaged_by <> 'claude') FROM ka_poptavky WHERE idp = $A337_ENQ")" "-||support|1"
# Mark as spam from the same form wins over the select
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=enquiries&action=detail&id=$A337_ENQ"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=enquiries&action=triage" -d "_csrf=$(csrf)" -d "id=$A337_ENQ" -d "prirazeno=0" --data-urlencode "poznamka=" -d kategorie=support -d priorita=3 --data-urlencode "navrh_odpovedi=Návrh A337" -d kategorie=spam
expect "3.37: Mark as spam in the Handling form" "$(sq "SELECT kategorie FROM ka_poptavky WHERE idp = $A337_ENQ")" "spam"
# an internal note returns to the conversation
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=enquiries&action=detail&id=$A337_ENQ"
A337_LOCATION=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -D - -X POST "$B/admin.php?module=enquiries&action=note" -d "_csrf=$(csrf)" -d "idp=$A337_ENQ" --data-urlencode "add_note=Poznámka A337" | tr -d '\r' | sed -n 's/^[Ll]ocation: //p')
expect "3.37 (U26): after a note the page opens at the conversation" "${A337_LOCATION##*\?}|$(sq "SELECT COUNT(*) FROM ka_comments WHERE target = 'enquiry:$A337_ENQ'")" "module=enquiries&action=detail&id=$A337_ENQ#thread|1"

check "3.37: the deal detail opens" 200 "/admin.php?module=pipeline&action=detail&id=$A337_DEAL" 'data-record-bar'
expect "3.37 (U34): the deal says its stage once – the bar holds the stage and Move, More holds Delete; the facts, the person and Edit in the side column, the history in the main one" \
  "$(sed -n '/data-record-bar/,/zaznam-obal/p' "$WORK/response" | grep -c 'data-deal-stage="new"\|id="move"\|data-deal-delete')|$(grep -c 'data-deal-stage' "$WORK/response")|$(sed -n '/class="zaznam-bok"/,$p' "$WORK/response" | grep -c 'data-deal-card\|data-deal-owner\|class="zakaznik-upravit"')|$(sed -n '/class="zaznam-hlavni"/,/class="zaznam-bok"/p' "$WORK/response" | grep -c 'data-deal-history')" \
  "3|1|3|1"
check "3.37: the customer detail opens" 200 "/admin.php?module=contacts&action=detail&id=$A337_CONTACT" 'data-record-bar'
expect "3.37 (U34): the customer bar – New deal (primary), E-mail, Call, More with Merge and Delete; the timeline in the main column, the facts in the side one" \
  "$(grep -c 'class="tl" href="[^"]*" data-record-new-deal' "$WORK/response")|$(grep -c 'href="mailto:olga-a337@example.com" data-record-email' "$WORK/response")|$(grep -c 'href="tel:+420777337337" data-record-call' "$WORK/response")|$(sed -n '/data-record-more/,/<\/details>/p' "$WORK/response" | grep -c 'data-contact-merge\|data-contact-delete')|$(sed -n '/class="zaznam-hlavni"/,/class="zaznam-bok"/p' "$WORK/response" | grep -c 'data-timeline>')|$(sed -n '/class="zaznam-bok"/,$p' "$WORK/response" | grep -c 'data-contact-card')" \
  "1|1|1|2|1|1"
A337_NAME=$(sq "SELECT IF(jmeno <> '', jmeno, user) FROM ka_uzivatele WHERE idu = $A337_ADMIN")
check "3.37 (U28): the board opens" 200 "/admin.php?module=pipeline" 'data-pipeline-board'
expect "3.37 (U28): a light card – no “nobody responsible”, the person as initials with the name for screen readers, the move form still there; the instructions folded" \
  "$(sed -n "/id=\"deal-$A337_DEAL\"/,/<\/li>/p" "$WORK/response" | grep -c "class=\"obchod-osoba\" title=\"$A337_NAME\"><span aria-hidden=\"true\">[^<]\{1,2\}</span><span class=\"navod-skryte\">[^<]*: $A337_NAME</span>")|$(grep -c 'nobody responsible' "$WORK/response")|$(sed -n "/id=\"deal-$A337_DEAL\"/,/<\/li>/p" "$WORK/response" | grep -c 'data-deal-move')|$(grep -c '<details class="obchody-navod"><summary>' "$WORK/response")" \
  "1|0|1|1"
sq "DELETE FROM ka_comments WHERE target = 'enquiry:$A337_ENQ'; DELETE FROM ka_deal_stage_history WHERE deal_id = $A337_DEAL; DELETE FROM ka_deals WHERE id = $A337_DEAL; DELETE FROM ka_poptavky WHERE idp = $A337_ENQ; DELETE FROM ka_contacts WHERE id = $A337_CONTACT" > /dev/null
