echo "== 3.42 review: prepared quotes – the product codes read once, spam takes its draft along, the bell's e-mail goes at once; menus read fresh per MCP call"
R342Q_ADMIN=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'admin'")
R342Q_NOW=$(site_time)
# (M1) an enquiry whose fields hold hundreds of comma-separated values: the catalogue is read once, the product still found
R342Q_JUNK=$(php -r 'echo str_repeat("a,", 199), "a";')
R342Q_DATA=$(php -r '$f = [["Produkty", "lehátko l2"]]; for ($i = 0; $i < 20; $i++) { $f[] = ["Pole " . $i, $argv[1]]; } echo json_encode($f, JSON_UNESCAPED_UNICODE);' -- "$R342Q_JUNK")
sq "INSERT INTO ka_poptavky (datum, formular, zdroj, stranka, email, stav, data) VALUES ('$R342Q_NOW', 'Poptávka 342q', 'stranka:1', '/poptavka', 'q342@example.cz', 0, '$R342Q_DATA')" > /dev/null
R342Q_ENQ=$(sq "SELECT MAX(idp) FROM ka_poptavky WHERE formular = 'Poptávka 342q'"); R342Q_ENQ="${R342Q_ENQ:-0}"
cat > "$WORK/r342q-lines.php" <<'PHP'
<?php
require 'system/bootstrap.php';
$app = new Kaleta\Core\App(require 'config.php');
$app->applyTimezone();
$db = $app->db();
$questions = fn (): int => (int) ($db->one("SHOW SESSION STATUS LIKE 'Questions'")['Value'] ?? 0);
$before = $questions();
$found = Kaleta\Core\Quotes::linesFromEnquiry($app, (int) $argv[1]);
$used = $questions() - $before - 1;
echo implode(',', array_map(fn (array $l): string => (string) ($l['item_id'] ?? '-'), $found['lines'])), '|', $found['matched'], '|', $used < 40 ? 'few queries' : 'queries: ' . $used;
PHP
expect "3.42 review M1: 20 fields of 200 values each – the product is matched and the catalogue is not read again for every value" \
  "$(cd "$WORK/web" && php "$WORK/r342q-lines.php" "$R342Q_ENQ" 2>&1)" "$C342_L2|1|few queries"

# (L6) an enquiry sorted out as spam takes its untouched prepared draft along; a draft someone saved, or made by hand, stays
cat > "$WORK/r342q-spam.php" <<'PHP'
<?php
require 'system/bootstrap.php';
$app = new Kaleta\Core\App(require 'config.php');
$app->applyTimezone();
use Kaleta\Core\Quotes;
use Kaleta\Core\Triage;
$db = $app->db();
$me = (int) $argv[1];
$app->settings()->set('quote_auto_draft', '1');
$enquiry = fn (): int => $db->insert('poptavky', ['datum' => date('Y-m-d H:i:s'), 'formular' => 'Poptávka 342s', 'email' => 's342@example.cz', 'stav' => 0, 'prirazeno' => $me,
    'data' => json_encode([['Kód', 'LH-L2']])]);
[$a, $b, $c] = [$enquiry(), $enquiry(), $enquiry()];
$qa = Quotes::prepareDraft($app, $a);
$qb = Quotes::prepareDraft($app, $b);
$db->run('UPDATE {quotes} SET updated_at = DATE_ADD(updated_at, INTERVAL 1 MINUTE) WHERE id = ?', [$qb]); // somebody saved it since
$qc = Quotes::save($app, null, ['enquiry_id' => $c], $me)['id'];
$bells = fn (int $q): int => (int) $db->value('SELECT COUNT(*) FROM {notifications} WHERE target = ?', ['quote:' . $q]);
$out = [$qa !== null && $qb !== null ? 'prepared' : 'none', $bells((int) $qa)];
foreach ([$a, $b, $c] as $id) {
    Triage::save($db, $id, Triage::clean('spam', null, null), 'claude');
}
$left = fn (?int $q): string => $db->value('SELECT 1 FROM {quotes} WHERE id = ?', [$q]) !== null ? 'kept' : 'gone';
$out[] = $left($qa) . ',' . $left($qb) . ',' . $left($qc);
$out[] = (int) $db->value('SELECT COUNT(*) FROM {quote_lines} WHERE quote_id = ?', [$qa]) . ':' . $bells((int) $qa);
$app->settings()->set('quote_auto_draft', '0');
foreach ([$qb, $qc] as $q) {
    Quotes::delete($app, (int) $q, $me, true);
}
echo implode('|', $out);
PHP
expect "3.42 review L6: spam (Claude's or a person's sorting) deletes the prepared draft with its lines and bell while nobody touched it – a saved or hand-made draft stays" \
  "$(cd "$WORK/web" && php "$WORK/r342q-spam.php" "$R342Q_ADMIN" 2>&1)" "prepared|1|gone,kept,kept|0:0"

