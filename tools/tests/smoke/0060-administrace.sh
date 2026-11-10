echo "== administrace"
check "zapomenuté heslo – formulář" 200 "/admin.php?action=password" "Poslat odkaz"
check "zapomenuté heslo – neplatný odkaz" 400 "/admin.php?action=password&token=$(printf 'a%.0s' $(seq 1 64))" "Odkaz už neplatí"
check "bez přihlášení je jen login" 200 /admin.php "Heslo"
TOKEN=$(csrf)
code=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code}' -X POST "$B/admin.php" -d "_csrf=$TOKEN" -d user=admin -d password=spatne-heslo-123); expect "špatné heslo odmítnuto" "$code" 401
code=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code}' -X POST "$B/admin.php" -d user=admin --data-urlencode "password=$PASSWORD"); expect "POST bez CSRF odmítnut" "$code" 400
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$TOKEN" -d user=admin --data-urlencode "password=$PASSWORD"
"${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO ka_nastaveni VALUES ('extensions','novinky,poptavky,newsletter,statistika,presmerovani,asistent,jazyky,claude') ON DUPLICATE KEY UPDATE hodnota=VALUES(hodnota)"
check "přehled" 200 /admin.php "Přehled"
# 3.11 (UX310-1, UX310-20): right after the installation the site audit names the starter site's sample content (placeholders,
# sample reviews and figures, sample texts); the questions for an agency are folded away on a site nobody hands over
check "3.11 UX310-1: the site audit names the sample content of the starter pages" 200 "/admin.php?module=audit" "Na stránce je pořád ukázkový obsah z úvodního webu"
# 3.23 (C17): the client questions are rows of the one hand-over checklist, marked "for a client" (five of them), with its progress
grep -q 'ukázkové reference: ' "$WORK/response" && grep -q '<h2 id="predani-nadpis">Předání klientovi <span class="stitek">hotovo [0-9]* z [0-9]*</span></h2>' "$WORK/response" \
  && [ "$(grep -c '<span class="stitek">pro klienta</span>' "$WORK/response")" = 5 ] && grep -q 'data-predani-radek="client_account"><span aria-hidden="true">!</span>' "$WORK/response" \
  && echo "  ok     3.11 UX310-1/20, 3.23 C17: the home page has sample testimonials; the client questions are marked rows of the hand-over checklist" || { echo "  CHYBA  3.11: the audit of a fresh starter site"; sed 's/<[^>]*>/ /g' "$WORK/response" | grep -i 'ukázk\|klient' | head -5; ERRORS=$((ERRORS+1)); }
