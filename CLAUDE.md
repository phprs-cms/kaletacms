# MiroCMS

Open-source CMS pro **firemní weby** (stránky, novinky/blog, později stavitel stránek, kolekce a formuláře) s napojením na
jazykové modely. Návrh, rozhodnutí a fáze: `../mirocms-interni/NAVRH.md`. Čisté PHP 8.4+ bez frameworku a bez Composeru
(vlastní PSR-4 autoloader v `system/bootstrap.php`), MySQL přes PDO, serverové HTML + trocha vanilla JS.

**Veřejně (README, texty, commity) se na projekt, ze kterého jádro vzniklo, neodkazuje.**

## Zásady

- **Jednoduchost nad abstrakcí.** Kód má přečíst i poučený laik. Žádné DI kontejnery, ORM, build kroky ani npm. Nová závislost = silný důvod.
- **Firemní web, ne magazín.** Žádné redakční workflow (korektura, zámky, předávka), rubriky, komentáře, čtenáři, předplatné, reklama,
  newsletter ani push – fáze 0 je odstranila, nevracej je. Co firmy potřebují navíc (formuláře a poptávky, údaje o firmě, kolekce,
  stavitel), přibývá podle `NAVRH.md`.
- **Standardy webu 2026/2027 bez ohledu na staré prohlížeče:** CSS vrstvy, `clamp()`, container queries, `color-mix()`/OKLCH, `:has()`,
  Popover API, `<dialog>`, `<details>`, View Transitions. Interaktivita přednostně bez JavaScriptu. Žádné polyfilly, CDN ani cizí písma.
- **Co se nevypisuje, nemá styl ani skript.** Do `image/web.css` ani `style.css` šablony nepatří selektor, který nikde nevzniká; skript nesmí
  hledat `[data-…]` prvek, který nikde nevzniká (hlídá `tools/testy.php`).
- **Tabulky** mají významové názvy (`mc_novinky`, `mc_kategorie`, `mc_uzivatele`, `mc_media`, `mc_nastaveni`…); v kódu vždy přes `{novinky}`.
  Starší názvy sloupců zůstaly: `idc` = novinka, `tema`/`idt` = kategorie, `ido` = médium, `idu` = uživatel.
- Identifikátory v kódu česky bez diakritiky; komentáře a texty česky s diakritikou.
- **Změna databáze = dva zápisy:** úplné schéma `system/sql/schema.sql` a migrace `system/sql/migrace/NNNN-popis.sql` + zvýšit
  `MIROCMS_VERZE_DB` v `system/bootstrap.php` (hlídá `tools/test.sh`). Výchozí stav je migrace 0001.
- **Rozšíření jsou uzavřený systém** (`Core\Rozsireni::SEZNAM`): žádné cizí plug-iny ani nahrávání kódu z administrace.
- **Role:** správce (2), editor (1 – veškerý obsah, vydává), autor novinek (0 – jen své novinky, nevydává). `Auth::smiVydavat()`,
  `Auth::spravovaniAutori()`, `Auth::articleScope()`; práva k sekcím navíc `mc_uzivatele_prava` (výchozí podle role, `Autori::vychoziModuly()`).
- **Nastavení:** nová volba = klíč v `Settings::DEFAULTS` + typ v `Konfigurace::POLE` + řádek `$pole(...)` ve `views/admin/config/<zalozka>.php`.
- **Nikdy `window.confirm()`** – v administraci atribut `data-potvrdit="text"`.
- **Administrace má CSP `script-src 'self'`:** žádné inline skripty ani `on*=` atributy; chování do `image/admin.js` přes `data-` atributy.
- **Prázdný výpis** v administraci přes `views/admin/prazdno.php`. Vzhled administrace je jediný (`image/admin.css`); změny kontroluj ve světlém
  i tmavém režimu a v šířce telefonu. Písmo administrace Noto Sans je hostované u sebe (`image/pisma/`).
- **Logo MiroCMS je zatím dočasné** (`views/admin/logo.php`, `image/mirocms-*`); na weby uživatelů se nedává.

## Web (front)

- `Front\Kernel`: `/` = úvodní stránka (nastavení `titulni_stranka`, v jazykové verzi její protějšek `preklad_z`), bez ní výpis novinek;
  úvodní stránka na své vlastní adrese přesměruje 301 na `/`. Novinky na `/novinky`, `/novinky/<seo>` (+ `.md`), `/novinky/kategorie/<seo>`,
  `/novinky/stitek/<seo>`. Stránky na `/<seo>` – vyhrazené adresy `Moduly\Stranky::VYHRAZENE`.