# (L3 + M4) a real form submission: the prepared quote's e-mail to its responsible person leaves with the response, under its own subject
R342Q_NOTIFY=$(sq "SELECT notify_email FROM ka_uzivatele WHERE idu = $R342Q_ADMIN")
sq "UPDATE ka_uzivatele SET notify_email = 'instant' WHERE idu = $R342Q_ADMIN; REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('quote_auto_draft', '1')" > /dev/null
# the mail leaves through the fake SMTP server – PHP's mail() has no sendmail on a CI runner and would fail the delivery
R342Q_SMTP_PORT=${SMTP2_PORT:-$((PORT + 7))}; mkdir -p "$WORK/smtp-342q"
command php "$ROOT/tools/fake-smtp.php" "$R342Q_SMTP_PORT" "$WORK/smtp-342q" > /dev/null 2>&1 & R342Q_SMTP_PID=$!
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('mail_mode', 'smtp'), ('smtp_host', '127.0.0.1'), ('smtp_port', '$R342Q_SMTP_PORT'), ('smtp_encryption', 'zadne'), ('smtp_user', ''), ('mail_from', 'web@example.cz')" > /dev/null
background_jobs hold
R342Q_MAIL0=$(sq "SELECT IFNULL(MAX(idp), 0) FROM ka_posta")
R342Q_TIME=$(( $(date +%s) - 60 ))
R342Q_SIG=$(php -r 'echo hash_hmac("sha256", $argv[1], $argv[2]);' "formular|$C342_SOURCE|$C342_ELEMENT|$R342Q_TIME" "$(setting_plain secret_key)")
sq "DELETE FROM ka_kontrola_ip WHERE typ = 'formular'" > /dev/null
curl -s -o /dev/null -X POST "$B/form" -d "zdroj=$C342_SOURCE" -d "prvek=$C342_ELEMENT" -d zpet=/poptavka-342 -d "as_cas=$R342Q_TIME" -d "as_podpis=$R342Q_SIG" -d p0=LH-L2 -d p1=fyzio342@example.cz
R342Q_ENQ_F=$(sq "SELECT MAX(idp) FROM ka_poptavky WHERE email = 'fyzio342@example.cz'"); R342Q_ENQ_F="${R342Q_ENQ_F:-0}"
R342Q_QF=$(sq "SELECT IFNULL(MAX(id), 0) FROM ka_quotes WHERE enquiry_id = $R342Q_ENQ_F")
# the sending runs after the response – a slow runner may still be at it when curl returns (up to 30 s: the PHP 8.3 CI runner
# of 3.51 needed more than 6)
for _ in $(seq 1 150); do
  [ "$(sq "SELECT COUNT(*) FROM ka_posta WHERE idp > $R342Q_MAIL0 AND odeslano IS NULL AND chyba = '' AND (predmet LIKE 'Draft quote prepared: %' OR predmet LIKE 'Připravený koncept nabídky: %')")" = 0 ] && break
  sleep 0.2
