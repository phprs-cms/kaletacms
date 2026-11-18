echo "== 3.46.1 review N1, N4: the addresses a restore checks are read in batches within a small memory limit; a source that cannot be read is skipped, the others are still read"
r202_php() { (cd "$WORK/web" && php -d memory_limit="${R202_LIMIT:-128M}" -r 'require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); $app->applyTimezone(); $db = $app->db(); $s = $app->settings(); $a = array_slice($argv, 1); '"$1" -- "${@:2}"); }
R202_NOW=$(site_time)

# --- N1: 20 000 enquiries of about 2 kB each (40 MB of fields) – a restore reads them in batches and keeps none of them
sq "INSERT INTO ka_poptavky (datum, formular, email, data)
  WITH RECURSIVE n (i) AS (SELECT 1 UNION ALL SELECT i + 1 FROM n WHERE i < 20000)
  SELECT '$R202_NOW', 'R202 form', '', CONCAT('[[\"Message\",\"', REPEAT('x', 2000), '\"],[\"E-mail\",\"r202-', i, '@example.cz\"]]') FROM n" > /dev/null 2>&1 \
  || sq "SET SESSION cte_max_recursion_depth = 30000; INSERT INTO ka_poptavky (datum, formular, email, data)
  WITH RECURSIVE n (i) AS (SELECT 1 UNION ALL SELECT i + 1 FROM n WHERE i < 20000)
  SELECT '$R202_NOW', 'R202 form', '', CONCAT('[[\"Message\",\"', REPEAT('x', 2000), '\"],[\"E-mail\",\"r202-', i, '@example.cz\"]]') FROM n" > /dev/null
R202_OUT=$(R202_LIMIT=48M r202_php '$base = memory_get_usage(); $count = 0; $last = "";
  foreach (Kaleta\Core\PersonalData::candidates($db) as $email) { if (str_starts_with($email, "r202-")) { $count++; $last = $email; } }
  echo $count, "|", $last, "|", memory_get_peak_usage() - $base < 16 * 1024 * 1024 ? "small" : "large:" . (memory_get_peak_usage() - $base);' 2>&1 | tail -1)
expect "3.46.1 review N1: every one of the 20 000 field addresses is handed out, within 48M and with the peak a few megabytes above the start" \
  "$R202_OUT" "20000|r202-20000@example.cz|small"

# --- N4: a column a migration has not added (a half-restored older backup) – that source is skipped and logged, the rest still read
R202_LOG="$WORK/web/storage/log/chyby.log"; R202_SIZE=$(php -r 'clearstatcache(); echo is_file($argv[1]) ? filesize($argv[1]) : 0;' -- "$R202_LOG")
sq "ALTER TABLE ka_quotes RENAME COLUMN customer_email TO r202_customer_email" > /dev/null
R202_N4=$(r202_php '$count = 0; foreach (Kaleta\Core\PersonalData::candidates($db) as $email) { $count += (int) str_starts_with($email, "r202-"); } echo $count;' 2>&1 | tail -1)
sq "ALTER TABLE ka_quotes RENAME COLUMN r202_customer_email TO customer_email" > /dev/null
R202_LOGGED=$(php -r 'clearstatcache(); $f = $argv[1]; $n = (int) $argv[2]; $new = is_file($f) ? (string) substr((string) file_get_contents($f), $n) : ""; echo str_contains($new, "r202_customer_email") || str_contains($new, "customer_email") ? "logged" : "silent"; if (is_file($f)) { $h = fopen($f, "r+"); ftruncate($h, $n); fclose($h); }' -- "$R202_LOG" "$R202_SIZE")
expect "3.46.1 review N4: with a column missing in one table, the addresses of the enquiries are still read (no exception) and the error is logged" "$R202_N4|$R202_LOGGED" "20000|logged"

# clean up
sq "DELETE FROM ka_poptavky WHERE formular = 'R202 form'" > /dev/null
