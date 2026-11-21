echo "== 3.48 B: every retention period on the Privacy tab (a shorter one confirmed on its own screen), the old forms leave them, Settings of sections, the stage arrows"
b348_page() { curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -w '%{http_code}' "$B/admin.php?$1"; }
b348_has() { grep -c -- "$1" "$WORK/response" | sed 's/^[1-9][0-9]*$/1/'; }
b348_post() { # b348_post <screen query> <module&action query> <fields…>: opens the screen (the form token), posts, prints the redirect
  curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?$1" > /dev/null; local target="$2"; shift 2
  curl -s -b "$JAR" -c "$JAR" -o "$WORK/b348-post" -w '%{redirect_url}' -X POST "$B/admin.php?$target" -d "_csrf=$(csrf)" "$@"; }
b348_get() { setting_plain "$1"; }
b348_periods() { for k in enquiries_months job_applications_months contacts_months quotes_months applications_months applications_pool_months whistleblowing_retention_months cookies_log_months enquiries_expiry; do printf '%s ' "$(b348_get "$k")"; done; }
b348_logged() { sq "SELECT COUNT(*) FROM ka_protokol WHERE akce = 'retention' AND modul = '$1' AND popis <> ''"; }
# the switches of the Privacy tab as they are – a form that leaves out a checkbox switches it off
b348_switches() { for k in cookies_log lead_attribution accessibility_toolbar captcha_fail_open; do [ "$(b348_get "$k")" = 1 ] && printf -- '-d %s=1 ' "$k"; done; }

# the section changes settings, the stages and the features of the shared site – every setting comes back at the end
B348_EXT=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'extensions'")
sq "DROP TABLE IF EXISTS b348_nastaveni; CREATE TABLE b348_nastaveni AS SELECT * FROM ka_nastaveni WHERE promenna <> 'extensions'" > /dev/null
features +applications +quotes +whistleblowing +bookings
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('enquiries_months', '24'), ('job_applications_months', '0'), ('contacts_months', '24'), ('quotes_months', '36'), ('applications_months', '6'), ('applications_pool_months', '12'), ('whistleblowing_retention_months', '24'), ('cookies_log_months', '36'), ('enquiries_expiry', 'delete')" > /dev/null

expect "3.48 B2: the Privacy tab starts with How long data is kept – a field per period of a feature that is on, bookings as enquiries, the fixed periods, off-site backups linked to Backups" \
  "$(b348_page 'module=settings&tab=cookies')|$(b348_has 'id="retention"')|$(b348_has 'name="enquiries_months"')|$(b348_has 'name="whistleblowing_retention_months"')|$(b348_has 'name="cookies_log_months"')|$(b348_has 'Rezervace: jako poptávky')|$(b348_has 'module=settings&amp;tab=backups')|$(grep -c 'name="cookies_log_months"' "$WORK/response")" \
  "200|1|1|1|1|1|1|1"

# a longer period is saved at once; a shorter one waits for its own screen
expect "3.48 B2: a longer period is saved at once (back on the tab)" \
  "$(b348_post 'module=settings&tab=cookies' 'module=settings&action=save' -d tab=cookies -d enquiries_months=36 $(b348_switches) | grep -c 'tab=cookies')|$(b348_get enquiries_months)" "1|36"
B348_TO=$(b348_post 'module=settings&tab=cookies' 'module=settings&action=save' -d tab=cookies -d enquiries_months=12 -d quotes_months=48 $(b348_switches))
expect "3.48 B2: a shorter one changes nothing until confirmed – the other change is saved, the address carries only the shorter number" \
  "$(printf '%s' "$B348_TO" | grep -c 'action=retention&enquiries_months=12$')|$(b348_get enquiries_months)|$(b348_get quotes_months)" "1|36|48"
expect "3.48 B2: the confirmation screen names the change, the bookings and the job period that follow it, and the records past the new limit" \
  "$(b348_page 'module=settings&action=retention&enquiries_months=12')|$(b348_has 'data-retention-harsher')|$(b348_has 'Poptávky: 36 měsíců → 12 měsíců')|$(b348_has 'Rezervace: 36 měsíců → 12 měsíců')|$(b348_has 'záznamů za novou lhůtou')|$(b348_get enquiries_months)" \
  "200|1|1|1|1|36"
