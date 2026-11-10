echo "== 3.15: the admin menu shows how many things wait (owner: a new enquiry was invisible from the menu)"
sq "INSERT INTO ka_poptavky (datum, formular, stranka, email, data, stav) VALUES ('$(site_time)', 'Badge 315', '/kontakt', 'badge315@example.cz', '[]', 0)" > /dev/null
# N315-10: new spam is left out of the badge and the Dashboard, as the list hides it
sq "INSERT INTO ka_poptavky (datum, formular, stranka, email, data, stav, kategorie) VALUES ('$(site_time)', 'Badge 315', '/kontakt', 'badge315@example.cz', '[]', 0, 'spam')" > /dev/null
NEW315=$(sq "SELECT COUNT(*) FROM ka_poptavky WHERE stav = 0 AND kategorie <> 'spam'")
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=pages"
menu_badge315() { perl -0ne 'if (m{module=enquiries"[^>]*>(.*?)</a>}s) { my $a = $1; print $1 if $a =~ m{class="menu-pocet" aria-hidden="true">([0-9+]+)<}; }' "$WORK/response"; }
# 3.25: the text for screen readers is there twice – on the item and on its group's summary (shown while the group is folded)
expect "3.15: Enquiries in the menu carries the number of new enquiries (spam left out, N315-10), with a text for screen readers" \
  "$(menu_badge315)|$(grep -c 'class="navod-skryte"> – nové: ' "$WORK/response")|$(grep -c "<span class=\"menu-skupina-pocet\"><span class=\"menu-pocet\" aria-hidden=\"true\">$NEW315<" "$WORK/response")|$(sq "SELECT COUNT(*) FROM ka_poptavky WHERE stav = 0") " "$NEW315|2|1|$((NEW315 + 1)) "
sq "UPDATE ka_poptavky SET stav = 1 WHERE stav = 0" > /dev/null
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=pages"
expect "3.15: … and none once they have been opened" "$(menu_badge315)" ""
sq "DELETE FROM ka_poptavky WHERE email = 'badge315@example.cz'" > /dev/null

