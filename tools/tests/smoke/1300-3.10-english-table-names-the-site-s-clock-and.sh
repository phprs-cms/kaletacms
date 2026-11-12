echo "== 3.10: English table names, the site's clock and regional language versions (data model §4, §6 and §2 step 2)"
# §4: {news} is the news table – the same rows through either placeholder, on the installed site's own connection
expect "3.10: {news} and {novinky}, {settings} and {nastaveni} are the same tables" \
  "$(cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $db = Kaleta\Core\App::boot()->db();
    echo $db->value("SELECT COUNT(*) FROM {news}") === $db->value("SELECT COUNT(*) FROM {novinky}") ? "same" : "differ", "|", $db->value("SELECT hodnota FROM {settings} WHERE promenna = ?", ["site_language"]);')" "same|cs"
# §6: System status shows the clock; a zone this server does not know (written straight into the database) is a warning
check "3.10: System status shows the site's time zone and that PHP and the database agree" 200 "/admin.php?module=status" 'Europe/Prague (UTC+0[12]:00) – PHP i databáze počítají čas stejně'
sq "UPDATE ka_nastaveni SET hodnota = 'Mars/Olympus_Mons' WHERE promenna = 'time_zone'" > /dev/null
check "3.10: a time zone the server does not know is a warning on System status" 200 "/admin.php?module=status" 'Časové pásmo v nastavení je Mars/Olympus_Mons, ale PHP běží v Europe/Prague'
sq "UPDATE ka_nastaveni SET hodnota = 'Europe/Prague' WHERE promenna = 'time_zone'" > /dev/null
# changing the zone in Settings → General asks first (3.12, data model §6): "Keep their clock time" changes nothing saved,
# "Convert saved dates" moves the moments by the offset and leaves civil times (a day off at 09:00) as they are
NEWS310=$(sq "SELECT CONCAT(idc, '|', datum) FROM ka_novinky ORDER BY idc LIMIT 1")
sq "INSERT INTO ka_booking_off (staff_id, off_from, off_to, note) VALUES (NULL, '2030-07-01 09:00:00', '2030-07-01 17:00:00', 'tz312')" > /dev/null
curl -s -b "$JAR" -c "$JAR" -o "$WORK/general310.html" "$B/admin.php?module=settings&tab=general"
php -r '$d = new DOMDocument(); @$d->loadHTML(file_get_contents($argv[1])); $x = new DOMXPath($d); $f = $x->query("//form[.//input[@name=\"tab\"]]")->item(0); $q = [];
  foreach ($x->query(".//input|.//select|.//textarea", $f) as $e) { $n = $e->getAttribute("name"); $t = $e->getAttribute("type"); if ($n === "" || $t === "submit" || (in_array($t, ["checkbox", "radio"], true) && !$e->hasAttribute("checked"))) continue;
    $v = $e->nodeName === "select" ? (($o = $x->query(".//option[@selected]", $e)->item(0) ?? $x->query(".//option", $e)->item(0)) ? $o->getAttribute("value") : "") : ($e->nodeName === "textarea" ? $e->textContent : $e->getAttribute("value"));
    $q[] = rawurlencode($n) . "=" . rawurlencode($n === "time_zone" ? "Europe/London" : $v); }
  echo implode("&", $q);' -- "$WORK/general310.html" > "$WORK/general310.post"