# 3.5 (UXA-07, UXA-20): until a Claude connection has ever called the site, the dashboard leads with "Connect Claude" (the
# address with Copy, the HTTPS warning), then First steps, and Ask Claude below
dash_order() { php -r '$h = (string) file_get_contents($argv[1]); $p = array_map(fn (string $n) => strpos($h, $n), [$argv[2], $argv[3], $argv[4]]); echo in_array(false, $p, true) ? "missing" : ($p[0] < $p[1] && $p[1] < $p[2] ? "ok" : "order");' "$WORK/response" "$@"; }
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php"
expect "3.5: not connected – Connect Claude, First steps, then Ask Claude" "$(dash_order 'id="pripojit-claude"' 'class="pruvodce"' 'id="ask-claude-nadpis"')" "ok"
# 3.32 (U37): the site's numbers first (Waiting for you and the tiles), then Connect Claude – open for the administrator –
# First steps (open while a step is left) and Ask Claude with its examples folded
expect "3.32 (U37): the tiles come before Connect Claude; the card and First steps are open for the administrator; the examples are folded" \
  "$(dash_order 'class="dlazdice"' 'id="pripojit-claude"' 'class="pruvodce"')|$(grep -c 'id="pripojit-claude" aria-labelledby="pripojit-claude-nadpis" open>' "$WORK/response")|$(grep -c '<details class="pruvodce" aria-label="[^"]*" open>' "$WORK/response")|$(grep -c '<details class="ask-claude-priklady-obal"><summary>' "$WORK/response")" "ok|1|1|1"
expect "3.12 (UX310-26): with the Connect Claude card, First steps do not repeat it" "$(grep -c 'admin.php#pripojit-claude' "$WORK/response" || true)" "0"
grep -q 'data-kopirovat="#mcp-adresa-prehled"' "$WORK/response" && grep -q "<code id=\"mcp-adresa-prehled\">$B/mcp</code>" "$WORK/response" && grep -q 'Web neběží na HTTPS' "$WORK/response" \
  && echo "  ok     3.5: the card has the MCP address with Copy and warns that the site is not on HTTPS" || { echo "  CHYBA  3.5: connect card"; ERRORS=$((ERRORS+1)); }
check "3.5: Claude settings have the address with Copy and the connection state" 200 "/admin.php?module=claude_settings" 'data-kopirovat="#mcp-adresa-nastaveni"'
grep -q 'Claude není připojený' "$WORK/response" && grep -q 'class="hlaska claude-panel hlaska-chyba"' "$WORK/response" && echo "  ok     3.5, 3.18: Claude settings say Claude is not connected (the status panel)" || { echo "  CHYBA  3.5: Claude settings state"; ERRORS=$((ERRORS+1)); }
check "3.5: My account has the address with Copy" 200 "/admin.php?action=account" 'data-kopirovat="#mcp-adresa-ucet"'
check "3.9.1: the admin footer links to the support page (Buy Me a Coffee)" 200 "/admin.php?action=account" 'href="https://buymeacoffee.com/Kaletacms"'
check "3.29: the support link in the admin footer asks to support the development of Kaleta CMS" 200 "/admin.php?action=account" 'Podpořte vývoj Kaleta CMS</a></footer>'
# 3.9.2 N67: an update never touches media/, so migration 0084 does – it replaces a media/.htaccess Kaleta shipped (here the
# one of 3.9.1, rebuilt from today's file) and keeps a customised one, with the new version next to it and a warning
# (3.15 N72: migration 0098 then brings the header rules that match an upper-case extension – both run again here)
n67_rerun() { "${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_nastaveni SET hodnota = TRIM(BOTH ',' FROM REPLACE(REPLACE(CONCAT(',', hodnota, ','), ',0084-media-htaccess,', ','), ',0098-media-htaccess-case,', ',')) WHERE promenna = 'data_migrations'"; curl -s -o /dev/null "$B/?n67=$RANDOM"; }
cp "$WORK/web/media/.htaccess" "$WORK/media-htaccess-new"
{ echo '# Uploaded files are only served – never run.'; sed -e '1,2d' -e '/case of the extension, as Apache/d' -e 's/ – in any$//' -e 's/"(?i)/"/' -e 's/|pht|phtml|phar|pl|py|cgi|sh|shtml|html?|js)(\\.|\$)/|phtml|phar|pl|py|cgi|sh|html?|js)$/' "$WORK/media-htaccess-new"; } > "$WORK/web/media/.htaccess"
expect "3.9.2 N67: the test rebuilds the media/.htaccess of 3.9.1 exactly" "$(php -r 'echo hash_file("sha256", $argv[1]);' "$WORK/web/media/.htaccess")" "e20dd912d8066f36f5d967ee462497fca1dcbcd5b53f19d6f77e6bff7ceec708"
n67_rerun
expect "3.9.2 N67, 3.15 N72: migrations 0084 and 0098 replace the media/.htaccess of an earlier release" "$(cmp -s "$WORK/web/media/.htaccess" "$WORK/media-htaccess-new" && echo same)|$([ -f "$WORK/web/media/.htaccess.kaleta-nova" ] && echo nova)" "same|"
printf '# my own rules\nRequire all granted\n' > "$WORK/web/media/.htaccess"
n67_rerun
expect "3.9.2 N67: a customised media/.htaccess stays, the new one waits next to it" "$(head -1 "$WORK/web/media/.htaccess")|$(cmp -s "$WORK/web/media/.htaccess.kaleta-nova" "$WORK/media-htaccess-new" && echo nova)" "# my own rules|nova"
check "3.9.2 N67: System status asks to carry the new media/.htaccess over" 200 "/admin.php?module=status" "media/.htaccess.kaleta-nova"
# 3.15 N72: the file of 3.9.2–3.14 (header rules for lower-case extensions only) is replaced by 0098 alone; System status
# names a file without the new rules
sed -e '/case of the extension, as Apache/d' -e 's/ – in any$//' -e 's/<FilesMatch "(?i)\\.svg\$">/<FilesMatch "\\.svg$">/' -e 's/<FilesMatch "(?i)\\.(docx/<FilesMatch "\\.(docx/' "$WORK/media-htaccess-new" > "$WORK/web/media/.htaccess"; rm -f "$WORK/web/media/.htaccess.kaleta-nova"
expect "3.15 N72: the test rebuilds the media/.htaccess of 3.9.2 exactly" "$(php -r 'echo hash_file("sha256", $argv[1]);' "$WORK/web/media/.htaccess")" "1901ef144717137b305ae54dcead8641c3552c35ee8c36a8e334ae37716a5669"
check "3.15 N72: System status names a media/.htaccess without the rules of 3.15" 200 "/admin.php?module=status" "nemá pravidla Kalety 3.15"
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_nastaveni SET hodnota = TRIM(BOTH ',' FROM REPLACE(CONCAT(',', hodnota, ','), ',0098-media-htaccess-case,', ',')) WHERE promenna = 'data_migrations'"; curl -s -o /dev/null "$B/?n72=$RANDOM"
expect "3.15 N72: migration 0098 replaces the media/.htaccess of 3.9.2" "$(cmp -s "$WORK/web/media/.htaccess" "$WORK/media-htaccess-new" && echo same)|$([ -f "$WORK/web/media/.htaccess.kaleta-nova" ] && echo nova)" "same|"
cp "$WORK/media-htaccess-new" "$WORK/web/media/.htaccess"; rm -f "$WORK/web/media/.htaccess.kaleta-nova"
TOKEN=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=requests&action=save" -d "_csrf=$TOKEN" -d quick=1 -d from=dashboard -d "text=Zkouška před připojením."
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php"
grep -q 'počká, dokud někdo Clauda k webu nepřipojí' "$WORK/response" && grep -q 'class="hlaska hlaska-varovani" role="status">Uloženo jako požadavek' "$WORK/response" && ! grep -q 'Odesláno Claudovi' "$WORK/response" \
  && echo "  ok     3.5, 3.18: a request saved before Claude is connected says it waits, not that it was sent" || { echo "  CHYBA  3.5: request saved while not connected"; ERRORS=$((ERRORS+1)); }
"${MYSQL[@]}" "$DB_NAME" -e "DELETE FROM ka_requests WHERE title = 'Zkouška před připojením.'"
# a personal token nobody used is not a connection; its first use is, and stays remembered after the token is revoked
"${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO ka_api_tokeny (idu, nazev, druh, otisk, vytvoren) SELECT idu, 'unused 3.5', 'token', SHA2('unused-3.5', 256), NOW() FROM ka_uzivatele WHERE user = 'admin'"
check "3.5: a token nobody used keeps the Connect Claude card" 200 /admin.php 'id="pripojit-claude"'
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_api_tokeny SET pouzit = NOW() WHERE nazev = 'unused 3.5'"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php"
"${MYSQL[@]}" "$DB_NAME" -e "DELETE FROM ka_api_tokeny WHERE nazev = 'unused 3.5'"
expect "3.5: once a connection was used, Ask Claude leads again and the card is gone (3.32: after the numbers)" "$(dash_order 'class="dlazdice"' 'id="ask-claude-nadpis"' 'class="pruvodce"')|$(grep -c 'id="pripojit-claude"' "$WORK/response" || true)" "ok|0"
# 3.18: "connected" means a valid connection exists now – revoking the only one says "not connected" again and the card returns
check "3.18: revoking every connection shows Claude as not connected again" 200 /admin.php 'class="claude-stav claude-stav-none"'
grep -q 'id="pripojit-claude"' "$WORK/response" && grep -q 'Claude není k tomuto webu připojený' "$WORK/response" && echo "  ok     3.18: the Connect Claude card returns, Ask Claude warns" || { echo "  CHYBA  3.18: not connected after revoking"; ERRORS=$((ERRORS+1)); }
"${MYSQL[@]}" "$DB_NAME" -e "DELETE FROM ka_nastaveni WHERE promenna = 'claude_first_used'" # the first real MCP call below sets it again (checked there)
check "přehled: nadpis obrazovky je h1" 200 /admin.php "<h1>Přehled</h1>"
grep -q '<li class=""><a href="/admin.php?module=appearance">' "$WORK/response" && grep -q '<li class=""><a href="/admin.php?module=pages"><strong>Připravte stránky' "$WORK/response" && echo "  ok     první kroky nepočítají vzhled a stránky ze startovacího webu za hotové" || { echo "  CHYBA  první kroky odškrtnuté startovacím webem"; ERRORS=$((ERRORS+1)); }
check "administrace: nadpis h1 a hlavní menu v <nav>" 200 "/admin.php?module=pages" '<nav class="menu-obal" aria-label="Hlavní menu">'
for m in pages "pages&action=new" enquiries parts components "components&action=new" collections "collections&action=new" news "news&action=new" "news&action=links" categories "categories&action=new" tags media stats appearance users "users&action=new" redirects changelog transfer extensions; do check "modul $m" 200 "/admin.php?module=$m"; done
check "uživatelé se shrnutím oprávnění" 200 "/admin.php?module=users" "Smí všechno"
for z in general seo analytics cookies mail webhooks backups; do check "nastavení/$z" 200 "/admin.php?module=settings&tab=$z"; done
# 3.2: tabs that became screens of their own – the old address leads there; the new screens and hubs open
for z in company:business health:status; do expect "3.2: settings&tab=${z%%:*} leads to ${z##*:}" "$(curl -s -b "$JAR" -o /dev/null -w '%{redirect_url}' "$B/admin.php?module=settings&tab=${z%%:*}" | sed 's/.*module=//')" "${z##*:}"; done
check "3.2: System status is its own screen" 200 "/admin.php?module=status" "Cron"
# 3.32 (U48): problems first (each linking to its row), then the groups – a group with a problem open, an all-green one folded
expect "3.32 (U48): System status – the problems link to their rows, the open groups are those with a problem, green ones are folded" \
  "$(php -r '$h = (string) file_get_contents($argv[1]); preg_match_all("#<a href=\"\\#(stav-\\d+)\">#", $h, $links); $missing = array_filter($links[1], fn ($id) => !str_contains($h, "<tr id=\"$id\" class=\"stav-")); preg_match_all("#<details class=\"stav-skupina\" data-status-group( open)?>\\s*<summary><h2>[^<]*</h2> <span class=\"stitek stitek-(\\w+)\">#", $h, $g, PREG_SET_ORDER); $wrong = array_filter($g, fn ($m) => ($m[1] === " open") !== ($m[2] === "koncept")); echo count($g) > 3 ? "groups" : "few", "|", $missing === [] ? "linked" : "broken", "|", $wrong === [] ? "folded" : "wrong", "|", str_contains($h, "data-status-problems") === (count($links[1]) > 0) ? "list" : "nolist";' "$WORK/response")" "groups|linked|folded|list"
check "3.2: Claude settings hold the instructions and the guardrails" 200 "/admin.php?module=claude_settings" 'name="claude_instructions"'
check "3.2: Business details show the hub tabs" 200 "/admin.php?module=facts" 'zalozky-hub'
check "3.2: the menu leads to the hubs" 200 "/admin.php" "module=claude_settings"
expect "3.2: Business details refuse the actions of Settings it does not offer" "$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' "$B/admin.php?module=business&action=download_backup&file=x")" 404
# 3.3.2 (N41): the cron and monitoring tokens change only from System status – not through Business details, which editors share
"${MYSQL[@]}" "$DB_NAME" -e "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('tasks_token', 'before-n41'), ('health_token', 'before-n41')"
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=business"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=business&action=save" -d "_csrf=$(csrf)" -d novy_token_ulohy=1 -d novy_token=1
N41_BUSINESS=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT GROUP_CONCAT(hodnota ORDER BY promenna) FROM ka_nastaveni WHERE promenna IN ('tasks_token', 'health_token')")
N41_ALERTS=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'alerts_enabled'")
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=status"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=status&action=save" -d "_csrf=$(csrf)" -d novy_token=1 $([ "$N41_ALERTS" = 0 ] || echo "-d alerts_enabled=1")
expect "3.3.2: Business details never replace the cron or monitoring token, System status does" \
  "$N41_BUSINESS|$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(hodnota <> 'before-n41', LENGTH(hodnota)) FROM ka_nastaveni WHERE promenna = 'health_token'")" "before-n41,before-n41|132"
