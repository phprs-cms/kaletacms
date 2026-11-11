echo "== 3.34 review low (c): a CV that cannot be stored fails the application – nothing is kept, the applicant is told"
r334_on
LC_BEFORE=$(sq "SELECT COUNT(*) FROM ka_applications")
mkdir -p "$WORK/web/storage/applications/$(date +%Y)/$(date +%m)"; chmod 0500 "$WORK/web/storage/applications/$(date +%Y)/$(date +%m)" "$WORK/web/storage/applications"
LC_OUT=$(t334_php '$tmp = tempnam(sys_get_temp_dir(), "lc"); file_put_contents($tmp, "%PDF-1.4 CV LC");
  try { Kaleta\Core\Applications::receive($app, ["id" => "lc", "obsah" => ["nazev" => "LC form"]], "stranka:1", "/kariera", [["CV", "cv.pdf (1 kB)"]], "lc@example.com", [0 => [$tmp, "pdf", "cv.pdf", 14]], false); echo "stored"; }
  catch (RuntimeException $e) { echo "refused"; }' 2>&1)
chmod 0770 "$WORK/web/storage/applications/$(date +%Y)/$(date +%m)" "$WORK/web/storage/applications"
if [ "$LC_OUT" = "stored" ] && [ "$(id -u)" = 0 ]; then echo "  ok     3.34 review low (c): (skipped – root writes into a read-only folder)"; sq "DELETE FROM ka_applications WHERE form = 'LC form'" > /dev/null
else expect "3.34 review low (c): the storage cannot be written – receive() refuses and stores no application" "$LC_OUT|$(sq "SELECT COUNT(*) FROM ka_applications")" "refused|$LC_BEFORE"; fi
expect "3.34 review low (c): the form says why (result ulozeni), and more files than an application keeps are refused (result soubory)" \
  "$(t334_php 'echo (int) (Kaleta\Builder\Elements\Form::messages("ulozeni") !== Kaleta\Builder\Elements\Form::messages("")), (int) str_contains(Kaleta\Builder\Elements\Form::messages("soubory"), "3");')" "11"
r334_off