TZ_NEXT=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{redirect_url}' -X POST "$B/admin.php?module=settings&action=save" --data-binary @"$WORK/general310.post")
expect "3.12: saving a new time zone leads to the confirmation, and the zone is not changed yet" "${TZ_NEXT#*\?}|$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'time_zone'")" "module=settings&action=time_zone&zone=Europe%2FLondon|Europe/Prague"
check "3.12: the confirmation offers to convert the saved dates or to keep their clock time" 200 "/admin.php?module=settings&action=time_zone&zone=Europe/London" 'Ponechat čas na hodinách'
contains -q 'Převést uložená data' "$WORK/response" && contains -q 'novinka naplánovaná na 12:00 v pásmu Europe/Prague bude naplánovaná na 11:00 v pásmu Europe/London' "$WORK/response" \
  && echo "  ok     3.12: … and says what converting does with a news item at 12:00" || { echo "  CHYBA  3.12: the confirmation text"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=time_zone" --data-urlencode "_csrf=$(csrf)" -d zone=Europe/London -d choice=keep
expect "3.12: “Keep their clock time” changes the zone and no saved date" "$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'time_zone'")|$(sq "SELECT CONCAT(idc, '|', datum) FROM ka_novinky WHERE idc = ${NEWS310%%|*}")|$(sq "SELECT COUNT(*) FROM ka_time_zone_conversions")" "Europe/London|$NEWS310|0"
sq "UPDATE ka_nastaveni SET hodnota = 'Europe/Prague' WHERE promenna = 'time_zone'" > /dev/null
check "3.12: the confirmation again (Prague → London)" 200 "/admin.php?module=settings&action=time_zone&zone=Europe/London" 'Převést uložená data'
# 3.12.1 (N312-2): a "never" date and the first possible one – whichever leaves the DATETIME range is left and counted
# (the first possible date sits in a hidden news item: the retention job may delete a log row that old before the check)
sq "INSERT INTO ka_protokol (cas, modul, akce, popis) VALUES ('9999-12-31 23:30:00', 'tz312', 'never', '')" > /dev/null
sq "INSERT INTO ka_novinky (seo_link, titulek, uvod, text, tema, datum) SELECT 'tz312-first', 'tz312 first', '', '', tema, '1000-01-01 00:30:00' FROM ka_novinky ORDER BY idc LIMIT 1" > /dev/null
# 3.12.1 (N312-1): three confirmations at once (a double click, a second tab, a retry) convert once
TZ_CSRF=$(csrf)
TZ_PIDS=(); for i in 1 2 3; do curl -s -b "$JAR" -o /dev/null -w '%{http_code}\n' -X POST "$B/admin.php?module=settings&action=time_zone" --data-urlencode "_csrf=$TZ_CSRF" -d zone=Europe/London -d choice=convert > "$WORK/tz312-$i" & TZ_PIDS+=($!); done; wait "${TZ_PIDS[@]}" # only these: the servers are background jobs too
NEWS312_LONDON=$(php -r 'echo (new DateTimeImmutable($argv[1], new DateTimeZone("Europe/Prague")))->setTimezone(new DateTimeZone("Europe/London"))->format("Y-m-d H:i:s");' -- "${NEWS310#*|}")
expect "3.12.1: three parallel “Convert saved dates” answer without an error and move the news date by the offset once; the day off stays at 09:00; one conversion, in the change log" \
  "$(cat "$WORK"/tz312-* | sort -u | tr '\n' ' ')|$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'time_zone'")|$(sq "SELECT datum FROM ka_novinky WHERE idc = ${NEWS310%%|*}")|$(sq "SELECT off_from FROM ka_booking_off WHERE note = 'tz312'")|$(sq "SELECT CONCAT(COUNT(*), ':', GROUP_CONCAT(CONCAT(from_zone, '>', to_zone, ':', finished_at IS NOT NULL))) FROM ka_time_zone_conversions c")|$(sq "SELECT COUNT(*) FROM ka_protokol WHERE modul = 'settings' AND akce IN ('time_zone', 'time_zone_converted') AND popis LIKE 'Europe/Prague → Europe/London: %'")" \
  "302 |Europe/London|$NEWS312_LONDON|2030-07-01 09:00:00|1:Europe/Prague>Europe/London:1|3"
expect "3.12.1: the date that would fall before the year 1000 stays and is counted; the “never” date moves inside the range" \
  "$(sq "SELECT datum FROM ka_novinky WHERE seo_link = 'tz312-first'"),$(sq "SELECT cas FROM ka_protokol WHERE modul = 'tz312' AND akce = 'never'")|$(sq "SELECT SUM(out_of_range) FROM ka_time_zone_progress")" "1000-01-01 00:30:00,9999-12-31 22:30:00|1"