check "nastavení: volba úvodní stránky" 200 "/admin.php?module=settings&tab=general" 'name="home_page"'
check "neznámý modul – 3.11 (UX310-30): it does not exist, rather than no access" 404 "/admin.php?module=neexistuje"
check "2.0: the public API of 1.x is gone" 404 /api/novinky
"${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO ka_nastaveni VALUES ('additional_languages','en') ON DUPLICATE KEY UPDATE hodnota='en'"
check "anglická verze webu" 200 /en/ 'lang="en"'
# 2.3.1: saving Settings → General as a browser does (every field of the form as it is) keeps the language versions
curl -s -b "$JAR" -c "$JAR" -o "$WORK/general.html" "$B/admin.php?module=settings&tab=general"
php -r '$d = new DOMDocument(); @$d->loadHTML(file_get_contents($argv[1])); $x = new DOMXPath($d); $f = $x->query("//form[.//input[@name=\"tab\"]]")->item(0); $q = [];
  foreach ($x->query(".//input|.//select|.//textarea", $f) as $e) { $n = $e->getAttribute("name"); $t = $e->getAttribute("type"); if ($n === "" || $t === "submit" || (in_array($t, ["checkbox", "radio"], true) && !$e->hasAttribute("checked"))) continue;
    $v = $e->nodeName === "select" ? (($o = $x->query(".//option[@selected]", $e)->item(0) ?? $x->query(".//option", $e)->item(0)) ? $o->getAttribute("value") : "") : ($e->nodeName === "textarea" ? $e->textContent : $e->getAttribute("value")); $q[] = rawurlencode($n) . "=" . rawurlencode($v); }
  echo implode("&", $q);' "$WORK/general.html" > "$WORK/general.post"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=save" --data-binary @"$WORK/general.post"
