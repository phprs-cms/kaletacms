echo "== 3.50 A1 (N125): an old address this site already uses gets no redirect – from a website import, the exports and WordPress"
# WpImport::redirectRefusal on the site's own tables: a live page, a collection item, a page of the English version, an
# existing redirect elsewhere – and the free addresses beside them
A350_LANGS=$(sq "SELECT IFNULL(MAX(hodnota), '') FROM ka_nastaveni WHERE promenna = 'additional_languages'")
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('additional_languages', 'en')" > /dev/null
a350_mcp create_page '{"title":"Kontakt 350","slug":"kontakt-350","visible":true,"content":"<p>Náš kontakt.</p>"}' > /dev/null
a350_mcp create_page '{"title":"Odkazy 350","slug":"odkazy-350","visible":true,"content":"<p><a href=\"/kontakt-350\">Napište nám</a></p>"}' > /dev/null
a350_mcp create_collection '{"name":"Produkty 350","slug":"produkty-350","item_pages":true,"fields":[{"label":"Price","type":"number"}]}' > /dev/null
a350_mcp save_collection_items '{"collection":"produkty-350","items":[{"name":"Stůl","slug":"stul-350","visible":true}]}' > /dev/null
sq "INSERT INTO ka_stranky (seo_link, titulek, text, jazyk, zobrazit, v_menu, zmeneno) VALUES ('contact-350', 'Contact 350', '<p>Contact.</p>', 'en', 1, 0, '$(site_time)');
  INSERT INTO ka_presmerovani (z_adresy, source_norm, na_adresu, vytvoreno) VALUES ('stary-350', 'stary-350', 'jinam-350', '$(site_time)')" > /dev/null
A350_REFUSALS=$(cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php");
  echo implode(",", array_map(fn (string $old): string => Kaleta\Core\WpImport::redirectRefusal($app->db(), $app->settings(), $old, "nove-350") ?: "free", array_slice($argv, 1)));' \
  -- kontakt-350 produkty-350/stul-350 en/contact-350 stary-350 volna-350 en/volna-350 produkty-350/jiny-350 'a*350')
expect "3.50 N125: redirectRefusal – a live page, an item, an English page and a redirect elsewhere are refused; free addresses are free" \
  "$A350_REFUSALS" "address_in_use,address_in_use,address_in_use,redirect_exists,free,free,free,not_literal"

# the website import: /kontakt-350 was built here first; the old site has a page there too. The imported page lands
# beside it (kontakt-350-2) and the old address keeps leading to the page of this site – no redirect, no link rewritten
a350_site /kontakt-350/,/novy-350/
a350_found false
a350_mcp import_website "{\"import_id\":\"$A350_ID\",\"confirm\":true}" > "$WORK/response"
for _ in $(seq 1 10); do [ "$(import_field phase)" = done ] && break; a350_mcp import_website "{\"import_id\":\"$A350_ID\"}" > "$WORK/response"; done
expect "3.50 N125 website import: the page arrives beside the one built here, the old address gets no redirect and is named in attention" \
  "$(import_field phase)|$(sq "SELECT GROUP_CONCAT(seo_link ORDER BY seo_link) FROM ka_stranky WHERE seo_link LIKE 'kontakt-350%'")|$(sq "SELECT COUNT(*) FROM ka_presmerovani WHERE z_adresy LIKE 'kontakt-350%'")|$(import_field result | php -r 'echo json_decode(stream_get_contents(STDIN), true)["redirects"] ?? "?";')|$(import_field attention | php -r 'echo count(array_filter((array) json_decode(stream_get_contents(STDIN), true), fn ($l) => str_starts_with((string) $l, "/kontakt-350 – the address is already in use on this site, so it does not redirect to /kontakt-350-2:")));')" \
  "done|kontakt-350,kontakt-350-2|0|0|1"
expect "3.50 N125 website import: the page linking to /kontakt-350 still links there (no healing), and the address shows this site's page" \
  "$(sq "SELECT text LIKE '%href=\"/kontakt-350\"%' FROM ka_stranky WHERE seo_link = 'odkazy-350'")|$(curl -s -o "$WORK/a350.html" -w '%{http_code}' "$B/kontakt-350")|$(grep -c 'Náš kontakt' "$WORK/a350.html")" \
  "1|200|1"

# an export (Blogger) has its own redirects: the refused one is listed, not counted, and never written
A350_BATCH=$(cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php");
  $redirect = (new ReflectionMethod(Kaleta\Import\Batch::class, "redirect"))->getClosure(new Kaleta\Import\Batch($app->db(), $app->settings(), "", 0));
  $state = [];
  $made = [];
  foreach (["http://old.example/kontakt-350", "http://old.example/stary-350", "http://old.example/blog-350"] as $url) {
      $made[] = $redirect(new Kaleta\Import\Post("k", "post", "T", "t", "<p>x</p>", oldUrl: $url), "novinky/clanek-350", $state);
  }
  echo implode(",", $made), "|", json_encode($state["presmerovani"]["odmitnute"] ?? [], JSON_UNESCAPED_SLASHES);')
expect "3.50 N125 exports (Import\\Batch): a page here and a redirect elsewhere are refused and listed with the reason, a free address redirects" \
  "$A350_BATCH|$(sq "SELECT GROUP_CONCAT(CONCAT(z_adresy, '>', na_adresu) ORDER BY z_adresy) FROM ka_presmerovani WHERE z_adresy IN ('kontakt-350', 'stary-350', 'blog-350')")" \
  '0,0,1|[["/kontakt-350","/novinky/clanek-350","address_in_use"],["/stary-350","/novinky/clanek-350","redirect_exists"]]|blog-350>novinky/clanek-350,stary-350>jinam-350'
# WordPress keeps its refusal (it is the same function now): 3.6 above imported the page /kontakt-stavby beside this site's own
expect "3.50 N125 WordPress: the old /kontakt-stavby of section 0550 still has no redirect" "$(sq "SELECT COUNT(*) FROM ka_presmerovani WHERE z_adresy = 'kontakt-stavby'")" "0"

sq "DELETE m FROM ka_import_mapa m JOIN ka_stranky s ON s.ids = m.nase_id WHERE m.typ = 'stranka' AND s.seo_link IN ('kontakt-350-2', 'novy-350');
  DELETE FROM ka_stranky WHERE seo_link IN ('kontakt-350', 'kontakt-350-2', 'novy-350', 'odkazy-350', 'contact-350');
  DELETE FROM ka_presmerovani WHERE z_adresy IN ('stary-350', 'blog-350', 'novy-350');
  REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('additional_languages', '$A350_LANGS')" > /dev/null
a350_mcp delete_collection '{"collection":"produkty-350"}' > /dev/null || true
