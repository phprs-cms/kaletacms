echo "== 3.34 B: the settings of applications – administrators only, the four settings, the templates, who has the section"
expect "3.34 B: HR gets 403 on the settings and on saving them" \
  "$(curl -s -b "$T334_JH" -o /dev/null -w '%{http_code}' "$B/admin.php?module=applications&action=settings")|$(t334_post "$T334_JH" settings_save -d applications_months=1)" "403|403"
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=applications&action=settings"
expect "3.34 B: the administrator sees the settings, the Czech templates and Hana among the people who may open applications" \
  "$(grep -c 'data-applications-settings' "$WORK/response")|$(grep -c 'data-templates-language="cs"' "$WORK/response")|$(grep -c 'Hana T334' "$WORK/response" | awk '{print ($1 > 0)}')|$(grep -c 'Olga T334' "$WORK/response")" "1|1|1|0"
T337_BUILT=$(grep -o 'name="tpl_cs_interview_subject" maxlength="200" value="[^"]*"' "$WORK/response" | sed 's/.*value="//;s/"$//')
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=applications&action=settings_save" -d "_csrf=$(csrf)" -d applications_months=9 -d applications_pool_months=18 -d applications_notify_full=1 \
  --data-urlencode "tpl_cs_interview_subject=$T337_BUILT" --data-urlencode "tpl_cs_interview_text=$(php -r 'require $argv[1] . "/system/bootstrap.php"; echo Kaleta\Core\Language::runWith("cs", fn () => t(Kaleta\Core\Applications::TEMPLATES["interview"][1]));' "$WORK/web")" \
  --data-urlencode "tpl_cs_rejection_subject=Bohužel T337" --data-urlencode "tpl_cs_rejection_text=Dobrý den {{name}}, tentokrát ne."
expect "3.34 B: saved – months, pool, the notification switch on, Claude off (unticked); only the changed template is stored" \
  "$(sq "SELECT GROUP_CONCAT(CONCAT(promenna, '=', hodnota) ORDER BY promenna SEPARATOR ';') FROM ka_nastaveni WHERE promenna IN ('applications_months', 'applications_pool_months', 'applications_notify_full', 'applications_claude')")|$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'application_templates'")" \
  'applications_claude=0;applications_months=9;applications_notify_full=1;applications_pool_months=18|{"cs":{"rejection":{"subject":"Bohužel T337","text":"Dobrý den {{name}}, tentokrát ne."}}}'
sq "DELETE FROM ka_nastaveni WHERE promenna IN ('applications_months', 'applications_pool_months', 'applications_notify_full', 'applications_claude', 'application_templates')" > /dev/null