B348_LOG=$(b348_logged settings)
b348_post 'module=settings&action=retention&enquiries_months=12' 'module=settings&action=retention' -d enquiries_months=12 -d choice=cancel > /dev/null
expect "3.48 B2: Keep the current periods changes nothing" "$(b348_get enquiries_months)|$(b348_logged settings)" "36|$B348_LOG"
b348_post 'module=settings&action=retention&enquiries_months=12' 'module=settings&action=retention' -d enquiries_months=121 -d choice=apply > /dev/null
b348_post 'module=settings&action=retention&enquiries_months=12' 'module=settings&action=retention' -d enquiries_months=-1 -d contacts_months=abc -d choice=apply > /dev/null
expect "3.48 B2: a tampered value outside the range (or not a number) is refused, never clamped – nothing changes" "$(b348_get enquiries_months)|$(b348_get contacts_months)|$(b348_logged settings)" "36|24|$B348_LOG"
b348_post 'module=settings&action=retention&enquiries_months=12' 'module=settings&action=retention' -d enquiries_months=12 -d choice=apply > /dev/null
expect "3.48 B2: Shorten the retention saves it and writes the change log with the old and the new value" \
  "$(b348_get enquiries_months)|$(( $(b348_logged settings) - B348_LOG ))|$(sq "SELECT popis FROM ka_protokol WHERE akce = 'retention' AND modul = 'settings' AND popis <> '' ORDER BY idp DESC LIMIT 1")" "12|1|Poptávky: 36 měsíců → 12 měsíců"
expect "3.48 B2: anonymise → delete is harsher too – it waits for the screen" \
  "$(sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('enquiries_expiry', 'anonymise')"; b348_post 'module=settings&tab=cookies' 'module=settings&action=save' -d tab=cookies -d enquiries_expiry=delete $(b348_switches) | grep -c 'action=retention&enquiries_expiry=delete')|$(b348_get enquiries_expiry)" "1|anonymise"
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('enquiries_expiry', 'delete')" > /dev/null

# a moved application waits: saving the Privacy tab confirms it only with the ticked confirmation (3.48 review)
sq "INSERT INTO ka_applications (received_at, name, data, status_at, last_activity_at, source_enquiry_id, moved_at) VALUES ('$(site_time)', 'B348 moved', '[]', '$(site_time)', '$(site_time)', 934801, '$(site_time)');
  REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('applications_retention_confirmed', '0')" > /dev/null
B348_MAXAPP=$(sq "SELECT MAX(id) FROM ka_applications")
b348_post 'module=settings&tab=cookies' 'module=settings&action=save' -d tab=cookies -d captcha_site_key=b348-key $(b348_switches) > /dev/null
expect "3.48 B2: saving another field of the Privacy tab confirms nothing" "$(b348_get applications_retention_confirmed)|$(b348_get captcha_site_key)" "0|b348-key"
b348_post 'module=settings&tab=cookies' 'module=settings&action=save' -d tab=cookies -d applications_months=6 $(b348_switches) > /dev/null
expect "3.48 review: the Applications period posted unchanged (the row on the page) confirms nothing either" "$(b348_get applications_retention_confirmed)" "0"
expect "3.48 review: the Privacy tab names the waiting moved application and offers the confirmation beside the period" \
  "$(b348_page 'module=settings&tab=cookies')|$(b348_has 'data-applications-retention-pending')|$(b348_has 'name="applications_retention_confirm"')" "200|1|1"
