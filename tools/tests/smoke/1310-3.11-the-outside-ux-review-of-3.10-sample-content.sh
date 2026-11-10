echo "== 3.11: the outside UX review of 3.10 – sample content, translations of builder pages, truthful presets, the page form"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=pages&action=new"; TOKEN=$(csrf)
# UX310-6: the title field of a new page is labelled, has a placeholder and the focus
contains -q '<label for="titulek">Název stránky</label>' "$WORK/response" && contains -q 'placeholder="např. Zahradní architektura" autofocus' "$WORK/response" \
  && echo "  ok     3.11 UX310-6: the page title field has its label, a placeholder and the focus" || { echo "  CHYBA  3.11: the page title field"; ERRORS=$((ERRORS+1)); }
contains -q '<label for="valid_until">Platí do</label>' "$WORK/response" && contains -q 'Nechte prázdné, aby zůstala vidět.' "$WORK/response" \
  && echo "  ok     3.11 UX310-15: Visible until says how to keep the page visible" || { echo "  CHYBA  3.11: Visible until"; ERRORS=$((ERRORS+1)); }
# UX310-1: a page from the landing template – the builder's pre-publish check gets its sample content from the server, a saved
# draft answers with what is still sample, MCP publish_build reports it, and once published the site audit lists the page
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=pages&action=save" -d "_csrf=$TOKEN" -d ids=0 --data-urlencode "titulek=Prodejní stránka 311" -d sablona=landing -d zobrazit=1 -d poradi=100
L311=$(sq "SELECT ids FROM ka_stranky WHERE titulek = 'Prodejní stránka 311'")
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=pages&action=builder&id=$L311"
contains -q '"vzory":\[{"id":"[a-z0-9]*","zprava":"Na stránce je pořád ukázkový obsah z úvodního webu (ukázkové reference: 2, zástupná čísla: 2, ukázkové texty: ' "$WORK/response" \
  && echo "  ok     3.11 UX310-1: the builder of a template page lists its sample content for the pre-publish check" || { echo "  CHYBA  3.11: sample content in the builder data"; grep -o '"vzory":[^]]*' "$WORK/response" | head -c 400; ERRORS=$((ERRORS+1)); }
mcp publish_build "{\"id\":$L311}" > "$WORK/response"; mcp_text
contains -q 'This page still has sample content from the starter site' "$WORK/text" \
  && echo "  ok     3.11 UX310-1: MCP publish_build reports the sample content first in its check" || { echo "  CHYBA  3.11: publish_build check"; head -c 500 "$WORK/text"; ERRORS=$((ERRORS+1)); }
