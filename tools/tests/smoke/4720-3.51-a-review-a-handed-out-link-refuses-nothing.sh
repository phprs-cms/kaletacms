echo "== 3.51 A review: the refuse link acts only for an e-mailed request – a link handed out by staff or Claude refuses nothing"
A720_TOKEN="kaleta_$(openssl rand -hex 24)"
sq "INSERT INTO ka_api_tokeny (idu, nazev, access, tool_set, otisk, vytvoren) SELECT idu, 'a720 admin', 'full', 'all', SHA2('$A720_TOKEN', 256), '$(site_time)' FROM ka_uzivatele WHERE user = 'admin'" > /dev/null
A720_ENQUIRY=$(sq "INSERT INTO ka_poptavky (datum, formular, zdroj, stranka, email, data, stav) VALUES ('$(site_time)', 'Kontakt', 'stranka:1', '/kontakt', 'jana-a720@example.com', '[]', 2); SELECT LAST_INSERT_ID();")
sq "INSERT INTO ka_odberatele (email, stav, token, datum, potvrzeno) VALUES ('jana-a720@example.com', 1, SUBSTRING(SHA2(RAND(), 256), 1, 32), '$(site_time)', '$(site_time)')" > /dev/null
A720_LINK=$(mcp_call "$A720_TOKEN" request_testimonial "{\"id\":$A720_ENQUIRY,\"send\":false}" | php -r '$r = json_decode((string) file_get_contents("php://stdin"), true); echo json_decode((string) ($r["result"]["content"][0]["text"] ?? "{}"), true)["link"] ?? "";')
A720_PATH=$(printf '%s' "$A720_LINK" | grep -o '/_testimonial/[a-f0-9]\{32\}' || true)
if [ -n "$A720_PATH" ]; then
  curl -s -o "$WORK/a720.html" -X POST "$B$A720_PATH?refuse=1"
  A720_BLOCKED=$(cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); echo Kaleta\Core\SendRegister::blocked($app->db(), "jana-a720@example.com") ?? "none";')
  expect "3.51 A review: the refuse button behind a link only handed out says the link is no longer valid – no refusal, the subscription stays, the request is not used; the form of the link still opens" \
    "$(grep -c 'Tento odkaz už neplatí' "$WORK/a720.html")|$A720_BLOCKED|$(sq "SELECT COUNT(*) FROM ka_odberatele WHERE email = 'jana-a720@example.com'")|$(sq "SELECT COUNT(*) FROM ka_testimonial_requests WHERE idp = $A720_ENQUIRY AND used_at IS NULL AND sent_at IS NULL")|$(curl -s -o /dev/null -w '%{http_code}' "$B$A720_PATH")" \
    "1|none|1|1|200"
else echo "  CHYBA  3.51 A review: request_testimonial gave no link"; ERRORS=$((ERRORS+1)); fi
sq "DELETE FROM ka_testimonial_requests WHERE idp = $A720_ENQUIRY; DELETE FROM ka_poptavky WHERE idp = $A720_ENQUIRY; DELETE FROM ka_odberatele WHERE email = 'jana-a720@example.com'; DELETE FROM ka_api_tokeny WHERE nazev = 'a720 admin'" > /dev/null
rm -f "$WORK/a720.html"
