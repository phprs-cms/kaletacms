echo "== 3.35 B5 (N94): personal-data requests find, export and erase only the person's own records"
# An enquiry counts when it came from the address or one of its fields holds exactly it; a customer record counts by its
# address, its linked records, or Kaleta's own merge note with exactly the address. A note that mentions the address, a
# message that quotes it and a longer address that contains it belong to other people and stay.
S335E_ADMIN=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'admin'")
S335E_TOKEN="kaleta_$(openssl rand -hex 24)"
sq "INSERT INTO ka_api_tokeny (idu, nazev, access, tool_set, otisk, vytvoren) VALUES ($S335E_ADMIN, 's335e full', 'full', 'all', SHA2('$S335E_TOKEN', 256), '$(site_time)')" > /dev/null
s335e_mcp() { curl -s -X POST "$B/mcp" -H "Authorization: Bearer $S335E_TOKEN" -H 'Content-Type: application/json' --data-binary "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/call\",\"params\":{\"name\":\"$1\",\"arguments\":$2}}"; }
s335e_enquiry() { sq "INSERT INTO ka_poptavky (datum, formular, stranka, email, data, stav) VALUES ('$(site_time)', 'N94', '/kontakt', '$1', '$2', 0)"; sq "SELECT MAX(idp) FROM ka_poptavky WHERE formular = 'N94'"; }
s335e_contact() { sq "INSERT INTO ka_contacts (email, email_norm, name, source, created_at, updated_at) VALUES ('$1', NULLIF(LOWER('$1'), ''), '$2', 'manual', '$(site_time)', '$(site_time)')"; sq "SELECT MAX(id) FROM ka_contacts"; }
s335e_note() { sq "INSERT INTO ka_contact_activities (contact_id, kind, text, author_name, happened_at, created_at) VALUES ($1, 'note', '$2', 'Admin', '$(site_time)', '$(site_time)')"; }
S335E_E1=$(s335e_enquiry 'jane335@acme.example' '[["Message","Hello"]]')
S335E_E2=$(s335e_enquiry 'bob335@acme.example' '[["Colleague e-mail","Jane335@acme.example"]]')
S335E_E3=$(s335e_enquiry 'carl335@acme.example' '[["Message","please cc jane335@acme.example"]]')
S335E_E4=$(s335e_enquiry 'mary.jane335@acme.example' '[["E-mail","mary.jane335@acme.example"]]')
S335E_C1=$(s335e_contact 'jane335@acme.example' 'Jane')
S335E_C2=$(s335e_contact 'bob335@acme.example' 'Bob'); s335e_note "$S335E_C2" "Call notes: cc''d jane335@acme.example on the offer."
S335E_C3=$(s335e_contact 'kept335@acme.example' 'Kept'); s335e_note "$S335E_C3" 'Sloučeno s kontaktem #9 (jane335@acme.example).'
S335E_C4=$(s335e_contact 'mary.jane335@acme.example' 'Mary'); s335e_note "$S335E_C4" 'Merged with the contact #8 (xjane335@acme.example).'
s335e_mcp find_personal_data '{"email":"jane335@acme.example"}' > "$WORK/response"
expect "3.35 B5 N94: find_personal_data lists the person's own enquiries (sent, or the address as a field) and contacts (own and merged), nothing else" \
  "$(php -r '$r = json_decode(json_decode(file_get_contents($argv[1]), true)["result"]["content"][0]["text"], true); sort($r["enquiry_ids"]); echo implode(",", $r["enquiry_ids"]), "|", $r["found"]["contacts"];' "$WORK/response")" "$S335E_E1,$S335E_E2|2"
s335e_mcp erase_personal_data '{"email":"jane335@acme.example","confirm":true}' > /dev/null
expect "3.35 B5 N94: erasing removes exactly those – the enquiry that quotes the address, the longer address and the contacts that only mention it stay" \
  "$(sq "SELECT GROUP_CONCAT(idp ORDER BY idp) FROM ka_poptavky WHERE formular = 'N94'")|$(sq "SELECT GROUP_CONCAT(id ORDER BY id) FROM ka_contacts WHERE id IN ($S335E_C1, $S335E_C2, $S335E_C3, $S335E_C4)")" \
  "$S335E_E3,$S335E_E4|$S335E_C2,$S335E_C4"
expect "3.35 B5 N94: a request for a shorter address finds no longer one (o@ is not jo@)" \
  "$(s335e_mcp find_personal_data '{"email":"ry.jane335@acme.example"}' | grep -c '\\"enquiry_ids\\":\[\]')" "1"
sq "DELETE FROM ka_contact_activities WHERE contact_id IN ($S335E_C2, $S335E_C4); DELETE FROM ka_contacts WHERE id IN ($S335E_C2, $S335E_C4); DELETE FROM ka_poptavky WHERE formular = 'N94'; DELETE FROM ka_api_tokeny WHERE nazev = 's335e full'" > /dev/null
