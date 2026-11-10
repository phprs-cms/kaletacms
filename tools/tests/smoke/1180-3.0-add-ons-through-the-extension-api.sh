echo "== 3.0: add-ons through the extension API"
mkdir -p "$WORK/web/extensions" && cp -R "$ROOT/docs/examples/extensions/hello" "$WORK/web/extensions/hello"
# the example asks for Kaleta 3.0; before the version is bumped for a release the tree may still say 2.x
sed -i.bak 's/">=3.0"/">=2.0"/' "$WORK/web/extensions/hello/extension.json" && rm -f "$WORK/web/extensions/hello/extension.json.bak"
mkdir -p "$WORK/web/extensions/broken" && printf '%s' '{"name":"Broken","class":"Broken\\Ext","requires":{"api":1}}' > "$WORK/web/extensions/broken/extension.json"
printf '%s\n' '<?php namespace Broken; final class Ext implements \Kaleta\Extension\ExtensionInterface { public function register(\Kaleta\Extension\Api $api): void { throw new \RuntimeException("deliberately broken"); } }' > "$WORK/web/extensions/broken/Extension.php"
mkdir -p "$WORK/web/extensions/old" && printf '%s' '{"name":"Old","class":"Old\\Ext","requires":{"api":0}}' > "$WORK/web/extensions/old/extension.json" && echo '<?php' > "$WORK/web/extensions/old/Extension.php"
check "add-ons: Add-ons lists what is in extensions/ and says why an old one cannot run" 200 "/admin.php?module=addons" "written for extension API 0"
# 3.3.2 (N36): the web serves only extensions/<slug>/public/ – never an add-on's code, manifest or SQL, and no PHP from public/
mkdir -p "$WORK/web/extensions/hello/public" && printf 'body{}' > "$WORK/web/extensions/hello/public/hello.css" && echo '<?php echo "ran";' > "$WORK/web/extensions/hello/public/run.php"
expect "3.3.2 add-ons: Extension.php, extension.json and public/*.php are refused, a file in public/ is served" \
  "$(for u in extensions/hello/Extension.php extensions/hello/extension.json extensions/hello/public/run.php extensions/README.md extensions/hello/public/hello.css; do printf '%s ' "$(curl -s -o /dev/null -w '%{http_code}' "$B/$u")"; done)" "403 403 403 403 200 "
# 3.19.1 (N86): with a path after it (path info) the PHP file is refused too, as on Apache
expect "3.19.1 (N86): public/run.php/x is refused and never runs" "$(curl -s -o "$WORK/run-x" -w '%{http_code}' "$B/extensions/hello/public/run.php/x")|$(grep -c 'ran' "$WORK/run-x" || true)" "403|0"
rm -rf "$WORK/web/extensions/hello/public"
TOKEN=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=addons&action=toggle" -d "_csrf=$TOKEN" -d slug=hello -d on=1
expect "add-ons: switching on needs the trust tick" "$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'addons_enabled'" || true)" ""
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=addons&action=toggle" -d "_csrf=$TOKEN" -d slug=hello -d on=1 -d trust=1
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=addons&action=toggle" -d "_csrf=$TOKEN" -d slug=broken -d on=1 -d trust=1
mcp vytvor_stranku '{"titulek":"Addon page","adresa":"addon-page","text":"<p>{{ext.hello.greeting name=\"Jana\"}}</p>","zobrazit":true}' > /dev/null
curl -s -o "$WORK/response" "$B/addon-page"
contains -q 'Hello, Jana!' "$WORK/response" && contains -q '<!-- hello add-on -->' "$WORK/response" && echo "  ok     add-ons: a token in a page and a footer filter" || { echo "  CHYBA  add-on token/filter"; grep -o '{{ext[^}]*}}' "$WORK/response" | head -3; ERRORS=$((ERRORS+1)); }
# 3.3.2 (N38): a token in what a visitor sent (the search query) is never run – with or without attributes
curl -s -G -o "$WORK/response" "$B/hledani" --data-urlencode 'q={{ext.hello.greeting name="Mallory"}}'
curl -s -G -o "$WORK/response2" "$B/hledani" --data-urlencode 'q={{ext.hello.greeting}}'
! contains -q 'hello-greeting' "$WORK/response" && ! contains -q 'hello-greeting' "$WORK/response2" && contains -q 'ext.hello.greeting name=&quot;Mallory&quot;' "$WORK/response" && echo "  ok     add-ons: a token in the search query is not run" || { echo "  CHYBA  add-on token ve vyhledávání"; grep -o '<input type="search"[^>]*>' "$WORK/response" "$WORK/response2" | head -2; ERRORS=$((ERRORS+1)); }
expect "add-ons: a broken add-on is switched off at once and its error kept" "$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'addons_enabled'")|$(sq "SELECT hodnota LIKE '%deliberately broken%' FROM ka_nastaveni WHERE promenna = 'addons_error.broken'")" "hello|1"
check "add-ons: the error shows in Add-ons" 200 "/admin.php?module=addons" "deliberately broken"
# 3.19.1 (N87): the change log says which add-on went on, and which way
expect "3.19.1 (N87): switching an add-on on is in the change log with its name" "$(sq "SELECT COUNT(*) FROM ka_protokol WHERE modul = 'addons' AND akce = 'enabled' AND popis LIKE '%(hello%'")" "1"
# 3.19.1 (N85): install.sql with a second statement after a semicolon on the same line is refused before anything runs
mkdir -p "$WORK/web/extensions/twostmt" && printf '%s' '{"name":"Two statements","class":"Twostmt\\Ext","requires":{"api":1}}' > "$WORK/web/extensions/twostmt/extension.json"
printf '%s\n' '<?php namespace Twostmt; final class Ext implements \Kaleta\Extension\ExtensionInterface { public function register(\Kaleta\Extension\Api $api): void {} }' > "$WORK/web/extensions/twostmt/Extension.php"
printf '%s\n' 'CREATE TABLE IF NOT EXISTS ka_ext_twostmt_a (id INT); CREATE TABLE ka_n85_probe (id INT);' > "$WORK/web/extensions/twostmt/install.sql"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=addons&action=toggle" -d "_csrf=$TOKEN" -d slug=twostmt -d on=1 -d trust=1
expect "3.19.1 (N85): install.sql with two statements on one line is refused – neither table is made, the add-on stays off" \
  "$(sq "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('ka_ext_twostmt_a', 'ka_n85_probe')")|$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'addons_enabled'")" "0|hello"
