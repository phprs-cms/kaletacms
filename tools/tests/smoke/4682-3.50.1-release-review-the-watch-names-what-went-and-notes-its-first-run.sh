echo "== 3.50.1 release review: the application form watch notes its first run before the scan, names a page that only lost its form and a deleted pop-up, and counts a collection's category pages as a place"
r682_watch() { (cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); $app->applyTimezone(); echo Kaleta\Builder\ApplicationFormWatch::run($app);'); }
r682_says() { if [[ "$1" == *"$2"* ]]; then echo "$3"; else echo "$1"; fi; }
R682_EXT=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'extensions'")
R682_STATE=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'application_forms_watch'")
R682_JOB=$(sq "SELECT CONCAT_WS('|', IFNULL(last_run, ''), IFNULL(last_ok, ''), last_error, failures, runs, duration_ms) FROM ka_jobs WHERE name = 'application_forms'")
features +applications
background_jobs hold
R682_ADMIN=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'admin'")
R682_FORM='{"v":1,"deti":[{"id":"sek682","typ":"sekce","deti":[{"id":"frm682","typ":"formular","obsah":{"nazev":"Apply","ucel":"uchazec","pole":[{"typ":"text","popisek":"Name"}]}}]}]}'
R682_PLAIN='{"v":1,"deti":[{"id":"sek682","typ":"sekce","deti":[{"id":"txt682","typ":"text","obsah":{"text":"We are not hiring now."}}]}]}'
R682_PAGE=$(sq "INSERT INTO ka_stranky (seo_link, titulek, text, zobrazit, stavba) VALUES ('r682-careers', 'R682 careers', '', 1, '$R682_FORM'); SELECT LAST_INSERT_ID();")
R682_POP=$(sq "INSERT INTO ka_popupy (nazev, adresa, spoustec, hodnota, pravidla, stavba, aktivni) VALUES ('r682-pop', 'r682-pop', 'cas', 5, '{}', '$R682_FORM', 1); SELECT LAST_INSERT_ID();")
R682_COLL=$(sq "INSERT INTO ka_kolekce (nazev, seo_link, pole, detail) VALUES ('R682 positions', 'r682-positions', '[]', 0); SELECT LAST_INSERT_ID();")
sq "INSERT INTO ka_collection_categories (idk, visible) VALUES ($R682_COLL, 1)" > /dev/null
R682_HIDDEN=$(sq "INSERT INTO ka_stranky (seo_link, titulek, text, zobrazit) VALUES ('r682-hidden', 'R682 hidden', '', 0); SELECT LAST_INSERT_ID();")
R682_CAT=$(sq "INSERT INTO ka_popupy (nazev, adresa, spoustec, hodnota, pravidla, stavba, aktivni) VALUES ('r682-cat', 'r682-cat', 'cas', 5, '{\"kde\":\"vybrane\",\"stranky\":[$R682_HIDDEN],\"kolekce\":[\"r682-positions\"]}', '$R682_FORM', 1); SELECT LAST_INSERT_ID();")
r682_excerpt() { sq "SELECT excerpt FROM ka_notifications WHERE kind = 'form_unreachable' AND target = '$1' AND user_id = $R682_ADMIN"; }

# the first run after an update: the job has no row yet – the run notes itself all the same
sq "DELETE FROM ka_jobs WHERE name = 'application_forms'; DELETE FROM ka_nastaveni WHERE promenna = 'application_forms_watch'" > /dev/null
R682_W1=$(r682_watch)
expect "3.50.1 release review: the watch's first run writes its row in the background jobs before the scan (no row before); a pop-up limited to a hidden page and a collection without item pages but with a category counts as reachable" \
  "$(r682_says "$R682_W1" 'started, ' started)|$(sq "SELECT COUNT(*) FROM ka_jobs WHERE name = 'application_forms' AND last_run IS NOT NULL")|$(sq "SELECT JSON_CONTAINS(hodnota, '$R682_CAT', '\$.popups') FROM ka_nastaveni WHERE promenna = 'application_forms_watch'")" \
  "started|1|1"

# the form taken off a living page and a pop-up deleted: the bell names the page by its title and says a pop-up was deleted
sq "UPDATE ka_stranky SET stavba = '$R682_PLAIN' WHERE ids = $R682_PAGE; DELETE FROM ka_popupy WHERE idpp = $R682_POP" > /dev/null
R682_W2=$(r682_watch)
expect "3.50.1 release review: a page that only lost its form is named by its title, not \"deleted page\"; a deleted pop-up is called a deleted pop-up (in the language of the site)" \
  "$(r682_says "$R682_W2" 'unreachable 2' alerted)|$(r682_excerpt "form:page:$R682_PAGE")|$(r682_excerpt "form:popup:$R682_POP" | sed 's/^smazané pop-up okno$/deleted pop-up/')" \
  "alerted|R682 careers|deleted pop-up"

# clean-up
background_jobs resume
sq "DELETE FROM ka_notifications WHERE kind = 'form_unreachable' AND target IN ('form:page:${R682_PAGE:-0}', 'form:popup:${R682_POP:-0}', 'form:popup:${R682_CAT:-0}');
  DELETE FROM ka_popupy WHERE adresa IN ('r682-pop', 'r682-cat'); DELETE FROM ka_stranky WHERE seo_link LIKE 'r682-%'; DELETE FROM ka_kolekce WHERE seo_link = 'r682-positions'" > /dev/null
if [ -n "$R682_STATE" ]; then
  sq "INSERT INTO ka_nastaveni (promenna, hodnota) VALUES ('application_forms_watch', '$(printf '%s' "$R682_STATE" | sed "s/'/''/g")') ON DUPLICATE KEY UPDATE hodnota = VALUES(hodnota)" > /dev/null
else
  sq "DELETE FROM ka_nastaveni WHERE promenna = 'application_forms_watch'" > /dev/null
fi
if [ -n "$R682_JOB" ]; then
  IFS='|' read -r R682_J1 R682_J2 R682_J3 R682_J4 R682_J5 R682_J6 <<< "$R682_JOB"
  sq "REPLACE INTO ka_jobs (name, last_run, last_ok, last_error, failures, runs, duration_ms) VALUES ('application_forms', NULLIF('$R682_J1', ''), NULLIF('$R682_J2', ''), '$(printf '%s' "$R682_J3" | sed "s/'/''/g")', ${R682_J4:-0}, ${R682_J5:-0}, ${R682_J6:-0})" > /dev/null
else
  sq "DELETE FROM ka_jobs WHERE name = 'application_forms'" > /dev/null
fi
features --exact "$R682_EXT"
