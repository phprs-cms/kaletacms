echo "== 3.50 B2: settings declared once – every settings form saved unchanged leaves the settings as they were; update_settings answers as before"
b2_replay() { # b2_replay <query of the form page>: posts the settings form of the page back as a browser would, unchanged
  curl -s -b "$JAR" -c "$JAR" -o "$WORK/b2-form.html" "$B/admin.php?$1"
  php -r '
    $d = new DOMDocument(); libxml_use_internal_errors(true); $d->loadHTML((string) file_get_contents($argv[1]));
    foreach ($d->getElementsByTagName("form") as $form) {
        if (strtolower($form->getAttribute("method")) !== "post" || !str_contains($form->getAttribute("action"), "action=save")) { continue; }
        $pairs = [];
        foreach ((new DOMXPath($d))->query(".//input|.//select|.//textarea", $form) as $el) {
            $name = $el->getAttribute("name");
            if ($name === "" || $el->hasAttribute("disabled")) { continue; }
            $type = strtolower($el->getAttribute("type") ?: "text");
            if ($el->nodeName === "input" && in_array($type, ["submit", "button", "image", "reset", "file"], true)) { continue; }
            if ($el->nodeName === "input" && in_array($type, ["checkbox", "radio"], true)) {
                if ($el->hasAttribute("checked")) { $pairs[] = [$name, $el->hasAttribute("value") ? $el->getAttribute("value") : "on"]; }
                continue;
            }
            if ($el->nodeName === "select") {
                $chosen = [];
                foreach ($el->getElementsByTagName("option") as $o) { if ($o->hasAttribute("selected")) { $chosen[] = $o; } }
                if ($chosen === [] && !$el->hasAttribute("multiple") && ($first = $el->getElementsByTagName("option")->item(0)) !== null) { $chosen = [$first]; }
                foreach ($chosen as $o) { $pairs[] = [$name, $o->hasAttribute("value") ? $o->getAttribute("value") : $o->textContent]; }
                continue;
            }
            $pairs[] = [$name, $el->nodeName === "textarea" ? $el->textContent : $el->getAttribute("value")];
        }
        file_put_contents($argv[2], implode("&", array_map(fn (array $p): string => urlencode($p[0]) . "=" . urlencode($p[1]), $pairs)));
        echo $form->getAttribute("action");
        exit(0);
    }
    exit(1);' -- "$WORK/b2-form.html" "$WORK/b2-body" > "$WORK/b2-action" || { echo "no form"; return; }
  local action; action=$(cat "$WORK/b2-action"); case "$action" in http*) ;; /*) action="$B$action" ;; *) action="$B/$action" ;; esac
  proof_fresh
  curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -w '%{http_code}' --data-binary @"$WORK/b2-body" -H 'Content-Type: application/x-www-form-urlencoded' "$action"
}
# b2_settings <file>: what the site reads for every declared setting (a missing row reads its default) and every other row that
# is not empty (a language variant saved empty reads the default language's). Two forms normalise a value they never showed, as
# in 3.49: the passkey-only switch is stored as 0 instead of an empty default, the mail service as its first choice "other",
# an empty cookie policy link as the address of the privacy policy page the form offers (3.20)
b2_settings() {
  (cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); $s = $app->settings(); $out = []; $privacy = Kaleta\Admin\Modules\Settings::privacyPolicyPage($app->db(), $s);
    foreach (array_keys(Kaleta\Core\Settings::DEFAULTS) as $k) { $out[] = $k . "=" . md5(match ($k) { "signin_passkey_only" => $s->bool($k) ? "1" : "0", "smtp_provider" => $s->get($k) === "" ? "other" : $s->get($k), "cookies_policy_url" => $s->get($k) === "" && $privacy !== null ? "/" . $privacy["seo_link"] : (string) preg_replace("#^.*(/" . preg_quote((string) ($privacy["seo_link"] ?? "\0"), "#") . ")$#D", "$1", $s->get($k)), default => $s->get($k) }); }
    foreach ($app->db()->pairs("SELECT promenna, hodnota FROM {nastaveni} ORDER BY promenna") as $k => $v) { if (!array_key_exists((string) $k, Kaleta\Core\Settings::DEFAULTS) && (string) $v !== "") { $out[] = $k . " row=" . md5((string) $v); } }
    echo implode("\n", $out), "\n";') > "$1"
}

b2_settings "$WORK/b2-before"
B2_CODES=""
for b2_page in 'module=settings&tab=general' 'module=settings&tab=languages' 'module=settings&tab=security' 'module=settings&tab=agency' 'module=settings&tab=seo' \
  'module=settings&tab=analytics' 'module=settings&tab=cookies' 'module=settings&tab=mail' 'module=settings&tab=reports' 'module=settings&tab=webhooks' \
  'module=settings&tab=backups' 'module=settings&tab=firewall' 'module=business' 'module=claude_settings' 'module=status'; do
  B2_CODES="$B2_CODES $(b2_replay "$b2_page")"
done
b2_settings "$WORK/b2-after"
expect "3.50 B2: the fifteen settings forms (the tabs, Business details, Claude settings, System status) post back with a redirect" "$B2_CODES" "$(printf ' 302%.0s' $(seq 1 15))"
expect "3.50 B2: saving every settings form unchanged leaves every setting reading what it read before (a row written with its default included)" \
  "$(diff "$WORK/b2-before" "$WORK/b2-after" | grep '^[<>]' | sed 's/=.*//' | tr '\n' ' ')" ""

# update_settings: an allowed key is stored, a key only for the administration is refused, an undeclared company_ key is an invalid value
B2_ADMIN=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'admin'")
B2_TOKEN="kaleta_$(openssl rand -hex 24)"
sq "INSERT INTO ka_api_tokeny (idu, nazev, access, tool_set, otisk, vytvoren) VALUES ($B2_ADMIN, 'b2 settings', 'full', 'all', SHA2('$B2_TOKEN', 256), '$(site_time)')" > /dev/null
B2_FOOTER=$(sq "SELECT IFNULL(MAX(HEX(hodnota)), '') FROM ka_nastaveni WHERE promenna = 'footer_text'"); B2_SMTP=$(sq "SELECT IFNULL(MAX(hodnota), '') FROM ka_nastaveni WHERE promenna = 'smtp_host'")
mcp_call "$B2_TOKEN" update_settings '{"settings":{"footer_text":"B2 footer","smtp_host":"b2.example","company_x":"y","site_name_de":"B2 Seite"}}' > "$WORK/response"
expect "3.50 B2: update_settings stores footer_text and site_name_de, refuses smtp_host (administration only) and calls company_x an invalid value" \
  "$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'footer_text'")|$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'site_name_de'")|$(sq "SELECT IFNULL(MAX(hodnota), '') FROM ka_nastaveni WHERE promenna = 'smtp_host'")|$(sq "SELECT COUNT(*) FROM ka_nastaveni WHERE promenna = 'company_x'")|$(grep -c 'cannot be changed through the Claude connection' "$WORK/response")|$(grep -c 'Invalid value.' "$WORK/response")" \
  "B2 footer|B2 Seite|$B2_SMTP|0|1|1"
sq "UPDATE ka_nastaveni SET hodnota = UNHEX('$B2_FOOTER') WHERE promenna = 'footer_text'; DELETE FROM ka_nastaveni WHERE promenna = 'site_name_de'; DELETE FROM ka_api_tokeny WHERE nazev = 'b2 settings'" > /dev/null
