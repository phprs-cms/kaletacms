echo "== 3.50 A1 (N154): a file with an imported name is deleted with its row; a file that stays keeps its row and says so"
# an import of a Kaleta export keeps the archive's names (media/<folder>/Price_list.pdf); before 3.50 Media deleted the row
# and left the file on the disk, out of anyone's reach
mkdir -p "$WORK/web/media/a350-import"
printf '%%PDF-1.4 a350' > "$WORK/web/media/a350-import/Price_list.pdf"
printf '%%PDF-1.4 outside' > "$WORK/a350-outside.pdf"
ln -sf "$WORK/a350-outside.pdf" "$WORK/web/media/a350-import/Linked.pdf"
A350_NOW=$(site_time)
A350_PDF=$(sq "INSERT INTO ka_media (obr_poloha, nazev, datum) VALUES ('media/a350-import/Price_list.pdf', 'Price list', '$A350_NOW'); SELECT LAST_INSERT_ID();")
A350_LINK=$(sq "INSERT INTO ka_media (obr_poloha, nazev, datum) VALUES ('media/a350-import/Linked.pdf', 'Linked', '$A350_NOW'); SELECT LAST_INSERT_ID();")
check "3.50 N154: Media lists the imported files" 200 "/admin.php?module=media&action=cleanup" "value=\"$A350_PDF\""
TOKEN=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=media&action=bulk" -d "_csrf=$TOKEN" -d provest=smaz -d zpet=cleanup -d "oznacene[]=$A350_PDF" -d "oznacene[]=$A350_LINK"
check "3.50 N154: the file that stayed on the disk is named in a message" 200 "/admin.php?module=media&action=cleanup" "could not be removed from the disk\|nepodařilo"
expect "3.50 N154: the imported file is gone with its row; a link out of media/ is not followed – it, its target and its row stay" \
  "$([ -e "$WORK/web/media/a350-import/Price_list.pdf" ] && echo file || echo gone)|$(sq "SELECT COUNT(*) FROM ka_media WHERE ido = $A350_PDF")|$([ -L "$WORK/web/media/a350-import/Linked.pdf" ] && echo link)|$([ -f "$WORK/a350-outside.pdf" ] && echo target)|$(sq "SELECT COUNT(*) FROM ka_media WHERE ido = $A350_LINK")" \
  "gone|0|link|target|1"
a350_mcp delete_media "{\"id\":$A350_LINK}" > "$WORK/response"
expect "3.50 N154 MCP: delete_media refuses with the reason and keeps the row while the file stays" \
  "$(contains -q 'could not be removed from the disk' "$WORK/response" && echo said)|$(sq "SELECT COUNT(*) FROM ka_media WHERE ido = $A350_LINK")" "said|1"
rm -f "$WORK/web/media/a350-import/Linked.pdf"
a350_mcp delete_media "{\"id\":$A350_LINK}" > "$WORK/response"
expect "3.50 N154 MCP: once the file is gone the row goes too" "$(sq "SELECT COUNT(*) FROM ka_media WHERE ido = $A350_LINK")" "0"
rm -rf "${WORK:?}/web/media/a350-import" "$WORK/a350-outside.pdf"
sq "DELETE FROM ka_api_tokeny WHERE nazev = 'a350 full'" > /dev/null