expect "saving Settings → General keeps the language versions" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'additional_languages'")" "en"
# 3.23 (C12): General is split into tabs – each tab's form saved as a browser does (every field as it is) leaves the other tabs alone
settings_form() { # <tab> <file>: the body a browser posts for the form of Settings → <tab>, unchanged
  curl -s -b "$JAR" -c "$JAR" -o "$WORK/settings-form.html" "$B/admin.php?module=settings&tab=$1"
  php -r '$d = new DOMDocument(); @$d->loadHTML(file_get_contents($argv[1])); $x = new DOMXPath($d); $f = $x->query("//form[.//input[@name=\"tab\"]]")->item(0); $q = [];
    foreach ($x->query(".//input|.//select|.//textarea", $f) as $e) { $n = $e->getAttribute("name"); $t = $e->getAttribute("type"); if ($n === "" || $t === "submit" || (in_array($t, ["checkbox", "radio"], true) && !$e->hasAttribute("checked"))) continue;
      $v = $e->nodeName === "select" ? (($o = $x->query(".//option[@selected]", $e)->item(0) ?? $x->query(".//option", $e)->item(0)) ? $o->getAttribute("value") : "") : ($e->nodeName === "textarea" ? $e->textContent : $e->getAttribute("value")); $q[] = rawurlencode($n) . "=" . rawurlencode($v); }
    echo implode("&", $q);' "$WORK/settings-form.html" > "$2"
}
SPLIT323="'additional_languages', 'auto_suspend', 'require_2fa', 'agency_name', 'share_buttons', 'page_cache', 'site_name', 'social_networks'"
split323() { sq "SELECT GROUP_CONCAT(CONCAT(promenna, '=', hodnota) ORDER BY promenna SEPARATOR ';') FROM ka_nastaveni WHERE promenna IN ($SPLIT323)"; }
sq "DROP TABLE IF EXISTS nastaveni323; CREATE TABLE nastaveni323 AS SELECT * FROM ka_nastaveni" > /dev/null # put back at the end of this part
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('auto_suspend', 'ucty'), ('require_2fa', ''), ('agency_name', 'Studio 323'), ('share_buttons', '1'), ('page_cache', '1'), ('social_networks', 'facebook')" > /dev/null
BEFORE323=$(split323)
for t in general languages security agency; do settings_form "$t" "$WORK/split323.post"; curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=save" --data-binary @"$WORK/split323.post"; done
expect "3.23: saving General, Languages, Security and Agency one after another as a browser does changes nothing on the other tabs" "$(split323)" "$BEFORE323"
# N3231-1 (review of 3.23.1): a save with one invalid field keeps the other settings – so it is one change-log row, although its message is an error
N32311_LOG=$(sq "SELECT COALESCE(MAX(idp), 0) FROM ka_protokol"); N32311_EMAIL=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'site_email'")
settings_form general "$WORK/n32311.post"; printf '&site_email=not-an-address' >> "$WORK/n32311.post"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=save" --data-binary @"$WORK/n32311.post"
curl -s -b "$JAR" -c "$JAR" -o /dev/null "$B/admin.php?module=settings&tab=general" # shows (and forgets) the highlighted field
expect "3.23.1 review (N3231-1): Settings saved with an invalid field – the field is refused, the save is one change-log row" \
  "$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'site_email'")|$(sq "SELECT GROUP_CONCAT(CONCAT(modul, ' ', akce, ' ', popis)) FROM ka_protokol WHERE idp > $N32311_LOG")" "$N32311_EMAIL|settings save general"
