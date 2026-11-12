echo "== 3.38 C: the customer's quote (a signed link, never public), sending, send_quote, the expiry job, retention and personal data"
C338_EXT=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'extensions'")
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('extensions', '$([ -z "$C338_EXT" ] && echo 'novinky,poptavky,statistika,presmerovani,claude' || echo "$C338_EXT"),quotes')" > /dev/null
C338_SMTP=$((PORT + 3)); mkdir -p "$WORK/smtp-c338"; command php "$ROOT/tools/fake-smtp.php" "$C338_SMTP" "$WORK/smtp-c338" > /dev/null 2>&1 & C338_SMTP_PID=$!
C338_ADMIN=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'admin'"); C338_OLD_EMAIL=$(sq "SELECT email FROM ka_uzivatele WHERE idu = $C338_ADMIN")
sq "UPDATE ka_uzivatele SET email = 'obchod338@firma.example' WHERE idu = $C338_ADMIN; REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('mail_mode', 'smtp'), ('smtp_host', '127.0.0.1'), ('smtp_port', '$C338_SMTP'),
  ('smtp_encryption', 'zadne'), ('smtp_user', ''), ('mail_from', 'web@example.cz'), ('quote_vat_rate', '21'), ('quote_terms', 'Dodání do 4 týdnů.'), ('quote_footer', 'Firma 338 s.r.o., zapsaná u KS v Brně')" > /dev/null
