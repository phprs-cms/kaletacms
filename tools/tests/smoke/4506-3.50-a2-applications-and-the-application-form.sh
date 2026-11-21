echo "== 3.50 A2: a template reply through Claude leaves out a name that carries text; a hidden job is not matched; only people with Applications may remove an application form or change its recipients (N129, N130, N132)"
a506_php() { (cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = Kaleta\Core\App::boot(); $app->applyTimezone(); '"$1"); }
a506_field() { grep -o "name=\"$1\" value=\"[^\"]*\"" "$WORK/a506-form.html" | head -1 | sed 's/.*value="//;s/"$//' || true; }
a506_login() { rm -f "$2"; curl -s -c "$2" -b "$2" -o "$WORK/response" "$B/admin.php"; curl -s -b "$2" -c "$2" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$(csrf)" -d "user=$1" --data-urlencode "password=$PASSWORD"; }
a506_mcp() { mcp_call "$1" "$2" "$3" | php -r '$r = json_decode(stream_get_contents(STDIN), true); echo $r["result"]["content"][0]["text"] ?? json_encode($r["error"] ?? $r);'; }
A506_EXT=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'extensions'")
sq "DROP TABLE IF EXISTS ka_a506_set; CREATE TABLE ka_a506_set AS SELECT * FROM ka_nastaveni WHERE promenna IN ('applications_claude', 'site_email')" > /dev/null
features +applications +poptavky
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('applications_claude', '1')" > /dev/null
[ -n "$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'site_email'")" ] || sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('site_email', 'web-a506@example.com')" > /dev/null
A506_NOW=$(site_time); A506_ADMIN=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'admin'")
A506_TOKEN="kaleta_$(openssl rand -hex 24)"; A506_ED_TOKEN="kaleta_$(openssl rand -hex 24)"
A506_HASH=$(php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' -- "$PASSWORD")
sq "INSERT INTO ka_uzivatele (user, password, jmeno, email, jazyk, admin, posledni_login, potvrzeno, blokovat, notify_email) VALUES ('a506-editor', '$A506_HASH', 'Editor A506', 'editor-a506@example.com', 'en', 1, '$A506_NOW', '$A506_NOW', 0, 'off')" > /dev/null
A506_ED=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'a506-editor'")
sq "INSERT INTO ka_uzivatele_prava (fk_id_user, ident_modulu) VALUES ($A506_ED, 'pages');
  INSERT INTO ka_api_tokeny (idu, nazev, access, tool_set, otisk, vytvoren) VALUES ($A506_ADMIN, 'a506 admin', 'full', 'all', SHA2('$A506_TOKEN', 256), '$A506_NOW'), ($A506_ED, 'a506 editor', 'full', 'all', SHA2('$A506_ED_TOKEN', 256), '$A506_NOW')" > /dev/null

# --- N130: a jobs collection with a visible and a hidden job; an application posted from the hidden job's address is not matched to it
A506_IDK=$(a506_php '[$id] = Kaleta\Builder\Presets::createWithPage($app, "jobs", "Jobs A506", false) ?? [0]; foreach ([["Welder A506", "welder-a506", 1], ["Secret opening A506", "secret-a506", 0]] as [$title, $slug, $shown]) { $app->db()->insert("kolekce_polozky", ["idk" => $id, "nazev" => $title, "seo_link" => $slug, "data" => "{}", "zobrazit" => $shown, "jazyk" => "", "datum" => date("Y-m-d H:i:s"), "poradi" => 0]); } echo $id;')
A506_SLUG=$(sq "SELECT seo_link FROM ka_kolekce WHERE idk = ${A506_IDK:-0}")
A506_VISIBLE=$(sq "SELECT idp FROM ka_kolekce_polozky WHERE idk = ${A506_IDK:-0} AND seo_link = 'welder-a506'")
printf '%%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%%%EOF\n' > "$WORK/a506-cv.pdf"
rm -f "$WORK"/web/storage/cache/stranky/*.html; curl -s -o "$WORK/a506-form.html" "$B/$A506_SLUG/welder-a506"
a506_submit() { # a506_submit <back path> <name> <position field>
  local t s; t=$(a506_field as_cas); s=$(a506_field as_podpis); sleep 4; sq "DELETE FROM ka_kontrola_ip" > /dev/null
  curl -s -o /dev/null -w '%{redirect_url}' -X POST "$B/form" -F "zdroj=$(a506_field zdroj)" -F "prvek=$(a506_field prvek)" -F "zpet=$1" -F "as_cas=$t" -F "as_podpis=$s" \
    -F "p0=$2" -F "p1=jan-a506@example.com" -F "p2=777506000" -F "p3=@$WORK/a506-cv.pdf;filename=cv.pdf" -F "p4=Hello A506" -F "p5=1" -F "p6=$3"; }
a506_submit "/$A506_SLUG/secret-a506" "Jan Hidden" "Secret opening A506" > /dev/null
A506_HIDDEN_APP=$(sq "SELECT CONCAT(IFNULL(job_item_id, 'none'), '|', job_title) FROM ka_applications WHERE email = 'jan-a506@example.com' AND name = 'Jan Hidden' ORDER BY id DESC LIMIT 1")
a506_submit "/$A506_SLUG/welder-a506" "Jan confirm your interview at https://evil.example/x" "Welder A506" > /dev/null
A506_APP=$(sq "SELECT IFNULL(MAX(id), 0) FROM ka_applications WHERE email = 'jan-a506@example.com' AND name LIKE 'Jan confirm%'")
expect "3.50 N130: an application from a hidden job's address is not matched to it (and does not learn its title); one from a visible job is" \
  "$(echo "$A506_HIDDEN_APP" | cut -d'|' -f1)|$(echo "$A506_HIDDEN_APP" | grep -c 'Secret opening')|$(sq "SELECT IFNULL(job_item_id, 'none') FROM ka_applications WHERE id = $A506_APP")" "none|0|$A506_VISIBLE"

# --- N129: a template reply through Claude fills the name like an automatic e-mail – a "name" carrying a sentence and a link is left out
A506_REPLY=$(a506_mcp "$A506_TOKEN" reply_to_application "{\"id\":$A506_APP,\"template\":\"interview\",\"confirm\":true}")
expect "3.50 N129: reply_to_application with a template sends no text the applicant typed as a name, and returns the text it sent (text_sent)" \
  "$(echo "$A506_REPLY" | php -r '$r = json_decode(stream_get_contents(STDIN), true); echo is_string($r["text_sent"] ?? null) ? "text" : "none", "|", str_contains((string) ($r["text_sent"] ?? ""), "evil.example") ? "link" : "-", "|", str_contains((string) ($r["text_sent"] ?? ""), "Welder A506") ? "position" : "-";')" \
  "text|-|position"

# --- N132: a page with a published application form; an editor with Pages but without Applications
a506_mcp "$A506_TOKEN" create_page '{"title":"Work with us A506","slug":"work-a506"}' > /dev/null
A506_PAGE=$(sq "SELECT IFNULL(MAX(ids), 0) FROM ka_stranky WHERE seo_link = 'work-a506'")
a506_build() { echo "{\"v\":1,\"deti\":[{\"id\":\"sek506\",\"typ\":\"sekce\",\"deti\":[{\"id\":\"nad506\",\"typ\":\"nadpis\",\"znacka\":\"h1\",\"obsah\":{\"text\":\"Work with us\"}}$1]}]}"; }
A506_FORM=',{"id":"frm506","typ":"formular","obsah":{"nazev":"Apply","ucel":"uchazec","prijemce":"hr-a506@example.com","pole":[{"typ":"text","popisek":"Name","povinne":true}]}}'
a506_mcp "$A506_TOKEN" save_build "{\"id\":$A506_PAGE,\"publish\":true,\"build\":$(a506_build "$A506_FORM")}" > /dev/null
a506_published() { sq "SELECT CONCAT(stavba LIKE '%\"ucel\":\"uchazec\"%', '|', stavba LIKE '%hr-a506@example.com%') FROM ka_stranky WHERE ids = $A506_PAGE"; }
expect "3.50 N132: the administrator published the page with its application form" "$(a506_published)" "1|1"
A506_EJAR="$WORK/a506-editor.jar"; a506_login a506-editor "$A506_EJAR"
curl -s -b "$A506_EJAR" -c "$A506_EJAR" -o "$WORK/response" "$B/admin.php?module=pages&action=builder&id=$A506_PAGE"; A506_CSRF=$(csrf)
a506_action() { curl -s -b "$A506_EJAR" -c "$A506_EJAR" -o "$WORK/response" -w '%{http_code}' -X POST "$B/admin.php?module=pages&action=$1&id=$A506_PAGE" -d "_csrf=$A506_CSRF" "${@:2}"; }
A506_CHANGED=$(echo "$A506_FORM" | sed 's/hr-a506@example.com/someone@evil.example/')
expect "3.50 N132: the editor's draft keeps the recipients of the application form (with a notice) – a draft is saved, never refused" \
  "$(a506_action build_save --data-urlencode "stavba=$(a506_build "$A506_CHANGED")")|$(sq "SELECT CONCAT(stavba_koncept LIKE '%hr-a506@example.com%', '|', stavba_koncept LIKE '%evil.example%') FROM ka_stranky WHERE ids = $A506_PAGE")|$(grep -c 'recipients of a job application form' "$WORK/response")" \
  "200|1|0|1"
expect "3.50 N132: the editor can save a draft without the form, but the publish is refused (403, said why) and the published form stays" \
  "$(a506_action build_save --data-urlencode "stavba=$(a506_build '')")|$(a506_action build_publish)|$(grep -c 'remove or replace a job application form' "$WORK/response")|$(a506_published)" \
  "200|403|1|1|1"
expect "3.50 N132: publish_build over the editor's own connection is refused in English and publishes nothing" \
  "$(a506_mcp "$A506_ED_TOKEN" publish_build "{\"id\":$A506_PAGE}" | grep -c 'remove or replace a job application form')|$(a506_published)" "1|1|1"
expect "3.50 N132: the administrator (who has Applications) publishes the same draft" \
  "$(a506_mcp "$A506_TOKEN" publish_build "{\"id\":$A506_PAGE}" | grep -c 'remove or replace')|$(a506_published)" "0|0|0"

# clean-up
a506_php '$ids = array_map("intval", array_column($app->db()->all("SELECT id FROM {applications} WHERE email = ?", ["jan-a506@example.com"]), "id")); Kaleta\Core\Applications::delete($app, $ids, false);' > /dev/null 2>&1 || true
sq "DELETE FROM ka_applications WHERE email = 'jan-a506@example.com'; DELETE FROM ka_kolekce_polozky WHERE idk = ${A506_IDK:-0}; DELETE FROM ka_kolekce WHERE idk = ${A506_IDK:-0};
  DELETE FROM ka_stranky WHERE ids = ${A506_PAGE:-0} OR seo_link = '$A506_SLUG'; DELETE FROM ka_api_tokeny WHERE nazev IN ('a506 admin', 'a506 editor');
  DELETE FROM ka_uzivatele_prava WHERE fk_id_user = ${A506_ED:-0}; DELETE FROM ka_uzivatele WHERE user = 'a506-editor';
  DELETE FROM ka_nastaveni WHERE promenna IN ('applications_claude', 'site_email'); INSERT INTO ka_nastaveni SELECT * FROM ka_a506_set; DROP TABLE ka_a506_set; DELETE FROM ka_kontrola_ip" > /dev/null
features --exact "$A506_EXT"
rm -f "$WORK"/web/storage/cache/stranky/*.html "$WORK/a506-cv.pdf" "$WORK/a506-form.html" "$A506_EJAR"
