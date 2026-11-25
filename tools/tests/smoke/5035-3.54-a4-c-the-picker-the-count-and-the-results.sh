echo "== 3.54 A4 and C: the administration's audience picker and count, the re-ask with the new number, the empty and blocked notices; a newsletter's results line (unsubscribed, visits)"
s354_settings_save 5035
S5035_EXT=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'extensions'")
features +newsletter +jazyky +customers +pipeline -quotes +statistika
s354_sending_site
s354_fixture
s5035_page() { curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=newsletters&action=$1"; }
s5035_has() { grep -c -- "$1" "$WORK/response" | tr -d ' '; }
s5035_state() { sq "SELECT CONCAT(status, '/', audience, '/', audience_filter, '/', (SELECT COUNT(*) FROM ka_newsletter_queue WHERE newsletter_id = ka_newsletters.id)) FROM ka_newsletters WHERE id = ${1:-0}"; }

# a new newsletter never copies the previous one's audience (owner decision): the last one goes to Czech customers, the new form to everyone
S5035_ID=$(s354_php 'echo Mailing::save($app, ["subject" => "S5035 Czech", "news_mode" => "none", "intro" => "Dobrý den", "audience" => "language", "customers_only" => true]);')
s5035_page new
expect "3.54 A4: the new newsletter's form – everyone confirmed is chosen, only customers is not ticked, though the previous one went to Czech customers" \
  "$(s5035_has 'name="audience" value="all" checked')|$(s5035_has 'name="audience" value="language" checked')|$(s5035_has 'name="audience_filter" value="customers" checked')|$(s5035_has 'name="audience_filter" value="customers"')" "1|0|0|1"

# the edit page names the audience and its number, the form posts that number
s5035_page "edit&id=$S5035_ID"
expect "3.54 A4: the draft's page – who it goes to and how many now (one Czech customer), a subscriber without a customer record yet is told, the send form carries the number" \
  "$(s5035_has 'Potvrzení odběratelé: 1 (Čeština, zákazníci). Adresy ze seznamu, na které nepíšeme, se přeskočí.')|$(s5035_has 'nejsou propojení se záznamem zákazníka, a proto se nepočítají jako zákazníci: 1')|$(s5035_has 'name="expected" value="1"')|$(s5035_has 'Odeslat 1 odběratelům')" \
  "1|1|1|1"

# the picker saves the audience; a subscriber confirms while the page is open: the send with the shown number starts nothing and asks again
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=newsletters&action=save" -d "_csrf=$(csrf)" -d "id=$S5035_ID" -d "subject=S5035 Czech" -d news_mode=none -d "intro=Dobrý den" -d audience=language -d audience_filter=
S5035_SAVED=$(s5035_state "$S5035_ID")
s5035_page "edit&id=$S5035_ID"; S5035_SHOWN=$(grep -o 'name="expected" value="[0-9]*"' "$WORK/response" | grep -o '[0-9][0-9]*')
sq "UPDATE ka_odberatele SET stav = 1, potvrzeno = '$(site_time)' WHERE email = 's354-cs4@example.com'" > /dev/null
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=newsletters&action=send" -d "_csrf=$(csrf)" -d "id=$S5035_ID" -d when=now -d "expected=$S5035_SHOWN"
s5035_page "edit&id=$S5035_ID"
expect "3.54 A4: the unticked box saves everyone of the language (4); one more confirms before the click – nothing is sent, the form says both numbers and now offers 5" \
  "$S5035_SAVED|$S5035_SHOWN|$(s5035_state "$S5035_ID")|$(s5035_has 'Počet příjemců se změnil: byl 4, teď je 5. Nic se neodeslalo')|$(s5035_has 'name="expected" value="5"')" \
  "draft/language//0|4|draft/language//0|1|1"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=newsletters&action=send" -d "_csrf=$(csrf)" -d "id=$S5035_ID" -d when=now -d expected=5
expect "3.54 A4: sent again with the number shown, it starts with exactly those 5" "$(sq "SELECT CONCAT(status, '/', recipients, '/', (SELECT COUNT(*) FROM ka_newsletter_queue WHERE newsletter_id = $S5035_ID)) FROM ka_newsletters WHERE id = $S5035_ID")" "sending/5/5"
sq "DELETE FROM ka_newsletter_queue; UPDATE ka_newsletters SET status = 'cancelled', finished_at = started_at WHERE id = $S5035_ID" > /dev/null

# the empty audience and the blocked filter: said on the page, Send disabled
S5035_DE=$(s354_php 'echo Mailing::save($app, ["subject" => "S5035 Deutsch", "news_mode" => "none", "audience" => "language", "language" => "de"]);')
sq "UPDATE ka_odberatele SET stav = 0 WHERE email = 's354-de1@example.com'" > /dev/null
s5035_page "edit&id=$S5035_DE"
S5035_EMPTY="$(s5035_has 'V jazyce tohoto newsletteru zatím nejsou žádní potvrzení odběratelé.')|$(s5035_has 'Odeslat 0 odběratelům')|$(grep -c 'type="submit" disabled>Odeslat 0' "$WORK/response" | tr -d ' ')"
sq "UPDATE ka_odberatele SET stav = 1 WHERE email = 's354-de1@example.com'" > /dev/null
S5035_CUST=$(s354_php 'echo Mailing::save($app, ["subject" => "S5035 customers", "news_mode" => "none", "customers_only" => true]);')
features -pipeline
s5035_page "edit&id=$S5035_CUST"
S5035_BLOCKED="$(s5035_has 'Jen zákazníci potřebují v Rozšířeních zapnutý obchodní proces nebo nabídky')|$(grep -c 'type="submit" disabled>Odeslat 0' "$WORK/response" | tr -d ' ')|$(s5035_has 'name="audience_filter" value="customers" checked')"
features +pipeline
expect "3.54 A4: nobody of the language – the page says so and Send is disabled; only customers with the pipeline and quotes off – the blocking notice, Send disabled, the box still shown ticked to untick" \
  "$S5035_EMPTY|$S5035_BLOCKED" "1|1|1|1|1|1"

# C: the results of one that started – unsubscribed through its links (the counter of part B), visits from its campaign since its start day
S5035_TAG=$(s354_php 'echo Kaleta\Core\NewsletterResults::campaignText((array) Mailing::byId($db, (int) $argv[1]));' "$S5035_ID")
sq "UPDATE ka_newsletters SET status = 'sent', started_at = '$(site_time '-2 days')', finished_at = '$(site_time '-2 days')', sent_count = 4, skipped_count = 1, unsubscribed_count = 2,
    html = CONCAT('<a href=\"https://example.cz/?utm_source=newsletter&amp;utm_medium=email&amp;utm_campaign=', SUBSTRING_INDEX('$S5035_TAG', ' / ', -1), '\">x</a>') WHERE id = $S5035_ID;
  DELETE FROM ka_stat_kampane WHERE kampan = '$S5035_TAG';
  INSERT INTO ka_stat_kampane (den, kampan, navstevy) VALUES (DATE('$(site_time '-3 days')'), '$S5035_TAG', 7), (DATE('$(site_time '-2 days')'), '$S5035_TAG', 3), (DATE('$(site_time)'), '$S5035_TAG', 2)" > /dev/null
s5035_results() { s354_php '$n = (array) Mailing::byId($db, (int) $argv[1]); $r = Mailing::results($app, $n); $all = Kaleta\Core\NewsletterResults::ofMany($app, Mailing::all($db))[(int) $argv[1]] ?? [];
  echo implode("/", array_map(fn ($v) => $v ?? "null", $r)), $r === $all ? "" : " (the list differs: " . json_encode($all) . ")";' "$S5035_ID"; }
S5035_TAGGED=$(s5035_results)
s5035_page "edit&id=$S5035_ID"
S5035_LINE=$(s5035_has 'Odhlásili se přes tento newsletter: 2 · Návštěvy z něj: 5')
features -statistika; S5035_OFF=$(s5035_results); features +statistika
sq "UPDATE ka_newsletters SET started_at = '$(site_time '-401 days')' WHERE id = $S5035_ID" > /dev/null; S5035_OLD=$(s5035_results)
sq "UPDATE ka_newsletters SET started_at = '$(site_time '-2 days')', html = '<p>no link to the site</p>' WHERE id = $S5035_ID" > /dev/null; S5035_UNTAGGED=$(s5035_results)
s5035_page "edit&id=$S5035_ID"
expect "3.54 C: sent 4, failed 0, skipped 1, unsubscribed 2, visits 5 (the day before the start not counted; the list's grouped query agrees) and the page's line; visits unknown with the statistics off, after 400 days and without a campaign name – the page shows – and why" \
  "$S5035_TAGGED|$S5035_LINE|$S5035_OFF|$S5035_OLD|$S5035_UNTAGGED|$(s5035_has 'Návštěvy z něj: –')|$(s5035_has 'Návštěvy se počítají, když je zapnutá statistika')" \
  "4/0/1/2/5|1|4/0/1/2/null|4/0/1/2/null|4/0/1/2/null|1|1"

sq "DELETE FROM ka_newsletter_queue; DELETE FROM ka_newsletters WHERE subject LIKE 'S5035 %'; DELETE FROM ka_stat_kampane WHERE kampan = '$S5035_TAG'" > /dev/null
features --exact "$S5035_EXT"
s354_settings_restore 5035
