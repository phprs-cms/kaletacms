#!/usr/bin/env bash
# Kaleta – browser test: a clean English install walked through in Chrome by tools/test-browser.mjs (admin, builder,
# editors, public site); fails on any script error. Needs PHP, MySQL, Node and Google Chrome (or CHROME=/path/to/chrome).
#   DB_HOST (127.0.0.1) DB_PORT (3306) DB_NAME (kaleta_test_browser) DB_USER (root) DB_PASS () PORT (8095)
# The database DB_NAME is DROPPED and recreated, and so are DB_NAME_remeslo and DB_NAME_poradenstvi (the Crafts and Consulting
# starters, served on PORT+1 and PORT+2 – 3.6).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DB_HOST="${DB_HOST:-127.0.0.1}"; DB_PORT="${DB_PORT:-3306}"; DB_NAME="${DB_NAME:-kaleta_test_browser}"; DB_USER="${DB_USER:-root}"; DB_PASS="${DB_PASS:-}"; PORT="${PORT:-8095}"
WORK="$(mktemp -d)"; B="http://127.0.0.1:$PORT"
MYSQL=(mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" --init-command="SET time_zone = '+00:00'"); [ -n "$DB_PASS" ] && MYSQL+=(-p"$DB_PASS")
cleanup() { for pid in ${SERVER_PID:-} ${STARTER_PIDS:-}; do kill "$pid" 2>/dev/null || true; done; rm -rf "$WORK"; }
trap cleanup EXIT

# axe-core (3.5) next to playwright-core: the accessibility check injects it from this local copy, never from a CDN
npm i --silent --prefix "$WORK/pw" playwright-core@1 axe-core@4 > /dev/null
mkdir "$WORK/web" && (cd "$ROOT" && git ls-files -z --cached --others --exclude-standard | while IFS= read -r -d '' f; do [ -e "$f" ] && printf '%s\0' "$f"; done | tar --null -T - -cf - | tar -xf - -C "$WORK/web")
mkdir -p "$WORK/web/media" "$WORK/web/storage/log" "$WORK/web/storage/cache"
# 3.6: the Crafts and Consulting starters too (ports PORT+1 and PORT+2, databases DB_NAME_remeslo and DB_NAME_poradenstvi),
# for the accessibility check of all three starters in dark mode and of a header with submenus and a mega menu
for starter in remeslo poradenstvi; do cp -R "$WORK/web" "$WORK/$starter"; done
"${MYSQL[@]}" -e "DROP DATABASE IF EXISTS \`$DB_NAME\`; CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_czech_ci"
(cd "$WORK/web" && exec php -S "127.0.0.1:$PORT" system/dev-router.php > "$WORK/server.log" 2>&1) & SERVER_PID=$!
for i in $(seq 1 30); do curl -s -o /dev/null "$B/install.php" && break; sleep 0.3; done
PASSWORD="Browser-$(openssl rand -hex 8)"
curl -s -o "$WORK/response" -X POST "$B/install.php" -d jazyk=en --data-urlencode "db_host=$DB_HOST" -d "db_port=$DB_PORT" -d "db_name=$DB_NAME" -d "db_user=$DB_USER" \
  --data-urlencode "db_password=$DB_PASS" -d db_prefix=ka_ --data-urlencode "nazev_webu=Browser Test Ltd" -d web=firemni -d user=admin -d "jmeno=Tester" -d email= \
  --data-urlencode "password=$PASSWORD" --data-urlencode "password2=$PASSWORD" \
  -d 'rozsireni[]=novinky' -d 'rozsireni[]=poptavky' -d 'rozsireni[]=newsletter' -d 'rozsireni[]=bookings' -d 'rozsireni[]=statistika' -d 'rozsireni[]=presmerovani' -d 'rozsireni[]=whistleblowing' -d 'rozsireni[]=claude'
[ ! -f "$WORK/web/install.php" ] || { echo "install failed"; sed 's/<[^>]*>//g' "$WORK/response" | grep -v '^\s*$' | head -20; exit 1; }
# a bookable service on a public page (/booking-test, a stored build has every content key): the browser picks a day (3.2.2)
"${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO ka_booking_services (id, name, duration_min) VALUES (900, 'Browser consultation', 60);
  INSERT INTO ka_booking_staff (id, name) VALUES (900, 'Browser Staff'); INSERT INTO ka_booking_staff_services VALUES (900, 900);
  INSERT INTO ka_booking_hours (staff_id, weekday, time_from, time_to) VALUES (900,1,'09:00','17:00'),(900,2,'09:00','17:00'),(900,3,'09:00','17:00'),(900,4,'09:00','17:00'),(900,5,'09:00','17:00'),(900,6,'09:00','17:00'),(900,7,'09:00','17:00');
  INSERT INTO ka_stranky (seo_link, titulek, text, v_menu, stavba) VALUES ('booking-test', 'Booking test', '', 0, '{\"v\":1,\"deti\":[{\"id\":\"s1\",\"typ\":\"sekce\",\"znacka\":\"section\",\"obsah\":{\"sirka\":\"obsah\",\"video\":\"\",\"pri_rolovani\":\"\",\"text_nahore\":\"\"},\"deti\":[{\"id\":\"bk1\",\"typ\":\"rezervace\",\"znacka\":\"form\",\"obsah\":{\"sluzba\":0,\"osoba\":0,\"tlacitko\":\"Book\",\"dekujeme\":\"Thank you.\",\"souhlas\":\"I agree.\"}}]}]}')"
# 3.5: the cookie bar shows (lead attribution needs consent to marketing; no outside script), with a link to the policy,
# and the accessibility toolbar is on – the accessibility steps check them together
"${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO ka_nastaveni (promenna, hodnota) VALUES ('cookies_mode', 'vestavena'), ('lead_attribution', '1'), ('cookies_policy_url', '/contact'), ('accessibility_toolbar', '1')
  ON DUPLICATE KEY UPDATE hodnota = VALUES(hodnota)"; rm -f "$WORK"/web/storage/cache/stranky/*.html
# 3.6: dark mode by the visitor's device on all three starters (the steps in a light browser see the light site); Crafts and
# Consulting get a main menu with submenus – a linked parent and a group with a column – and Consulting a header site part
# whose Navigation element is a mega menu (the built-in header and the builder element, both checked)
MENU='[{"typ":"stranka","ids":1,"text":""},{"typ":"stranka","ids":2,"text":"","deti":[{"typ":"stranka","ids":3,"text":"Our services","popis":"What we do"},{"typ":"odkaz","url":"/news","text":"Latest news","popis":"What is new"}]},{"typ":"skupina","text":"Resources","deti":[{"typ":"skupina","text":"Learn","deti":[{"typ":"odkaz","url":"/search?q=help","text":"Help centre"},{"typ":"odkaz","url":"/news","text":"Blog"}]},{"typ":"stranka","ids":4,"text":"Contact us","popis":"Write or call"}]},{"typ":"stranka","ids":4,"text":""}]'
"${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO ka_nastaveni (promenna, hodnota) VALUES ('dark_mode', 'auto') ON DUPLICATE KEY UPDATE hodnota = VALUES(hodnota)"
STARTERS="firemni=$B"; STARTER_PORT=$PORT
for starter in remeslo poradenstvi; do
  STARTER_PORT=$((STARTER_PORT + 1)); SDB="${DB_NAME}_$starter"; SB="http://127.0.0.1:$STARTER_PORT"
  "${MYSQL[@]}" -e "DROP DATABASE IF EXISTS \`$SDB\`; CREATE DATABASE \`$SDB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_czech_ci"
  (cd "$WORK/$starter" && exec php -S "127.0.0.1:$STARTER_PORT" system/dev-router.php > "$WORK/$starter.log" 2>&1) & STARTER_PIDS="${STARTER_PIDS:-} $!"
  for i in $(seq 1 30); do curl -s -o /dev/null "$SB/install.php" && break; sleep 0.3; done
  curl -s -o "$WORK/response" -X POST "$SB/install.php" -d jazyk=en --data-urlencode "db_host=$DB_HOST" -d "db_port=$DB_PORT" -d "db_name=$SDB" -d "db_user=$DB_USER" \
    --data-urlencode "db_password=$DB_PASS" -d db_prefix=ka_ --data-urlencode "nazev_webu=Browser $starter" -d "web=$starter" -d user=admin -d "jmeno=Tester" -d email= \
    --data-urlencode "password=$PASSWORD" --data-urlencode "password2=$PASSWORD" -d 'rozsireni[]=novinky' -d 'rozsireni[]=poptavky'
  [ ! -f "$WORK/$starter/install.php" ] || { echo "install of $starter failed"; sed 's/<[^>]*>//g' "$WORK/response" | grep -v '^\s*$' | head -20; exit 1; }
  "${MYSQL[@]}" "$SDB" -e "INSERT INTO ka_nastaveni (promenna, hodnota) VALUES ('dark_mode', 'auto') ON DUPLICATE KEY UPDATE hodnota = VALUES(hodnota);
    INSERT INTO ka_menu (umisteni, jazyk, polozky) VALUES ('hlavni', '', '$MENU')"
  STARTERS="$STARTERS,$starter=$SB"
done
HEADER=$(cd "$WORK/poradenstvi" && php -r 'require "system/bootstrap.php"; $b = Kaleta\Builder\PartTemplates::build("hlavicka", "klasicka", "en") ?? [];
  $mega = function (array &$n) use (&$mega): void { if (($n["typ"] ?? "") === "navigace") { $n["obsah"]["mega"] = true; } foreach (array_keys($n["deti"] ?? []) as $i) { $mega($n["deti"][$i]); } };
  foreach (array_keys($b["deti"] ?? []) as $i) { $mega($b["deti"][$i]); } echo addslashes((string) json_encode($b, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));')
"${MYSQL[@]}" "${DB_NAME}_poradenstvi" -e "INSERT INTO ka_casti (typ, jazyk, varianta, nazev, stavba) VALUES ('hlavicka', '', '', 'Header', '$HEADER')"
# right-to-left (data model §5): the Crafts starter gets an Arabic version with a page of Arabic sections, Latin product names inside
# Arabic text and a carousel – the browser checks it at 1440 and 390 px (no sideways scroll, the phone menu, mirrored arrows); on a
# starter, so the steps on the main site see a single-language site as before
"${MYSQL[@]}" "${DB_NAME}_remeslo" -e "UPDATE ka_nastaveni SET hodnota = CONCAT(hodnota, ',jazyky') WHERE promenna = 'extensions' AND NOT FIND_IN_SET('jazyky', hodnota);
  INSERT INTO ka_nastaveni (promenna, hodnota) VALUES ('additional_languages', 'ar'), ('cookies_mode', 'vestavena'), ('lead_attribution', '1') ON DUPLICATE KEY UPDATE hodnota = VALUES(hodnota)"
(cd "$WORK/remeslo" && php -r 'require "system/bootstrap.php"; $db = Kaleta\Core\App::boot()->db();
  foreach (["ar" => "rtl-test", "en" => "ltr-test"] as $language => $slug) { // the same page in English: the carousel checks run in both directions
    $build = Kaleta\Core\Language::runWith($language, function () use ($db, $language): array {
      [$sections, $classes] = [[], []];
      foreach (["uvod", "vyhody", "faq", "cenik", "poptavka"] as $key) { $s = Kaleta\Builder\Library::section($key, $language); $sections[] = $s["prvek"]; $classes = array_merge($classes, $s["tridy"]); }
      Kaleta\Builder\Library::createClasses($db, $classes);
      array_splice($sections, 1, 0, [Kaleta\Builder\Build::fresh("sekce", [], [Kaleta\Builder\Build::fresh("nadpis", ["text" => $language === "ar" ? "شريط Kinesio Tape (5 cm)" : "Kinesio Tape (5 cm)"]),
        Kaleta\Builder\Build::fresh("text", ["html" => $language === "ar" ? "<p>السعر 120 EUR – Kinesio Tape (5 cm × 5 m) للرياضيين.</p>" : "<p>Kinesio Tape (5 cm × 5 m) for athletes – 120 EUR.</p>"]),
        Kaleta\Builder\Build::fresh("karusel", ["naraz" => "1", "popis" => "Kinesio"], Kaleta\Builder\Elements\Carousel::defaultChildren())])]);
      return Kaleta\Builder\Build::sanitize(["v" => 1, "deti" => $sections])[0];
    });
    $db->insert("stranky", ["seo_link" => $slug, "titulek" => $slug, "text" => "", "v_menu" => 0, "jazyk" => $language === "ar" ? "ar" : "", "zobrazit" => 1, "stavba" => Kaleta\Builder\Build::toJson($build)]);
  }')
rm -f "$WORK"/remeslo/storage/cache/stranky/*.html
# 3.16: a monthly report in the archive (Core\ReportArchive) with something in every section the browser checks – a request,
# an update, firewall blocks, a published news item – for the print layout step
LAST="$(php -r 'echo (new DateTimeImmutable("first day of last month"))->format("Y-m");')"
"${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO ka_requests (created_at, updated_at, author_id, title, text, status, done_at) VALUES ('$LAST-03 10:00:00', '$LAST-04 10:00:00', 1, 'Update the opening hours for the holidays and add a note about the parking behind the building', 'x', 'done', '$LAST-04 10:00:00'), ('$LAST-20 10:00:00', '$LAST-20 10:00:00', 1, 'New price list', 'x', 'new', NULL);
  INSERT INTO ka_events (created_at, type, severity, message, data) VALUES ('$LAST-10 04:00:00', 'update.applied', 'info', 'Version 3.16.0 was installed (from 3.15.4).', '{\"version\":\"3.16.0\",\"from\":\"3.15.4\"}');
  INSERT INTO ka_firewall_log (created_at, ip, reason, path) VALUES ('$LAST-11 01:00:00', '203.0.113.9', 'probe', '/wp-login.php'), ('$LAST-12 01:00:00', '203.0.113.9', 'rate', '/');
  INSERT INTO ka_protokol (cas, kdo, jmeno, via, modul, akce, popis) VALUES ('$LAST-05 09:00:00', 1, 'Tester', '', 'pages', 'save', 'Home'), ('$LAST-06 09:00:00', 1, 'Tester', 'Claude', 'claude', 'save_collection_item', 'x');
  DELETE FROM ka_monthly_reports"
(cd "$WORK/web" && php -r 'require "system/bootstrap.php"; Kaleta\Core\ReportArchive::store(Kaleta\Core\App::boot(), Kaleta\Core\MonthlyReport::previousMonth(new DateTimeImmutable()));') \
  || { echo "storing the monthly report failed"; exit 1; }
# 3.17: the main site is also a fleet console – three sites with what waits on them (Fleet\Inbox, the inbox at 1440 and 390 px),
# one of them with 95 days of history (a bad day with an outage, a day just under 100 %, a version installed) and the previous
# month's fleet report per customer – for the inbox, trends and fleet report print steps
(cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = Kaleta\Core\App::boot(); $db = $app->db(); $s = $app->settings();
  Kaleta\Core\Extensions::save($s, [...Kaleta\Core\Extensions::enabled($s), "fleet"]);
  $customer = $db->insert("fleet_customers", ["name" => "Dental Clinic Smile and Partners International Ltd"]);
  $iso = fn (int $h): string => date(DATE_ATOM, time() - $h * 3600);
  $sites = [["Smile Dental – the main clinic website with a long name", "https://smile-dental-clinic-with-a-long-domain.example", $customer, date("Y-m-d H:i:s", time() - 600), 1, ["last_backup" => time(),
      "requests" => ["new" => 1, "in_progress" => 1, "done_to_review" => 0, "oldest_waiting_hours" => 80, "items" => [
        ["id" => 12, "title" => str_repeat("Update the price list of the implants and the whitening ", 2), "status" => "new", "created_at" => $iso(80), "updated_at" => $iso(80)],
        ["id" => 13, "title" => "New team photo", "status" => "in_progress", "created_at" => $iso(3), "updated_at" => $iso(1)]]],
      "pending_review" => ["staged_media" => 4, "page_drafts" => 2], "enquiries_new_not_spam" => 3, "bookings_pending" => 2,
      "domain_watch" => ["local" => false, "certificate" => ["expires" => date("Y-m-d", time() + 5 * 86400)], "domain" => null], "audit" => ["links" => 3]]],
    ["Smile Dental – booking", "https://booking.smile.example", $customer, date("Y-m-d H:i:s", time() - 30 * 3600), 1, ["last_backup" => time(), "enquiries_unanswered" => 5]],
    ["Bakery", "https://bakery.example", null, date("Y-m-d H:i:s", time() - 600), 0, ["last_backup" => time(), "update_problem" => "Version 3.17.0 did not install", "security_7_days" => ["failed_sign_ins" => 120, "events" => []]]]];
  foreach ($sites as $i => [$name, $url, $cid, $seen, $up, $beat]) {
    $db->insert("fleet_sites", ["name" => $name, "url" => $url, "public_key" => "browserkey" . $i, "paired_at" => date("Y-m-d H:i:s"), "last_seen" => $seen, "version" => "3.16.0",
      "up" => $up, "up_changed" => date("Y-m-d H:i:s", time() - 1200), "status" => "ok", "customer_id" => $cid, "heartbeat" => json_encode($beat)]);
  }
  $at = fn (string $m): string => date("Y-m-d H:i:s", strtotime($m));
  $db->insert("fleet_sites", ["id" => 900, "name" => "Smile Dental – shop", "url" => "https://shop.smile.example", "public_key" => "browser-test-317", "paired_at" => $at("-120 days"),
    "last_seen" => $at("now"), "version" => "3.17.0", "version_since" => $at("-20 days"), "status" => "ok", "up" => 1, "up_checked" => $at("now"), "customer_id" => $customer,
    "heartbeat" => json_encode(["version" => "3.17.0", "last_backup" => time() - 3600, "cron_last_run" => time() - 300, "enquiries_new_not_spam" => 1])]);
  for ($i = 0; $i < 95; $i++) {
    $failed = $i === 12 ? 9 : ($i === 40 ? 1 : 0);
    $db->insert("fleet_history", ["site_id" => 900, "day" => date("Y-m-d", strtotime("-$i days")), "checks_ok" => 288 - $failed, "checks_failed" => $failed,
      "response_ms_total" => (288 - $failed) * (180 + $i % 30), "response_count" => 288 - $failed, "status" => "ok", "version" => $i > 20 ? "3.16.2" : "3.17.0",
      "attention" => $i === 12 ? 100 : 0, "reasons" => $i === 12 ? "down" : "", "updated_at" => $at("-$i days")]);
  }
  $db->insert("fleet_incidents", ["site_id" => 900, "started_at" => $at("-12 days 10:00"), "ended_at" => $at("-12 days 10:45"), "status" => 503]);
  Kaleta\Core\Events::record($db, "fleet.site_updated", "info", "https://shop.smile.example runs version 3.17.0.", ["site" => 900, "version" => "3.17.0"]);
  Kaleta\Fleet\Report::store($app, Kaleta\Core\MonthlyReport::previousMonth(new DateTimeImmutable()));') || { echo "preparing the fleet console failed"; exit 1; }
# 3.18: the browser test changes the state of the Claude connection in the database (the mysql command as a JSON list)
MYSQL_JSON=$(php -r 'echo json_encode(array_slice($argv, 1));' -- "${MYSQL[@]}")
NODE_PATH="$WORK/pw/node_modules" BASE="$B" STARTERS="$STARTERS" PASSWORD="$PASSWORD" MYSQL_JSON="$MYSQL_JSON" DB_NAME="$DB_NAME" CHROME="${CHROME:-/Applications/Google Chrome.app/Contents/MacOS/Google Chrome}" node "$ROOT/tools/test-browser.mjs"
for site in web remeslo poradenstvi; do
  if [ -s "$WORK/$site/storage/log/chyby.log" ]; then echo "== application error log ($site):"; cat "$WORK/$site/storage/log/chyby.log"; exit 1; fi
done
