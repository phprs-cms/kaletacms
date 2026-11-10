echo "== 2.13: social post drafts (Core\\SocialDrafts) – a person posts them, the site never does"
mcp create_news "{\"title\":\"Nová hala pro výrobu\",\"intro\":\"<p>Otevřeli jsme novou výrobní halu &amp; sklad.</p>\",\"category\":\"$CATEGORY\",\"tags\":\"nová hala F14, výroba F14, CNC stroje F14, čtvrtý F14\",\"image\":\"media/foto.jpg\",\"publish\":true}" > "$WORK/response"; SOC_NEWS=$(mcp_value id)
mcp get_social_drafts "{\"id\":$SOC_NEWS}" > "$WORK/response"
expect "social drafts: a news item published through Claude has a draft for Facebook and LinkedIn (the default), none for X" "$(mcp_value drafts 0 network)|$(mcp_value drafts 1 network)|$(mcp_value drafts 2)|$(mcp_value published)" "facebook|linkedin|null|1"
expect "social drafts: the tracked link (the statistics count utm campaigns) and the news image" "$(mcp_value drafts 0 link)|$(mcp_value drafts 1 link)|$(mcp_value drafts 0 image)" \
  "$B/novinky/nova-hala-pro-vyrobu?utm_source=facebook&utm_medium=social&utm_campaign=nova-hala-pro-vyrobu|$B/novinky/nova-hala-pro-vyrobu?utm_source=linkedin&utm_medium=social&utm_campaign=nova-hala-pro-vyrobu|$B/media/foto.jpg"
mcp_value drafts 0 text > "$WORK/draft.txt"
grep -q '^Nová hala pro výrobu$' "$WORK/draft.txt" && grep -q '^Otevřeli jsme novou výrobní halu & sklad\.$' "$WORK/draft.txt" && grep -q '^#NovaHalaF14 #VyrobaF14 #CncStrojeF14$' "$WORK/draft.txt" && tail -1 "$WORK/draft.txt" | grep -q 'utm_source=facebook' \
  && echo "  ok     social drafts: the text is the title, the lead as plain text, three hashtags from the tags and the link" || { echo "  CHYBA  Facebook draft text"; cat "$WORK/draft.txt"; ERRORS=$((ERRORS+1)); }
SOC_LINK=$(mcp_value drafts 0 link); curl -s -o "$WORK/social.html" -A 'Mozilla/5.0 (Windows NT 10.0) Chrome/120 Social' "$SOC_LINK" # a user agent not seen today = a new visitor
hit_from "$WORK/social.html" 'Mozilla/5.0 (Windows NT 10.0) Chrome/120 Social' --data-urlencode "query=?${SOC_LINK#*\?}" > /dev/null # 3.29: confirmed by the browser, with the page's query
expect "social drafts: a visit through the tracked link counts in the campaign statistics" "$(sq "SELECT COALESCE(SUM(navstevy), 0) > 0 FROM ka_stat_kampane WHERE kampan LIKE 'facebook / social / nova-hala-pro-vyrobu%'")" "1"
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=news&action=edit&id=$SOC_NEWS"
grep -q 'id="social-posts"' "$WORK/response" && [ "$(grep -o 'data-kopirovat="#social-text-[0-9]*"' "$WORK/response" | wc -l | tr -d ' ')" = 2 ] && grep -q 'action=social_posted' "$WORK/response" && ! grep -q 'action=social_suggest' "$WORK/response" \
  && echo "  ok     social drafts: the editor shows the panel with a Copy button per draft and Mark as posted; no assistant button while the assistant is off" || { echo "  CHYBA  social posts panel"; ERRORS=$((ERRORS+1)); }
SOC_FB=$(sq "SELECT id FROM ka_social_drafts WHERE idc = $SOC_NEWS AND network = 'facebook'")
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=news&action=social_save" -d "_csrf=$(csrf)" -d "id=$SOC_FB" --data-urlencode "text=Upravený text <b>bez HTML</b> $B/novinky/nova-hala-pro-vyrobu?utm_source=facebook&utm_medium=social&utm_campaign=nova-hala-pro-vyrobu"
expect "social drafts: a draft edited in the admin before copying (HTML stripped)" "$(sq "SELECT text LIKE 'Upravený text bez HTML http%' FROM ka_social_drafts WHERE id = $SOC_FB")" "1"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=news&action=social_posted" -d "_csrf=$(csrf)" -d "id=$SOC_FB" -d posted=1
expect "social drafts: marked as posted" "$(sq "SELECT copied_at IS NOT NULL FROM ka_social_drafts WHERE id = $SOC_FB")" "1"
check "social drafts: the news list links the drafts still waiting to be posted" 200 "/admin.php?module=news" "id=$SOC_NEWS#social-posts\"[^>]*>[^<]* (1)</a>"
SOC_LI=$(sq "SELECT id FROM ka_social_drafts WHERE idc = $SOC_NEWS AND network = 'linkedin'")
mcp update_social_draft "{\"id\":$SOC_LI,\"text\":\"Text od Clauda\"}" > "$WORK/response"
expect "social drafts: Claude polishes a draft with update_social_draft" "$(mcp_value draft text)|$(sq "SELECT text FROM ka_social_drafts WHERE id = $SOC_LI")" "Text od Clauda|Text od Clauda"
mcp create_news "{\"title\":\"Koncept bez příspěvků\",\"category\":\"$CATEGORY\"}" > "$WORK/response"; SOC_DRAFT_NEWS=$(mcp_value id)
mcp get_social_drafts "{\"id\":$SOC_DRAFT_NEWS}" > "$WORK/response"
expect "social drafts: an unpublished news item has none" "$(mcp_value published)|$(mcp_value drafts)|$(sq "SELECT COUNT(*) FROM ka_social_drafts WHERE idc = $SOC_DRAFT_NEWS")" "|[]|0"
sq "INSERT INTO ka_nastaveni VALUES ('social_networks', 'facebook,linkedin,x,instagram') ON DUPLICATE KEY UPDATE hodnota = VALUES(hodnota)" > /dev/null
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=news&action=new"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=news&action=save" -d "_csrf=$(csrf)" -d idc=0 --data-urlencode "titulek=Dlouhá novinka pro X" -d "tema=$(sq "SELECT idt FROM ka_kategorie WHERE jazyk = '' ORDER BY idt LIMIT 1")" -d "autor=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'admin'")" -d stav=vydany \
  --data-urlencode "uvod=<p>$(printf 'Otevřeli jsme novou výrobní halu s moderními stroji. %.0s' $(seq 1 12))</p>" --data-urlencode "stitky=hala F14, stroje F14"
