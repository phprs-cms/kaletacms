echo "== 3.39 B (N106): secret settings and TOTP seeds sealed with the key in config.php"
n106_raw() { sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = '$1'"; }
n106_php() { (cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); $app->applyTimezone(); $a = array_slice($argv, 1); '"$1" -- "${@:2}"); }
N106_PW="pw-$(tok)"
# a password typed in Settings → Mail is stored sealed; the site reads it, the page never shows it
mail_form -d smtp_provider=other -d smtp_host=127.0.0.1 -d "smtp_port=$SMTP_PORT" -d smtp_encryption=zadne -d smtp_user= -d "smtp_password=$N106_PW"
N106_RAW=$(n106_raw smtp_password)
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=mail"
expect "3.39 N106: a typed SMTP password is stored sealed (marker, no plain text), the site reads it and the form never prints it" \
  "$([ "${N106_RAW#kaleta-sealed:}" != "$N106_RAW" ] && echo sealed)|$(printf '%s' "$N106_RAW" | grep -q -- "$N106_PW" && echo plain || echo hidden)|$([ "$(setting_plain smtp_password)" = "$N106_PW" ] && echo read)|$(grep -c "$N106_PW" "$WORK/response")" "sealed|hidden|read|0"
# a local backup holds the sealed value only
n106_php 'Kaleta\Core\Backup::create($app->db(), "n106", false);' > /dev/null 2>&1; N106_BACKUP=$(ls -t "$WORK/web/storage/zalohy/" | grep -m1 'n106')
expect "3.39 N106: a local backup holds the password only sealed" \
  "$(gzip -dcf "$WORK/web/storage/zalohy/$N106_BACKUP" 2>/dev/null | grep -c "$N106_PW")|$([ "$(gzip -dcf "$WORK/web/storage/zalohy/$N106_BACKUP" 2>/dev/null | grep -c 'kaleta-sealed:')" -gt 0 ] && echo sealed)" "0|sealed"
rm -f "$WORK/web/storage/zalohy/$N106_BACKUP"
sq "UPDATE ka_nastaveni SET hodnota = '' WHERE promenna = 'smtp_password'" > /dev/null
# a value written in plain text by older code: System status warns, the daily security task seals it, the site still reads it
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('backup_password', 'legacy-plain-106')" > /dev/null
check "3.39 N106: System status names a secret still in plain text" 200 "/admin.php?module=status" 'denní bezpečnostní úloha je zašifruje klíčem z config.php'
N106_JOB=$(run_job security)
expect "3.39 N106: the daily security task seals it and the site reads the same value" \
  "$(printf '%s' "$N106_JOB" | grep -q 'secrets encrypted ' && echo job || echo "$N106_JOB")|$(n106_raw backup_password | cut -c1-14)|$(setting_plain backup_password)" "job|kaleta-sealed:|legacy-plain-106"
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=status"
expect "3.39 N106: … then System status no longer names a secret in plain text" "$(grep -c 'denní bezpečnostní úloha je zašifruje' "$WORK/response")" "0"
# a TOTP seed: sealed by the same task, and the code from the app still signs in
sq "UPDATE ka_uzivatele SET totp_tajemstvi = 'JBSWY3DPEHPK3PXP', pocet_chyb = 0, zamceno_do = NULL WHERE user = 'autor'; DELETE FROM ka_kontrola_ip WHERE typ IN ('login', 'totp')" > /dev/null
run_job security > /dev/null
N106_AUTHOR=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'autor'")
N106_CODE=$(php -r 'require $argv[1] . "/system/src/Core/Totp.php"; echo Kaleta\Core\Totp::code("JBSWY3DPEHPK3PXP", intdiv(time(), 30));' -- "$ROOT")
expect "3.39 N106: a stored TOTP seed is sealed (no plain seed in the database) and a code from the app still signs in" \
  "$(sq "SELECT LEFT(totp_tajemstvi, 14), LOCATE('JBSWY3DPEHPK3PXP', totp_tajemstvi) FROM ka_uzivatele WHERE user = 'autor'" | tr '\t' '/')|$(totp_plain "$N106_AUTHOR")|$(sign_in_2fa "$WORK/jar106" "$N106_CODE")" "kaleta-sealed:/0|JBSWY3DPEHPK3PXP|302"
# a database without its key (vault_check of another config.php): the site keeps working, the secrets read as empty, System status says why
N106_CHECK=$(n106_raw vault_check)
sq "UPDATE ka_nastaveni SET hodnota = '$(printf '0%.0s' $(seq 1 32))' WHERE promenna = 'vault_check'" > /dev/null
check "3.39 N106: without the key System status explains that the stored passwords cannot be read" 200 "/admin.php?module=status" 'nejdou přečíst – byla zašifrovaná klíčem, který tento config.php nemá'
expect "3.39 N106: … the site keeps running and a sealed secret reads as empty" "$(curl -s -o /dev/null -w '%{http_code}' "$B/")|$(setting_plain backup_password)" "200|"
sq "UPDATE ka_nastaveni SET hodnota = '$N106_CHECK' WHERE promenna = 'vault_check'; UPDATE ka_nastaveni SET hodnota = '' WHERE promenna = 'backup_password'; UPDATE ka_uzivatele SET totp_tajemstvi = '', pocet_chyb = 0 WHERE user = 'autor'; DELETE FROM ka_kontrola_ip WHERE typ IN ('login', 'totp')" > /dev/null