# 3.23.1 (item 12): every tab in the row (the console tab too – an agency is named above), saved unchanged one after another,
# leaves every setting as it was; the phone select offers the same tabs
TABS3231=$(curl -s -b "$JAR" "$B/admin.php?module=settings&tab=general" | perl -0ne 'print join(" ", $1 =~ /tab=([a-z_]+)"/g) if /<nav class="zalozky"[^>]*>(.*?)<\/nav>/s')
SELECT3231=$(curl -s -b "$JAR" "$B/admin.php?module=settings&tab=general" | perl -0ne 'print join(" ", $1 =~ /<option value="([a-z_]+)"/g) if /<select id="zalozka-vyber"[^>]*>(.*?)<\/select>/s')
expect "3.23.1 (N-C12): the phone select of Settings offers the same tabs as the row, 13 of them" "$SELECT3231|$(echo "$TABS3231" | wc -w | tr -d ' ')" "$TABS3231|13"
# pass 1 may only add the default of a key never saved before (a fresh secret too); pass 2 must change nothing at all
diff3231() { sq "SELECT IFNULL(GROUP_CONCAT(promenna ORDER BY promenna), 'none') FROM (SELECT k.promenna FROM ka_nastaveni k $1 JOIN nastaveni3231 n USING (promenna) WHERE NOT (k.hodnota <=> n.hodnota)
    UNION SELECT n.promenna FROM nastaveni3231 n LEFT JOIN ka_nastaveni k USING (promenna) WHERE k.promenna IS NULL) d"; }
