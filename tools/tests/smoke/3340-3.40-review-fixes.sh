echo "== 3.40 review: the fixes of the security review of quote PDFs, quotes II and applications II"
r340_php() { (cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = Kaleta\Core\App::boot(); $app->applyTimezone(); '"$1"); }

# M1: a mangled talent-pool link (a trailing dot or bracket) is a 404 that is never kept among the addresses not found
R340_TOKEN=$(php -r 'echo bin2hex(random_bytes(32));')
for r340_suffix in '.' ')' '/'; do curl -s -o /dev/null "$B/application-consent/$R340_TOKEN$r340_suffix"; done
curl -s -o /dev/null "$B/en/Application-Consent/$R340_TOKEN."
expect "3.40 review M1: no mangled /application-consent/ address is kept among the addresses not found" \
  "$(sq "SELECT COUNT(*) FROM ka_nenalezeno WHERE cesta LIKE '%$R340_TOKEN%'")" "0"

# M2: a bulk rejection leaves a hired applicant alone – no rejection, no e-mail, counted as skipped
sq "DROP TABLE IF EXISTS ka_r340_saved; CREATE TABLE ka_r340_saved AS SELECT * FROM ka_nastaveni WHERE promenna IN ('mail_mode', 'smtp_host', 'mail_from');
  REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('mail_mode', 'smtp'), ('smtp_host', '127.0.0.1'), ('mail_from', 'web@example.cz')" > /dev/null
R340_HIRED=$(sq "INSERT INTO ka_applications (received_at, job_item_id, job_title, form, name, email, email_norm, language, data, status, status_at, last_activity_at, decided_at) VALUES ('$(site_time)', 9341, 'Welder R340', 'Job application', 'Hana R340', 'hana-r340@example.com', 'hana-r340@example.com', '', '[]', 'hired', '$(site_time)', '$(site_time)', '$(site_time)'); SELECT LAST_INSERT_ID();")
R340_P0=$(sq "SELECT IFNULL(MAX(idp), 0) FROM ka_posta")
expect "3.40 review M2: Reject and send the rejection skips a hired applicant – still hired, no reply, nothing in the mail queue" \
  "$(r340_php 'echo json_encode(Kaleta\Core\Applications::rejectWithTemplate($app, ['"$R340_HIRED"'], "position_filled"));')|$(sq "SELECT CONCAT(status, ':', IFNULL(JSON_LENGTH(replies), 0)) FROM ka_applications WHERE id = $R340_HIRED")|$(sq "SELECT COUNT(*) FROM ka_posta WHERE idp > $R340_P0")" \
  '{"sent":0,"queued":0,"no_email":0,"skipped":1}|hired:0|0'
r340_php 'Kaleta\Core\Applications::delete($app, ['"$R340_HIRED"'], false);' > /dev/null
sq "DELETE FROM ka_application_counts WHERE job_item_id = 9341; DELETE FROM ka_nastaveni WHERE promenna IN ('mail_mode', 'smtp_host', 'mail_from'); INSERT INTO ka_nastaveni SELECT * FROM ka_r340_saved; DROP TABLE ka_r340_saved" > /dev/null

# M3: a set saved in CZK inserted into a EUR quote – the free line comes without a price, with a warning (MCP)
R340_EXT=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'extensions'")
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('extensions', '$([ -z "$R340_EXT" ] && echo 'novinky,poptavky,statistika,presmerovani,claude' || echo "$R340_EXT"),quotes')" > /dev/null
sq "INSERT INTO ka_price_lists (name, currency, is_default, created_at, updated_at) VALUES ('Euro R340', 'EUR', 0, '$(site_time)', '$(site_time)');
  INSERT INTO ka_quote_line_sets (name, content, currency, created_at, updated_at) VALUES ('Laser R340', '[{\"kind\":\"free\",\"code\":\"\",\"name\":\"Montáž R340\",\"unit\":\"h\",\"unit_price\":\"5000.00\",\"vat_rate\":\"21\",\"quantity\":\"1\",\"discount_percent\":\"0\"}]', 'CZK', '$(site_time)', '$(site_time)')" > /dev/null
R340_EUR=$(sq "SELECT id FROM ka_price_lists WHERE name = 'Euro R340'"); R340_EUR="${R340_EUR:-0}"
r340_quote() { mcp_call "$API_TOKEN" save_quote "{\"title\":\"$1\",\"price_list\":\"$2\",\"line_set\":\"Laser R340\"}" | php -r '$r = json_decode(stream_get_contents(STDIN), true); $j = json_decode((string) ($r["result"]["content"][0]["text"] ?? "{}"), true); echo implode(" ", array_map(fn ($l) => $l["name"] . ":" . ($l["unit_price"] ?? "none"), $j["quote"]["lines"] ?? [])), "|", (int) str_contains(implode(" ", $j["warnings"] ?? []), "saved in CZK");'; }
expect "3.40 review M3: into a EUR quote the set's free line comes without a price and with a warning; into a CZK quote it keeps 5000" \
  "$(r340_quote 'R340 EUR' "$R340_EUR")/$(r340_quote 'R340 CZK' "$(sq "SELECT id FROM ka_price_lists WHERE is_default = 1")")" "Montáž R340:none|1/Montáž R340:5000.00|0"
sq "DELETE FROM ka_quote_lines WHERE quote_id IN (SELECT id FROM ka_quotes WHERE title IN ('R340 EUR', 'R340 CZK')); DELETE FROM ka_quotes WHERE title IN ('R340 EUR', 'R340 CZK');
  DELETE FROM ka_quote_line_sets WHERE name = 'Laser R340'; DELETE FROM ka_price_lists WHERE id = $R340_EUR; DELETE FROM ka_quote_counters" > /dev/null

# M4: the tasks about a quote lose their titles when the quote is anonymised (retention or erasure) and go when it is deleted
R340_ADMIN=$(sq "SELECT MIN(idu) FROM ka_uzivatele WHERE admin = 2 AND blokovat = 0")
r340_numbered() { sq "INSERT INTO ka_quotes (number, title, status, price_list_id, currency, language, vat_mode, customer_name, customer_email, created_by, created_at, updated_at) VALUES ($1, 'Quote R340', '$2', (SELECT id FROM ka_price_lists WHERE is_default = 1), 'CZK', 'cs', 'standard', 'Rita R340', 'rita-r340@example.com', $R340_ADMIN, '$(site_time)', '$(site_time)'); SELECT LAST_INSERT_ID();"; }
r340_task() { sq "INSERT INTO ka_tasks (title, target, assignee_id, created_by, created_at, updated_at, due_at) VALUES ('Call Rita R340 about it', 'quote:$1', $R340_ADMIN, $R340_ADMIN, '$(site_time)', '$(site_time)', '$(site_date '+3 days')'); SELECT LAST_INSERT_ID();"; }
R340_QN=$(r340_numbered "'R340-0001'" sent); R340_QD=$(r340_numbered NULL draft)
R340_TN=$(r340_task "$R340_QN"); R340_TD=$(r340_task "$R340_QD")
r340_php 'Kaleta\Core\QuoteDelivery::erase($app->db(), [["id" => '"$R340_QN"', "number" => "R340-0001"], ["id" => '"$R340_QD"', "number" => null]]);' > /dev/null
expect "3.40 review M4: erasing a person blanks the task title of their numbered quote (the task stays) and deletes the task of their draft" \
  "$(sq "SELECT CONCAT(COUNT(*), ':', IFNULL(MAX(title), '-')) FROM ka_tasks WHERE id = $R340_TN")|$(sq "SELECT COUNT(*) FROM ka_tasks WHERE id = $R340_TD")|$(sq "SELECT COUNT(*) FROM ka_tasks WHERE title LIKE '%Rita R340%'")" "1:|0|0"
sq "DELETE FROM ka_tasks WHERE target = 'quote:$R340_QN'; DELETE FROM ka_quotes WHERE id = $R340_QN" > /dev/null
