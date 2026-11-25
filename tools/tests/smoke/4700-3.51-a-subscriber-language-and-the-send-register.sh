echo "== 3.51 A: a sign-up keeps its language; Subscribers show the languages and the send register; an administrator lifts a bounce, never a refusal; one confirmation e-mail a week to a register address"
A700_EXT=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'extensions'")
A700_LANGS="$(sq "SELECT COUNT(*) FROM ka_nastaveni WHERE promenna = 'additional_languages'")|$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'additional_languages'")"
features +newsletter +jazyky
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('additional_languages', 'en')" > /dev/null
rm -f "$WORK"/web/storage/cache/stranky/*.html
a700_php() { (cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); $db = $app->db(); '"$1" -- "${@:2}"); }
# a sign-up as the Newsletter element sends it, signed ten seconds ago (no waiting); the per-IP limit of sign-ups is cleared first
a700_subscribe() {
  local signed
  sq "DELETE FROM ka_kontrola_ip WHERE typ = 'odber'" > /dev/null
  signed=$(a700_php '$a = new Kaleta\Core\Antispam($db, $app->settings()); $t = time() - 10; echo $t, " ", hash_hmac("sha256", "odber|" . $t, (string) (new ReflectionMethod($a, "key"))->invoke($a));')
  curl -s -o /dev/null -w '%{redirect_url}' -X POST "$B$1/odber" --data-urlencode "email=$2" -d "zpet=$1/" -d "as_cas=${signed% *}" -d "as_podpis=${signed#* }" -d web_adresa=
}
a700_ok() { if printf '%s' "$1" | grep -q 'subscription=ok'; then echo ok; else echo "$1"; fi; }
a700_mails() { sq "SELECT COUNT(*) FROM ka_posta WHERE komu = '$1'"; }
a700_blocked() { a700_php 'echo Kaleta\Core\SendRegister::blocked($db, $argv[1]) ?? "none";' "$1"; }

A700_EN=$(a700_subscribe /en 'A700-En@Example.com')
A700_CS=$(a700_subscribe '' 'a700-cs@example.com')
expect "3.51 A1: a sign-up on /en/ keeps the language en, one on the default version ''; each gets its confirmation e-mail" \
  "$(a700_ok "$A700_EN")|$(sq "SELECT CONCAT(language, '/', stav) FROM ka_odberatele WHERE email = 'a700-en@example.com'")|$(sq "SELECT CONCAT('[', language, ']') FROM ka_odberatele WHERE email = 'a700-cs@example.com'")|$(a700_mails a700-en@example.com)|$(a700_mails a700-cs@example.com)" \
  "ok|en/0|[]|1|1"
A700_TOKEN=$(sq "SELECT token FROM ka_odberatele WHERE email = 'a700-en@example.com'")
curl -s -o /dev/null -X POST "$B/en/odber?confirm=$A700_TOKEN"
a700_php 'Kaleta\Core\SendRegister::record($db, "bounced", $argv[1]);' 'a700-en@example.com'
curl -s -b "$JAR" -c "$JAR" -o "$WORK/a700-list.html" "$B/admin.php?module=subscribers"
expect "3.51 A1/A2: Subscribers – the counts per language and of the register, the Language column, the bounce mark with Lift on the bounced row only" \
  "$(grep -c 'English: 1 potvrzených, 0 čeká' "$WORK/a700-list.html")|$(grep -c 'v registru: 1 nedoručitelných, 0 odmítlo' "$WORK/a700-list.html")|$(grep -c '<th scope="col">Jazyk</th>' "$WORK/a700-list.html")|$(grep -c 'data-register="bounced"' "$WORK/a700-list.html")|$(grep -o 'action=lift' "$WORK/a700-list.html" | wc -l | tr -d ' ')" \
  "1|1|1|1|1"
curl -s -b "$JAR" -o "$WORK/a700.csv" "$B/admin.php?module=subscribers&action=csv"
# 3.54: the proof of consent follows the language (wording, page, form – empty for this sign-up without the element's inputs)
expect "3.51 A1: the CSV of confirmed subscribers gives each row its language" "$(grep -c '^a700-en@example.com;.*;en;;/en/;$' "$WORK/a700.csv" | tr -d ' ')" "1"

# A2: lift takes the bounce off; a refused address cannot be lifted by a person
A700_IDO=$(sq "SELECT ido FROM ka_odberatele WHERE email = 'a700-en@example.com'")
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=subscribers&action=lift" -d "_csrf=$(grep -o -m1 'name="_csrf" value="[a-f0-9]*"' "$WORK/a700-list.html" | sed 's/.*value="//;s/"//')" -d "ido=$A700_IDO"
A700_AFTER_LIFT=$(a700_blocked a700-en@example.com)
a700_php 'Kaleta\Core\SendRegister::record($db, "refused", $argv[1]);' 'a700-cs@example.com'
A700_IDO_CS=$(sq "SELECT ido FROM ka_odberatele WHERE email = 'a700-cs@example.com'")
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=subscribers"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=subscribers&action=lift" -d "_csrf=$(csrf)" -d "ido=$A700_IDO_CS"
expect "3.51 A2: an administrator lifts the bounce; a refusal stays (only the person's own confirmed sign-up ends it)" "$A700_AFTER_LIFT|$(a700_blocked a700-cs@example.com)" "none|refused"

# A3: the guard – a register address gets the confirmation e-mail at most once a week; confirming lifts the refusal
A700_MAILS0=$(a700_mails a700-cs@example.com)
sq "UPDATE ka_odberatele SET datum = '$(site_time '-1 hour')' WHERE email = 'a700-cs@example.com'" > /dev/null
a700_subscribe '' 'a700-cs@example.com' > /dev/null
A700_MAILS1=$(a700_mails a700-cs@example.com)
sq "UPDATE ka_odberatele SET datum = '$(site_time '-8 days')' WHERE email = 'a700-cs@example.com'" > /dev/null
A700_ANSWER=$(a700_subscribe '' 'a700-cs@example.com')
expect "3.51 A3: a second sign-up of a refused address within 7 days sends nothing (the same answer), after 7 days one e-mail" \
  "$((A700_MAILS1 - A700_MAILS0))|$(( $(a700_mails a700-cs@example.com) - A700_MAILS0 ))|$(a700_ok "$A700_ANSWER")" "0|1|ok"
sq "INSERT INTO ka_odberatele (email, token, datum) VALUES ('a700-new@example.com', SUBSTRING(SHA2(RAND(), 256), 1, 32), '$(site_time '-1 hour')')" > /dev/null
A700_NEW0=$(a700_mails a700-new@example.com)
a700_subscribe '' 'a700-new@example.com' > /dev/null
expect "3.51 A3: an address off the register is mailed again at once, as before 3.51" "$(( $(a700_mails a700-new@example.com) - A700_NEW0 ))" "1"
A700_TOKEN_CS=$(sq "SELECT token FROM ka_odberatele WHERE email = 'a700-cs@example.com'")
curl -s -o "$WORK/response" "$B/odber?confirm=$A700_TOKEN_CS"
A700_GET=$(a700_blocked a700-cs@example.com)
curl -s -o /dev/null -X POST "$B/odber?confirm=$A700_TOKEN_CS"
expect "3.51 A3: opening the confirmation link changes nothing; confirming it subscribes and ends the refusal" "$A700_GET|$(sq "SELECT stav FROM ka_odberatele WHERE email = 'a700-cs@example.com'")|$(a700_blocked a700-cs@example.com)" "refused|1|none"

# the suite shares one database: what this section made goes, the settings come back
sq "DELETE FROM ka_odberatele WHERE email IN ('a700-en@example.com', 'a700-cs@example.com', 'a700-new@example.com'); DELETE FROM ka_odber_fronta WHERE email LIKE 'a700-%'" > /dev/null
for A700_E in a700-en@example.com a700-cs@example.com a700-new@example.com; do a700_php 'Kaleta\Core\SendRegister::lift($db, "bounced", $argv[1]); Kaleta\Core\SendRegister::lift($db, "refused", $argv[1]); Kaleta\Core\RestoreLedger::forget($db, "unsubscribed", $argv[1]);' "$A700_E"; done
features --exact "$A700_EXT"
if [ "${A700_LANGS%%|*}" = 0 ]; then sq "DELETE FROM ka_nastaveni WHERE promenna = 'additional_languages'" > /dev/null; else sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('additional_languages', '${A700_LANGS#*|}')" > /dev/null; fi
rm -f "$WORK"/web/storage/cache/stranky/*.html "$WORK/a700-list.html" "$WORK/a700.csv"
