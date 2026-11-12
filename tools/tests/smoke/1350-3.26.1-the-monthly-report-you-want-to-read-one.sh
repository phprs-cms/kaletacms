echo "== 3.26.1: the monthly report you want to read – one look for the e-mail and the report page, what it took to run the site"
expect "3.26.1: the stored month counts the work – the care part with the runs of the daily checks, the decisions and the off-site copies" \
  "$(sq "SELECT CONCAT(JSON_TYPE(JSON_EXTRACT(data, '\$.care')), '|', JSON_TYPE(JSON_EXTRACT(data, '\$.care.job_runs')), '|', JSON_TYPE(JSON_EXTRACT(data, '\$.care.attention')) IN ('OBJECT', 'ARRAY'), '|', JSON_TYPE(JSON_EXTRACT(data, '\$.care.offsite_copies')), '|', JSON_LENGTH(JSON_EXTRACT(data, '\$.stats.days')) >= 28) FROM ka_monthly_reports WHERE month = '$LAST_MONTH'")" \
  "OBJECT|OBJECT|1|INTEGER|1"
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&action=report_view&month=$LAST_MONTH"
expect "3.26.1: the report page opens with the e-mail's blocks – the band, the tiles, what it took to run the site, dark rules for the screen only" \
  "$(grep -c '<section class="prehled" id="summary"' "$WORK/response")|$([ "$(grep -o 'class="kr-tile"' "$WORK/response" | wc -l)" -ge 3 ] && echo tiles)|$(grep -c 'Co dal provoz webu za práci\|What it took to run the site' "$WORK/response")|$(grep -c '@media screen and (prefers-color-scheme: dark)' "$WORK/response")|$(grep -c '<svg' "$WORK/response")" "1|tiles|1|1|0"
mcp get_monthly_report "{\"month\":\"$LAST_MONTH\"}" > "$WORK/response"
expect "3.26.1 MCP get_monthly_report: care counts the work by who did it (additive), never hours" \
  "$(mcp_value care totals all | grep -cE '^[0-9]+$')|$(mcp_value care automatic backups | grep -cE '^[0-9]+$')|$(mcp_value care | grep -qiE 'hours|minutes|price' && echo hours || echo none)" "1|1|none"
# the e-mail through SMTP to the local sink: one message with an HTML and a text part, both complete
mkdir -p "$WORK/smtp3261"
SMTP3261_PORT=$((PORT + 3)); command php "$ROOT/tools/fake-smtp.php" "$SMTP3261_PORT" "$WORK/smtp3261" > /dev/null 2>&1 & SMTP3261_PID=$! # its own: the sink of the comments (2.15) still runs as SMTP_PID
MAIL3261=$(sq "SELECT GROUP_CONCAT(CONCAT(promenna, '=', hodnota) SEPARATOR '&') FROM ka_nastaveni WHERE promenna IN ('mail_mode', 'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_user', 'mail_from', 'report_recipients')")
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('mail_mode', 'smtp'), ('smtp_host', '127.0.0.1'), ('smtp_port', '$SMTP3261_PORT'), ('smtp_encryption', 'zadne'), ('smtp_user', ''), ('mail_from', 'web@example.cz'), ('report_recipients', 'report3261@example.cz')" > /dev/null
for i in $(seq 1 25); do (exec 3<>"/dev/tcp/127.0.0.1/$SMTP3261_PORT") 2>/dev/null && break; sleep 0.2; done
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=reports"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=report_send" -d "_csrf=$(csrf)" -d "month=$LAST_MONTH"
F3261=""; for i in $(seq 1 40); do F3261=$(grep -l '^X-Rcpt-To: report3261@example.cz' "$WORK"/smtp3261/*.eml 2>/dev/null | tail -1); [ -n "$F3261" ] && break; sleep 0.25; done
: > "$WORK/eml3261.txt"; [ -n "$F3261" ] && php -r '[$h, $b] = explode("\r\n\r\n", file_get_contents($argv[1]), 2); echo $h, "\n"; preg_match_all("/base64\r\n\r\n([A-Za-z0-9+\/=\r\n]+)/", $b, $p); foreach ($p[1] as $x) { echo base64_decode($x), "\n"; }' -- "$F3261" > "$WORK/eml3261.txt"
expect "3.26.1: the report e-mail reaches the SMTP sink – multipart with a text and an HTML part" \
  "$([ -n "$F3261" ] && echo sent)|$(grep -ci 'multipart/alternative' "$F3261" 2>/dev/null | head -1 | grep -c '[1-9]')|$(grep -ci 'text/plain' "$F3261" 2>/dev/null | grep -c '[1-9]')|$(grep -ci 'text/html' "$F3261" 2>/dev/null | grep -c '[1-9]')" "sent|1|1|1"
expect "3.26.1: the HTML part – the accent band, tiles, the split of the work, e-mail safe (600 px, no SVG or script, light and dark)" \
  "$(grep -c 'border-radius:13px 13px 0 0' "$WORK/eml3261.txt")|$([ "$(grep -o 'class="kr-tile"' "$WORK/eml3261.txt" | wc -l)" -ge 3 ] && echo tiles)|$(grep -c 'Co dal provoz webu za práci\|What it took to run the site' "$WORK/eml3261.txt")|$(grep -c 'max-width:600px' "$WORK/eml3261.txt")|$(grep -c '<svg\|<script' "$WORK/eml3261.txt")|$(grep -c 'content="light dark"' "$WORK/eml3261.txt")" \
  "1|tiles|1|1|0|1"
expect "3.26.1: the text part – the opening sentence, the work by group, the link to the whole report; no hours or prices" \
  "$(grep -c '^Provoz webu .* si vyžádal .* úkonů\|^Keeping .* running took .* tasks' "$WORK/eml3261.txt")|$(grep -c '^CO DAL PROVOZ WEBU ZA PRÁCI\|^WHAT IT TOOK TO RUN THE SITE' "$WORK/eml3261.txt")|$([ "$(grep -c 'module=settings&tab=reports' "$WORK/eml3261.txt")" -ge 1 ] && echo link)|$(grep -ciE '\b(hodin|hours|Kč|EUR|price|cena)\b' "$WORK/eml3261.txt")" \
  "1|1|link|0"
kill "$SMTP3261_PID" 2>/dev/null || true
php -r 'foreach (explode("&", $argv[1]) as $pair) { [$k, $v] = array_pad(explode("=", $pair, 2), 2, ""); echo "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES (\x27", addslashes($k), "\x27, \x27", addslashes($v), "\x27);\n"; }' -- "$MAIL3261" | sq "$(cat)" > /dev/null

