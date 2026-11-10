echo "== nové prvky builderu"
mcp stavba_uloz "{\"id\":$IDZ,\"publikovat\":true,\"stavba\":{\"v\":1,\"deti\":[{\"typ\":\"sekce\",\"deti\":[
{\"typ\":\"drobecky\"},
{\"typ\":\"ikona\",\"obsah\":{\"ikona\":\"telefon\",\"tvar\":\"kruh\"}},
{\"typ\":\"galerie\",\"obsah\":{\"fotky\":[{\"src\":\"media/2026/01/a.jpg\",\"alt\":\"Dílna\"},{\"src\":\"media/2026/01/b.jpg\",\"alt\":\"\"}]}},
{\"typ\":\"zalozky\",\"obsah\":{\"karty\":[{\"nazev\":\"Základ\",\"obsah\":\"<p>A</p>\"},{\"nazev\":\"Plus\",\"obsah\":\"<p>B</p>\"}]}},
{\"typ\":\"karusel\",\"obsah\":{\"naraz\":\"2\"},\"deti\":[{\"typ\":\"text\",\"obsah\":{\"html\":\"<p>Snímek</p>\"}}]},
{\"typ\":\"mapa\",\"obsah\":{\"adresa\":\"Brno, Náměstí Svobody\"}},
{\"typ\":\"faq\",\"obsah\":{\"jedna\":true,\"faq\":false,\"polozky\":[{\"otazka\":\"Co?\",\"odpoved\":\"<p>To.</p>\"}]}}
]}]}}" > "$WORK/response"
grep -q 'chyby\\":\[\]' "$WORK/response" && echo "  ok     nové prvky projdou validátorem" || { echo "  CHYBA  validace nových prvků"; head -c 600 "$WORK/response"; ERRORS=$((ERRORS+1)); }
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/z-html"
for pattern in 'class="ka-drobecky"' 'aria-current="page">Z HTML' 'class="ka-ikona ka-ikona--kruh" aria-hidden="true"><svg' 'class="ka-galerie"' 'alt="Dílna"' 'role="tablist"' 'aria-controls="zp-' 'data-karusel' '--ka-naraz:2' 'data-vlozit="https://maps.google.com/maps?q=Brno' 'name="faq-'; do
  grep -qF -- "$pattern" "$WORK/response" || { echo "  CHYBA  nový prvek na webu: chybí $pattern"; ERRORS=$((ERRORS+1)); }
done
grep -q '"BreadcrumbList"' "$WORK/response" && ! grep -q '"FAQPage"' "$WORK/response" && echo "  ok     nové prvky na webu, drobečky i pro vyhledávače, akordeon bez FAQPage" || { echo "  CHYBA  strukturovaná data stránky"; ERRORS=$((ERRORS+1)); }

