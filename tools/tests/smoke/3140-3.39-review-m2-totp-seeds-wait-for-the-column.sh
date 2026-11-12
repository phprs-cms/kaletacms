echo "== 3.39 review M2: a TOTP seed is sealed only once the widened column is in place"
m2_php() { (cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); $app->applyTimezone(); $a = array_slice($argv, 1); '"$1" -- "${@:2}"); }
if [ "$(sq "SELECT COUNT(*) FROM ka_uzivatele WHERE CHAR_LENGTH(totp_tajemstvi) > 64")" = "0" ]; then
  # the old VARCHAR(64) column (0126 not applied): a new seed and the security task keep seeds plain, so nothing is cut off
  sq "ALTER TABLE ka_uzivatele MODIFY totp_tajemstvi VARCHAR(64) NOT NULL DEFAULT ''; UPDATE ka_uzivatele SET totp_tajemstvi = 'JBSWY3DPEHPK3PXP' WHERE user = 'autor'" > /dev/null
  run_job security > /dev/null
  expect "3.39 review M2: with the old 64-character column a new seed and the security task leave seeds plain" \
    "$(m2_php 'echo Kaleta\Core\Vault::sealSeed($app->db(), $app->settings(), "JBSWY3DPEHPK3PXP");')|$(sq "SELECT totp_tajemstvi FROM ka_uzivatele WHERE user = 'autor'")" \
    "JBSWY3DPEHPK3PXP|JBSWY3DPEHPK3PXP"
  sq "ALTER TABLE ka_uzivatele MODIFY totp_tajemstvi VARCHAR(255) NOT NULL DEFAULT ''" > /dev/null
  run_job security > /dev/null
  expect "3.39 review M2: … with the widened column both seal" \
    "$(m2_php 'echo substr(Kaleta\Core\Vault::sealSeed($app->db(), $app->settings(), "JBSWY3DPEHPK3PXP"), 0, 14);')|$(sq "SELECT LEFT(totp_tajemstvi, 14) FROM ka_uzivatele WHERE user = 'autor'")" \
    "kaleta-sealed:|kaleta-sealed:"
  sq "UPDATE ka_uzivatele SET totp_tajemstvi = '' WHERE user = 'autor'" > /dev/null
else
  echo "  FAIL   3.39 review M2: an account already has a sealed seed, the column cannot be narrowed for the test"; ERRORS=$((ERRORS+1))
fi