- **Layout** = `layout/<složka>/base.php` + `style.css` + `info.php`; může přepsat kterýkoli pohled z `system/views/front/`
  (`novinka.php`, `vypis.php`, `stranka.php`…). Vestavěný je jen `zakladni` (`Front\Layouty::VYCHOZI`, `Mcp\Nastroje::VESTAVENE_SABLONY`).
  Layout musí vypsat `<?= $hlava ?>` před `</head>` a `<?= $pata ?>` před `</body>` (SEO, strukturovaná data, měření, cookie lišta – `Front\Seo`).
  Barvy, písma, škálu a rozměry ber z tokenů design systému (`--mc-barva-*`, `--mc-krok-*`, `--mc-mezera-*`, `--mc-sirka`…) s vlastní výchozí hodnotou.
  **Vrstvy kaskády** celého webu: `@layer tokeny, spolecne, sablona, stavitel, tridy, prvky;` (`DesignSystem::VRSTVY`) – šablona píše do `sablona`,
  nic nevrstveného (to by přebilo vše) a bez `!important`.
- **Tmavý režim:** `<html data-tmavy>` podle `tmavy_rezim`, v CSS `@media (prefers-color-scheme: dark) { :root[data-tmavy] { … } }`.
- **Společné prvky** (galerie, prohlížečka fotek, video, osnova, sdílení, FAQ, úprava na webu) mají styl a skript v `image/web.css` a `image/web.js`
  (vkládá `Seo::hlava()`); pravidla v `:where()` s nulovou vahou, aby je šablona přebila. Doplňky textu novinky vkládá `Front\TextNovinky`.
- **Texty webu přes `t('Česky')`** (`Core\Jazyk`, slovníky `system/jazyky/<kód>.php`; administrace `admin-<kód>.php`, instalátor
  `install-<kód>.php` – úplnost hlídá `tools/testy.php`). Jazyky: čeština a angličtina (`Jazyk::DOSTUPNE`, `Jazyk::KODY`).
  Doplňuj nástrojem `tools/slovnik.py`. Hodnoty formulářů se nepřekládají.
- **Jazykové verze:** sloupec `jazyk` ('' = výchozí) mají stránky, kategorie a novinky (novinka ho přebírá z kategorie). Každý dotaz webu
  vypisující obsah filtruje `Jazyk::sloupecWebu()`. `App::url()` přidává `/en/` jen adresám bez přípony (soubory, `api/`, `mcp` jsou společné).
- **Cache stránek** (`Front\Cache`): jen pro nepřihlášené; každý POST v administraci volá `Cache::vymaz()`. `Auth::user()` nesmí na webu
  založit session anonymnímu návštěvníkovi.
- **Adresa webu je nastavení `adresa_webu`,** ne hlavička Host – absolutní adresy ber z `$app->request->origin()`.
- **Obrázky:** varianty a WebP vznikají v `Core\Obrazky::uloz()`, `srcset` doplňuje `Front\Clanky::priprav()`, rozměry `Front\ObrazkyHtml::dopln()`.
- **Výpisy novinek nenačítají dlouhé texty** (`Front\Clanky::SLOUPCE_VYPISU`) – nový sloupec pro výpis doplň i tam.
- **Úprava přímo na webu** (`Kernel::upravaNaMiste()`, `views/front/upravit.php`): „Upravit zde“ pro přihlášené s právem; ukládají akce
  `uloz_text` v `Moduly\Novinky` a `Moduly\Stranky`.

## Stavitel stránek a design systém

- **Design systém** (`Stavitel\DesignSystem`, nastavení `design_system` JSON, admin Vzhled webu): pár rozhodnutí → tokeny v `@layer tokeny`.
  Fluidní škály přes `clamp()`, odstíny `color-mix(in oklch)`, kontrast WCAG počítá PHP (`kontrasty()`). Starší `brand_*` se čtou jen jako záloha.
  Živý náhled ve Vzhledu i předvolby počítá jen PHP (akce `nahled`) – výpočet tokenů nikdy neduplikuj v JS.
- **Stavba** = `mc_stranky.stavba` (publikovaná) a `stavba_koncept` (editor, MCP): `{"v":1,"deti":[{id,typ,znacka,obsah,styl,tridy,kotva,popis,deti}]}`.
  Jeden prvek = jedna značka. **Jediný validátor** `Stavba::vycisti()` (editor, MCP, import – nikdy neukládej stavbu bez něj) a **jediný vykreslovač**
  `Stavba::vykresli()`; CSS stránky jen z použitých typů, tříd (`mc_tridy`) a stylů prvků. Na webu se vadný prvek vynechá, nikdy výjimka.
