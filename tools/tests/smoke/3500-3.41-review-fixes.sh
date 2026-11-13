echo "== 3.41 review: sign-in before migration 0130, redirecting site address, epochs, team totals, update source, fleet pairing, reset links"
r341_csrf() { grep -o 'name="_csrf" value="[a-f0-9]*"' "$1" | head -1 | sed 's/.*value="//;s/"//' || true; }
r341_php() { (cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = Kaleta\Core\App::boot(); $app->applyTimezone(); '"$1" -- "${@:2}"); }

# M1: files of 3.41 on a database without migration 0130 (a manual upload, a failed migration) – an administrator whose
# session expired can still sign in, and that first administrator request applies the migration
sq "ALTER TABLE ka_uzivatele DROP COLUMN sign_in_epoch; UPDATE ka_nastaveni SET hodnota = '129' WHERE promenna = 'db_version'" > /dev/null
R341_JAR="$WORK/jar-r341"; curl -s -c "$R341_JAR" -o "$WORK/response" "$B/admin.php"
R341_SIGNIN=$(curl -s -b "$R341_JAR" -c "$R341_JAR" -o /dev/null -w '%{http_code}' -X POST "$B/admin.php" -d "_csrf=$(r341_csrf "$WORK/response")" -d user=admin --data-urlencode "password=$PASSWORD")
curl -s -b "$R341_JAR" -c "$R341_JAR" -o "$WORK/response" "$B/admin.php"
expect "3.41 review M1: without sign_in_epoch the sign-in succeeds and the next administrator request migrates the column back" \
  "$R341_SIGNIN|$(grep -c 'name="password"' "$WORK/response")|$(sq "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ka_uzivatele' AND COLUMN_NAME = 'sign_in_epoch'")|$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'db_version'")" \
  "302|0|1|130"
if [ "$(sq "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ka_uzivatele' AND COLUMN_NAME = 'sign_in_epoch'")" = "0" ]; then
  sq "ALTER TABLE ka_uzivatele ADD COLUMN sign_in_epoch INT UNSIGNED NOT NULL DEFAULT 0; UPDATE ka_nastaveni SET hodnota = '130' WHERE promenna = 'db_version'" > /dev/null
fi

# L1: a check that met a redirect to another host is a warning "couldn't check", never "the web server refuses them"
sq "UPDATE ka_nastaveni SET hodnota = '{\"at\":$(date +%s),\"open\":[],\"error\":\"\",\"redirect\":\"https://elsewhere.example/\"}' WHERE promenna = 'server_rules'" > /dev/null
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=status"
expect "3.41 review L1: System status says the check couldn't run because the address redirects, not that the folders are refused" \
  "$(grep -c 'Nepodařilo se ověřit, zda je webový server návštěvníkům odmítá – adresa webu přesměrovává (na https://elsewhere.example/)' "$WORK/response")|$(grep -c 'webový server je návštěvníkům odmítá (zkontrolováno' "$WORK/response")" "1|0"
sq "UPDATE ka_nastaveni SET hodnota = '' WHERE promenna = 'server_rules'" > /dev/null

# L2: new backup codes in My account (the passkey account of 2400) end the account's other sign-ins and keep this one
sq "UPDATE ka_uzivatele SET pocet_chyb = 0 WHERE idu = $Q340_USER; DELETE FROM ka_kontrola_ip WHERE typ IN ('login', 'totp')" > /dev/null
R341_A="$WORK/jar-r341-a"; R341_B="$WORK/jar-r341-b"; rm -f "$R341_A" "$R341_B"
p335_passkey "$R341_A" "$WORK/pk340-a" 5 90 > /dev/null; p335_passkey "$R341_B" "$WORK/pk340-a" 5 91 > /dev/null
R341_BOTH="$(p335_signed_in "$R341_A")$(p335_signed_in "$R341_B")"
R341_EPOCH0=$(sq "SELECT sign_in_epoch FROM ka_uzivatele WHERE idu = $Q340_USER")
P335_CSRF=$(p335_page "$R341_A" "?action=account")
expect "3.41 review L2: new backup codes raise the sign-in epoch – this sign-in stays, the other one ends" \
  "$R341_BOTH|$(p335_account "$R341_A" -d co=backup_codes_new)|$(grep -c 'Your new backup codes' "$WORK/response")|$(( $(sq "SELECT sign_in_epoch FROM ka_uzivatele WHERE idu = $Q340_USER") - R341_EPOCH0 ))|$(p335_signed_in "$R341_A")|$(p335_signed_in "$R341_B")" \
  "11|200|1|1|1|0"

# L3: a colleague (Eva of 3400) sees the team's totals only while three or more people did counted work – screen and MCP agree
R341_PEOPLE=$(r341_php '[$f, $t] = Kaleta\Core\TeamSummary::range("7_days"); echo Kaleta\Core\TeamSummary::people(Kaleta\Core\TeamSummary::collect($app, ["enquiries", "tasks", "deals"], $f, $t), ["enquiries", "tasks", "deals"]);')
curl -s -b "$JAR_R341A" -c "$JAR_R341A" -o "$WORK/r341.html" "$B/admin.php?module=my_work&action=summary&period=7_days"
mcp_call "$R341A_TOKEN" team_summary '{"period":"7_days"}' > "$WORK/response"; mcp_text
R341_MCP=$(php -r '$j = json_decode((string) file_get_contents($argv[1]), true); echo is_array($j["team"] ?? null) ? "totals" : "note:" . ($j["team_note"] ?? "");' -- "$WORK/text")
if [ "$R341_PEOPLE" -ge 3 ]; then R341_WANT="0|totals"; else R341_WANT="1|note:Team totals appear from three people."; fi
expect "3.41 review L3: the colleague's team row and MCP follow the three-people rule ($R341_PEOPLE people counted)" "$(grep -c 'data-team-hidden' "$WORK/r341.html")|$R341_MCP" "$R341_WANT"
# with work only of Eva and the administrator in a period, the colleague gets the note instead of the totals
expect "3.41 review L3: two people – no totals for a colleague, the totals for an administrator" \
  "$(r341_php '$s = Kaleta\Core\TeamSummary::build($app, ["deals"], "7_days", (int) $argv[1], false); $a = Kaleta\Core\TeamSummary::build($app, ["deals"], "7_days", (int) $argv[1], true);
    [$f, $t] = Kaleta\Core\TeamSummary::range("7_days"); $n = Kaleta\Core\TeamSummary::people(Kaleta\Core\TeamSummary::collect($app, ["deals"], $f, $t), ["deals"]);
    echo $n < 3 ? (($s["team"] === null ? "hidden" : "shown") . "|" . (is_array($a["team"]) ? "admin" : "-")) : "hidden|admin";' "$R341A_EVA")" "hidden|admin"

# L4: a refused update source is reported as refused (with what to change), not as "not responding"
R341_OLD_UPDATE_URL=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'update_url'")
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('update_url', 'http://updates.example/aktualizace.json'); UPDATE ka_nastaveni SET hodnota = '' WHERE promenna = 'update_cache'" > /dev/null
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=status"
expect "3.41 review L4: System status says the source must be https and where to change it – not that it isn't responding" \
  "$(grep -c 'Zdroj aktualizací musí být na adrese https://. Změňte zdroj aktualizací v Nastavení → Zálohy a aktualizace' "$WORK/response")|$(grep -c 'zdroj aktualizací neodpovídá' "$WORK/response")" "1|0"
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('update_url', '$R341_OLD_UPDATE_URL'); UPDATE ka_nastaveni SET hodnota = '' WHERE promenna = 'update_cache'" > /dev/null

# L5: pairing again starts without the answer binding and the request time of an earlier pairing (a stand-in console)
R341_SITE_URL=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'site_url'")
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('site_url', 'https://site-r341.example'), ('fleet_answers_bound', '1'), ('fleet_last_ts', '4102444800')" > /dev/null
expect "3.41 review L5: Link::pair resets fleet_answers_bound and fleet_last_ts (no bound answer yet, the new heartbeat's time)" \
  "$(r341_php '$pair = sodium_crypto_sign_keypair(); $secret = sodium_crypto_sign_secretkey($pair);
    Kaleta\Fleet\Http::$transport = function (string $url, string $body) use ($secret): array {
        if (str_ends_with($url, "/fleet/pair")) { $answer = (string) json_encode(["site_id" => 7341, "console_name" => "R341"]); return [200, $answer, base64_encode(sodium_crypto_sign_detached($answer, $secret))]; }
        return [503, "", ""];
    };
    $key = Kaleta\Fleet\Link::makeKey("https://console-r341.example", bin2hex(random_bytes(16)), base64_encode(sodium_crypto_sign_publickey($pair)), "R341");
    Kaleta\Fleet\Link::pair($app, $key, false); $s = $app->settings();
    echo $s->get("fleet_site_id"), "|", $s->get("fleet_answers_bound") === "" ? "unbound" : "bound!", "|", $s->int("fleet_last_ts") < 4102444800 ? "fresh" : "old!";')" \
  "7341|unbound|fresh"
sq "UPDATE ka_nastaveni SET hodnota = '' WHERE promenna IN ('fleet_console_url', 'fleet_console_key', 'fleet_console_name', 'fleet_site_id', 'fleet_updates', 'fleet_last_error', 'fleet_answers_bound', 'fleet_last_ts', 'fleet_update_allowed');
  REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('site_url', '$R341_SITE_URL')" > /dev/null

# L7: the person's own request replaces an administrator's pending 3-day link (a signed-in account); the old link stops working
sq "DELETE FROM ka_kontrola_ip WHERE typ = 'obnova';
  INSERT INTO ka_uzivatele (user, password, jmeno, email, jazyk, admin, posledni_login, potvrzeno, blokovat, obnova_otisk, obnova_cas) VALUES ('r341l7', '$(php -r 'echo password_hash("R341-l7-pw-1", PASSWORD_DEFAULT);')', 'Reset R341', 'r341l7@example.com', 'en', 0, '$(site_time '-3 days')', '$(site_time '-9 days')', 0, SHA2('r341-admin-link', 256), '$(site_time '+71 hours')')" > /dev/null
R341_L7=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'r341l7'"); R341_P0=$(sq "SELECT IFNULL(MAX(idp), 0) FROM ka_posta")
R341_RJAR="$WORK/jar-r341r"; curl -s -c "$R341_RJAR" -o "$WORK/response" "$B/admin.php?action=password"
curl -s -b "$R341_RJAR" -c "$R341_RJAR" -o /dev/null -X POST "$B/admin.php?action=password" -d "_csrf=$(r341_csrf "$WORK/response")" -d kdo=r341l7@example.com
expect "3.41 review L7: a request replaces the administrator's link – a new one-hour link is queued, the old token is gone" \
  "$(sq "SELECT CONCAT(obnova_otisk <> SHA2('r341-admin-link', 256), '|', obnova_cas <= '$(site_time '+1 minute')') FROM ka_uzivatele WHERE idu = $R341_L7")|$(sq "SELECT COUNT(*) FROM ka_posta WHERE idp > $R341_P0 AND komu = 'r341l7@example.com'")" "1|1|1"
sq "DELETE FROM ka_posta WHERE idp > $R341_P0; DELETE FROM ka_kontrola_ip WHERE typ = 'obnova'; DELETE FROM ka_uzivatele WHERE idu = $R341_L7" > /dev/null

# L8: the office screen's rotation script carries the nonce when the preview policy is on (and stays plain without it)
expect "3.41 review L8: Screen::response – every inline script has the request's nonce under the policy, none without it" \
  "$(r341_php '$plain = Kaleta\Front\Screen::response($app)->body; Kaleta\Front\Csp::start(); $html = Kaleta\Front\Screen::response($app)->body;
    preg_match_all("/<script\b[^>]*>/", $html, $m); echo count($m[0]), "|", count(array_filter($m[0], fn ($t) => str_contains($t, "nonce=\""))), "|", substr_count($plain, "nonce=");')" "1|1|0"
