echo "== 3.39 B (N112): reactivating a blocked account revokes its Claude connections and tokens – unless the administrator keeps them"
N112_TOKEN="kaleta_$(php -r 'echo bin2hex(random_bytes(24));')"
N112_CLIENT=$(printf 'b%.0s' $(seq 1 32))
n112_tokens() { sq "INSERT INTO ka_api_tokeny (idu, nazev, otisk, vytvoren) VALUES ($N112_U, 'N112 token', SHA2('$N112_TOKEN', 256), '$(site_time)');
  INSERT INTO ka_api_tokeny (idu, nazev, otisk, vytvoren, klient, druh, expirace) VALUES ($N112_U, 'N112 app', SHA2('app-$N112_TOKEN', 256), '$(site_time)', '$N112_CLIENT', 'obnova', '$(site_time '+1 year')')" > /dev/null; }
n112_count() { sq "SELECT COUNT(*) FROM ka_api_tokeny WHERE idu = $N112_U"; }
n112_mcp() { [ "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$B/mcp" -H "Authorization: Bearer $N112_TOKEN" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}')" = 401 ] && echo refused || echo accepted; }
sq "INSERT INTO ka_uzivatele (user, password, jmeno, email, admin, blokovat, potvrzeno) VALUES ('n112-user', '!', 'Blocked N112', 'n112@example.cz', 1, 1, '$(site_time)')" > /dev/null
N112_U=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'n112-user'"); N112_U="${N112_U:-0}"
n112_tokens
expect "3.39 N112: a blocked account's token is refused, but it is still there" "$(n112_mcp)|$(n112_count)" "refused|2"
check "3.39 N112: the form of a blocked account offers to revoke its connections on unblocking, ticked" 200 "/admin.php?module=users&action=edit&id=$N112_U" 'name="zrusit_pripojeni" value="1" checked'
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=users&action=reactivate" -d "_csrf=$(csrf)" -d "idu=$N112_U" -d user=n112-user
expect "3.39 N112: Reactivate unblocks the account and revokes what it had – the old token does not come back to life" "$(sq "SELECT blokovat FROM ka_uzivatele WHERE idu = $N112_U")|$(n112_count)|$(n112_mcp)" "0|0|refused"
# blocked again with tokens: the form's box unticked keeps them on purpose
sq "UPDATE ka_uzivatele SET blokovat = 1 WHERE idu = $N112_U" > /dev/null; n112_tokens
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=users&action=edit&id=$N112_U"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=users&action=save" -d "_csrf=$(csrf)" -d "idu=$N112_U" -d user=n112-user -d "jmeno=Blocked N112" -d email=n112@example.cz -d admin=1 -d zrusit_pripojeni=0
expect "3.39 N112: unblocking in the form with the box unticked keeps the connections" "$(sq "SELECT blokovat FROM ka_uzivatele WHERE idu = $N112_U")|$(n112_count)|$(n112_mcp)" "0|2|accepted"
# and ticked (the default) revokes them
sq "UPDATE ka_uzivatele SET blokovat = 1 WHERE idu = $N112_U" > /dev/null
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=users&action=edit&id=$N112_U"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=users&action=save" -d "_csrf=$(csrf)" -d "idu=$N112_U" -d user=n112-user -d "jmeno=Blocked N112" -d email=n112@example.cz -d admin=1 -d zrusit_pripojeni=0 -d zrusit_pripojeni=1
expect "3.39 N112: … ticked, unblocking revokes them and the change log says so" "$(sq "SELECT blokovat FROM ka_uzivatele WHERE idu = $N112_U")|$(n112_count)|$(sq "SELECT COUNT(*) FROM ka_protokol WHERE akce = 'unblock_revoke'")" "0|0|2"
sq "DELETE FROM ka_uzivatele_prava WHERE fk_id_user = $N112_U; DELETE FROM ka_uzivatele WHERE idu = $N112_U" > /dev/null
