echo "== 3.51 R0 (D10): a site one database version behind migrates before new code serves – the public site, cron and MCP"
R795_TOKEN="kaleta_$(openssl rand -hex 24)"
sq "INSERT INTO ka_api_tokeny (idu, nazev, access, tool_set, otisk, vytvoren) SELECT idu, 'r795 admin', 'full', 'all', SHA2('$R795_TOKEN', 256), '$(site_time)' FROM ka_uzivatele WHERE user = 'admin'" > /dev/null
r795_behind() { sq "ALTER TABLE ka_newsletters DROP COLUMN skipped_count; UPDATE ka_nastaveni SET hodnota = '137' WHERE promenna = 'db_version'" > /dev/null; }
r795_state() { echo "$(sq "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ka_newsletters' AND COLUMN_NAME = 'skipped_count'")|$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'db_version'")"; }
r795_behind
R795_BEHIND=$(r795_state)
R795_SITE=$(curl -s -o /dev/null -w '%{http_code}' "$B/")
R795_AFTER_SITE=$(r795_state)
r795_behind
R795_CRON=$(curl -s "$B/ulohy?token=$(setting_plain tasks_token)" | cut -c1-2)
R795_AFTER_CRON=$(r795_state)
r795_behind
R795_MCP=$(mcp_call "$R795_TOKEN" site_info '{}' | grep -c '"kaleta_version' || true)
R795_AFTER_MCP=$(r795_state)
expect "3.51 R0 (D10): behind (no skipped_count, version 137) → the home page, cron (/ulohy) and an MCP call each bring 0138 in before they answer" \
  "$R795_BEHIND|$R795_SITE $R795_AFTER_SITE|$R795_CRON $R795_AFTER_CRON|$R795_MCP $R795_AFTER_MCP" "0|137|200 1|139|OK 1|139|1 1|139"
sq "DELETE FROM ka_api_tokeny WHERE nazev = 'r795 admin'" > /dev/null
