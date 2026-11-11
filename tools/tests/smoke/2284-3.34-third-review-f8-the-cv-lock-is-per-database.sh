echo "== 3.34 third review F8: the lock of the CV work carries the database – a site on the same server with the same prefix does not hold it"
r334_on
# another database on this server (any other – information_schema) holds the lock its own name gives for the prefix ka_
( "${MYSQL[@]}" information_schema -N -e "SELECT GET_LOCK(CONCAT('kaleta_cv_', LEFT(SHA2(CONCAT(DATABASE(), '|', 'ka_'), 256), 40)), 0), SLEEP(4)" > /dev/null 2>&1 ) & F8_HOLD=$!
sleep 1
expect "3.34 third review F8: a lock of another database with the same prefix does not stop this site's CV work" \
  "$(t334_php '$ok = Kaleta\Core\Applications::lockFiles($app->db(), 0); if ($ok) { Kaleta\Core\Applications::unlockFiles($app->db()); } echo $ok ? "free" : "held";')" "free"
wait "$F8_HOLD" 2>/dev/null || true
r334_off
