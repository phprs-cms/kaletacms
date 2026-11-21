echo "== 3.48 review: off-site backup retention saved from its tab, a switched-off Pipeline leaves the MCP timeline, personal data without Customers, the links of Settings of sections"
r348_page() { curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -w '%{http_code}' "$B/admin.php?$1"; }
r348_has() { grep -c -- "$1" "$WORK/response" | sed 's/^[1-9][0-9]*$/1/'; }
R348_EXT=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'extensions'")
sq "DROP TABLE IF EXISTS r348_nastaveni; CREATE TABLE r348_nastaveni AS SELECT * FROM ka_nastaveni WHERE promenna <> 'extensions'" > /dev/null

# H: Backups and updates – a shorter "Keep off-site copies" is saved at once (it has no confirmation screen), the change logged
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('backup_remote_days', '90')" > /dev/null
R348_LOG=$(sq "SELECT COUNT(*) FROM ka_protokol WHERE akce = 'retention' AND modul = 'settings'")
r348_page 'module=settings&tab=backups' > /dev/null
R348_TO=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{redirect_url}' -X POST "$B/admin.php?module=settings&action=save" -d "_csrf=$(csrf)" -d tab=backups -d backup_remote_days=30)
expect "3.48 review: Backups – 90 → 30 days is saved, back on the Backups tab (no empty confirmation), the shorter period in the change log" \
  "$(setting_plain backup_remote_days)|$(printf '%s' "$R348_TO" | grep -c 'tab=backups')|$(printf '%s' "$R348_TO" | grep -c 'action=retention')|$(( $(sq "SELECT COUNT(*) FROM ka_protokol WHERE akce = 'retention' AND modul = 'settings'") - R348_LOG ))" "30|1|0|1"
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('backup_remote_days', '0')" > /dev/null
r348_page 'module=settings&tab=backups' > /dev/null
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=save" -d "_csrf=$(csrf)" -d tab=backups -d backup_remote_days=120
expect "3.48 review: Backups – from 0 (keep) pruning can be switched on" "$(setting_plain backup_remote_days)" "120"

# L: get_contact with the Pipeline off carries no deal entry at all (and no note about them); with it on the deal is there
features +customers +pipeline
R348_ADMIN=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'admin'")
R348_TOKEN="kaleta_$(openssl rand -hex 24)"
sq "INSERT INTO ka_api_tokeny (idu, nazev, access, tool_set, otisk, vytvoren) VALUES ($R348_ADMIN, 'r348 full', 'full', 'all', SHA2('$R348_TOKEN', 256), '$(site_time)')" > /dev/null
sq "INSERT INTO ka_contacts (email, email_norm, name, source, created_at, updated_at, last_activity_at) VALUES ('hana-r348@example.com', 'hana-r348@example.com', 'Hana R348', 'manual', '$(site_time)', '$(site_time)', '$(site_time)');
  INSERT INTO ka_deals (title, contact_id, owner_id, stage, created_at, updated_at) VALUES ('Deal R348', LAST_INSERT_ID(), NULL, 'new', '$(site_time)', '$(site_time)')" > /dev/null
R348_CONTACT=$(sq "SELECT id FROM ka_contacts WHERE email = 'hana-r348@example.com'"); R348_DEAL=$(sq "SELECT id FROM ka_deals WHERE title = 'Deal R348'")
sq "INSERT INTO ka_deal_stage_history (deal_id, from_stage, to_stage, at, user_id) VALUES ($R348_DEAL, NULL, 'new', '$(site_time)', $R348_ADMIN)" > /dev/null
r348_deals() { mcp_call "$R348_TOKEN" get_contact "{\"id\":$R348_CONTACT}" | php -r '$r = json_decode(stream_get_contents(STDIN), true); $o = json_decode((string) ($r["result"]["content"][0]["text"] ?? "{}"), true);
  echo count(array_filter($o["timeline"] ?? [], fn ($e) => ($e["kind"] ?? "") === "deal")), ",", str_contains((string) ($o["note"] ?? ""), "deal entry") ? "note" : "-";'; }
expect "3.48 review: get_contact – the Pipeline on shows the deal; off, no deal entry and no note about deal entries" \
  "$(r348_deals)|$(features -pipeline; r348_deals)" "1,note|0,-"

# L: a personal data request with Customers off names the customer record without a link to the switched-off section
features -pipeline -customers
r348_page 'module=enquiries&action=personal' > /dev/null
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -X POST "$B/admin.php?module=enquiries&action=personal" -d "_csrf=$(csrf)" -d email=hana-r348@example.com -d provest=find
expect "3.48 review: Personal data request, Customers off – the record as text with \"(section switched off)\", no link to module=contacts" \
  "$(r348_has 'data-personal-contacts')|$(sed -n '/data-personal-contacts/p' "$WORK/response" | grep -c "#$R348_CONTACT")|$(sed -n '/data-personal-contacts/p' "$WORK/response" | grep -c 'module=contacts')|$(sed -n '/data-personal-contacts/p' "$WORK/response" | grep -c '(sekce je vypnutá)')" "1|1|0|1"
features +customers
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -X POST "$B/admin.php?module=enquiries&action=personal" -d "_csrf=$(csrf)" -d email=hana-r348@example.com -d provest=find
expect "3.48 review: Personal data request, Customers on – the record links to it" "$(sed -n '/data-personal-contacts/p' "$WORK/response" | grep -c "module=contacts&amp;action=detail&amp;id=$R348_CONTACT")" "1"

# M: every link of Settings of sections opens a screen – none lands on a POST-only action that flashes "saved"
features +bookings
r348_page 'module=settings&tab=sections' > /dev/null
R348_FALSE=""
for q in $(grep -o 'href="/admin.php?module=[^"#]*' "$WORK/response" | sed 's|href="/admin.php?||; s|&amp;|\&|g' | sort -u); do
  curl -s -L -b "$JAR" -c "$JAR" -o "$WORK/r348-link" "$B/admin.php?$q"
  grep -q 'class="hlaska hlaska-[a-z]*" role="status">[^<]*uložen' "$WORK/r348-link" && R348_FALSE="$R348_FALSE $q"
done
expect "3.48 review: Settings of sections – the Bookings and Addresses settings are anchors of their lists; no link flashes a false \"saved\"" \
  "$(r348_has 'module=bookings#booking-settings')|$(r348_has 'module=redirects#automaticky')|$R348_FALSE|$(r348_page 'module=bookings&action=settings')|$(curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=bookings"; r348_has 'Nastavení rezervací uloženo')" "1|1||302|0"

sq "DELETE FROM ka_deal_stage_history WHERE deal_id = ${R348_DEAL:-0}; DELETE FROM ka_deals WHERE id = ${R348_DEAL:-0}; DELETE FROM ka_contacts WHERE id = ${R348_CONTACT:-0}; DELETE FROM ka_api_tokeny WHERE nazev = 'r348 full';
  DELETE FROM ka_nastaveni WHERE promenna <> 'extensions'; INSERT INTO ka_nastaveni SELECT * FROM r348_nastaveni; DROP TABLE r348_nastaveni" > /dev/null
features --exact "$R348_EXT"
