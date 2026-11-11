echo "== 3.35 re-review N1: a host change without the confirmation does not skip the fresh proof of the other fields"
# The legacy single General form (tab=general without general_split, as before 3.23: General, Languages, Security and
# Agency in one form) saves the security fields with the site address. A session without a fresh proof posts a new host without the confirmation and turns passkeys only and the
# two-factor rule off: none of the three is saved. With the password, the two rules are saved and the address still waits
# for its confirmation. Uses r426_age, r426_set and settings_form_as of 2426.
r446_url() { sq "SELECT IFNULL((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'site_url'), '')"; }
r446_save() { curl -s -b "$JAR" -c "$JAR" -o /dev/null "$B/admin.php?module=settings&tab=general" # shows (and forgets) a refused value
  : > "$WORK/r446.legacy"; local tab # the old form showed General, Languages, Security and Agency together
  for tab in general languages security agency; do settings_form_as "$JAR" "$tab" "$WORK/r446.post"; cat "$WORK/r446.post" >> "$WORK/r446.legacy"; printf '&' >> "$WORK/r446.legacy"; done
  sed -i.bak -e 's/general_split=1//g' -e 's/signin_passkey_only=1//g' "$WORK/r446.legacy" && rm -f "$WORK/r446.legacy.bak"; printf 'tab=general%s' "$*" >> "$WORK/r446.legacy"
  curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code}' -X POST "$B/admin.php?module=settings&action=save" --data-binary @"$WORK/r446.legacy"; }
R446_URL=$(r446_url)
sq "UPDATE ka_uzivatele SET pocet_chyb = 0 WHERE user = 'admin'; REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('signin_passkey_only', '1'), ('require_2fa', 'vsichni')" > /dev/null
r426_age "$JAR"
r446_save "" > /dev/null
expect "3.35 re-review N1: the legacy form is posted without the split marker" "$(grep -c 'general_split' "$WORK/r446.legacy")" "0"
expect "3.35 re-review N1: a new host without confirmation and without the proof – the address, passkeys only and the two-factor rule all stay" \
  "$(r446_save "&site_url=https%3A%2F%2Fother-host-446.example&require_2fa=")|$(r426_set)|$([ "$(r446_url)" = "$R446_URL" ] && echo kept)" "302|vsichni/1|kept"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=general"
expect "3.35 re-review N1: the refusal names the missing confirmation and the missing password" \
  "$(grep -c 'Tick the confirmation under the address' "$WORK/response")|$(grep -c 'Enter your current password under the field' "$WORK/response")" "1|1"
expect "3.35 re-review N1: with the password the two rules are saved, the new host still waits for its confirmation" \
  "$(r446_save "&site_url=https%3A%2F%2Fother-host-446.example&require_2fa=&soucasne=$PASSWORD")|$(r426_set)|$([ "$(r446_url)" = "$R446_URL" ] && echo kept)" "302|/0|kept"
sq "DELETE FROM ka_nastaveni WHERE promenna IN ('require_2fa', 'signin_passkey_only'); UPDATE ka_uzivatele SET pocet_chyb = 0 WHERE user = 'admin'" > /dev/null
