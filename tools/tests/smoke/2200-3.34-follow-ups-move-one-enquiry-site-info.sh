echo "== 3.34 follow-ups: Move to Applications on one enquiry, site_info features.applications, the list_enquiries hint for kind job"
T340_EXT=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'extensions'")
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('extensions', '$([ -z "$T340_EXT" ] && echo 'novinky,poptavky,statistika,presmerovani,claude' || echo "$T340_EXT"),applications')" > /dev/null
T340=$(sq "INSERT INTO ka_poptavky (datum, formular, zdroj, prvek, stranka, email, data, stav, kategorie, triaged_by) VALUES ('$(site_time '-2 days')', 'Contact T340', 'stranka:1', 'x', '/contact', 'vera-t340@example.com',
  '[[\"Name\",\"Vera T340\"],[\"Message\",\"I would like to work for you\"]]', 1, 'job', 'person'); SELECT LAST_INSERT_ID();")
sq "INSERT INTO ka_comments (target, kind, author_name, text, via, created_at) VALUES ('enquiry:$T340', 'note', 'Admin', 'Strong profile T340', 'admin', '$(site_time)')" > /dev/null
# Olga has Enquiries but not Applications: no button, and the action refuses
curl -s -b "$T334_JO" -o "$WORK/response" "$B/admin.php?module=enquiries&action=detail&id=$T340"
T340_OLGA_BUTTON=$(grep -c 'data-to-application' "$WORK/response")
curl -s -b "$T334_JO" -o /dev/null -X POST "$B/admin.php?module=enquiries&action=to_application" -d "_csrf=$(csrf)" -d "idp=$T340"
expect "3.34 follow-up: without Applications no Move to Applications – no button, the action leaves the enquiry where it is" \
  "$T340_OLGA_BUTTON|$(sq "SELECT COUNT(*) FROM ka_poptavky WHERE idp = $T340")|$(sq "SELECT COUNT(*) FROM ka_applications WHERE source_enquiry_id = $T340")" "0|1|0"
# the administrator (both sections) moves it: the detail of the new application opens, the note follows, the enquiry is gone
T340_LOG=$(sq "SELECT COUNT(*) FROM ka_protokol WHERE modul = 'applications' AND akce = 'move' AND popis = '1 moved to applications'")
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=enquiries&action=detail&id=$T340"
T340_BUTTON=$(grep -c 'data-to-application' "$WORK/response")
T340_TO=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{redirect_url}' -X POST "$B/admin.php?module=enquiries&action=to_application" -d "_csrf=$(csrf)" -d "idp=$T340")
T340_A=$(sq "SELECT IFNULL(MAX(id), 0) FROM ka_applications WHERE source_enquiry_id = $T340")
expect "3.34 follow-up: Move to Applications – the button, the redirect to the application, reviewed with the name, the note follows, the enquiry is gone" \
  "$T340_BUTTON|$(echo "$T340_TO" | grep -c "module=applications&action=detail&id=$T340_A")|$(sq "SELECT CONCAT(status, '|', name) FROM ka_applications WHERE id = $T340_A")|$(sq "SELECT COUNT(*) FROM ka_comments WHERE target = 'application:$T340_A' AND text = 'Strong profile T340'")|$(sq "SELECT COUNT(*) FROM ka_poptavky WHERE idp = $T340")" \
  "1|1|reviewed|Vera T340|1|0"
expect "3.34 follow-up: moving it again finds nothing (the enquiry moved once); the change log has the count, not the person" \
  "$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' -X POST "$B/admin.php?module=enquiries&action=to_application" -d "_csrf=$(csrf)" -d "idp=$T340")|$(sq "SELECT COUNT(*) FROM ka_applications WHERE source_enquiry_id = $T340")|$(( $(sq "SELECT COUNT(*) FROM ka_protokol WHERE modul = 'applications' AND akce = 'move' AND popis = '1 moved to applications'") - T340_LOG ))" \
  "302|1|1"
# site_info: features.applications for the administrator and for Olga (no section)
mcp site_info '{}' > "$WORK/response"; mcp_text
T340_INFO=$(php -r '$d = json_decode(file_get_contents($argv[1]), true); $f = $d["features"]["applications"] ?? []; echo json_encode([$f["enabled"] ?? null, $f["claude"] ?? null, $f["can_open"] ?? null]);' "$WORK/text")
mcp_as "$T334_TO" site_info '{}' > "$WORK/response"; mcp_text
expect "3.34 follow-up site_info: features.applications – on, Claude may read, the administrator may open; Olga may not" \
  "$T340_INFO|$(php -r '$d = json_decode(file_get_contents($argv[1]), true); echo json_encode($d["features"]["applications"]["can_open"] ?? null);' "$WORK/text")" "[true,true,true]|false"
# list_enquiries: a row of kind job points to Applications while the feature is on, and only then
T340_B=$(sq "INSERT INTO ka_poptavky (datum, formular, zdroj, prvek, stranka, email, data, stav, kategorie, triaged_by) VALUES ('$(site_time '-1 day')', 'Contact T340', 'stranka:1', 'x', '/contact', 'ota-t340@example.com',
  '[[\"Name\",\"Ota T340\"]]', 0, 'job', 'person'); SELECT LAST_INSERT_ID();")
mcp list_enquiries '{"category":"job","search":"t340"}' > "$WORK/response"; mcp_text
T340_HINT=$(php -r '$d = json_decode(file_get_contents($argv[1]), true); echo str_contains($d[0]["applications_hint"] ?? "", "list_applications") ? "hint" : "none";' "$WORK/text")
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('extensions', '$T340_EXT'); DELETE FROM ka_nastaveni WHERE promenna = 'extensions' AND hodnota = ''" > /dev/null
mcp list_enquiries '{"category":"job","search":"t340"}' > "$WORK/response"; mcp_text
expect "3.34 follow-up list_enquiries: kind job points to list_applications with the feature on, says nothing with it off" \
  "$T340_HINT|$(php -r '$d = json_decode(file_get_contents($argv[1]), true); echo isset($d[0]["applications_hint"]) ? "hint" : "none";' "$WORK/text")" "hint|none"
sq "DELETE FROM ka_comments WHERE target IN ('application:$T340_A', 'enquiry:$T340_B'); DELETE FROM ka_applications WHERE id = $T340_A; DELETE FROM ka_poptavky WHERE idp = $T340_B" > /dev/null
