echo "== 3.42 T7 (Q9): large settings are read on first use, not with every page"
q9_php() { (cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); $app->applyTimezone(); '"$1" -- "${@:2}"); }

Q9_DRAFT_OLD=$(sq "SELECT COUNT(*) FROM ka_nastaveni WHERE promenna = 'look_draft'")
sq "INSERT INTO ka_nastaveni (promenna, hodnota) VALUES ('look_draft', '{\"q9\":1}') ON DUPLICATE KEY UPDATE hodnota = VALUES(hodnota); DELETE FROM ka_nastaveni WHERE promenna = 'domain_watch'" > /dev/null
expect "3.42 Q9: the first read leaves the lazy settings out; look_draft is read when asked for, a lazy key with no row gives its default and is looked up once; a write is seen at once" \
  "$(q9_php '$s = $app->settings(); $s->get("site_name"); $v = new ReflectionProperty($s, "values"); $before = array_key_exists("look_draft", $v->getValue($s)); $draft = $s->get("look_draft"); $watch = $s->get("domain_watch"); $read = array_keys((new ReflectionProperty($s, "lazyRead"))->getValue($s)); $s->set("domain_watch", "x"); echo json_encode([$before, $draft, $watch, $read, $s->get("domain_watch")]);')" \
  '[false,"{\"q9\":1}","",["look_draft","domain_watch"],"x"]'
expect "3.42 Q9: Settings::DEFAULTS still answers every key the site has stored (no stored key is unknown but the per-language ones and those of add-ons)" \
  "$(q9_php '$known = array_keys(Kaleta\Core\Settings::DEFAULTS); $stored = $app->db()->all("SELECT promenna FROM {nastaveni}"); echo implode(",", array_filter(array_column($stored, "promenna"), fn ($k) => !in_array($k, $known, true) && !preg_match("/_[a-z]{2}(-[a-z]+)?$|^(addons_error|ext)\\./", $k)));')" ""
sq "DELETE FROM ka_nastaveni WHERE promenna = 'domain_watch'" > /dev/null
[ "$Q9_DRAFT_OLD" = "0" ] && sq "DELETE FROM ka_nastaveni WHERE promenna = 'look_draft'" > /dev/null
true
