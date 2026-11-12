echo "== 3.41 b: the remaining low findings of the deep security audit (N113–N123)"
s341_csrf() { grep -o 'name="_csrf" value="[a-f0-9]*"' "$1" | head -1 | sed 's/.*value="//;s/"//' || true; }
s341_php() { (cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = Kaleta\Core\App::boot(); $app->applyTimezone(); '"$1"); }

# N117: a failed sign-in logs the name of an existing account only – never what was typed into the name field
S341_JAR="$WORK/jar-s341"; curl -s -c "$S341_JAR" -o "$WORK/response" "$B/admin.php"; S341_TOKEN=$(s341_csrf "$WORK/response")
S341_LOG0=$(sq "SELECT IFNULL(MAX(idp), 0) FROM ka_protokol")
curl -s -b "$S341_JAR" -c "$S341_JAR" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$S341_TOKEN" --data-urlencode "user=Typed-Secret-S341" -d password=whatever-s341
curl -s -b "$S341_JAR" -c "$S341_JAR" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$S341_TOKEN" -d user=admin -d password=wrong-password-s341
expect "3.41 N117: a failed sign-in logs 'unknown account' for a name no account has and the account's name for an existing one" \
  "$(sq "SELECT GROUP_CONCAT(popis ORDER BY idp SEPARATOR '|') FROM ka_protokol WHERE idp > $S341_LOG0 AND modul = 'prihlaseni' AND akce = 'neuspech'")|$(sq "SELECT COUNT(*) FROM ka_protokol WHERE popis LIKE '%Typed-Secret-S341%'")" \
  "unknown account|account: admin|0"
sq "DELETE FROM ka_kontrola_ip WHERE typ = 'login'; UPDATE ka_uzivatele SET pocet_chyb = 0, zamceno_do = NULL WHERE user = 'admin'" > /dev/null