b348_post 'module=settings&tab=cookies' 'module=settings&action=save' -d tab=cookies -d applications_months=6 -d applications_retention_confirm=1 -d cookies_policy_url=javascript:alert $(b348_switches) > /dev/null
expect "3.48 review: the ticked confirmation in a save with an invalid field confirms nothing" "$(b348_get applications_retention_confirmed)" "0"
b348_post 'module=settings&tab=cookies' 'module=settings&action=save' -d tab=cookies -d applications_months=6 -d applications_retention_confirm=1 $(b348_switches) > /dev/null
expect "3.48 review: the ticked confirmation confirms the moved applications" "$(b348_get applications_retention_confirmed)" "$B348_MAXAPP"

# the four old forms: saved without their retention fields they leave every period as it is
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('enquiries_months', '18'), ('job_applications_months', '9'), ('contacts_months', '30'), ('quotes_months', '12'), ('applications_months', '7'), ('applications_pool_months', '20'), ('whistleblowing_retention_months', '60'), ('enquiries_expiry', 'anonymise')" > /dev/null
B348_BEFORE=$(b348_periods)
b348_post 'module=enquiries' 'module=enquiries&action=settings' -d triage_assistant=1 > /dev/null
b348_post 'module=applications&action=settings' 'module=applications&action=settings_save' -d applications_claude=1 > /dev/null
b348_post 'module=quotes&action=settings' 'module=quotes&action=settings_save' -d quote_number_format='{YYYY}-{NNNN}' -d quote_valid_days=30 -d quote_vat_rate=21 -d quote_vat_rates=21 -d quote_max_discount=20 -d quote_link_days=30 -d quote_vat_payer=1 > /dev/null
b348_post 'module=whistleblowing' 'module=whistleblowing&action=settings' $([ "$(b348_get whistleblowing_enabled)" = 1 ] && echo -d enabled=1) $(b348_get whistleblowing_readers | tr ',' '\n' | sed '/^$/d;s/^/-d readers[]=/') --data-urlencode "intro=$(b348_get whistleblowing_intro)" > /dev/null
expect "3.48 B2: Enquiries, Applications, Quotes and Whistleblowing saved without their retention fields leave every period unchanged" "$(b348_periods)|$(b348_get triage_assistant)" "$B348_BEFORE|1"
expect "3.48 B2: the four screens show the period as a line that links to the Privacy tab" \
  "$(b348_page 'module=enquiries' > /dev/null; b348_has 'data-retention="enquiries"')$(b348_page 'module=quotes&action=settings' > /dev/null; b348_has 'data-retention="quotes"')$(b348_page 'module=applications&action=settings' > /dev/null; b348_has 'data-retention="talent_pool"')$(b348_page 'module=whistleblowing' > /dev/null; b348_has 'data-retention="whistleblowing"')|$(b348_has 'module=settings&amp;tab=cookies#whistleblowing_retention_months')" "1111|1"
# a page of 3.47 still open posts the old fields: saved as before, a shorter period goes to the change log
B348_ELOG=$(b348_logged enquiries)
b348_post 'module=enquiries' 'module=enquiries&action=settings' -d mesice=6 -d mesice_uchazeci=9 -d po_uplynuti=anonymise -d contacts_months=30 > /dev/null
b348_post 'module=quotes&action=settings' 'module=quotes&action=settings_save' -d quote_number_format='{YYYY}-{NNNN}' -d quote_valid_days=30 -d quote_vat_rate=21 -d quote_vat_rates=21 -d quote_max_discount=20 -d quote_link_days=30 -d quote_vat_payer=1 -d quotes_months=24 > /dev/null
expect "3.48 B2: a legacy POST with the fields still saves (until 3.50); the shorter enquiries period is in the change log" \
  "$(b348_get enquiries_months)|$(b348_get quotes_months)|$(( $(b348_logged enquiries) - B348_ELOG ))" "6|24|1"