SOC_X_NEWS=$(sq "SELECT idc FROM ka_novinky WHERE titulek = 'Dlouhá novinka pro X'")
expect "social drafts: publishing in the admin prepares a draft for each of the four chosen networks" "$(sq "SELECT GROUP_CONCAT(network ORDER BY network) FROM ka_social_drafts WHERE idc = $SOC_X_NEWS")" "facebook,instagram,linkedin,x"
mcp get_social_drafts "{\"id\":$SOC_X_NEWS}" > "$WORK/response"
X_LEN=$(mcp_value drafts 2 text | php -r 'echo mb_strlen(preg_replace("#https?://\S+#", str_repeat("x", 23), trim(stream_get_contents(STDIN))));')
[ "$X_LEN" -le 280 ] && [ "$X_LEN" -gt 240 ] && mcp_value drafts 2 text | grep -q '#HalaF14 #StrojeF14' && mcp_value drafts 2 text | tail -1 | grep -q "utm_source=x&utm_medium=social&utm_campaign=dlouha-novinka-pro-x$" \
  && echo "  ok     social drafts: the X draft fits 280 characters with the link counted as 23 ($X_LEN), hashtags and link whole" || { echo "  CHYBA  X draft ($X_LEN)"; mcp_value drafts 2 text; ERRORS=$((ERRORS+1)); }
mcp_value drafts 3 text > "$WORK/draft.txt"
! grep -q 'http' "$WORK/draft.txt" && grep -q 'Odkaz v biu\|Link in bio' "$WORK/draft.txt" && [ "$(mcp_value drafts 3 link)" = "$B/novinky/dlouha-novinka-pro-x?utm_source=instagram&utm_medium=social&utm_campaign=dlouha-novinka-pro-x" ] \
  && echo "  ok     social drafts: Instagram has no link in the text (link in bio), the tracked link waits for the bio" || { echo "  CHYBA  Instagram draft"; cat "$WORK/draft.txt"; ERRORS=$((ERRORS+1)); }
case "$(mcp_value drafts 0 image)" in "$B"/og/[a-f0-9]*.png) echo "  ok     social drafts: a news item without an image gets the picture the site draws (2.12)";; *) echo "  CHYBA  social drafts image without a news image: „$(mcp_value drafts 0 image)“"; ERRORS=$((ERRORS+1));; esac
mcp update_social_draft "{\"id\":$(sq "SELECT id FROM ka_social_drafts WHERE idc = $SOC_X_NEWS AND network = 'x'"),\"text\":\"$(printf 'a%.0s' $(seq 1 281))\"}" | contains -q 'X allows 280' && echo "  ok     social drafts: Claude cannot make an X draft longer than 280" || { echo "  CHYBA  update_social_draft X limit"; ERRORS=$((ERRORS+1)); }
curl -s -X POST "$B/mcp" -H "Authorization: Bearer $API_TOKEN" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' | contains -q '"name":"update_social_draft"' && echo "  ok     social drafts: the tools are listed" || { echo "  CHYBA  social tools missing in tools/list"; ERRORS=$((ERRORS+1)); }
sq "DELETE FROM ka_nastaveni WHERE promenna = 'social_networks'" > /dev/null
