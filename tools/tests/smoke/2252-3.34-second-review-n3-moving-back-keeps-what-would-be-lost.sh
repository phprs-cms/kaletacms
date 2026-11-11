echo "== 3.34 second review N3: moving back to Enquiries says first what moves and what stays – nothing the retention of enquiries would delete at once goes back"
r334_on
N3_OLD=$(site_time '-30 months'); N3_NOW=$(site_time '-2 days'); N3_POOL=$(site_date '+6 months')
N3_ENQ_MONTHS=$(sq "SELECT IFNULL(MAX(hodnota), '') FROM ka_nastaveni WHERE promenna = 'enquiries_months'")
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('enquiries_months', '24');
  INSERT INTO ka_applications (received_at, form, source, name, email, data, status, status_at, last_activity_at, pool_until) VALUES
  ('$N3_OLD', 'N3 back', 'stranka:1', 'Old N3', 'old-n3@example.com', '[]', 'reviewed', '$N3_OLD', '$N3_OLD', NULL),
  ('$N3_NOW', 'N3 back', 'stranka:1', 'Hired N3', 'hired-n3@example.com', '[]', 'hired', '$N3_NOW', '$N3_NOW', NULL),
  ('$N3_NOW', 'N3 back', 'stranka:1', 'Pool N3', 'pool-n3@example.com', '[]', 'rejected', '$N3_NOW', '$N3_NOW', '$N3_POOL'),
  ('$N3_NOW', 'N3 back', 'stranka:1', 'Fresh N3', 'fresh-n3@example.com', '[]', 'interview', '$N3_NOW', '$N3_NOW', NULL)" > /dev/null
expect "3.34 second review N3: the rule – too old for the retention of enquiries stays, hired and kept for future openings stay unless ticked, the rest moves" \
  "$(t334_php '$n = "2026-11-11 12:00:00"; $r = fn (array $row, bool $all): string => Kaleta\Core\ApplicationMove::backReason($row + ["source" => "stranka:1", "status" => "new", "pool_until" => null], 24, 6, ["kolekce:4"], $n, $all) ?? "moves";
    echo implode(",", [$r(["received_at" => "2024-01-01 10:00:00"], true), $r(["received_at" => "2026-10-01 10:00:00", "status" => "hired"], false), $r(["received_at" => "2026-10-01 10:00:00", "status" => "hired"], true),
      $r(["received_at" => "2026-10-01 10:00:00", "pool_until" => "2027-01-01"], false), $r(["received_at" => "2026-10-01 10:00:00", "pool_until" => "2026-01-01"], false),
      $r(["received_at" => "2026-03-01 10:00:00", "source" => "kolekce:4"], false), $r(["received_at" => "2026-10-01 10:00:00"], false)]);')" \
  "expired,kept,moves,kept,moves,expired,moves"
# without the review a post does not move anything – it lands on the review
N3_KEY="stranka:1|N3 back"
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=applications&action=move"
expect "3.34 second review N3: a post without the confirmation moves nothing and opens the review" \
  "$(curl -s -b "$JAR" -o /dev/null -w '%{http_code} %{redirect_url}' -X POST "$B/admin.php?module=applications&action=move_run" -d "_csrf=$(csrf)" -d direction=back --data-urlencode "form=$N3_KEY" | grep -c 'action=move&back=')|$(sq "SELECT COUNT(*) FROM ka_applications WHERE form = 'N3 back'")" "1|4"
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=applications&action=move&back=$(php -r 'echo rawurlencode($argv[1]);' "$N3_KEY")"
expect "3.34 second review N3: the review says how many move, how many stay and why (total|move|expired|kept|move with the box), that the status, its history and the consent are dropped, and offers the box" \
  "$(grep -o 'data-move-back-review="[^"]*"' "$WORK/response")|$(grep -c 'data-move-back-drops' "$WORK/response")|$(grep -c 'name="include_kept"' "$WORK/response")" \
  'data-move-back-review="4|1|1|2|3"|1|1'
curl -s -b "$JAR" -o /dev/null -X POST "$B/admin.php?module=applications&action=move_run" -d "_csrf=$(csrf)" -d direction=back -d confirmed=1 --data-urlencode "form=$N3_KEY"
expect "3.34 second review N3: confirmed – only the fresh one goes back; the old, the hired and the kept one stay applications" \
  "$(sq "SELECT GROUP_CONCAT(name ORDER BY name) FROM ka_applications WHERE form = 'N3 back'")|$(sq "SELECT COUNT(*) FROM ka_poptavky WHERE formular = 'N3 back' AND email = 'fresh-n3@example.com'")" "Hired N3,Old N3,Pool N3|1"
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=applications&action=move&back=$(php -r 'echo rawurlencode($argv[1]);' "$N3_KEY")"
curl -s -b "$JAR" -o /dev/null -X POST "$B/admin.php?module=applications&action=move_run" -d "_csrf=$(csrf)" -d direction=back -d confirmed=1 -d include_kept=1 --data-urlencode "form=$N3_KEY"
expect "3.34 second review N3: with the box ticked the hired and the kept one go too – the old one never (the retention would delete it at once)" \
  "$(sq "SELECT GROUP_CONCAT(name ORDER BY name) FROM ka_applications WHERE form = 'N3 back'")|$(sq "SELECT COUNT(*) FROM ka_poptavky WHERE formular = 'N3 back'")" "Old N3|3"
sq "DELETE FROM ka_comments WHERE target IN (SELECT CONCAT('enquiry:', idp) FROM ka_poptavky WHERE formular = 'N3 back'); DELETE FROM ka_poptavky WHERE formular = 'N3 back'; DELETE FROM ka_applications WHERE form = 'N3 back'" > /dev/null
if [ -n "$N3_ENQ_MONTHS" ]; then sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('enquiries_months', '$N3_ENQ_MONTHS')" > /dev/null; else sq "DELETE FROM ka_nastaveni WHERE promenna = 'enquiries_months'" > /dev/null; fi
r334_off