- **Prvek** = třída v `Stavitel\Prvky\` (dědí `Prvek`, zapsaná v `Stavba::PRVKY`): pole obsahu (`vlastnosti()`), povolené značky, základní CSS
  do vrstvy `stavitel` přes `:where()`. **Styl** (`Stavitel\Styl::VLASTNOSTI`) má stavy `zaklad`/`tablet` (≤1023 px)/`mobil` (≤767 px)/`hover`; hodnoty
  jsou tokeny nebo bezpečné volné hodnoty. Vlastní CSS tříd projde `Styl::vlastniCss()` (bez `url()`, bloků, `@`).
- **Publikování** (`Stavitel\Publikace`, i z MCP): předchozí verze do `mc_stavba_revize` (20, `ids` stránky nebo `cast` = "typ:jazyk"), do `text` se uloží obsah bez rozložení
  (`Stavba::jakoText`) – z něj čerpá hledání, llms.txt, API i návrat k textu. Náhled konceptu `?stavba=koncept` jen s právem Stránky, `&editor=1` přidá `data-mc-id`.
- **Editor** `image/stavitel.js` + `stavitel.css` (samostatná stránka `akce=stavitel`): plátno je skutečná stránka v iframe (počítač vykreslený v 1280 px
  a zmenšený), průběžné ukládání konceptu (`stavba_uloz`, vrací vyčištěný strom), knihovna sekcí `Stavitel\Knihovna`, verze.
- **Části webu** (`Stavitel\Casti`, tabulka `mc_casti` typ+jazyk, admin `Moduly\Casti`, jen správce): záhlaví, patička a obálky `novinka`/`vypis`/`nenalezeno`
  (prvek `obsah` = místo pro obsah systému). Web je skládá v `Front\Kernel::castiWebu()` se stavbou stránky v jednom `Kontext` → jedno CSS.
  Layout vypisuje `$casti['hlavicka']`/`['paticka']`, když nejsou `null`. Prvky `JEN_CASTI` (logo, navigace, udaje, obsah) se nabízejí jen v částech.
  Akce stavitele sdílí trait `Admin\StavitelAkce` (stránky i části), publikování a verze `Stavitel\Publikace`.
- **Firma** (`Front\Firma`, Nastavení → Firma, klíče `firma_*`): prvek `udaje` (Údaje firmy) je vypisuje na webu, `Seo` z nich skládá
  Organization/LocalBusiness (`@id` …#firma) s adresou, otevírací dobou a geo. Otevírací doba se píše lidsky po řádcích, `Firma::hodiny()` ji rozebere.
- **Kolekce** (`Stavitel\Kolekce`, tabulky `mc_kolekce` + `mc_kolekce_polozky`, admin `Moduly\Kolekce`, MCP `seznam_kolekci`, `vytvor_kolekci`,
  `uloz_polozku_kolekce`): prvek `kolekce` (Výpis kolekce) zopakuje svůj vnitřek pro každou položku a `{{pole}}` v obsahu nahradí přes `Kolekce::dosad()`
  podle typu cílového pole (text se escapuje až prvkem, inline/html hned, odkaz se znovu ověří). Prvky uvnitř výpisu dostávají styl přes třídu `s-<id>`,
  ne přes id. Detail `/<kolekce>/<položka>` kreslí šablona ze stavitele (`mc_kolekce.stavba`, `Front\Kernel::detailKolekce`).
- **Komponenty** (`Stavitel\Komponenty`, `mc_komponenty`, admin `Moduly\Komponenty`, v editoru „Uložit jako komponentu“): prvek `komponenta`
  vloží publikovanou stavbu komponenty s hodnotami `{{vlastností}}` (stejné `Kolekce::dosad`); uvnitř bez značek editoru a se stylem přes třídu,
  ochrana proti zanoření (`Kontext::$zanoreni`). Náhled pro editor `/_komponenta/<id>` (jen správce).
- **Formuláře** (prvek `formular`, `Front\Formulare` na `POST /formular`): pole a příjemce se berou z PUBLIKOVANÉ stavby podle `zdroj` + id prvku,
  nikdy z požadavku. Ochrana `Core\Antispam` (podpis času, honeypot, limit na IP) – bez cookies, stránka zůstává v cache. Výsledek jen jako kód
  v adrese (`?formular=<id>&vysledek=ok|pole|limit|overeni`), text hlášení nikdy z adresy. Poptávky v `mc_poptavky` (admin `Moduly\Poptavky`,
  CSV, samy se mažou po `poptavky_mesice`), upozornění přes `Posta::odesli` s Reply-To návštěvníka.
- **HTML → stavba** (`Stavitel\ZHtml`, MCP `stavba_z_html`): sémantické HTML + `<style>` s pravidly jedné třídy → prvky a třídy; co převést nejde, se nahlásí.

## Obsah a služby

- **Novinky** (`Moduly\Novinky`): koncept / vydaná (i naplánovaná), koš 30 dní, revize (20 posledních) s porovnáním, rozepsaný stav na serveru,
  kontrola nefunkčních odkazů, AI asistent a překlad. Změna adresy vydané novinky, stránky nebo kategorie zapíše přesměrování (`Presmerovani::pridej`).
- **Hledání** přes `mc_novinky.hledani` (`Core\Hledani`): kdo ukládá novinku jinudy než administrací nebo MCP, volá `Hledani::indexuj()`.
- **Oznámení o vydání** (webhook, IndexNow) jen přes `Core\Oznameni::zpracuj()` a sloupec `oznameno`.
- **Pošta** vždy přes `Core\Posta::odesli()` (fronta `mc_posta`). **Nahrávání:** obrázky `Core\Obrazky`, přílohy `Core\Soubory` (whitelist přípon).
- **Čas:** pásmo `casove_pasmo` (`App::casovePasmo()`); zapisuj přes `date()`, porovnávej s `NOW()`.
- **AI asistent** (`Core\Asistent`): klíč `ai_klic` je typ `tajne`; odpověď modelu je nedůvěryhodný vstup. Překlad (`Asistent::preloz()`) bere od modelu
  jen text úseků, značky z originálu; výsledek je vždy koncept. Adresa API jen konstantou `MIROCMS_AI_URL` v `config.php`.
- **MCP** (`Mcp\Server`, `Mcp\Nastroje`, `/mcp`, token z Můj účet): stránky (i stavitel: `stavba_schema`, `stavba_z_html`, `stavba_nacti`, `stavba_uloz`,
  `vloz_sekci`, `publikuj_stavbu`), design systém (`uprav_design_system`, správce), novinky, kategorie, média a VLASTNÍ šablony. Nová novinka je koncept,
  nová stránka skrytá; vydat/zveřejnit jen na výslovný pokyn a s právem. **Hranice (bezpečí na prvním místě):** žádný nástroj nesmí zapisovat mimo obsah
  a `layout/<vlastní>/`, spouštět kód ani dotaz; PHP šablon ukládaných přes MCP projde `Core\SablonaKontrola`. Pravidla pro Claude v souborech: `layout/CLAUDE.md`.
- **Přihlášení:** hesla `password_hash`, TOTP, passkeys (`Core\Passkey`, jen jako náhrada kódu u účtu s TOTP), obnova hesla `Admin\ObnovaHesla`.

## Import z WordPressu a export

`Moduly\Prenos` obsluhuje formuláře, práci dělají `Core\WpSoubor` (proudové čtení WXR), `Core\WpObsah` (čištění obsahu, bloky Gutenbergu),
`Core\WpImport` (zápis) a `Core\StahovaniObrazku`; `Core\ExportWebu` dělá otevřený archiv obsahu.
- Příspěvky → novinky, stránky → stránky (skryté v navigaci), kategorie → kategorie (strom se zplošťuje), štítky → štítky, **přesměrování všech starých adres**
  (hezké i `/?p=123`). Komentáře se nepřenášejí. Účty se nezakládají – novinky patří tomu, kdo importuje.
- Dávky (`WpImport::DAVKA`, `SEKUND`) se stavem v `storage/import/`, idempotence přes `mc_import_mapa` (převedené se nepřepisuje).
- **Bezpečnost, která se nesmí rozvolnit** (hlídá `tools/testy.php`): XML s DOCTYPE/entitou se odmítá, `LIBXML_NONET`; obsah projde jen povolovacím seznamem
  značek; `StahovaniObrazku` jen z domény starého webu, jen veřejné IP, připnuté spojení, limity velikosti a času, SVG nikdy.
- Export vybírá nastavení z povolovacího seznamu `ExportWebu::NASTAVENI`; účty, hesla ani klíče do něj nikdy nepatří.

## Vydání a aktualizace

Verze `MIROCMS_VERSION` v `system/bootstrap.php`; `php tools/vydani.php <verze> --url=…` sestaví a lokálně podepíše balíček (`docs/VYDAVANI.md`).
**Klíče vydavatele MiroCMS zatím nejsou vygenerované** – `system/aktualizace.pub` je prázdný; vzniknou před prvním vydáním. Soukromé klíče nikdy do gitu.
Doména projektu (`mirocms.eu` v kódu je zástupná) není rozhodnutá; kontroly webu v CI jsou vypnuté (`if: false`).

## Spuštění a testy

`php -S 127.0.0.1:8095 system/dev-router.php` (preview `mirocms`), vývojová databáze `mirocms_dev`, `config.php` není v gitu.
Po změně: `tools/test.sh` (lint, jednotkové testy `tools/testy.php`, čistá instalace a průchod webem i administrací; potřebuje MySQL,
databázi `mirocms_test` smaže a vytvoří), případně jen `php tools/testy.php`, a projít dotčené stránky v prohlížeči.