c338_eml() { php -r '[$h, $b] = explode("\r\n\r\n", file_get_contents($argv[1]), 2); echo $h, "\n"; preg_match("/^Subject: (.*)$/m", $h, $s); echo "Subject-Decoded: ", mb_decode_mimeheader(trim($s[1] ?? "")), "\n";
  preg_match_all("/base64\r\n\r\n([A-Za-z0-9+\/=\r\n]+)/", $b, $p); foreach ($p[1] as $x) { echo base64_decode($x), "\n"; }
  if ($p[1] === [] && preg_match("/^Content-Transfer-Encoding: base64/mi", $h)) { echo base64_decode(preg_replace("/\s+/", "", $b)), "\n"; }' -- "$1"; }
c338_php() { (cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); $app->applyTimezone(); $db = $app->db(); $a = array_slice($argv, 1); '"$1" -- "${@:2}"); }
C338_CANARY='87654.32'

# three drafts for one customer: from an enquiry with a deal (the admin sends it), one whose e-mail is not the customer's own, and one for Claude
c338_php '$c = $db->insert("contacts", ["email" => "zakaznik338@example.cz", "email_norm" => "zakaznik338@example.cz", "name" => "Zdena Zákaznice", "source" => "manual", "created_at" => date("Y-m-d H:i:s"), "updated_at" => date("Y-m-d H:i:s")]);
  $e = $db->insert("poptavky", ["datum" => date("Y-m-d H:i:s"), "formular" => "Poptávka 338", "tema" => "Laser C338", "email" => "zakaznik338@example.cz", "contact_id" => $c, "stav" => 0, "data" => json_encode([["Zpráva", "Chci nabídku"]])]);
  $d = Kaleta\Core\Deals::save($app, null, ["title" => "Laser pro Zdenu", "source_enquiry_id" => $e, "contact_id" => $c], (int) $a[0]);
  $line = [["name" => "Laser C338", "code" => "LC338", "quantity" => "2", "unit" => "ks", "unit_price" => $a[1], "vat_rate" => "21"], ["name" => "Montáž", "quantity" => "1", "unit" => "h", "unit_price" => "1500", "vat_rate" => "21"]];
  $q1 = Kaleta\Core\Quotes::save($app, null, ["enquiry_id" => $e, "title" => "Laser pro dílnu", "lines" => $line, "valid_days" => 21], (int) $a[0])["id"];
  $q2 = Kaleta\Core\Quotes::save($app, null, ["contact_id" => $c, "customer" => ["email" => "jiny338@example.cz"], "lines" => $line], (int) $a[0])["id"];
  $q3 = Kaleta\Core\Quotes::save($app, null, ["contact_id" => $c, "title" => "Pro Clauda", "lines" => $line], (int) $a[0])["id"];
  echo implode(" ", [$c, $e, $d, $q1, $q2, $q3]);' "$C338_ADMIN" "$C338_CANARY" > "$WORK/c338-ids"
read -r C338_CONTACT C338_ENQUIRY C338_DEAL C338_Q1 C338_Q2 C338_Q3 < "$WORK/c338-ids" || true
C338_Q1="${C338_Q1:-0}"; C338_Q2="${C338_Q2:-0}"; C338_Q3="${C338_Q3:-0}"; C338_DEAL="${C338_DEAL:-0}"

# the send dialog: the recipient read only, the subject with {number}, the deal box ticked by the setting; the preview is the document of a draft
check "3.38 C: the send dialog offers the customer's own address and the deal box" 200 "/admin.php?module=quotes&action=send&id=$C338_Q1" 'data-quote-recipient>zakaznik338@example.cz<'
grep -q 'name="subject" maxlength="200" value="Nabídka {number} – Laser pro dílnu"' "$WORK/response" && grep -q 'name="set_deal_value" value="1" checked' "$WORK/response" && grep -q 'data-potvrdit="' "$WORK/response" \
  && echo "  ok     3.38 C: the subject in the quote's language with {number}, the deal box ticked, a confirmation before sending" || { echo "  CHYBA  3.38 C: the send dialog"; ERRORS=$((ERRORS+1)); }
C338_HEAD=$(curl -s -b "$JAR" -c "$JAR" -D - -o "$WORK/c338-preview" "$B/admin.php?module=quotes&action=preview&id=$C338_Q1" | tr -d '\r')
expect "3.38 C: the admin preview of a draft is the same document, marked as a draft, never cached" \
  "$(grep -c 'Koncept – zatím neodesláno' "$WORK/c338-preview")|$(grep -c 'image/quote.css' "$WORK/c338-preview")|$(grep -ci '^cache-control: private, no-store' <<< "$C338_HEAD")" "1|1|1"

# sending: the number, the frozen document, the link, one e-mail to the customer only, the deal takes the net total
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=quotes&action=send" -d "_csrf=$(csrf)" -d "id=$C338_Q1" --data-urlencode "subject=Nabídka {number} – Laser pro dílnu" \
  --data-urlencode $'message=Dobrý den,\nposíláme nabídku.' -d set_deal_value=1
C338_NUMBER=$(sq "SELECT number FROM ka_quotes WHERE id = $C338_Q1")
expect "3.38 C: the sent quote has its number, the e-mail row, a link (only its hash) and the deal its net total" \
  "$(c338_php '$q = Kaleta\Core\Quotes::get($db, (int) $a[0]); $h = $db->value("SELECT token_hash FROM {quotes} WHERE id = ?", [(int) $a[0]]);
    echo $q === null ? "missing" : implode("|", [$q["status"], (int) ($q["number"] !== null), (int) ($q["sent_mail"] !== null), strlen((string) $h), (int) ((string) $q["token_expires_at"] > Kaleta\Core\Clock::now()), $q["history"][1]["action"] ?? "-"]);' "$C338_Q1" 2>&1)|$(sq "SELECT value FROM ka_deals WHERE id = $C338_DEAL")" \
  "sent|1|1|64|1|sent|$(sq "SELECT total_net FROM ka_quotes WHERE id = $C338_Q1")"
F=$(grep -l "^X-Rcpt-To: zakaznik338@example.cz" "$WORK"/smtp-c338/*.eml 2>/dev/null | tail -1 || true); if [ -n "$F" ]; then c338_eml "$F" > "$WORK/c338-eml"; else : > "$WORK/c338-eml"; fi
C338_LINK=$(grep -o "$B/quote/[a-f0-9]\{64\}" "$WORK/c338-eml" | head -1); C338_TOKEN="${C338_LINK##*/}"
expect "3.38 C (A-13): one recipient, Reply-To the sender, the subject with the number; the text has the message, the number, the total and the link" \
  "$(grep -c '^X-Rcpt-To:' "$F" 2>/dev/null)|$(grep -c '^Reply-To: obchod338@firma.example' "$WORK/c338-eml")|$(grep -c "^Subject-Decoded: Nabídka $C338_NUMBER – Laser pro dílnu" "$WORK/c338-eml")|$(grep -c '^posíláme nabídku.$' "$WORK/c338-eml")|$(grep -c "Číslo nabídky: $C338_NUMBER" "$WORK/c338-eml")|$(grep -c 'Celkem včetně DPH: 213' "$WORK/c338-eml")|${#C338_TOKEN}" \
  "1|1|1|1|1|1|64"
expect "3.38 C: the mail log and the change log name only the number – never the customer, the title or an amount" \
  "$(sq "SELECT predmet FROM ka_posta WHERE idp = (SELECT sent_mail FROM ka_quotes WHERE id = $C338_Q1)")|$(sq "SELECT COUNT(*) FROM ka_protokol WHERE modul = 'quotes' AND (popis LIKE '%87654%' OR popis LIKE '%176808%' OR popis LIKE '%Zdena%')")" \
  "Nabídka $C338_NUMBER: $(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'site_name'")|0"

# the customer's page: the frozen document in the site's look, never cached or indexed; a wrong token is the plain 404
C338_HEAD=$(curl -s -D - -o "$WORK/c338-page" "$C338_LINK" | tr -d '\r')
expect "3.38 C: the link opens the document – number, customer, lines, totals, terms and footer – with no-store, noindex, no referrer and its own CSP" \
  "$(head -1 <<< "$C338_HEAD" | grep -c ' 200')|$(grep -ci '^cache-control: private, no-store' <<< "$C338_HEAD")|$(grep -ci '^x-robots-tag: noindex, nofollow' <<< "$C338_HEAD")|$(grep -ci '^referrer-policy: no-referrer' <<< "$C338_HEAD")|$(grep -ci "^content-security-policy: default-src 'none'" <<< "$C338_HEAD")|$(grep -c "<h1>Nabídka $C338_NUMBER</h1>" "$WORK/c338-page")|$(grep -c 'Zdena Zákaznice' "$WORK/c338-page")|$(grep -o '87[^0-9<]*654,32' "$WORK/c338-page" | wc -l | tr -d ' ')|$(grep -c 'Dodání do 4 týdnů.' "$WORK/c338-page")|$(grep -c 'Firma 338 s.r.o.' "$WORK/c338-page")|$(grep -c 'data-quote-print' "$WORK/c338-page")" \
  "1|1|1|1|1|1|1|1|1|1|1"
grep -q '<link rel="stylesheet" href="[^"]*/image/quote.css' "$WORK/c338-page" && grep -q '@layer tokeny' "$WORK/c338-page" && ! grep -q 'cookies-\|vitals.js\|data-popup' "$WORK/c338-page" \
  && echo "  ok     3.38 C: the document has the design system's tokens and quote.css – no menu, cookie bar, pop-ups or statistics" || { echo "  CHYBA  3.38 C: the document's look"; ERRORS=$((ERRORS+1)); }
curl -s -o /dev/null "$C338_LINK"; curl -s -D "$WORK/c338-again" -o /dev/null "$C338_LINK"
expect "3.38 C: opened again it is still not served from the page cache" "$(grep -ci '^x-cache:' "$WORK/c338-again")" "0"
C338_WRONG=$(php -r 'echo bin2hex(random_bytes(32));')
expect "3.38 C (A4): a wrong token, an upper-case one and a short one are the site's 404 with no detail, and no token lands among the addresses not found" \
  "$(curl -s -o "$WORK/c338-404" -w '%{http_code}' "$B/quote/$C338_WRONG")|$(grep -c "$C338_NUMBER\|87[^0-9<]*654" "$WORK/c338-404")|$(curl -s -o /dev/null -w '%{http_code}' "$B/quote/$(tr 'a-f' 'A-F' <<< "$C338_TOKEN")")|$(curl -s -o /dev/null -w '%{http_code}' "$B/quote/${C338_TOKEN:0:20}")|$(sq "SELECT COUNT(*) FROM ka_nenalezeno WHERE cesta LIKE 'quote/%$C338_WRONG%'")" \
  "404|0|404|404|0"
C338_LEAKS=""
for c338_path in "/robots.txt" "/sitemap.xml" "/llms.txt" "/hledani?q=Laser" "/feed.json" "/"; do
  curl -s -o "$WORK/c338-public" "$B$c338_path"; grep -q "87654\|87[^0-9<]\{1,3\}654\|$C338_TOKEN\|$C338_NUMBER" "$WORK/c338-public" && C338_LEAKS="$C338_LEAKS $c338_path"
done
expect "3.38 C (A4): the quote is in no public listing (sitemap, llms.txt, search, feed, home) and robots.txt keeps /quote/ out" "${C338_LEAKS// /}|$(curl -s "$B/robots.txt" | grep -c '^Disallow: /quote/$')" "|1"
c338_php 'Kaleta\Core\Prices::save($app, Kaleta\Core\Prices::defaultList($db, $app->settings())["id"], ["code" => "LC338", "label" => "Laser C338", "unit_price" => "1"], 0);' > /dev/null 2>&1 || true
curl -s -o "$WORK/c338-page" "$C338_LINK"
expect "3.38 C: a later price change never rewrites the document the customer has" "$(grep -o '87[^0-9<]*654,32' "$WORK/c338-page" | wc -l | tr -d ' ')" "1"

# the link: withdrawn at once (404, no detail), a new one works and the old never comes back
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=quotes&action=send&id=$C338_Q1"
grep -q "data-quote-link>$C338_LINK<" "$WORK/response" && echo "  ok     3.38 C: the dialog of a sent quote shows its link again (Copy link)" || { echo "  CHYBA  3.38 C: Copy link"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=quotes&action=link_withdraw" -d "_csrf=$(csrf)" -d "id=$C338_Q1"
expect "3.38 C: a withdrawn link is the 404 at once" "$(curl -s -o /dev/null -w '%{http_code}' "$C338_LINK")|$(sq "SELECT token_hash IS NULL FROM ka_quotes WHERE id = $C338_Q1")" "404|1"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=quotes&action=send&id=$C338_Q1"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=quotes&action=link" -d "_csrf=$(csrf)" -d "id=$C338_Q1"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=quotes&action=send&id=$C338_Q1"
C338_LINK2=$(grep -o "$B/quote/[a-f0-9]\{64\}" "$WORK/response" | head -1)
expect "3.38 C: a new link opens the quote, the withdrawn one stays dead" "$([ -n "$C338_LINK2" ] && [ "$C338_LINK2" != "$C338_LINK" ] && echo new)|$(curl -s -o /dev/null -w '%{http_code}' "$C338_LINK2")|$(curl -s -o /dev/null -w '%{http_code}' "$C338_LINK")" "new|200|404"

# MCP send_quote (A-5): confirm=false only shows; an address that is not the customer's own is refused; a drafts-only connection cannot
mcp send_quote "{\"id\":$C338_Q2,\"confirm\":false}" > "$WORK/response"; mcp_text; cp "$WORK/text" "$WORK/c338.json"
mcp send_quote "{\"id\":$C338_Q2,\"confirm\":true}" > "$WORK/response"
expect "3.38 C MCP: send_quote without confirm sends nothing and says why; an address typed into the quote is refused" \
  "$(grep -c '"sent":false' "$WORK/c338.json")|$(grep -c '"cannot_send":"The quote' "$WORK/c338.json")|$(grep -c 'not the address of its customer record' "$WORK/response")|$(sq "SELECT status FROM ka_quotes WHERE id = $C338_Q2")" "1|1|1|draft"
C338_DRAFTS="kaleta_$(openssl rand -hex 24)"
sq "INSERT INTO ka_api_tokeny (idu, nazev, access, tool_set, otisk, vytvoren) VALUES ($C338_ADMIN, 'c338 drafts', 'drafts', 'all', SHA2('$C338_DRAFTS', 256), '$(site_time)')" > /dev/null
curl -s -X POST "$B/mcp" -H "Authorization: Bearer $C338_DRAFTS" -H 'Content-Type: application/json' --data-binary "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/call\",\"params\":{\"name\":\"send_quote\",\"arguments\":{\"id\":$C338_Q3,\"confirm\":true}}}" > "$WORK/response"
expect "3.38 C MCP: a drafts-only connection cannot send a quote" "$(grep -c '"isError":true\|"error"' "$WORK/response")|$(sq "SELECT status FROM ka_quotes WHERE id = $C338_Q3")" "1|draft"
mcp send_quote "{\"id\":$C338_Q3,\"confirm\":true,\"message\":\"Dobrý den, nabídka od Clauda.\"}" > "$WORK/response"; mcp_text
expect "3.38 C MCP: with confirm on a full connection the quote goes to the customer's own address, numbered" \
  "$(grep -c '"sent":true' "$WORK/text")|$(grep -c '"status":"sent"' "$WORK/text")|$(sq "SELECT CONCAT(status, '|', number IS NOT NULL, '|', token_hash IS NOT NULL) FROM ka_quotes WHERE id = $C338_Q3")|$(grep -l '^X-Rcpt-To: zakaznik338@example.cz' "$WORK"/smtp-c338/*.eml | wc -l | tr -d ' ')" \
  "1|1|sent|1|1|2"
F=$(grep -l "^X-Rcpt-To: zakaznik338@example.cz" "$WORK"/smtp-c338/*.eml 2>/dev/null | tail -1 || true); C338_LINK3=$( { [ -n "$F" ] && c338_eml "$F"; } | grep -o "$B/quote/[a-f0-9]\{64\}" | head -1)
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('extensions', '$C338_EXT')" > /dev/null
expect "3.38 C: with the feature off the section is gone, yet a customer's link still opens" \
  "$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code}' "$B/admin.php?module=quotes&action=send&id=$C338_Q3")|$(curl -s -o /dev/null -w '%{http_code}' "${C338_LINK3:-$B/quote/none}")" "403|200"
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('extensions', '$([ -z "$C338_EXT" ] && echo 'novinky,poptavky,statistika,presmerovani,claude' || echo "$C338_EXT"),quotes')" > /dev/null

# the expiry job: a sent quote past its validity expires (the document says so), its owner gets a bell without an amount; a reminder once
sq "UPDATE ka_quotes SET valid_until = '$(site_date '-1 day')' WHERE id = $C338_Q1; UPDATE ka_quotes SET valid_until = '$(site_date '+2 days')' WHERE id = $C338_Q3; DELETE FROM ka_notifications WHERE target LIKE 'quote:%'" > /dev/null
sq "INSERT INTO ka_uzivatele (user, password, jmeno, email, admin, blokovat) VALUES ('obchod-338', 'x', 'Obchodník', 'obchodnik338@example.cz', 1, 0)" > /dev/null
C338_OWNER=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'obchod-338'"); sq "INSERT INTO ka_uzivatele_prava (fk_id_user, ident_modulu) VALUES ($C338_OWNER, 'quotes'); UPDATE ka_quotes SET owner_id = $C338_OWNER WHERE id IN ($C338_Q1, $C338_Q3)" > /dev/null
run_job quotes > "$WORK/c338-job"; run_job quotes > /dev/null
expect "3.38 C: the job expires the quote past its validity and reminds once of the one that expires in two days – bells name the number only" \
  "$(sq "SELECT status FROM ka_quotes WHERE id = $C338_Q1")|$(sq "SELECT GROUP_CONCAT(CONCAT(kind, '@', target, '@', excerpt) ORDER BY kind SEPARATOR ' ') FROM ka_notifications WHERE user_id = $C338_OWNER")|$(grep -c 'expired 1' "$WORK/c338-job")" \
  "expired|quote_expired@quote:$C338_Q1@ quote_expiring@quote:$C338_Q3@|1"
expect "3.38 C: the customer's page of an expired quote says so" "$(curl -s "$C338_LINK2" | grep -c 'Platnost této nabídky skončila')" "1"
sq "UPDATE ka_quotes SET token_expires_at = '$(site_time '-1 minute')' WHERE id = $C338_Q1" > /dev/null; run_job quotes > /dev/null
expect "3.38 C: a link past its end is withdrawn by the job and is the 404" "$(sq "SELECT token_hash IS NULL FROM ka_quotes WHERE id = $C338_Q1")|$(curl -s -o /dev/null -w '%{http_code}' "$C338_LINK2")" "1|404"

# reports and the record of processing: counts only, never money
c338_php '$r = json_encode(Kaleta\Core\MonthlyReport::build($app, new DateTimeImmutable("first day of this month"))); $d = json_decode((string) $r, true);
  echo json_encode($d["quotes"] ?? null), "|", str_contains((string) $r, "87654") || str_contains((string) $r, "176808") ? "money" : "", "|";
  echo count(array_filter(Kaleta\Core\Privacy::processingRecord($app), fn ($x) => $x["heading"] === Kaleta\Core\Language::t("Quotes")));' > "$WORK/c338-report" 2>&1 || true
expect "3.38 C: the monthly report counts the quotes of the month without any amount; the record of processing has a Quotes section" "$(cat "$WORK/c338-report")" '{"sent":2,"accepted":0,"declined":0,"expired":1}||1'

# personal data: the file lists the person's quotes; erasing deletes their drafts and keeps a numbered quote without the person
c338_php '$f = Kaleta\Core\PersonalData::find($db, "zakaznik338@example.cz", $app); echo count($f["quotes"]), "|", Kaleta\Core\PersonalData::counts($f)["quotes"], "|", $f["quotes"][0]["lines"][0]["unit_price"] ?? "", "|";
  $r = Kaleta\Core\PersonalData::erase($app, "zakaznik338@example.cz"); echo $r["erased"]["quotes"] ?? "none";' > "$WORK/c338-pd" 2>&1 || true
expect "3.38 C: personal data – the file has the three quotes with their lines; erasing counts them" "$(cat "$WORK/c338-pd")" "3|3|$C338_CANARY|3"
expect "3.38 C: after the erasure the numbered quotes stay without the person and without a link, the draft is gone" \
  "$(sq "SELECT GROUP_CONCAT(CONCAT(id = $C338_Q1 OR id = $C338_Q3, ':', customer_email = '', customer_name = '', snapshot IS NULL, token_hash IS NULL, anonymised_at IS NOT NULL, number IS NOT NULL) ORDER BY id SEPARATOR ' ') FROM ka_quotes WHERE id IN ($C338_Q1, $C338_Q2, $C338_Q3)")" \
  "1:111111 1:111111"

# retention (A-11): quotes_months after the last status change the way of old enquiries; untouched drafts after 6 months
c338_php '$l = [["name" => "X", "quantity" => "1", "unit_price" => "10"]];
  $old = Kaleta\Core\Quotes::finalise($app, Kaleta\Core\Quotes::save($app, null, ["customer" => ["name" => "Stará Firma", "email" => "stara338@example.cz"], "lines" => $l], 0)["id"], 0)["id"];
  $keep = Kaleta\Core\Quotes::finalise($app, Kaleta\Core\Quotes::save($app, null, ["customer" => ["name" => "Nová Firma"], "lines" => $l], 0)["id"], 0)["id"];
  $draft = Kaleta\Core\Quotes::save($app, null, ["customer" => ["name" => "Koncept"], "lines" => $l], 0)["id"];
  $db->run("UPDATE {quotes} SET updated_at = ? WHERE id = ?", [Kaleta\Core\Clock::monthsAgo(37), $old]);
  $db->run("UPDATE {quotes} SET updated_at = ? WHERE id = ?", [Kaleta\Core\Clock::monthsAgo(7), $draft]);
  $app->settings()->set("enquiries_expiry", "anonymise"); $r = Kaleta\Core\QuoteDelivery::purge($app);
  echo json_encode($r), "|", $db->value("SELECT CONCAT(customer_name, \"/\", number IS NOT NULL) FROM {quotes} WHERE id = ?", [$old]), "|", $db->value("SELECT customer_name FROM {quotes} WHERE id = ?", [$keep]), "|", (int) $db->value("SELECT COUNT(*) FROM {quotes} WHERE id = ?", [$draft]), "|";
  $db->run("UPDATE {quotes} SET updated_at = ?, anonymised_at = NULL WHERE id = ?", [Kaleta\Core\Clock::monthsAgo(37), $keep]); $app->settings()->set("enquiries_expiry", "delete");
  echo json_encode(Kaleta\Core\QuoteDelivery::purge($app)), "|", (int) $db->value("SELECT COUNT(*) FROM {quotes} WHERE id = ?", [$keep]) + (int) $db->value("SELECT COUNT(*) FROM {quote_lines} WHERE quote_id = ?", [$keep]);' > "$WORK/c338-ret" 2>&1 || true
expect "3.38 C (A-11): retention – an old quote anonymised (the number stays) or deleted with its lines as the site does with enquiries (3.38 review L6: the anonymised one too), a recent one kept, an old draft deleted" \
  "$(cat "$WORK/c338-ret")" '{"drafts":1,"deleted":0,"anonymised":1}|/1|Nová Firma|0|{"drafts":0,"deleted":2,"anonymised":0}|0'

# the default texts per language (administrators)
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=quotes&action=settings"
grep -q 'data-quotes-texts' "$WORK/response" && grep -q 'name="quote_footer"' "$WORK/response" && echo "  ok     3.38 C: Quotes → Settings has the default texts" || { echo "  CHYBA  3.38 C: the texts form"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=quotes&action=texts_save" -d "_csrf=$(csrf)" --data-urlencode "quote_intro=Děkujeme za poptávku." -d quote_terms= -d quote_footer=
expect "3.38 C: the default texts are saved" "$(sq "SELECT GROUP_CONCAT(CONCAT(promenna, '=', hodnota) ORDER BY promenna SEPARATOR ' ') FROM ka_nastaveni WHERE promenna IN ('quote_intro', 'quote_terms', 'quote_footer')")" "quote_footer= quote_intro=Děkujeme za poptávku. quote_terms="
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('extensions', '$C338_EXT')" > /dev/null

# clean up
kill "$C338_SMTP_PID" 2>/dev/null || true; wait "$C338_SMTP_PID" 2>/dev/null || true
c338_php 'Kaleta\Core\QuoteDelivery::delete($db, array_map("intval", array_column($db->all("SELECT id FROM {quotes}"), "id")));' > /dev/null 2>&1 || true
sq "DELETE FROM ka_uzivatele_prava WHERE fk_id_user = $C338_OWNER; DELETE FROM ka_uzivatele WHERE idu = $C338_OWNER; DELETE FROM ka_api_tokeny WHERE nazev = 'c338 drafts'; DELETE FROM ka_notifications WHERE target LIKE 'quote:%';
  DELETE FROM ka_deal_stage_history WHERE deal_id = $C338_DEAL; DELETE FROM ka_deals WHERE id = $C338_DEAL; DELETE FROM ka_quote_counters; DELETE FROM ka_nastaveni WHERE promenna LIKE 'quote%' OR promenna = 'enquiries_expiry';
  UPDATE ka_uzivatele SET email = '$C338_OLD_EMAIL' WHERE idu = $C338_ADMIN; REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('mail_mode', 'mail'), ('smtp_host', ''); DELETE FROM ka_nastaveni WHERE promenna = 'extensions' AND hodnota = ''" > /dev/null
