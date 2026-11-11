echo "== 3.34 third review F5: the retention deletes an application whose CV list is locked when attachment_paths says where its files are"
r334_on
F5_HEX=$(php -r 'echo bin2hex(random_bytes(16));'); mkdir -p "$WORK/web/storage/applications/2026/03"; printf 'sealed CV' > "$WORK/web/storage/applications/2026/03/$F5_HEX.bin"
F5_ID=$(t334_php '$db = $app->db(); $old = Kaleta\Core\Clock::at("-30 months");
  echo $db->insert("applications", ["received_at" => $old, "form" => "F5", "data" => "[]", "status" => "rejected", "status_at" => $old, "decided_at" => $old, "last_activity_at" => $old,
    "attachments" => Kaleta\Core\Vault::seal(Kaleta\Core\Vault::derive(str_repeat("cd", 32), "applications"), "[]"), "attachment_paths" => json_encode(["bin" => ["2026/03/'"$F5_HEX"'.bin"], "old" => []])]);')
F5_MONTHS=$(sq "SELECT IFNULL(MAX(hodnota), '') FROM ka_nastaveni WHERE promenna = 'applications_months'")
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('applications_months', '6')" > /dev/null
t334_php 'Kaleta\Core\Applications::purge($app);' > /dev/null
expect "3.34 third review F5: locked list, files located – past its retention it goes with its file" \
  "$(sq "SELECT COUNT(*) FROM ka_applications WHERE id = $F5_ID")|$([ -f "$WORK/web/storage/applications/2026/03/$F5_HEX.bin" ] && echo kept || echo gone)" "0|gone"
if [ -n "$F5_MONTHS" ]; then sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('applications_months', '$F5_MONTHS')" > /dev/null; else sq "DELETE FROM ka_nastaveni WHERE promenna = 'applications_months'" > /dev/null; fi
r334_off