# update_settings: cookies_log_months stays settable; a shorter value is logged with both values
B348_ADMIN=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'admin'")
B348_TOKEN="kaleta_$(openssl rand -hex 24)"
sq "INSERT INTO ka_api_tokeny (idu, nazev, access, tool_set, otisk, vytvoren) VALUES ($B348_ADMIN, 'b348 full', 'full', 'all', SHA2('$B348_TOKEN', 256), '$(site_time)')" > /dev/null
B348_MLOG=$(b348_logged settings)
mcp_call "$B348_TOKEN" update_settings '{"settings":{"cookies_log_months":12}}' > /dev/null
expect "3.48 B5: update_settings cookies_log_months 36 → 12 is saved and written to the change log with both values; the other periods stay admin-only" \
  "$(b348_get cookies_log_months)|$(( $(b348_logged settings) - B348_MLOG ))|$(sq "SELECT popis FROM ka_protokol WHERE akce = 'retention' AND modul = 'settings' AND popis <> '' ORDER BY idp DESC LIMIT 1" | grep -c '36 .* 12')|$(mcp_call "$B348_TOKEN" update_settings '{"settings":{"enquiries_months":1}}' | grep -c 'enquiries_months')|$(b348_get enquiries_months)" \
  "12|1|1|1|6"
expect "3.48 B5: processing_record carries the retention rows (key, label, value, unit, zero, editable, feature_on)" \
  "$(mcp_call "$B348_TOKEN" processing_record '{}' | php -r '$r = json_decode(stream_get_contents(STDIN), true); $o = json_decode((string) ($r["result"]["content"][0]["text"] ?? "{}"), true); $row = $o["retention"][0] ?? []; echo implode(",", array_keys($row)), "|", count($o["retention"] ?? []);')" \
  "key,label,value,unit,zero,editable,feature_on|10"

# Settings of sections: the links to the settings that live in the sections, no Save button
expect "3.48 B3: Settings of sections – a tab after General with the sections' settings (Quotes, Applications, the stages) and no Save button" \
  "$(b348_page 'module=settings&tab=sections')|$(b348_has 'module=quotes&amp;action=settings')|$(b348_has 'module=pipeline&amp;action=stages')|$(b348_has 'module=applications&amp;action=settings')|$(b348_has 'class="tl" type="submit" value=')" "200|1|1|1|0"

# the stage arrows: a shift saves the new order; the filtered-empty board says so once
B348_STAGES=$( (cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); foreach (Kaleta\Core\Deals::stages($app->settings()) as $i => $s) { echo "-d key[]=", $s["key"], " -d label[]=", rawurlencode($s["label"]), " -d kind[]=", $s["kind"], " -d order[]=", $i + 1, " "; }') )
B348_FIRST=$( (cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); echo implode(",", array_slice(array_column(Kaleta\Core\Deals::stages($app->settings()), "key"), 0, 2));') )
b348_post 'module=pipeline&action=stages' 'module=pipeline&action=stages_save' $B348_STAGES -d shift=down:0 > /dev/null
expect "3.48 B4: Move down on the first stage saves it second" \
  "$( (cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); echo implode(",", array_slice(array_column(Kaleta\Core\Deals::stages($app->settings()), "key"), 0, 2));') )" \
  "$(printf '%s' "$B348_FIRST" | awk -F, '{print $2 "," $1}')"
expect "3.48 B4: the stage editor has the arrows (hidden order, no number fields) and says how many deals a stage still holds" \
  "$(b348_page 'module=pipeline&action=stages')|$(b348_has 'name="shift" value="up:1"')|$(b348_has 'type="number" id="etapa-poradi')|$(b348_has 'class="vychozi-odeslani"')" "200|1|0|1"
expect "3.48 B4: a filter that matches no deal – one line with Clear filter above the board, the columns print no \"No deal here\"" \
  "$(b348_page 'module=pipeline&search=zzz-b348-nothing')|$(b348_has 'data-pipeline-no-match')|$(b348_has 'data-stage-empty')|$(b348_has 'data-pipeline-reason')" "200|1|0|1"

# everything back as it was
sq "DELETE FROM ka_applications WHERE name = 'B348 moved'; DELETE FROM ka_api_tokeny WHERE nazev = 'b348 full';
  DELETE FROM ka_nastaveni WHERE promenna <> 'extensions'; INSERT INTO ka_nastaveni SELECT * FROM b348_nastaveni; DROP TABLE b348_nastaveni" > /dev/null
features --exact "$B348_EXT"