sq "DELETE FROM ka_novinky WHERE seo_link = 'tz312-first'" > /dev/null
check "3.12: General shows the finished conversion and the value left as it was" 200 "/admin.php?module=settings&tab=general" 'Uložená data jsou převedená z Europe/Prague na Europe/London'
contains -q '1 hodnot zůstalo, jak byly' "$WORK/response" && echo "  ok     3.12.1: … with the value left as it was" || { echo "  CHYBA  3.12.1: General does not show the value left as it was"; ERRORS=$((ERRORS+1)); }
check "3.12.1: System status warns about the value left as it was" 200 "/admin.php?module=status" 'Převod časového pásma'
# idempotent: a finished conversion never runs again
expect "3.12: the background job finds nothing to convert again" "$(cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $db = Kaleta\Core\App::boot()->db(); var_export(Kaleta\Core\TimeZoneConversion::run($db, 5.0));')|$(sq "SELECT datum FROM ka_novinky WHERE idc = ${NEWS310%%|*}")" "NULL|$NEWS312_LONDON"
# N312-1 at the database: two processes start the way back (London → Prague) at the same moment – one starts, one is refused.
# N312-7: in batches (nothing at once but the busy tables), a row written after the start is on the new clock and stays;
# the conversion stopped before its first batch resumes later; a "never" date moved east is left and counted
sq "INSERT INTO ka_protokol (cas, modul, akce, popis) VALUES ('9999-12-31 23:30:00', 'tz312', 'never2', '')" > /dev/null
TZ_BEGIN='require "system/bootstrap.php"; $app = Kaleta\Core\App::boot(); echo var_export(Kaleta\Core\TimeZoneConversion::begin($app->db(), $app->settings(), "Europe/London", "Europe/Prague", true, null, 0) !== null, true), "\n";'
(cd "$WORK/web" && php -r "$TZ_BEGIN" > "$WORK/tzb1") & TZ_PIDS=($!); (cd "$WORK/web" && php -r "$TZ_BEGIN" > "$WORK/tzb2") & TZ_PIDS+=($!); wait "${TZ_PIDS[@]}"
sq "INSERT INTO ka_protokol (cas, modul, akce, popis) VALUES ('2031-01-01 10:00:00', 'tz312', 'after', '')" > /dev/null
expect "3.12.1: two parallel starts – exactly one conversion, the zone switched once" "$(cat "$WORK/tzb1" "$WORK/tzb2" | sort | tr '\n' ' ')|$(sq "SELECT COUNT(*) FROM ka_time_zone_conversions WHERE from_zone = 'Europe/London'")|$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'time_zone'")" "false true |1|Europe/Prague"
expect "3.12.1: the way back stopped before its first batch resumes, restores the dates once, keeps the row written after the start and the day off" "$(cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $db = Kaleta\Core\App::boot()->db();
    $first = Kaleta\Core\TimeZoneConversion::run($db, 0.0); $second = Kaleta\Core\TimeZoneConversion::run($db, 20.0);
    echo $first["done"] ? "done" : "stopped", ":", $second["done"] && $second["finished_now"] ? "done" : "open", ":", $second["out_of_range"], ":", implode(",", $second["failed"]);')|$(sq "SELECT CONCAT(idc, '|', datum) FROM ka_novinky WHERE idc = ${NEWS310%%|*}")|$(sq "SELECT off_from FROM ka_booking_off WHERE note = 'tz312'")|$(sq "SELECT GROUP_CONCAT(CONCAT(akce, '=', cas) ORDER BY akce) FROM ka_protokol WHERE modul = 'tz312' AND akce IN ('after', 'never', 'never2')")" \
  "stopped:done:1:|$NEWS310|2030-07-01 09:00:00|after=2031-01-01 10:00:00,never=9999-12-31 23:30:00,never2=9999-12-31 23:30:00"
# N312-2: a table that cannot be converted is skipped with its error – the conversion finishes, the other jobs are not held
sq "INSERT INTO ka_time_zone_conversions (from_zone, to_zone, started_at) VALUES ('Europe/Prague', 'Europe/Prague', NOW()); INSERT INTO ka_time_zone_progress (conversion_id, table_name) VALUES (LAST_INSERT_ID(), 'missing_312')" > /dev/null
sq "UPDATE ka_jobs SET last_run = '2000-01-01 00:00:00' WHERE name IN ('time_zone', 'notifications')" > /dev/null
curl -s -o /dev/null "$B/tasks?token=testtoken123"
expect "3.12.1: a failing table is skipped and reported, the conversion ends, the next jobs run" \
  "$(sq "SELECT COUNT(*) FROM ka_time_zone_conversions WHERE finished_at IS NULL")|$(sq "SELECT error <> '' FROM ka_time_zone_progress WHERE table_name = 'missing_312'")|$(sq "SELECT CONCAT(last_error = '', ':', last_run > '2000-01-01') FROM ka_jobs WHERE name = 'time_zone'")|$(sq "SELECT last_run > '2000-01-01' FROM ka_jobs WHERE name = 'notifications'")" \
  "0|1|1:1|1"