save3231() { for t in $TABS3231; do settings_form "$t" "$WORK/tab3231.post"; curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=save" --data-binary @"$WORK/tab3231.post"; done; }
sq "DROP TABLE IF EXISTS nastaveni3231; CREATE TABLE nastaveni3231 AS SELECT * FROM ka_nastaveni" > /dev/null; save3231; PASS1_3231=$(diff3231 "")
sq "DROP TABLE nastaveni3231; CREATE TABLE nastaveni3231 AS SELECT * FROM ka_nastaveni" > /dev/null; save3231
expect "3.23.1: saving each of the $(echo "$TABS3231" | wc -w | tr -d ' ') Settings tabs unchanged, one after another, keeps every value of the other tabs – and a second round changes nothing at all" \
  "$PASS1_3231|$(diff3231 LEFT)" "none|none"
sq "DROP TABLE nastaveni3231" > /dev/null
grep -q 'name="general_split" value="1"' <(curl -s -b "$JAR" "$B/admin.php?module=settings&tab=general") && ! grep -q 'name="auto_suspend\[\]"\|name="agency_name"\|name="additional_languages\[\]"' <(curl -s -b "$JAR" "$B/admin.php?module=settings&tab=general") \
  && echo "  ok     3.23: the General form carries the marker and none of the moved fields" || { echo "  CHYBA  3.23: the General form still has moved fields or no marker"; ERRORS=$((ERRORS+1)); }
check "3.23: Settings → Languages has the languages" 200 "/admin.php?module=settings&tab=languages" 'id="additional_languages"'
grep -q 'name="site_language"' "$WORK/response" && grep -q 'aria-current="page">Jazyky<' "$WORK/response" && echo "  ok     3.23: … and the site language, on the tab Jazyky" || { echo "  CHYBA  3.23: the Languages tab"; ERRORS=$((ERRORS+1)); }
check "3.23: Settings → Security has two-factor sign-in, the suspension and the way to the firewall" 200 "/admin.php?module=settings&tab=security" 'name="require_2fa"'
grep -q 'name="auto_suspend\[\]"' "$WORK/response" && grep -q 'href="/admin.php?module=settings&amp;tab=firewall">Nastavení → Firewall</a>' "$WORK/response" && echo "  ok     3.23: … the suspension and the link to Nastavení → Firewall" || { echo "  CHYBA  3.23: the Security tab"; ERRORS=$((ERRORS+1)); }
check "3.23: Settings → Agency has the agency" 200 "/admin.php?module=settings&tab=agency" 'name="agency_logo"'
# the single General form of 3.22 (no marker – a page opened before the update) still saves everything it showed, as before
set323() { sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = '$1'"; }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=save" --data-urlencode "_csrf=$(grep -o 'name="_csrf" value="[a-f0-9]*"' "$WORK/settings-form.html" | head -1 | sed 's/.*value="//;s/"//')" -d tab=general \
  --data-urlencode "site_name=$(set323 site_name)" --data-urlencode "site_url=$(set323 site_url)" --data-urlencode "time_zone=$(set323 time_zone)" --data-urlencode "home_page=$(set323 home_page)" \
  -d site_language="$(set323 site_language)" -d 'additional_languages[]=en' -d 'additional_languages[]=de' -d require_2fa= --data-urlencode "agency_name=Studio 3.22" -d page_cache=1
expect "3.23: a 3.22 General form without the marker still saves the languages, security and agency it showed – and its unticked boxes, as before" \
  "$(sq "SELECT GROUP_CONCAT(CONCAT(promenna, '=', hodnota) ORDER BY promenna SEPARATOR ';') FROM ka_nastaveni WHERE promenna IN ('additional_languages', 'require_2fa', 'agency_name', 'auto_suspend', 'share_buttons', 'page_cache')")" \
  "additional_languages=en,de;agency_name=Studio 3.22;auto_suspend=;page_cache=1;require_2fa=;share_buttons=0"
sq "CREATE TEMPORARY TABLE changed323 AS SELECT n.* FROM nastaveni323 n JOIN ka_nastaveni k USING (promenna) WHERE NOT (k.hodnota <=> n.hodnota); REPLACE INTO ka_nastaveni SELECT * FROM changed323;
  CREATE TEMPORARY TABLE added323 AS SELECT k.promenna FROM ka_nastaveni k LEFT JOIN nastaveni323 n USING (promenna) WHERE n.promenna IS NULL; DELETE k FROM ka_nastaveni k JOIN added323 USING (promenna); DROP TABLE nastaveni323" > /dev/null
# 3.23 (C8): the console tab is in the row only with an agency named or a pairing; its address works anyway
! grep -q 'tab=console"' <(curl -s -b "$JAR" "$B/admin.php?module=settings&tab=agency") && grep -q 'objeví se záložka Hlášení do konzole' <(curl -s -b "$JAR" "$B/admin.php?module=settings&tab=agency") \
  && echo "  ok     3.23: without an agency the console tab is not in the row, and Agency says when it appears" || { echo "  CHYBA  3.23: the console tab shows without an agency"; ERRORS=$((ERRORS+1)); }
check "3.23: … its address still opens it, under the new name" 200 "/admin.php?module=settings&tab=console" 'aria-current="page">Hlášení do konzole<'
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('agency_name', 'Studio 323')" > /dev/null
check "3.23: with an agency named the console tab is the last one in the row" 200 "/admin.php?module=settings&tab=agency" '>Agentura</a>'
grep -q '<a href="/admin.php?module=settings&amp;tab=console">Hlášení do konzole</a>' "$WORK/response" && [ "$(perl -0ne 'print $1 if /<nav class="zalozky"[^>]*>(.*?)<\/nav>/s' "$WORK/response" | grep -o 'tab=[a-z_]*' | tail -1)" = "tab=console" ] \
  && echo "  ok     3.23: … linked as the last tab, after Agentura" || { echo "  CHYBA  3.23: the console tab with an agency"; ERRORS=$((ERRORS+1)); }
sq "UPDATE ka_nastaveni SET hodnota = '' WHERE promenna = 'agency_name'" > /dev/null
# an old link to a field that moved goes on to its tab (image/admin.js reads the map the General tab carries)
check "3.23: General carries where the moved fields went" 200 "/admin.php?module=settings&tab=general" 'data-pole-jinde="'
grep -q '&quot;additional_languages&quot;:&quot;/admin.php?module=settings&amp;tab=languages&quot;' "$WORK/response" && grep -q '&quot;require_2fa&quot;:&quot;/admin.php?module=settings&amp;tab=security&quot;' "$WORK/response" \
  && echo "  ok     3.23: … additional_languages to Languages, require_2fa to Security" || { echo "  CHYBA  3.23: the map of moved fields"; grep -o 'data-pole-jinde="[^"]*"' "$WORK/response" | head -c 400; ERRORS=$((ERRORS+1)); }
code=$(curl -s -o /dev/null -w '%{http_code}' "$B/en/novinky/vitejte-v-kalete"); expect "novinka jiné jazykové verze přesměruje" "$code" 301

# a failed news item validation must return the form with a message, not error 500
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=news&action=new"
TOKEN=$(csrf)
code=$(curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -w '%{http_code}' -X POST "$B/admin.php?module=news&action=save" -d "_csrf=$TOKEN" -d idc=0 -d titulek= -d tema=1)
[ "$code" = 200 ] && grep -q 'name="titulek"' "$WORK/response" && echo "  ok     chyba ve formuláři novinky vrátí formulář" || { echo "  CHYBA  validace novinky: kód $code"; ERRORS=$((ERRORS+1)); }

# news without a category (enabled only after installation): „Nová novinka“ (New news item) is not a dead end – a default category is created in the site language
"${MYSQL[@]}" "$DB_NAME" -e "SET FOREIGN_KEY_CHECKS=0; DROP TABLE IF EXISTS kat_zaloha; CREATE TABLE kat_zaloha AS SELECT * FROM ka_kategorie; DELETE FROM ka_kategorie; UPDATE ka_nastaveni SET hodnota='en' WHERE promenna='site_language'"
check "nová novinka bez kategorie otevře editor" 200 "/admin.php?module=news&action=new" 'name="titulek"'
expect "výchozí kategorie založená v jazyce webu" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(COUNT(*), ':', MAX(nazev)) FROM ka_kategorie")" "1:News"
"${MYSQL[@]}" "$DB_NAME" -e "SET FOREIGN_KEY_CHECKS=0; DELETE FROM ka_kategorie; INSERT INTO ka_kategorie SELECT * FROM kat_zaloha; DROP TABLE kat_zaloha; UPDATE ka_nastaveni SET hodnota='cs' WHERE promenna='site_language'"

# news author: sees only their own news items and does not publish
NEWS_ID=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT idc FROM ka_novinky ORDER BY idc LIMIT 1")
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=users&action=save" -d "_csrf=$TOKEN" -d idu=0 -d jmeno=Autor -d user=autor --data-urlencode "password=$PASSWORD" -d admin=0
# 3.9 N39-1: a new password set by an administrator ends the user's Claude connections (OAuth tokens live a year); personal
# tokens stay only when the box is unticked (3.31.2, N101: the form then sends zrusit_tokeny=0 – without the field they go)
IDU_AUTOR=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT idu FROM ka_uzivatele WHERE user = 'autor'")
"${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO ka_api_tokeny (idu, nazev, klient, druh, otisk, vytvoren, expirace) VALUES ($IDU_AUTOR, 'Claude', 'n39client', 'obnova', SHA2('n39-refresh', 256), '$(site_time)', '$(site_time '+300 days')'), ($IDU_AUTOR, 'Claude', 'n39client', 'pristup', SHA2('n39-access', 256), '$(site_time)', '$(site_time '+1 hour')'), ($IDU_AUTOR, 'personal', NULL, 'token', SHA2('n39-personal', 256), '$(site_time)', NULL)"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=users&action=save" -d "_csrf=$TOKEN" -d "idu=$IDU_AUTOR" -d jmeno=Autor -d user=autor --data-urlencode "password=$PASSWORD" --data-urlencode "soucasne=$PASSWORD" -d admin=0 -d zrusit_tokeny=0
expect "3.9 N39-1: an administrator's new password ends the user's Claude connections, the personal token stays when the box is unticked" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(SUM(druh <> 'token'), '|', SUM(druh = 'token')) FROM ka_api_tokeny WHERE idu = $IDU_AUTOR")" "0|1"
# 3.15 (N19): the user's form offers to revoke the personal tokens too (ticked, as in My account); without a new password nothing goes
check "3.15 N19: the user's form offers to revoke the personal tokens with the new password" 200 "/admin.php?module=users&action=edit&id=$IDU_AUTOR" 'name="zrusit_tokeny" value="1" checked'
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=users&action=save" -d "_csrf=$TOKEN" -d "idu=$IDU_AUTOR" -d jmeno=Autor -d user=autor -d admin=0 -d zrusit_tokeny=1
N19_KEPT=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_api_tokeny WHERE idu = $IDU_AUTOR AND druh = 'token'")
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=users&action=save" -d "_csrf=$TOKEN" -d "idu=$IDU_AUTOR" -d jmeno=Autor -d user=autor --data-urlencode "password=$PASSWORD" --data-urlencode "soucasne=$PASSWORD" -d admin=0 -d zrusit_tokeny=1
expect "3.15 N19: with it ticked an administrator's new password revokes the personal tokens too" "$N19_KEPT|$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_api_tokeny WHERE idu = $IDU_AUTOR")" "1|0"
"${MYSQL[@]}" "$DB_NAME" -e "DELETE FROM ka_api_tokeny WHERE idu = $IDU_AUTOR"
JAR2="$WORK/jar2"
TOKEN2=$(curl -s -c "$JAR2" "$B/admin.php" | grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//;s/"//')
curl -s -b "$JAR2" -c "$JAR2" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$TOKEN2" -d user=autor --data-urlencode "password=$PASSWORD"
code=$(curl -s -b "$JAR2" -o "$WORK/response" -w '%{http_code}' "$B/admin.php?module=news")
[ "$code" = 200 ] && ! grep -q "action=edit&amp;id=$NEWS_ID\"" "$WORK/response" && echo "  ok     autor nevidí cizí novinky" || { echo "  CHYBA  autor – výpis: kód $code"; ERRORS=$((ERRORS+1)); }
expect "autor cizí novinku neotevře" "$(curl -s -b "$JAR2" -o /dev/null -w '%{http_code}' "$B/admin.php?module=news&action=edit&id=$NEWS_ID")" 404
expect "autor nemá přístup ke stránkám" "$(curl -s -b "$JAR2" -o /dev/null -w '%{http_code}' "$B/admin.php?module=pages")" 403
# 3.22 (U6): the link dialog of the editor – pages, news and files for the administrator; an author without Pages finds the
# published pages, never a hidden one; signed out there is nothing
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?action=link_targets&q=kont"
grep -q '"url":"\\/kontakt"' "$WORK/response" && grep -q '"label":"Stránky"' "$WORK/response" && echo "  ok     3.22 U6: link targets – a page found by part of its title, in its group" || { echo "  CHYBA  3.22 U6: link targets for the administrator: $(head -c 300 "$WORK/response")"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?action=link_targets&q="
grep -q '"url":"\\/"' "$WORK/response" && grep -q '"title":"Novinky"' "$WORK/response" && echo "  ok     3.22 U6: an empty search offers the pages with the home page and the news listing" || { echo "  CHYBA  3.22 U6: empty search: $(head -c 300 "$WORK/response")"; ERRORS=$((ERRORS+1)); }
"${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO ka_stranky (seo_link, titulek, text, zobrazit, v_menu) VALUES ('kontaktni-skryta-322', 'Kontaktní skrytá 322', '', 0, 0)"
U6_ADMIN=$(curl -s -b "$JAR" "$B/admin.php?action=link_targets&q=kontaktn%C3%AD" | grep -c 'kontaktni-skryta-322' || true)
U6_AUTHOR=$(curl -s -b "$JAR2" "$B/admin.php?action=link_targets&q=kont")
expect "3.22 U6: the administrator sees the hidden page, the author only the published ones" "$U6_ADMIN|$(grep -c 'kontaktni-skryta-322' <<< "$U6_AUTHOR" || true)|$(grep -c '"url":"\\/kontakt"' <<< "$U6_AUTHOR" || true)" "1|0|1"
"${MYSQL[@]}" "$DB_NAME" -e "DELETE FROM ka_stranky WHERE seo_link = 'kontaktni-skryta-322'"
expect "3.22 U6: signed out, the link targets are the sign-in page" "$(curl -s "$B/admin.php?action=link_targets&q=kont" | grep -c '"groups"' || true)" "0"
TOKEN2=$(curl -s -b "$JAR2" "$B/admin.php?module=news&action=new" | grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//;s/"//')
curl -s -b "$JAR2" -c "$JAR2" -o /dev/null -X POST "$B/admin.php?module=news&action=save" -d "_csrf=$TOKEN2" -d idc=0 -d titulek=XSS-test -d tema=1 \
  --data-urlencode 'uvod=<p onmouseover="alert(1)">Perex</p><script>alert(2)</script>' --data-urlencode 'text=<p><img src=x onerror=alert(3)><a href="javascript:alert(4)">odkaz</a></p>'
expect "autor nevloží do novinky skript" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(uvod, text) REGEXP 'script|onerror|onmouseover|javascript' FROM ka_novinky WHERE titulek = 'XSS-test'")" "0"
# 3.31.2 (pre-release review): Kaleta's "ka-" classes (any case) are left out of an author's text, like in the builder; the
# author's own classes and the editor's gallery stay
curl -s -b "$JAR2" -c "$JAR2" -o /dev/null -X POST "$B/admin.php?module=news&action=save" -d "_csrf=$TOKEN2" -d idc=0 -d titulek=KA-class-3312 -d tema=1 \
  --data-urlencode 'uvod=<p class="KA-Kolekce-strany">Perex</p>' --data-urlencode 'text=<p class="ka-kolekce-strany karta3312"><a class="ka-tlacitko" href="/kontakt">odkaz</a></p><figure class="galerie"><img src="/media/x3312.jpg" alt=""></figure>'
expect "3.31.2 review: an author's news text loses Kaleta's ka- classes and keeps its own and the gallery" \
  "$(sq "SELECT CONCAT(CONCAT(uvod, text) REGEXP '(?i)ka-', '|', text LIKE '%class=\"karta3312\"%', '|', text LIKE '%<figure class=\"galerie\">%', '|', uvod) FROM ka_novinky WHERE titulek = 'KA-class-3312'")" "0|1|1|<p>Perex</p>"
check "editor vidí na přehledu novinky od autorů, které čekají na vydání" 200 /admin.php "Novinky od autorů čekají na vydání"
check "výpis novinek: filtr Čekají na vydání" 200 "/admin.php?module=news&status=ke_vydani" "XSS-test"

