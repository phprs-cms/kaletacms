echo "== 3.54 D3: the site search says while rows wait for their search text – only rows a visitor could find, a news re-index, the notice on /hledani, indexing over MCP; System status"
d070_get() { curl -s -G -A "Mozilla/5.0 test" "$B/hledani" --data-urlencode "q=$1"; }
d070_has() { local n; n=$(grep -cF -- "$1" || true); [ "${n:-0}" -gt 0 ] && echo 1 || echo 0; }
# search_site over MCP never fills the index (only /hledani does): "<results>|<indexing or ->"
d070_mcp() { mcp_call "$D070_TOKEN" search_site "{\"query\":\"$1\"}" | php -r '$r = json_decode(stream_get_contents(STDIN), true); $d = json_decode($r["result"]["content"][0]["text"] ?? "{}", true);
  echo count($d["results"] ?? []), "|", array_key_exists("indexing", $d) ? var_export($d["indexing"], true) : "-";'; }
D070_NOW=$(site_time)
D070_ADMIN=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'admin'")
D070_TEMA=$(sq "SELECT tema FROM ka_novinky ORDER BY idc LIMIT 1")
D070_TOKEN="kaleta_$(openssl rand -hex 24)"
sq "INSERT INTO ka_api_tokeny (idu, nazev, access, tool_set, otisk, vytvoren) VALUES ($D070_ADMIN, 'd070 admin', 'full', 'all', SHA2('$D070_TOKEN', 256), '$D070_NOW');
  DELETE FROM ka_kontrola_ip WHERE typ = 'hledani'" > /dev/null
background_jobs hold
fill_search_index > /dev/null
D070_STATE=$(sq "SELECT COALESCE((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'search_index_at'), '')")

# nothing waits: no flag over MCP, no notice on the page, System status says every row can be found
sq "INSERT INTO ka_stranky (seo_link, titulek, text, zobrazit, v_menu) VALUES ('ready-d070', 'Hotová D070', '<p>Obsah pripravenoq070</p>', 1, 0)" > /dev/null
fill_search_index > /dev/null
expect "3.54 D3: with nothing waiting search_site has no indexing key and /hledani no notice" \
  "$(d070_mcp pripravenoq070)|$(d070_get pripravenoq070 | d070_has 'se ještě indexují')" "1|-|0"

# a hidden page, a page in the trash and a page of another language version waiting are no reason – the visitor could not
# find them anyway (SearchIndex::pending() counts them, the flag does not)
sq "INSERT INTO ka_stranky (seo_link, titulek, text, zobrazit, v_menu) VALUES ('hidden-d070', 'Skrytá D070', '<p>skrytaq070</p>', 0, 0);
  INSERT INTO ka_stranky (seo_link, titulek, text, zobrazit, v_menu, smazano) VALUES ('trash-d070', 'Koš D070', '<p>kosq070</p>', 1, 0, '$D070_NOW');
  INSERT INTO ka_stranky (seo_link, titulek, text, zobrazit, v_menu, jazyk) VALUES ('other-d070', 'Other D070', '<p>otherq070</p>', 1, 0, 'xx')" > /dev/null
expect "3.54 D3: hidden, trashed and other-language rows waiting raise no flag (pending counts them: 3 waiting)" \
  "$(d070_mcp pripravenoq070)|$(search_index_pending)" "1|-|3"

# a visible page waiting (right after an update, an import): search_site misses its body word and says indexing; the
# search page shows the notice while rows wait after its own slice – here a slice of nothing left: it fills and finds it
sq "UPDATE ka_stranky SET zobrazit = 1, hledani = NULL WHERE seo_link = 'hidden-d070'" > /dev/null
expect "3.54 D3: a visible page waiting – search_site does not find it by its body and answers indexing: true" "$(d070_mcp skrytaq070)" "0|true"
D070_PAGE=$(d070_get skrytaq070)
expect "3.54 D3: /hledani fills the waiting rows first (heal) and finds the page by its body; nothing visible waits, so no notice" \
  "$(printf '%s' "$D070_PAGE" | d070_has 'href="/hidden-d070"')|$(printf '%s' "$D070_PAGE" | d070_has 'se ještě indexují')|$(sq "SELECT hledani IS NOT NULL FROM ka_stranky WHERE seo_link = 'hidden-d070'")" "1|0|1"

# the notice itself: rows still wait after the page's slice of 0.3 s (another request holds the heal lock, as a parallel
# search would) – the page prints the one sentence, the found count still answers
sq "UPDATE ka_stranky SET hledani = NULL WHERE seo_link = 'hidden-d070'" > /dev/null
# the lock is the site's own name (Db::lockName – a GET_LOCK name is shared by every database on the server, 3.54 review)
D070_LOCK=$(cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); echo Kaleta\Core\SearchIndex::lockName($app->db());')
D070_HELD=$(DB_PASS="$DB_PASS" php -r '$pdo = new PDO("mysql:host=" . $argv[1] . ";port=" . $argv[2] . ";dbname=" . $argv[3], $argv[4], (string) getenv("DB_PASS"));
  $got = (int) $pdo->query("SELECT GET_LOCK(" . $pdo->quote($argv[6]) . ", 0)")->fetchColumn();
  $html = (string) file_get_contents($argv[5] . "/hledani?q=pripravenoq070", false, stream_context_create(["http" => ["header" => "User-Agent: Mozilla/5.0 test"]]));
  echo $got, "|", (int) str_contains($html, "se ještě indexují"), "|", (int) str_contains($html, "href=\"/ready-d070\"");' -- "$DB_HOST" "$DB_PORT" "$DB_NAME" "$DB_USER" "$B" "$D070_LOCK")
expect "3.54 D3: while a visible page waits and the heal lock (the site's own name, at most 64 characters, not the shared kaleta_search_heal_ka_) is taken, /hledani shows \"Některé stránky se ještě indexují…\" and still lists what it found" \
  "$(printf '%s' "$D070_LOCK" | grep -c '^kaleta-search-heal-')|$([ ${#D070_LOCK} -le 64 ] && echo 1)|$D070_HELD" "1|1|1|1|1"
sq "DELETE FROM ka_kontrola_ip WHERE typ = 'hledani'" > /dev/null

# System status: the waiting rows "cannot be found on the site search yet"
expect "3.54 D3: System status words the waiting rows as not findable on the site search yet (a warning only when the job is stuck)" \
  "$(cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = Kaleta\Core\App::boot(); foreach (Kaleta\Core\Language::runWith("en", fn (): array => Kaleta\Core\Health::checks($app, true), "admin-") as $c) {
    if (str_contains((string) $c["info"], "cannot be found on the site search yet")) { echo $c["stav"]; } }')" "ok"
fill_search_index > /dev/null

# a news re-index of a newer Search::VERSION running: the flag is up even with no NULL row; MCP misses a word the version 1
# index lacks, /hledani rewrites a slice first (heal runs reindexNews) and finds it
D070_NEWS=$(sq "INSERT INTO ka_novinky (seo_link, titulek, uvod, text, tema, datum, visible, noindex, hledani) VALUES ('stara-d070', 'Старая новость D070', '', '', $D070_TEMA, '$D070_NOW', 1, 0, 'd070'); SELECT LAST_INSERT_ID();")
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('search_index_at', '1:$((D070_NEWS - 1))')" > /dev/null
expect "3.54 D3: a news re-index running – search_site says indexing and misses the Cyrillic title word; /hledani rewrites it and finds it; the re-index then ends" \
  "$(d070_mcp 'новость')|$(d070_get 'новость' | d070_has 'href="/novinky/stara-d070"')|$(sq "SELECT hledani LIKE '%новость%' FROM ka_novinky WHERE idc = $D070_NEWS")|$(d070_mcp pripravenoq070)" \
  "0|true|1|1|1|-"
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('search_index_at', '$D070_STATE'); DELETE FROM ka_novinky WHERE idc = $D070_NEWS" > /dev/null

# one re-indexer at a time, reading the state as stored: a search whose request read "1:0" before the job finished the
# re-index never writes its old cursor back over the finished one (3.54 review)
fill_search_index > /dev/null
D070_RACE=$(cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); $db = $app->db(); $s = $app->settings();
  $s->get("site_url"); $s->set(Kaleta\Core\SearchIndex::STATE, "1:0"); $done = Kaleta\Core\Search::VERSION . ":0";
  $db->run("UPDATE {nastaveni} SET hodnota = ? WHERE promenna = ?", [$done, Kaleta\Core\SearchIndex::STATE]); // the job finished meanwhile
  Kaleta\Core\SearchIndex::heal($db, $s, 0.000001);
  echo $db->value("SELECT hodnota FROM {nastaveni} WHERE promenna = ?", [Kaleta\Core\SearchIndex::STATE]) === $done ? "kept" : "overwritten";')
expect "3.54 D3: a finished re-index stays finished when a search that read the old state heals after it" "$D070_RACE" "kept"
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('search_index_at', '$D070_STATE')" > /dev/null

background_jobs resume
sq "DELETE FROM ka_stranky WHERE seo_link IN ('ready-d070', 'hidden-d070', 'trash-d070', 'other-d070'); DELETE FROM ka_api_tokeny WHERE nazev = 'd070 admin';
  DELETE FROM ka_kontrola_ip WHERE typ = 'hledani'" > /dev/null