done
expect "3.42 review L3 + M4: the prepared quote's e-mail is sent with the response (not left for the background jobs) and its subject names the prepared quote, not a review" \
  "$( [ "$R342Q_QF" -gt 0 ] && echo prepared)|$(sq "SELECT CONCAT(COUNT(*), '|', SUM(odeslano IS NOT NULL), '|', SUM(predmet LIKE '%Review%' OR predmet LIKE '%kontrole%')) FROM ka_posta WHERE idp > $R342Q_MAIL0 AND (predmet LIKE 'Draft quote prepared: %' OR predmet LIKE 'Připravený koncept nabídky: %')")" \
  "prepared|1|1|0"
background_jobs resume
kill "$R342Q_SMTP_PID" 2>/dev/null || true; wait "$R342Q_SMTP_PID" 2>/dev/null || true
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES $MAIL_SINK_VALUES" > /dev/null
sq "UPDATE ka_uzivatele SET notify_email = '$R342Q_NOTIFY' WHERE idu = $R342Q_ADMIN; REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('quote_auto_draft', '0');
    DELETE FROM ka_quote_lines WHERE quote_id = $R342Q_QF; DELETE FROM ka_quotes WHERE id = $R342Q_QF; DELETE FROM ka_notifications WHERE target = 'quote:$R342Q_QF'" > /dev/null

# (L2) one MCP batch: get_menu, a page renamed, get_menu again – the second answer has the new title
R342Q_PAGE=$(sq "SELECT ids FROM ka_stranky WHERE ids = ${C342_PAGE:-0}")
# the site's own footer menu is parked meanwhile (the name 'paticka-342q' is no location) and comes back afterwards
R342Q_TITLE=$(sq "SELECT titulek FROM ka_stranky WHERE ids = $R342Q_PAGE")
sq "UPDATE ka_menu SET umisteni = 'paticka-342q' WHERE umisteni = 'paticka' AND jazyk = '';
    INSERT INTO ka_menu (umisteni, jazyk, polozky) VALUES ('paticka', '', '[{\"typ\":\"stranka\",\"text\":\"\",\"ids\":$R342Q_PAGE}]')" > /dev/null
php -r '$call = fn (int $id, string $tool, array $args): array => ["jsonrpc" => "2.0", "id" => $id, "method" => "tools/call", "params" => ["name" => $tool, "arguments" => $args]];
  echo json_encode([$call(1, "get_menu", ["location" => "footer"]), $call(2, "update_page", ["id" => (int) $argv[1], "title" => "Poptavka 342 renamed"]), $call(3, "get_menu", ["location" => "footer"])]);' -- "$R342Q_PAGE" > "$WORK/r342q-batch-request.json"
R342Q_RC=0
curl -s -X POST "$B/mcp" -H "Authorization: Bearer $API_TOKEN" -H 'Content-Type: application/json' --data-binary "@$WORK/r342q-batch-request.json" -o "$WORK/r342q-batch.json" || R342Q_RC=$?
[ "$R342Q_RC" = 0 ] || echo "  (the MCP batch request ended with curl code $R342Q_RC)"
expect "3.42 review L2: a page renamed between two get_menu calls of one MCP batch shows its new title in the second" \
  "$(php -r '$r = array_column(json_decode((string) file_get_contents($argv[1]), true) ?: [], null, "id"); $t = fn (int $i): string => json_encode(json_decode((string) ($r[$i]["result"]["content"][0]["text"] ?? "{}"), true)["on_site"] ?? null, JSON_UNESCAPED_UNICODE);
    echo str_contains($t(1), "Poptavka 342 renamed") ? "new" : "old", "|", str_contains($t(3), "Poptavka 342 renamed") ? "new" : "old";' -- "$WORK/r342q-batch.json")" "old|new"
sq "DELETE FROM ka_menu WHERE umisteni = 'paticka' AND jazyk = ''; UPDATE ka_menu SET umisteni = 'paticka' WHERE umisteni = 'paticka-342q' AND jazyk = ''" > /dev/null
mcp update_page "$(php -r 'echo json_encode(["id" => (int) $argv[1], "title" => $argv[2]], JSON_UNESCAPED_UNICODE);' -- "$R342Q_PAGE" "$R342Q_TITLE")" > /dev/null || true