check "3.12.1: System status names the table not converted" 200 "/admin.php?module=status" 'missing_312'
sq "UPDATE ka_nastaveni SET hodnota = 'Europe/Prague' WHERE promenna = 'time_zone'; DELETE FROM ka_booking_off WHERE note = 'tz312'; DELETE FROM ka_protokol WHERE modul = 'tz312'; DELETE FROM ka_time_zone_conversions" > /dev/null
# §1.2: names sort in the alphabet of the site language – "chata" after "hrad" on a Czech site, before it in English and
# German; the server is asked which collations it has (a Czech site sends the same SQL as before)
sq "INSERT INTO ka_stitky (nazev, seo_link) VALUES ('Chata 312', 'chata-312'), ('Hrad 312', 'hrad-312'), ('Cesta 312', 'cesta-312'), ('Čaj 312', 'caj-312')" > /dev/null
expect "3.12: tags sort as Czech on a Czech site and by Unicode or German rules on an English or German one" "$(cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $db = Kaleta\Core\App::boot()->db();
    foreach (["cs", "en", "de"] as $l) { echo $l, ":", implode(",", array_map(fn ($n) => strtok($n, " "), array_column($db->all("SELECT nazev FROM {stitky} WHERE seo_link LIKE ? ORDER BY " . $db->sortText("nazev", $l), ["%-312"]), "nazev"))), " "; }')" \
  "cs:Cesta,Čaj,Hrad,Chata en:Čaj,Cesta,Chata,Hrad de:Čaj,Cesta,Chata,Hrad "
check "3.12: the admin list of tags sorts through the site language too (Czech here)" 200 "/admin.php?module=tags" 'Chata 312'
sq "DELETE FROM ka_stitky WHERE seo_link LIKE '%-312'" > /dev/null
# §2 step 2: a Brazilian Portuguese version next to Czech and English – /pt-br/ in addresses, pt-BR in lang and hreflang, the
# two-letter versions exactly as before
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('additional_languages', 'en,pt-br'), ('home_page', '0')" > /dev/null
mcp create_page '{"title":"Kontakt 310","slug":"kontakt-310","content":"<p>Česky 310</p>","visible":true}' > "$WORK/response"; CS310=$(mcp_value id)
mcp create_page "{\"title\":\"Contato 310\",\"slug\":\"contato-310\",\"language\":\"pt-br\",\"translation_of\":$CS310,\"content\":\"<p>Português 310</p>\",\"visible\":true}" > "$WORK/response"
mcp update_settings '{"settings":{"site_name_pt-br":"Meu site 310"}}' > /dev/null
expect "3.10: a page and a site name in the pt-br version are stored under the lowercase tag" \
  "$(sq "SELECT CONCAT(jazyk, ':', seo_link) FROM ka_stranky WHERE preklad_z = $CS310")|$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'site_name_pt-br'")" "pt-br:contato-310|Meu site 310"
P310=$(get39 /pt-br/contato-310)
contains -q '<html lang="pt-BR"' "$WORK/response" && contains -q 'Português 310' "$WORK/response" && contains -q 'Meu site 310' "$WORK/response" \
  && contains -q 'hreflang="cs" href="[^"]*/kontakt-310"' "$WORK/response" && contains -q 'hreflang="pt-BR" href="[^"]*/pt-br/contato-310"' "$WORK/response" \
  && contains -q '<meta property="og:locale" content="pt_BR">' "$WORK/response" && P310="$P310 ok"
# 3.12 (UX310-12): pt-br visitors get the Portuguese interface texts (pt.php), not English
contains -q 'Pular para o conteúdo' "$WORK/response" && ! contains -q '>Skip to content<' "$WORK/response" && echo "  ok     3.12: /pt-br/ has Portuguese visitor texts" || { echo "  CHYBA  3.12: /pt-br/ visitor texts are not Portuguese"; ERRORS=$((ERRORS+1)); }
C310=$(get39 /kontakt-310)
contains -q '<html lang="cs"' "$WORK/response" && contains -q 'hreflang="pt-BR" href="[^"]*/pt-br/contato-310"' "$WORK/response" && ! contains -q 'Meu site 310' "$WORK/response" && C310="$C310 ok"
expect "3.10: /pt-br/contato-310 is the pt-BR version (lang, hreflang, og:locale, its site name); /kontakt-310 points to it" "$P310|$C310" "200  ok|200  ok"
get39 /sitemap.xml > /dev/null
expect "3.10: the sitemap lists the pt-br address; the prefix is lowercase only" "$(contains -q '/pt-br/contato-310</loc>' "$WORK/response" && echo listed)|$(get39 /pt-BR/contato-310)" "listed|404 "
mcp create_page '{"title":"Pt","slug":"pt-br"}' > "$WORK/response"
expect "3.10: a regional code stays reserved as a page slug" "$(contains -q 'isError' "$WORK/response" && echo refused)|$(sq "SELECT COUNT(*) FROM ka_stranky WHERE seo_link = 'pt-br'")" "refused|0"
sq "DELETE FROM ka_stranky WHERE ids = $CS310 OR preklad_z = $CS310; DELETE FROM ka_nastaveni WHERE promenna = 'site_name_pt-br'; REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('additional_languages', 'en'), ('home_page', '$HOME39')" > /dev/null

