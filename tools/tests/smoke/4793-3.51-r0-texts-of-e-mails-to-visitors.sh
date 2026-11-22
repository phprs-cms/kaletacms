echo "== 3.51 R0: Settings → Mail has the three texts of the e-mails to visitors (default wording, per language); Claude may not change them"
R793_TOKEN="kaleta_$(openssl rand -hex 24)"
sq "INSERT INTO ka_api_tokeny (idu, nazev, access, tool_set, otisk, vytvoren) SELECT idu, 'r793 admin', 'full', 'all', SHA2('$R793_TOKEN', 256), '$(site_time)' FROM ka_uzivatele WHERE user = 'admin'" > /dev/null
curl -s -b "$JAR" -c "$JAR" -o "$WORK/r793-mail.html" "$B/admin.php?module=settings&tab=mail"
expect "3.51 R0.6: the Mail tab shows the three fields, empty with the default as the placeholder and the mark, the identity template in the site language" \
  "$(grep -c 'name="mail_identity" value=""' "$WORK/r793-mail.html")|$(grep -c 'name="newsletter_reason" value=""' "$WORK/r793-mail.html")|$(grep -c 'name="mail_refuse_text" value=""' "$WORK/r793-mail.html")|$(grep -c 'placeholder="Odesílatel: {{company}}, {{address}}."' "$WORK/r793-mail.html")" \
  "1|1|1|1"
R793_OUT=$(mcp_call "$R793_TOKEN" update_settings '{"settings":{"mail_identity":"From {{company}}, {{address}}","mail_refuse_text_en":"Stop: {{link}}"}}')
expect "3.51 R0.6: update_settings refuses the identity and a language variant of the refuse line; nothing is stored" \
  "$(printf '%s' "$R793_OUT" | grep -o 'mail_[a-z_]*\\":\\"This setting cannot be changed' | wc -l | tr -d ' ')|$(sq "SELECT COUNT(*) FROM ka_nastaveni WHERE promenna IN ('mail_identity', 'mail_refuse_text_en') AND hodnota <> ''")" "2|0"
sq "DELETE FROM ka_api_tokeny WHERE nazev = 'r793 admin'" > /dev/null