rm -rf "$WORK/web/extensions/twostmt"
curl -s -X POST "$B/mcp" -H "Authorization: Bearer $API_TOKEN" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' > "$WORK/response"
mcp ext_hello_greet '{"name":"Petr"}' > "$WORK/response2"
contains -q '"name":"ext_hello_greet"' "$WORK/response" && contains -q 'Hello, Petr!' "$WORK/response2" && echo "  ok     add-ons: Claude lists and calls the add-on's tool" || { echo "  CHYBA  add-on MCP tool"; head -c 300 "$WORK/response2"; ERRORS=$((ERRORS+1)); }
# 3.3.2 (N39): add-on tools check the user's role like the built-in ones – a write tool needs an editor by default,
# a tool may require a section; a read tool stays open to every user
mkdir -p "$WORK/web/extensions/gate" && printf '%s' '{"name":"Gate","class":"Gate\\Ext","requires":{"api":1}}' > "$WORK/web/extensions/gate/extension.json"
cat > "$WORK/web/extensions/gate/Extension.php" <<'PHP'
<?php namespace Gate; final class Ext implements \Kaleta\Extension\ExtensionInterface { public function register(\Kaleta\Extension\Api $api): void {
    $api->mcpTool('write', 'A write tool without a role.', [], 'write', fn (array $a): array => ['written' => true]);
    $api->mcpTool('leads', 'A read tool for the Enquiries section.', [], 'read', fn (array $a): array => ['leads' => 1], 'enquiries');
    $api->mcpTool('look', 'A read tool without a role.', [], 'read', fn (array $a): array => ['looked' => true]);
} }
PHP
sq "UPDATE ka_nastaveni SET hodnota = 'hello,gate' WHERE promenna = 'addons_enabled';
  INSERT INTO ka_uzivatele (user, password, jmeno, admin, posledni_login, potvrzeno) VALUES ('n39-author', '', 'Author N39', 0, '$(site_time)', '$(site_time)');
  DELETE FROM ka_uzivatele_prava WHERE fk_id_user = (SELECT idu FROM ka_uzivatele WHERE user = 'n39-author') AND ident_modulu = 'enquiries'" > /dev/null
AUTHOR_TOKEN="kaleta_$(printf 'e%.0s' $(seq 1 48))"
sq "INSERT INTO ka_api_tokeny (idu, nazev, otisk, vytvoren) SELECT idu, 'author', '$(php -r 'echo hash("sha256", $argv[1]);' "$AUTHOR_TOKEN")', '$(site_time)' FROM ka_uzivatele WHERE user = 'n39-author'" > /dev/null
expect "3.3.2 add-ons: an author's connection cannot call a write tool or a tool of a section it lacks, may call a read tool; the admin may call all" \
  "$(mcp_as "$AUTHOR_TOKEN" ext_gate_write '{}' | grep -c 'needs an editor')|$(mcp_as "$AUTHOR_TOKEN" ext_gate_leads '{}' | grep -c 'section')|$(mcp_as "$AUTHOR_TOKEN" ext_gate_look '{}' | grep -c 'looked')|$(mcp ext_gate_write '{}' | grep -c 'written')|$(mcp ext_gate_leads '{}' | grep -c 'leads')" "1|1|1|1|1"
