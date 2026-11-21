echo "== 3.50 C: the role form grouped like the menu (U53), the short form of a new page (U56), the deal page says its stage once (U72), the agency logo picker (CPO 11)"
c350_get() { curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -w '%{http_code}' "$B/admin.php?$1"; }

# U53: the same boxes, now in a group per menu group, the level under its own legend
expect "3.50 C (U53): the role form – the boxes in groups like the menu (Content first), the same moduly[] values as before, the level under its own legend" \
  "$(c350_get 'module=roles&action=new')|$(grep -o 'data-role-group="[a-z-]*"' "$WORK/response" | head -1)|$(grep -c 'name="moduly\[\]"' "$WORK/response" | awk '{print ($1 >= 15)}')|$(grep -cE '<legend>(What the role may do with content|Co role smí s obsahem)</legend>' "$WORK/response")|$(grep -cE '<legend>(News|Novinky)</legend>' "$WORK/response")" \
  "200|data-role-group=\"content\"|1|1|0"

# U56: a new page shows the title and the start choice; the rest folds under More settings, open when ?parent= gives a value
expect "3.50 C (U56): New page folds Parent, URL, Display and the rest under More settings (closed); a parent from the link opens it; editing a page has no fold" \
  "$(c350_get 'module=pages&action=new')|$(grep -c '<details class="pokrocile" data-more-settings>' "$WORK/response")|$(sed -n '/data-more-settings/,/class="tlacitka"/p' "$WORK/response" | grep -c 'id="nadrazena"\|id="seo_link"\|name="zobrazit"\|id="poradi"')|$(c350_get "module=pages&action=new&parent=$(sq "SELECT MIN(ids) FROM ka_stranky WHERE smazano IS NULL")" > /dev/null; grep -c 'data-more-settings open' "$WORK/response")|$(c350_get "module=pages&action=edit&id=$(sq "SELECT MIN(ids) FROM ka_stranky WHERE smazano IS NULL")" > /dev/null; grep -c 'data-more-settings' "$WORK/response")" \
  "200|1|4|1|0"

# U72: an open deal says its stage once (Move's select), the responsible person only in its panel; a won deal keeps a coloured mark
C350_NOW=$(site_time)
sq "INSERT INTO ka_deals (title, stage, created_at, updated_at) VALUES ('Deal C350 open', 'new', '$C350_NOW', '$C350_NOW');
  INSERT INTO ka_deals (title, stage, closed_at, created_at, updated_at) VALUES ('Deal C350 won', 'won', '$C350_NOW', '$C350_NOW', '$C350_NOW')" > /dev/null
C350_DEAL=$(sq "SELECT id FROM ka_deals WHERE title = 'Deal C350 open'"); C350_WON=$(sq "SELECT id FROM ka_deals WHERE title = 'Deal C350 won'")
expect "3.50 C (U72): the deal page – no stage pill next to Move on an open deal, one Responsible person field (the panel), a won deal keeps its mark" \
  "$(c350_get "module=pipeline&action=detail&id=$C350_DEAL")|$(grep -c 'data-deal-closed' "$WORK/response")|$(grep -c 'data-deal-stage="new"' "$WORK/response")|$(grep -c 'name="owner_id"' "$WORK/response")|$(c350_get "module=pipeline&action=detail&id=$C350_WON" > /dev/null; grep -c 'data-deal-closed' "$WORK/response")|$(c350_get "module=pipeline&action=new" > /dev/null; grep -c 'name="owner_id"' "$WORK/response")" \
  "200|0|1|1|1|1"
sq "DELETE FROM ka_deal_stage_history WHERE deal_id IN ($C350_DEAL, $C350_WON); DELETE FROM ka_deals WHERE id IN ($C350_DEAL, $C350_WON)" > /dev/null

# CPO 11: the agency logo is picked from Media; the stored value keeps its form (media/…), a foreign address is still refused
expect "3.50 C (CPO 11): Settings → Agency – the logo field has the Media picker (data-obrazek=\"cesta\")" \
  "$(c350_get 'module=settings&tab=agency')|$(grep -c 'name="agency_logo"[^>]*data-obrazek="cesta"' "$WORK/response")" "200|1"
