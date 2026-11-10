echo "== 3.15: a major version on its own update source (aktualizace-4.json), never with the regular updates"
major_source() { sq "INSERT INTO ka_nastaveni VALUES ('update_url','http://127.0.0.1:$CHANNEL_PORT/$1/aktualizace.json'), ('update_channel', 'latest') ON DUPLICATE KEY UPDATE hodnota=VALUES(hodnota);
  UPDATE ka_nastaveni SET hodnota = '' WHERE promenna IN ('update_cache', 'update_major_cache', 'update_attempt')" > /dev/null; }
# A: the line has 3.99.10, the next major 4.0.0 (marked security): both are shown, the major cannot be started before 3.99.10
major_source velka; backups_page
contains -F 'value="3.99.10"' "$WORK/response" && contains -F 'id="major-update"' "$WORK/response" && contains -F 'Je k dispozici Kaleta 4.0.0' "$WORK/response" && contains -F 'Tables renamed to their English names' "$WORK/response" \
  && contains -F 'module=status#ready-4' "$WORK/response" && contains -F 'Nejdřív nainstalujte verzi 3.99.10' "$WORK/response" && ! contains -F 'action=update_major' "$WORK/response" \
  && echo "  ok     3.15: Backups and updates offers 3.99.10 and shows Kaleta 4.0.0 on its own, linked to Ready for 4.0, not installable before 3.99.10" || { echo "  CHYBA  3.15: the major version on Backups and updates"; ERRORS=$((ERRORS+1)); }
check "3.15: System status shows Kaleta 4.0.0 above the Ready for 4.0 checks" 200 "/admin.php?module=status" 'Je k dispozici Kaleta 4.0.0'
mcp get_health '{}' > "$WORK/response"
expect "3.15: get_health says the line offers 3.99.10 and the next major 4.0.0" "$(mcp_value update available)|$(mcp_value update major_available)" "3.99.10|4.0.0"
# B: the background job with automatic security updates on: 4.0.0 is marked security, but a major version never installs by itself
sq "INSERT INTO ka_nastaveni VALUES ('auto_updates', '1') ON DUPLICATE KEY UPDATE hodnota = '1'; UPDATE ka_nastaveni SET hodnota = '' WHERE promenna IN ('update_cache', 'update_major_cache', 'update_attempt'); UPDATE ka_jobs SET last_run = NULL WHERE name = 'updates'" > /dev/null
curl -s -o "$WORK/tasks.txt" "$B/ulohy?token=testtoken123"
expect "3.15: the background job looks up 4.0.0 but never installs it, even marked security with automatic updates on" \
  "$([ -f "$WORK/web/image/test-aktualizace4.txt" ] && echo installed || echo kept)|$(sq "SELECT JSON_UNQUOTE(JSON_EXTRACT(hodnota, '\$.manifest.verze')) FROM ka_nastaveni WHERE promenna = 'update_major_cache'")|$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'update_attempt'")|$(grep -o 'updates: [^,]*' "$WORK/tasks.txt" | head -1)" \
  "kept|4.0.0||updates: version 3.99.10 available"
sq "UPDATE ka_nastaveni SET hodnota = '0' WHERE promenna = 'auto_updates'" > /dev/null
# C: 4.0.0 at the regular address (a wrong upload): nothing is offered, and the regular update refuses it (defence in depth)
major_source velka3; backups_page
! contains -F 'value="4.0.0"' "$WORK/response" && ! contains -F 'id="major-update"' "$WORK/response" && echo "  ok     3.15: a major version at aktualizace.json is not offered" || { echo "  CHYBA  3.15: a major version offered as a regular update"; ERRORS=$((ERRORS+1)); }
curl -s -L -b "$JAR" -c "$JAR" -o "$WORK/response" -X POST "$B/admin.php?module=settings&action=update" -d "_csrf=$TOKEN" -d verze=4.0.0
[ ! -f "$WORK/web/image/test-aktualizace4.txt" ] && contains -F 'Verze 4.0.0 je nová hlavní verze Kalety' "$WORK/response" && echo "  ok     3.15: the regular update refuses a manifest of another major version" || { echo "  CHYBA  3.15: the regular update installed or did not explain a major version"; ERRORS=$((ERRORS+1)); }
# E (N315-3): the check of the site's own line fails – it cannot say this is the newest 3.x, so 4.0.0 cannot be started (fails closed)
major_source velka4; backups_page
contains -F 'id="major-update"' "$WORK/response" && ! contains -F 'action=update_major' "$WORK/response" && contains -F 'Kaleta nemohla ověřit, že na webu běží nejnovější vydání 3.x' "$WORK/response" \
  && echo "  ok     3.15 (N315-3): with the line's source failing, Kaleta 4.0.0 is shown but cannot be started" || { echo "  CHYBA  3.15 (N315-3): the major update offered without a confirmed newest 3.x"; ERRORS=$((ERRORS+1)); }
