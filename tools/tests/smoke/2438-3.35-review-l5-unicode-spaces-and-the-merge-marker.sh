echo "== 3.35 review L5: personal-data requests – Unicode spaces and case aside, merge notes by their stable marker"
# An enquiry sent from the address wrapped in no-break spaces and in another letter case, and a field holding it with a
# narrow no-break space, are the person's; a merge (MCP merge_contacts) stores the merged address with its note
# (merged_email), so the customer it was merged into is found in any language; an older note with a no-break space too.
# Uses s335e_* of 2380 with a token of its own (2380 revokes its own).
S335E_TOKEN="kaleta_$(openssl rand -hex 24)"
sq "INSERT INTO ka_api_tokeny (idu, nazev, access, tool_set, otisk, vytvoren) VALUES ($S335E_ADMIN, 'r438 full', 'full', 'all', SHA2('$S335E_TOKEN', 256), '$(site_time)')" > /dev/null
R438_NBSP=$(printf '\xc2\xa0'); R438_NNBSP=$(printf '\xe2\x80\xaf')
R438_E1=$(s335e_enquiry "${R438_NBSP}Jane438@ACME.example${R438_NBSP}" '[["Message","Hi"]]')
R438_E2=$(s335e_enquiry 'bob438@acme.example' "[[\"Colleague\",\"${R438_NNBSP}jane438@acme.example\"]]")
R438_E3=$(s335e_enquiry 'carl438@acme.example' '[["Message","write to jane438@acme.example"]]')
R438_C1=$(s335e_contact 'keep438@acme.example' 'Keep'); R438_C2=$(s335e_contact 'jane438@acme.example' 'Jane')
s335e_mcp merge_contacts "{\"into\":$R438_C1,\"from\":$R438_C2,\"confirm\":true}" > /dev/null
expect "3.35 review L5: the merge note carries the merged address as its marker" "$(sq "SELECT merged_email FROM ka_contact_activities WHERE contact_id = $R438_C1 AND merged_email <> ''")" "jane438@acme.example"
sq "UPDATE ka_contact_activities SET text = 'Notiz ohne Adresse' WHERE contact_id = $R438_C1 AND merged_email <> ''" > /dev/null # any wording – the marker decides
R438_C3=$(s335e_contact 'old438@acme.example' 'Old'); s335e_note "$R438_C3" "Sloučeno s kontaktem #9 (${R438_NBSP}JANE438@acme.example)."
s335e_mcp find_personal_data '{"email":"jane438@acme.example"}' > "$WORK/response"
expect "3.35 review L5: found – both enquiries (spaces and case aside), the customer of the marker and of the older note; not the quote" \
  "$(php -r '$r = json_decode(json_decode(file_get_contents($argv[1]), true)["result"]["content"][0]["text"], true); sort($r["enquiry_ids"]); echo implode(",", $r["enquiry_ids"]), "|", $r["found"]["contacts"];' -- "$WORK/response")" "$R438_E1,$R438_E2|2"
sq "DELETE FROM ka_contact_activities WHERE contact_id IN ($R438_C1, $R438_C2, $R438_C3); DELETE FROM ka_contacts WHERE id IN ($R438_C1, $R438_C2, $R438_C3); DELETE FROM ka_poptavky WHERE idp IN ($R438_E1, $R438_E2, $R438_E3); DELETE FROM ka_api_tokeny WHERE nazev = 'r438 full'" > /dev/null
