echo "== 3.47 C: a body over post_max_size comes back to its form with the limits; the browser gets them first; the audit names a form the server cannot honour"
c347_php() { (cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = Kaleta\Core\App::boot(); $app->applyTimezone(); '"$1"); }
# a published page with a form of two file fields (a limit of 25 MB – above what the suite's PHP accepts per file)
C347_IDS=$(c347_php '[$b] = Kaleta\Builder\Build::sanitize(["v" => 1, "deti" => [["id" => "c347sec", "typ" => "sekce", "deti" => [["id" => "c347form", "typ" => "formular", "obsah" => ["nazev" => "Files C347", "max_priloha" => 25,
  "pole" => [["typ" => "text", "popisek" => "Name"], ["typ" => "soubor", "popisek" => "File one"], ["typ" => "soubor", "popisek" => "File two"]]]]]]]]);
  echo $app->db()->insert("stranky", ["titulek" => "Files C347", "seo_link" => "files-c347", "text" => "", "zobrazit" => 1, "v_menu" => 0, "zmeneno" => date("Y-m-d H:i:s"), "stavba" => json_encode($b, JSON_UNESCAPED_UNICODE)]);')
rm -f "$WORK"/web/storage/cache/stranky/*.html

# a second server on the same site with a small post_max_size (and no warning in the page, as on a production host)
C347_PORT=$((PORT + 26)); C347_B="http://127.0.0.1:$C347_PORT"
(cd "$WORK/web" && exec php -d post_max_size=1M -d upload_max_filesize=1M -d display_errors=0 -S "127.0.0.1:$C347_PORT" system/dev-router.php > "$WORK/server-c347.log" 2>&1) & C347_PID=$!
for i in $(seq 1 100); do curl -s -o /dev/null "$C347_B/" && break; sleep 0.2; done
curl -s -o "$WORK/c347-page.html" "$C347_B/files-c347" || true
C347_ACTION=$(grep -o 'action="[^"]*form=c347form[^"]*"' "$WORK/c347-page.html" | head -1 | sed 's/^action="//;s/"$//;s/&amp;/\&/g')
expect "3.47 C1/C2: a form with a file field posts with form, source and back in its address; it carries the limits for the browser" \
  "$(echo "$C347_ACTION" | grep -c "source=stranka%3A${C347_IDS:-0}&back=%2Ffiles-c347")|$(grep -c 'data-max-post="1048576"' "$WORK/c347-page.html")|$(grep -o 'data-max-bytes="1048576"' "$WORK/c347-page.html" | wc -l | tr -d ' ')" "1|1|2"

head -c 2200000 /dev/zero > "$WORK/c347-big.bin"
C347_ENQ=$(sq "SELECT IFNULL(MAX(idp), 0) FROM ka_poptavky")
C347_BIG=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -X POST "$C347_B$C347_ACTION" -F "zdroj=stranka:${C347_IDS:-0}" -F "prvek=c347form" -F "p0=Jana C347" -F "p1=@$WORK/c347-big.bin;filename=big.pdf")
expect "3.47 C1: a body over post_max_size goes back to the form with result=size (303), nothing is stored" \
  "$(echo "$C347_BIG" | cut -d' ' -f1)|$(echo "$C347_BIG" | grep -c "/files-c347?form=c347form&result=size#")|$(sq "SELECT COUNT(*) FROM ka_poptavky WHERE idp > $C347_ENQ")" "303|1|0"
curl -s -o "$WORK/c347-result.html" "$C347_B/files-c347?form=c347form&result=size" || true
expect "3.47 C1: the page says the files were too large, with the limits of the form and the server" \
  "$(grep -o 'Soubory jsou příliš velké, proto se nic neodeslalo. Jeden soubor může mít nejvýše 1 MB, všechny soubory dohromady nejvýše 1 MB.\|The files are too large, so nothing was sent. One file can have up to 1 MB, all files together up to 1 MB.' "$WORK/c347-result.html" | sort -u | wc -l | tr -d ' ')|$(grep -c 'role="alert"' "$WORK/c347-result.html" | sed 's/^[1-9][0-9]*$/yes/')" "1|yes"
C347_UNKNOWN=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -X POST "$C347_B/formular?form=nope&source=stranka%3A${C347_IDS:-0}&back=%2Ffiles-c347" -F "p1=@$WORK/c347-big.bin;filename=big.pdf")
C347_EVIL=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -X POST "$C347_B/formular?form=c347form&source=stranka%3A${C347_IDS:-0}&back=%2F%2Fevil.example" -F "p1=@$WORK/c347-big.bin;filename=big.pdf")
expect "3.47 C1: an unknown form goes back to the page without a result; a back address that is not a path of the site goes home" \
  "$(echo "$C347_UNKNOWN" | cut -d' ' -f1)|$(echo "$C347_UNKNOWN" | grep -c 'files-c347$')|$(echo "$C347_EVIL" | cut -d' ' -f1)|$(echo "$C347_EVIL" | grep -c 'evil')" "303|1|303|0"
kill "$C347_PID" 2> /dev/null || true; wait "$C347_PID" 2> /dev/null || true

# the audit on the suite's own PHP limits: the form allows 25 MB per file
C347_AUDIT=$(c347_php 'Kaleta\Core\Language::set("en"); $n = 0; foreach ((new Kaleta\Core\Audit($app))->run() as $f) { $n += $f["kind"] === "build" && ($f["element"] ?? "") === "c347form" && str_contains($f["message"], "The form allows attachments up to 25 MB") ? 1 : 0; } echo $n, "|", Kaleta\Core\Files::limit() > 0 && Kaleta\Core\Files::limit() < 25 * 1048576 ? 1 : 0;')
expect "3.47 C3: the site audit names a form whose limit (25 MB) is above what this server accepts per file" "$(echo "$C347_AUDIT" | cut -d'|' -f1)" "$(echo "$C347_AUDIT" | cut -d'|' -f2)"

sq "DELETE FROM ka_stranky WHERE ids = ${C347_IDS:-0}; DELETE FROM ka_poptavky WHERE idp > $C347_ENQ" > /dev/null
rm -f "$WORK"/web/storage/cache/stranky/*.html "$WORK/c347-page.html" "$WORK/c347-result.html" "$WORK/c347-big.bin"