sq "UPDATE ka_nastaveni SET hodnota = 'hello' WHERE promenna = 'addons_enabled'; DELETE FROM ka_uzivatele WHERE user = 'n39-author'" > /dev/null
rm -rf "$WORK/web/extensions/gate"
# 3.3.2 (N12): without the News section an editor-level user reads over MCP only the news visitors see, as with pages
mcp create_news "{\"title\":\"N12 draft only for News\",\"category\":\"$CATEGORY\"}" > "$WORK/response"; N12_DRAFT=$(mcp_value id)
sq "INSERT INTO ka_uzivatele (user, password, jmeno, admin, posledni_login, potvrzeno) VALUES ('n12-editor', '', 'Editor N12', 1, '$(site_time)', '$(site_time)');
  INSERT INTO ka_uzivatele_prava (fk_id_user, ident_modulu) SELECT idu, 'pages' FROM ka_uzivatele WHERE user = 'n12-editor'" > /dev/null
EDITOR12_TOKEN="kaleta_$(printf 'd%.0s' $(seq 1 48))"
sq "INSERT INTO ka_api_tokeny (idu, nazev, otisk, vytvoren) SELECT idu, 'editor', '$(php -r 'echo hash("sha256", $argv[1]);' "$EDITOR12_TOKEN")', '$(site_time)' FROM ka_uzivatele WHERE user = 'n12-editor'" > /dev/null
expect "3.3.2 MCP: list_news and get_news without the News section show no drafts; with it they do" \
  "$(mcp_as "$EDITOR12_TOKEN" list_news '{"limit":50}' | grep -c 'N12 draft only')|$(mcp_as "$EDITOR12_TOKEN" get_news "{\"id\":$N12_DRAFT}" | grep -c '"isError":true')|$(mcp list_news '{"limit":50}' | grep -c 'N12 draft only')" "0|1|1"
mcp trash_news "{\"id\":$N12_DRAFT}" > /dev/null
# 3.15 (N71): the Notebook is a section – a user without it reads no notes over MCP, not even the pinned titles in site_info
expect "3.15 N71: without the Notebook section read_notebook is refused and site_info names no pinned note; the admin still reads them" \
  "$(mcp_as "$EDITOR12_TOKEN" read_notebook '{}' | grep -c 'no access to the Notebook')|$(mcp_as "$EDITOR12_TOKEN" site_info '{}' | grep -c 'Fotky z roku 2024')|$(mcp read_notebook '{}' | grep -c 'Fotky z roku 2024')|$(mcp site_info '{}' | grep -c 'Fotky z roku 2024')" "1|0|1|1"
# 3.3.2 (N11): an author-level role with the Categories section cannot rename or delete a category in the admin (as over MCP)
sq "UPDATE ka_uzivatele SET admin = 0, password = '$(php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' "$PASSWORD")' WHERE user = 'n12-editor';
  INSERT INTO ka_uzivatele_prava (fk_id_user, ident_modulu) SELECT idu, 'categories' FROM ka_uzivatele WHERE user = 'n12-editor'" > /dev/null
JAR_N11="$WORK/cookies-n11.txt"; curl -s -c "$JAR_N11" -o "$WORK/response" "$B/admin.php"
curl -s -b "$JAR_N11" -c "$JAR_N11" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$(csrf)" -d user=n12-editor --data-urlencode "password=$PASSWORD"
N11_ID=$(sq "SELECT idt FROM ka_kategorie WHERE seo_link = '$CATEGORY' OR nazev = '$CATEGORY' LIMIT 1"); N11_NAME=$(sq "SELECT nazev FROM ka_kategorie WHERE idt = $N11_ID")
curl -s -b "$JAR_N11" -o "$WORK/response" "$B/admin.php?module=categories"
curl -s -b "$JAR_N11" -c "$JAR_N11" -o /dev/null -X POST "$B/admin.php?module=categories&action=save" -d "_csrf=$(csrf)" -d "idt=$N11_ID" -d nazev=Renamed-by-author -d seo_link=renamed-by-author
curl -s -b "$JAR_N11" -c "$JAR_N11" -o /dev/null -X POST "$B/admin.php?module=categories&action=delete" -d "_csrf=$(csrf)" -d "idt=$N11_ID"
expect "3.3.2 admin: an author-level role with the Categories section neither renames nor deletes a category" "$(sq "SELECT nazev FROM ka_kategorie WHERE idt = $N11_ID")" "$N11_NAME"
sq "DELETE FROM ka_uzivatele WHERE user = 'n12-editor'" > /dev/null
check "add-ons: the add-on's admin page" 200 "/admin.php?module=addons&action=page&p=hello.settings" "Greeting word"
TOKEN=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=addons&action=page&p=hello.settings" -d "_csrf=$TOKEN" -d word=Ahoj
rm -f "$WORK"/web/storage/cache/stranky/*.html
expect "add-ons: the admin page saved the add-on's own setting, the page uses it" "$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'ext.hello.word'")|$(curl -s "$B/addon-page" | grep -c 'Ahoj, Jana!')" "Ahoj|1"
curl -s -o "$WORK/tasks.txt" "$B/ulohy?token=testtoken123"
grep -q "ext_hello_daily" "$WORK/tasks.txt" && echo "  ok     add-ons: the add-on's job runs with the others" || { echo "  CHYBA  add-on job"; cat "$WORK/tasks.txt"; ERRORS=$((ERRORS+1)); }
