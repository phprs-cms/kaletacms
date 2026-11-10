echo "== 3.31.2 (N103): a failed automatic update is tried again later – three tries, an hour and twelve hours apart"
S3312_SITE_EMAIL=$(sq "SELECT IFNULL((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'site_email'), '')")
setting site_email s3312-updates@example.com; sq "DELETE FROM ka_posta WHERE komu = 's3312-updates@example.com'" > /dev/null
s3312_mails() { sq "SELECT COUNT(*) FROM ka_posta WHERE komu = 's3312-updates@example.com' AND predmet LIKE '%3.99.11%'"; }
s3312_source() { sq "INSERT INTO ka_nastaveni VALUES ('update_url','http://127.0.0.1:$CHANNEL_PORT/retry-bezpecnostni.json'), ('auto_updates', '1') ON DUPLICATE KEY UPDATE hodnota = VALUES(hodnota);
  UPDATE ka_nastaveni SET hodnota = '' WHERE promenna IN ('update_cache', 'update_attempt')" > /dev/null; }
s3312_source
expect "3.31.2 N103: the download fails – try 1 of 3, the next in an hour, the site e-mail is told" "$(s3312_updates)|$(s3312_shape)|$(s3312_mails)" "updates: failed 3.99.11 (try 1 of 3)|3.99.11|1|1h|1"
expect "3.31.2 N103: before its time the version is not tried again (nothing counted)" "$(s3312_updates)|$(s3312_shape)" "updates: checked recently|3.99.11|1|1h"
sq "UPDATE ka_nastaveni SET hodnota = '' WHERE promenna = 'update_cache'" > /dev/null
expect "3.31.2 N103: … also after a new look at the source" "$(s3312_updates)|$(s3312_shape)" "updates: version 3.99.11 waits for try 2|3.99.11|1|1h"
s3312_due
expect "3.31.2 N103: when its time comes it is tried again from the remembered manifest; the second failure waits twelve hours, without another e-mail" \
  "$(s3312_updates)|$(s3312_shape)|$(s3312_mails)" "updates: failed 3.99.11 (try 2 of 3)|3.99.11|2|12h|1"
s3312_due
expect "3.31.2 N103: the third failure is the last – the version is done with, the site e-mail is told, nothing is tried after it" \
  "$(s3312_updates)|$(s3312_attempt)|$(s3312_mails)|$(s3312_updates)|$(sq "UPDATE ka_nastaveni SET hodnota = '' WHERE promenna = 'update_cache'" > /dev/null; s3312_updates)|$(sq "SELECT COUNT(*) FROM ka_events WHERE type = 'update.failed' AND message LIKE '%3.99.11%'")" \
  "updates: failed 3.99.11|3.99.11|2|updates: checked recently|updates: version 3.99.11 available|0"
# two requests at once: the one that does not get the lock neither tries nor counts (no false failure)
sq "UPDATE ka_nastaveni SET hodnota = '3.99.11|1|1' WHERE promenna = 'update_attempt'" > /dev/null
S3312_LOCK=$(cd "$WORK/web" && php -r 'require "system/bootstrap.php"; echo Kaleta\Core\App::boot()->db()->lockName("update-attempt");')
( "${MYSQL[@]}" "$DB_NAME" -e "SELECT GET_LOCK('$S3312_LOCK', 0); SELECT SLEEP(6)" > /dev/null ) & S3312_LOCK_PID=$!
sleep 1.5
expect "3.31.2 N103: while another request holds the attempt, this one neither tries nor counts" "$(s3312_updates)|$(s3312_attempt)" "updates: version 3.99.11 is being handled|3.99.11|1|1"
wait "$S3312_LOCK_PID" 2>/dev/null || true
# the package reaches the server: the try that was waiting installs the version (in maintenance mode too, N102) and says so
cp "$WORK/kanal/retry-package.zip" "$WORK/kanal/retry.zip"; setting maintenance 1
sq "DELETE FROM ka_posta WHERE komu = 's3312-updates@example.com'" > /dev/null
expect "3.31.2 N103: the second try, an hour later, installs the security release – on a site in maintenance mode as well" \
  "$(s3312_updates)|$([ -f "$WORK/web/image/test-retry.txt" ] && echo installed)|$(grep -o "KALETA_VERSION = '[^']*'" "$WORK/web/system/bootstrap.php")|$(s3312_attempt)|$(s3312_mails)|$(sq "SELECT COUNT(*) FROM ka_events WHERE type = 'update.applied' AND data LIKE '%3.99.11%'")" \
  "updates: installed 3.99.11|installed|KALETA_VERSION = '3.99.11'|3.99.11|1|1"
# back to 3.99.9 for the rest of the test
setting maintenance 0; setting site_email "$S3312_SITE_EMAIL"
sed -i.bak "s/const KALETA_VERSION = '3.99.11';/const KALETA_VERSION = '3.99.9';/" "$WORK/web/system/bootstrap.php" && rm -f "$WORK/web/system/bootstrap.php.bak" "$WORK/web/image/test-retry.txt"
sq "DELETE FROM ka_events WHERE type LIKE 'update.%' AND (data LIKE '%3.99.11%' OR message LIKE '%3.99.11%'); DELETE FROM ka_posta WHERE komu = 's3312-updates@example.com';
  UPDATE ka_nastaveni SET hodnota = '0' WHERE promenna = 'auto_updates'; UPDATE ka_nastaveni SET hodnota = '' WHERE promenna IN ('update_cache', 'update_attempt')" > /dev/null

