echo "== 3.51 R0: the send register writes through the restore ledger (fingerprints only), lift takes one kind off, forget() leaves it"
r791_php() { (cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); $db = $app->db(); $e = $argv[2];
  echo match ($argv[1]) {
    "record-bounced" => (Kaleta\Core\SendRegister::record($db, "bounced", $e) ?? "done"),
    "record-refused" => (Kaleta\Core\SendRegister::record($db, "refused", $e) ?? "done"),
    "record-other" => (Kaleta\Core\SendRegister::record($db, "erased", $e) ?? "done"),
    "forget" => (Kaleta\Core\RestoreLedger::forget($db, "bounced", $e) ?? "done"),
    "lift" => (Kaleta\Core\SendRegister::lift($db, "bounced", $e) ?? "done"),
    "blocked" => (Kaleta\Core\SendRegister::blocked($db, $e) ?? "none"),
    "counts" => json_encode(Kaleta\Core\SendRegister::counts($db)),
  };' -- "$1" "${2:-}"); }
r791_rows() { sq "SELECT COUNT(*) FROM ka_restore_ledger WHERE kind IN ('bounced', 'refused')"; }
R791_ROWS=$(r791_rows); R791_ERASED=$(sq "SELECT COUNT(*) FROM ka_restore_ledger WHERE kind = 'erased'")
r791_php record-bounced 'R791-Dead@Example.com' > /dev/null
r791_php record-bounced 'r791-dead@example.com' > /dev/null
r791_php record-refused 'r791-no@example.com' > /dev/null
r791_php record-other 'r791-other@example.com' > /dev/null
expect "3.51 R0.4: an address recorded twice (any case) is one row of 64 hex characters, another kind is not taken through the register; blocked() reads both kinds" \
  "$(( $(r791_rows) - R791_ROWS ))|$(sq "SELECT COUNT(*) FROM ka_restore_ledger WHERE kind IN ('bounced', 'refused') AND hash NOT REGEXP '^[0-9a-f]{64}$'")|$(sq "SELECT COUNT(*) FROM ka_restore_ledger WHERE kind = 'erased'")|$(r791_php blocked 'r791-dead@example.com')|$(r791_php blocked 'r791-no@example.com')" \
  "2|0|$R791_ERASED|bounced|refused"
r791_php forget 'r791-dead@example.com' > /dev/null
R791_KEPT=$(r791_php blocked 'r791-dead@example.com')
r791_php lift 'r791-dead@example.com' > /dev/null
expect "3.51 R0.4: RestoreLedger::forget() leaves the bounce; SendRegister::lift() takes it off" "$R791_KEPT|$(r791_php blocked 'r791-dead@example.com')" "bounced|none"
# the suite shares one database: the register rows this section made go
sq "DELETE FROM ka_restore_ledger WHERE kind IN ('bounced', 'refused') AND created_at >= '$(site_time '-1 hour')'" > /dev/null