curl -s -L -b "$JAR" -c "$JAR" -o "$WORK/response" -X POST "$B/admin.php?module=settings&action=update_major" -d "_csrf=$TOKEN" -d verze=4.0.0 -d precteno=1
[ ! -f "$WORK/web/image/test-aktualizace4.txt" ] && contains -F 'Kaleta nemohla ověřit, že na webu běží nejnovější vydání 3.x' "$WORK/response" \
  && echo "  ok     3.15 (N315-3): a POST refuses the major update while the line's source fails" || { echo "  CHYBA  3.15 (N315-3): the major update ran without a confirmed newest 3.x"; ERRORS=$((ERRORS+1)); }
# D: the site runs the newest 3.x – the administrator installs 4.0.0: only with the confirmation, and only after a fresh backup
major_source velka2; backups_page
contains -F 'action=update_major' "$WORK/response" && contains -F 'name="precteno"' "$WORK/response" && echo "  ok     3.15: on the newest 3.x the major update can be started (with a confirmation)" || { echo "  CHYBA  3.15: no major update button"; ERRORS=$((ERRORS+1)); }
curl -s -L -b "$JAR" -c "$JAR" -o "$WORK/response" -X POST "$B/admin.php?module=settings&action=update_major" -d "_csrf=$TOKEN" -d verze=4.0.0
[ ! -f "$WORK/web/image/test-aktualizace4.txt" ] && contains -F 'Potvrďte, že jste si přečetli' "$WORK/response" && echo "  ok     3.15: without the confirmation nothing installs" || { echo "  CHYBA  3.15: the major update ran without the confirmation"; ERRORS=$((ERRORS+1)); }
sleep 1; touch "$WORK/before-major"
# N3261-1: the light background jobs are due after this answer (Notifications::afterAdminResponse, once a minute) – they used
# to run in the process that installed 4.0.0, with the old version's code, and overwrite the new line's update information
# with the 3.x manifest (flaky in CI: only when no other request had run them in the minute before)
sq "UPDATE ka_nastaveni SET hodnota = '0' WHERE promenna = 'notification_check'" > /dev/null
curl -s -L -b "$JAR" -c "$JAR" -o "$WORK/response" -X POST "$B/admin.php?module=settings&action=update_major" -d "_csrf=$TOKEN" -d verze=4.0.0 -d precteno=1
MAJOR_BACKUP=$(find "$WORK/web/storage/zalohy" -name '*predaktualizaci*' -newer "$WORK/before-major" | head -1)
expect "3.15: the administrator installs 4.0.0 – after a new backup, recorded, and the site knows its new line" \
  "$([ -f "$WORK/web/image/test-aktualizace4.txt" ] && echo installed)|$(grep -o "KALETA_VERSION = '[^']*'" "$WORK/web/system/bootstrap.php")|$([ -n "$MAJOR_BACKUP" ] && echo backup)|$(sq "SELECT COUNT(*) FROM ka_events WHERE type = 'update.applied' AND data LIKE '%4.0.0%'")|$(sq "SELECT JSON_UNQUOTE(JSON_EXTRACT(hodnota, '\$.manifest.verze')) FROM ka_nastaveni WHERE promenna = 'update_cache'")" \
  "installed|KALETA_VERSION = '4.0.0'|backup|1|4.0.0"
contains -F "$(basename "${MAJOR_BACKUP:-none}")" "$WORK/response" && echo "  ok     3.15: the message names the backup made before the major update" || { echo "  CHYBA  3.15: the message after the major update"; grep -o 'class="hlaska[^"]*"[^<]*<[^<]*' "$WORK/response" | head -3; ERRORS=$((ERRORS+1)); }
# the rest of the test runs on the 3.x line again
sed -i.bak "s/const KALETA_VERSION = '4.0.0';/const KALETA_VERSION = '3.99.9';/" "$WORK/web/system/bootstrap.php" && rm -f "$WORK/web/system/bootstrap.php.bak"
sq "UPDATE ka_nastaveni SET hodnota = '' WHERE promenna IN ('update_cache', 'update_major_cache', 'update_attempt')" > /dev/null
sq "UPDATE ka_nastaveni SET hodnota = '$SITE_URL_BEFORE' WHERE promenna = 'site_url'" > /dev/null; kill "$PROBE_PID" 2>/dev/null || true
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_nastaveni SET hodnota = '' WHERE promenna IN ('update_url', 'update_cache')"
kill "$CHANNEL_PID" 2>/dev/null || true

