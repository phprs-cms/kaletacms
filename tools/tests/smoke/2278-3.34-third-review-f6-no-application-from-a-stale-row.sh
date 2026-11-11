echo "== 3.34 third review F6: an enquiry deleted while a move waited never becomes an application"
r334_on
F6_AT=$(site_time '-2 days')
F6_E=$(sq "INSERT INTO ka_poptavky (datum, formular, zdroj, stranka, email, data, stav) VALUES ('$F6_AT', 'F6 form', 'stranka:1', '/kariera', 'f6@example.com', '[[\"Name\",\"F6\"]]', 0); SELECT LAST_INSERT_ID();")
F6_KEEP=$(sq "INSERT INTO ka_poptavky (datum, formular, zdroj, stranka, email, data, stav) VALUES ('$F6_AT', 'F6 form', 'stranka:1', '/kariera', 'f6-keep@example.com', '[[\"Name\",\"F6 keep\"]]', 0); SELECT LAST_INSERT_ID();")
sq "INSERT INTO ka_comments (target, author_name, text, via, created_at) VALUES ('enquiry:$F6_E', 'F6', 'a note', 'admin', '$F6_AT')" > /dev/null
# the move read both rows, then (while it waited for the lock) the first enquiry was deleted with its note
F6_OUT=$(t334_php '$rows = $app->db()->all("SELECT * FROM {poptavky} WHERE idp IN ('"$F6_E,$F6_KEEP"') ORDER BY idp");
  $app->db()->run("DELETE FROM {poptavky} WHERE idp = '"$F6_E"'"); Kaleta\Core\Comments::forgetEnquiries($app->db(), ['"$F6_E"']);
  $r = (new ReflectionMethod(Kaleta\Core\ApplicationMove::class, "moveRows"))->invoke(null, $app, $rows); echo $r["moved"], "|", count($r["ids"]);')
expect "3.34 third review F6: the deleted one is rolled back and skipped, the other moves" \
  "$F6_OUT|$(sq "SELECT CONCAT_WS('|', (SELECT COUNT(*) FROM ka_applications WHERE source_enquiry_id = $F6_E), (SELECT COUNT(*) FROM ka_applications WHERE source_enquiry_id = $F6_KEEP), (SELECT COUNT(*) FROM ka_poptavky WHERE idp IN ($F6_E, $F6_KEEP)))")" \
  "1|1|0|1|0"
expect "3.34 third review F6: the move by form and of one enquiry read their rows under the lock; migration 0120 checks its delete too" \
  "$(grep -c 'F6: the rows are read under the lock' "$WORK/web/system/src/Core/ApplicationMove.php")|$(grep -c 'F6: read under the lock' "$WORK/web/system/src/Core/ApplicationMove.php")|$(grep -c "if (\$db->delete('poptavky', \['idp' => \$idp\]) !== 1) {" "$WORK/web/system/sql/migrace/0120-applications-move.php")" "1|1|1"
t334_php 'Kaleta\Core\Applications::delete($app, array_map("intval", array_column($app->db()->all("SELECT id FROM {applications} WHERE source_enquiry_id = '"$F6_KEEP"'"), "id")), false);' > /dev/null
r334_off
