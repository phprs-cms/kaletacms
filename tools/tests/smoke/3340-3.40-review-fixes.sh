echo "== 3.40 review: the fixes of the security review of quote PDFs, quotes II and applications II"
r340_php() { (cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = Kaleta\Core\App::boot(); $app->applyTimezone(); '"$1"); }

# M1: a mangled talent-pool link (a trailing dot or bracket) is a 404 that is never kept among the addresses not found
R340_TOKEN=$(php -r 'echo bin2hex(random_bytes(32));')
for r340_suffix in '.' ')' '/'; do curl -s -o /dev/null "$B/application-consent/$R340_TOKEN$r340_suffix"; done
curl -s -o /dev/null "$B/en/Application-Consent/$R340_TOKEN."
expect "3.40 review M1: no mangled /application-consent/ address is kept among the addresses not found" \
  "$(sq "SELECT COUNT(*) FROM ka_nenalezeno WHERE cesta LIKE '%$R340_TOKEN%'")" "0"