mcp site_audit '{"kind":"sample"}' > "$WORK/response"; mcp_text
contains -q 'Prodejní stránka 311' "$WORK/text" && contains -q '"kind":"sample"' "$WORK/text" \
  && echo "  ok     3.11 UX310-1: site_audit kind sample lists the published template page" || { echo "  CHYBA  3.11: site_audit kind sample"; head -c 500 "$WORK/text"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -X POST "$B/admin.php?module=pages&action=build_save&id=$L311" -d "_csrf=$TOKEN" \
  --data-urlencode 'stavba={"v":1,"deti":[{"id":"own311","typ":"text","obsah":{"html":"<p>Naše vlastní slova o zahradách v Brně.</p>"}},{"id":"quo311","typ":"citat","obsah":{"text":"[Slova zákazníka – skutečný citát, použitý s jeho svolením]","autor":"[Jméno zákazníka]"}}]}'
contains -q '"vzory":\[{"id":"quo311","zprava":"Na stránce je pořád ukázkový obsah z úvodního webu (ukázkové reference: 1)' "$WORK/response" && ! contains -q '"id":"own311","zprava"' "$WORK/response" \
  && echo "  ok     3.11 UX310-1: a saved draft answers with the sample still in it – a rewritten text is not" || { echo "  CHYBA  3.11: sample content after a build save"; head -c 600 "$WORK/response"; ERRORS=$((ERRORS+1)); }
sq "DELETE FROM ka_stranky WHERE ids = $L311" > /dev/null
# UX310-4: "Create" a translation of a builder page – the form names the translation, and the new page starts as a copy of the
# original's build, hidden until its first publish, in the builder
SL311=$(sq "SELECT ids FROM ka_stranky WHERE seo_link = 'sluzby' AND jazyk = '' AND smazano IS NULL")
check "3.11 UX310-4: the translation form names the original and the language" 200 "/admin.php?module=pages&action=new&language=en&translation_of=$SL311" "Překlad: Služby → English"
contains -q 'name="copy_build" value="1"' "$WORK/response" && ! contains -q 'name="sablona"' "$WORK/response" && contains -q 'Vytvořit překlad a otevřít builder' "$WORK/response" \
  && echo "  ok     3.11 UX310-4: the form copies the build instead of offering a blank text page" || { echo "  CHYBA  3.11: the translation form"; ERRORS=$((ERRORS+1)); }
TR311=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{redirect_url}' -X POST "$B/admin.php?module=pages&action=save" -d "_csrf=$TOKEN" -d ids=0 --data-urlencode "titulek=Services 311" -d jazyk=en -d "preklad_z=$SL311" -d copy_build=1 -d text= -d zobrazit=1 -d poradi=100)
expect "3.11 UX310-4: the translation opens in the builder with the original's build, hidden until its first publish" \
  "$(printf %s "$TR311" | grep -q 'action=builder' && echo builder || echo "$TR311")|$(sq "SELECT CONCAT(t.stavba_koncept = COALESCE(o.stavba_koncept, o.stavba), '|', t.zobrazit, '|', t.show_on_publish, '|', t.preklad_z = o.ids) FROM ka_stranky t JOIN ka_stranky o ON o.ids = $SL311 WHERE t.titulek = 'Services 311'")" "builder|1|0|1|1"
sq "DELETE FROM ka_stranky WHERE titulek = 'Services 311'" > /dev/null
# UX310-7: Translations put the home page first and say why a language is not in the switcher yet
HOME311=$(sq "SELECT ids FROM ka_stranky WHERE seo_link = 'uvod' AND jazyk = ''")
sq "UPDATE ka_nastaveni SET hodnota = 'en,pt-br,ja' WHERE promenna = 'additional_languages'; REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('home_page', '$HOME311')" > /dev/null
check "3.11 UX310-7: Translations say Brazilian Portuguese is not in the language switcher until the home page is translated" 200 "/admin.php?module=pages&action=translations" "Português (Brasil) zatím není v přepínači jazyků."
contains -q "action=new&amp;language=pt-br&amp;translation_of=$HOME311\">Přeložit úvodní stránku" "$WORK/response" \
  && echo "  ok     3.11 UX310-7: it links to translating the home page" || { echo "  CHYBA  3.11: the switcher hint on Translations"; ERRORS=$((ERRORS+1)); }
check "3.11 UX310-12: Settings → Languages marks a language without visitor texts and names the one not in the switcher" 200 "/admin.php?module=settings&tab=languages" "(/ja/ · texty pro návštěvníky anglicky)"
# 3.12 (UX310-12): Portuguese has visitor texts now; Japanese is marked in the language list and in a warning
! contains -q '(/pt-br/ · texty pro návštěvníky anglicky)' "$WORK/response" && contains -q 'class="hlaska hlaska-varovani">Návštěvníci verze 日本語 uvidí texty webu' "$WORK/response" \
  && contains -q '>日本語 – texty pro návštěvníky anglicky</option>' "$WORK/response" && echo "  ok     3.12: the language picker warns about a language without visitor texts" || { echo "  CHYBA  3.12: the language picker warning"; ERRORS=$((ERRORS+1)); }
contains -q 'Zatím nejsou v přepínači jazyků: [^<]*Português (Brasil)[,.]' "$WORK/response" && echo "  ok     3.11 UX310-7: Settings → General names the language not in the switcher" || { echo "  CHYBA  3.11: the switcher hint in Settings"; ERRORS=$((ERRORS+1)); }
sq "UPDATE ka_nastaveni SET hodnota = 'en' WHERE promenna = 'additional_languages'; REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('home_page', '$HOME39')" > /dev/null
# UX310-5: a ready-made collection says what really happened with its list page – the address taken by a page, then free
PLANS311=$(cd "$WORK/web" && php -r 'require "system/bootstrap.php"; Kaleta\Core\Language::set("cs"); echo slugify(t("Pricing plans"));')
mcp create_page "{\"title\":\"Plány 311\",\"slug\":\"$PLANS311\",\"content\":\"<p>Naše plány</p>\"}" > /dev/null
curl -s -L -b "$JAR" -c "$JAR" -o "$WORK/response" -X POST "$B/admin.php?module=collections&action=preset" -d "_csrf=$TOKEN" -d preset=plans
contains -q "Vytvořeno. Stránka „Plány 311“ už používá adresu /$PLANS311, takže stránka s výpisem nevznikla" "$WORK/response" && ! contains -q 'se skrytou stránkou, která ji vypisuje' "$WORK/response" \
  && echo "  ok     3.11 UX310-5: a preset whose address is a page says no list page was added, and which page has it" || { echo "  CHYBA  3.11: preset message with a taken address"; grep -o 'class="hlaska[^>]*>[^<]*' "$WORK/response" | head -3; ERRORS=$((ERRORS+1)); }
N_C3_LOG=$(sq "SELECT COALESCE(MAX(idp), 0) FROM ka_protokol")
curl -s -L -b "$JAR" -c "$JAR" -o "$WORK/response" -X POST "$B/admin.php?module=collections&action=preset" -d "_csrf=$TOKEN" -d preset=plans
expect "3.23.1 (N-C3): a ready-made collection is one change-log row in Collections (its list page has its own in Pages)" "$(sq "SELECT GROUP_CONCAT(CONCAT(akce, ' ', popis)) FROM ka_protokol WHERE idp > $N_C3_LOG AND modul = 'collections'")" "preset plans: $PLANS311-2"
contains -q "Vytvořeno, i se skrytou stránkou /$PLANS311-2, která ji vypisuje" "$WORK/response" && expect "3.11 UX310-5: the second one gets a free address and its hidden list page" "$(sq "SELECT zobrazit FROM ka_stranky WHERE seo_link = '$PLANS311-2'")" "0" \
  || { echo "  CHYBA  3.11: preset message with its list page"; grep -o 'class="hlaska[^>]*>[^<]*' "$WORK/response" | head -3; ERRORS=$((ERRORS+1)); }
# UX310-30: a mistyped section is "does not exist", not "no access"
check "3.11 UX310-30: an unknown admin section is a 404" 404 "/admin.php?module=claude" "Tato stránka neexistuje."
