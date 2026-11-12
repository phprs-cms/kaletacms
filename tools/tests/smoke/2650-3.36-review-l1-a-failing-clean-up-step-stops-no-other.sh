echo "== 3.36 review L1: a failing step of the daily clean-up keeps no other retention from running"
# a real database error in the applications (a view whose table is gone in the place of ka_applications, MySQL 1356) is
# logged and thrown on at the end – but the sign-ups awaiting confirmation for over 30 days still go
background_jobs hold # the jobs after a response would fail on the broken table too
l1php() { (cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); '"$1" -- "${@:2}"); }
L1_TOKEN=$(php -r 'echo bin2hex(random_bytes(16));')
sq "INSERT INTO ka_odberatele (email, stav, token, datum) VALUES ('l1-old@example.test', 0, '$L1_TOKEN', NOW() - INTERVAL 40 DAY)" > /dev/null
sq "RENAME TABLE ka_applications TO ka_l1_applications; CREATE TABLE ka_l1_gone (id INT); CREATE VIEW ka_applications AS SELECT id FROM ka_l1_gone; DROP TABLE ka_l1_gone" > /dev/null
L1_OUT=$(l1php 'try { Kaleta\Core\Notifications::purgePersonalData($app, true); echo "no error"; } catch (Throwable $e) { echo str_contains($e->getMessage(), "1356") ? "thrown" : "other: " . $e->getMessage(); }')
sq "DROP VIEW ka_applications; RENAME TABLE ka_l1_applications TO ka_applications" > /dev/null
expect "3.36 review L1: the error in the applications is thrown on at the end, the later step still removed the old unconfirmed sign-up, each failing step is logged (every step that reads the applications)" \
  "$L1_OUT|$(sq "SELECT COUNT(*) FROM ka_odberatele WHERE email = 'l1-old@example.test'")|$( [ "$(grep -c 'General error: 1356' "$WORK/web/storage/log/chyby.log" || true)" -ge 1 ] && echo logged)" "thrown|0|logged"
php -r '$f = $argv[1]; file_put_contents($f, implode("", array_filter(file($f) ?: [], fn ($r) => !str_contains($r, "General error: 1356"))));' "$WORK/web/storage/log/chyby.log"
sq "DELETE FROM ka_odberatele WHERE email = 'l1-old@example.test'" > /dev/null
background_jobs resume
