# MiroCMS

Open-source CMS pro firemní weby s vizuálním stavitelem stránek a napojením na jazykové modely. Návrh a fáze: `../mirocms-interni/NAVRH.md`.
**Veřejně (README, texty, commity) se na projekt, ze kterého jádro vzniklo, neodkazuje.** Čisté PHP 8.4+ bez frameworku a bez Composeru
(vlastní PSR-4 autoloader v `system/bootstrap.php`), MySQL přes PDO, serverové HTML + trocha vanilla JS.

## Zásady

> **Fáze 0 (probíhá):** jádro je převzaté z redakčního systému pro magazíny. Zásady níže o magazínu (články, rubriky, čtenáři,
> předplatné, reklama, layouty novin…) platí jen do jejich odstranění – při úklidu je smaž nebo přepiš podle `NAVRH.md`.


- **Jednoduchost nad abstrakcí.** Kód má přečíst i poučený laik, který si chce web upravit. Žádné DI
  kontejnery, ORM, build kroky ani npm. Nová závislost = potřeba silného důvodu.
- **Z MiroCMS držíme ducha, ne mechaniky – a žádnou kompatibilitu.** Uživatel (2026-09-18) zrušil import ze
  starého MiroCMS: nepřevádějí se data, nepřesměrovávají staré adresy, nedrží se žádný sloupec ani funkce „kvůli
  2.x". Zůstává jednoduchost, zaměření na magazíny, české názvy tabulek/sloupců (`rs_clanky.titulek`…) a
  identifikátory modulů, protože tak už systém je postavený. Ovládání musí být srozumitelné člověku, který
  MiroCMS nikdy neviděl. **Administrace má jediný vzhled** – nepřidávej žádné další „prostředí“
  ani jiný skin.
- **Vzhled administrace** (jediný, `image/admin.css`) vychází z referenčního screenshotu uživatele:
  seskupené menu s čárovými ikonami (`views/admin/ikony.php`), podklad #F7F7F8, bílé karty s jemnou linkou,
  výrazné nadpisy, avatar a přepínač světlý/tmavý vpravo nahoře. Každou změnu šablon administrace zkontroluj ve světlém
  i tmavém režimu a v šířce telefonu.
- **Logo MiroCMS** (finální, z Claude Design): v administraci, na přihlášení a v instalátoru jen přes `views/admin/logo.php` (barvy nápisu řídí
  `--logo-php` / `--logo-rs`, značka je vždy `#2B5BE3` = `--akcent` světlého režimu). Soubory `image/mirocms-logo.svg`, `mirocms-logo-tmavy.svg`,
  `mirocms-znacka.svg` + PNG 32 a 180 pro ikonu záložky. Na weby uživatelů se značka MiroCMS nedává – ty mají vlastní logo z Identity webu.
- **Identita webu** (`Front\Identita`, modul `vzhled`): šablony berou hlavní barvu a písma z proměnných `--rs-akcent`,
  `--rs-pismo-titulky`, `--rs-pismo-text` s vlastní výchozí hodnotou (`--akcent: var(--rs-akcent, #326891)`). Nová šablona
  je musí použít také. Žádná externí písma – jen sady v `Identita::PISMA_*`.
- **Tři layouty webu** (`classic-newspaper`, `modern-magazine`, `minimal`): nová proměnná pro šablony
  nebo nový systémový blok se musí promítnout do všech tří (vestavěné vyjmenovává i `Mcp\Nastroje::VESTAVENE_SABLONY`). Žádná externí písma ani CDN.
  Výchozí šablona instalace a náhrada za chybějící složku je `Front\Layouty::VYCHOZI` (`classic-newspaper`); původní třísloupcová `default`
  byla zrušena (migrace 0041), vlastní šablony vzniklé jako její kopie fungují dál – nic z ní za běhu nedědily.
- **Co se nevypisuje, nemá styl ani skript.** Do `image/web.css` a `style.css` šablon nepatří selektor, který nevzniká v žádné šabloně,
  pohledu `views/front/`, třídě `Front\*` ani v editoru. Společný vzhled prvků (štítky článku, Ve zkratce, otázky a odpovědi, hodnocení,
  komentáře, anketa, reklama, typy bloků) je na konci `image/web.css` v `:where()` s nulovou vahou – `web.css` se načítá až PO `style.css`
  šablony, takže jen nulová váha zaručí, že pravidlo šablony (i vlastní) vyhraje. Šablona nese jen to, co se liší; nový společný prvek
  = základ do tohoto úseku, ne kopie do tří šablon.
- Identifikátory v kódu (metody `akce*`, proměnné domény, šablony) česky bez diakritiky; komentáře a
  texty česky s diakritikou. Jádro (`Core/`) má API anglicky.
- **Změna databáze = dva zápisy:** úplné schéma v `system/sql/schema.sql` (nové instalace) a migrace
  `system/sql/migrace/NNNN-popis.sql` (stávající weby; provede se sama při vstupu admina do administrace,
  číslo drží `rs_config.verze_db`).
- **Rozšíření jsou uzavřený systém** (`Core\Rozsireni::SEZNAM`): žádné cizí plug-iny, žádné veřejné API,
  žádné nahrávání kódu z administrace. Nová volitelná funkce = položka v `SEZNAM` + `ROZSIRENI` u modulu +
  kontrola `Rozsireni::je()` na webu. Jádro (články, média, rubriky, stránky, bloky, uživatelé, nastavení) vypnout nejde.
- **Vydání a aktualizace:** verze je `MIROCMS_VERSION` v `system/bootstrap.php`; `php tools/vydani.php <verze> --url=…`
  vytvoří `dist/*.zip` a podepsaný `dist/aktualizace.json`. Soukromé klíče `tools/klice/*.key` NIKDY do gitu ani do balíčku;
  `system/aktualizace.pub` nese veřejné klíče (provozní + záložní, na řádek jeden) a podpisy ověřuje jen `Core\Podpis` – platí kterýkoli z nich.
  Podepisuje se i příznak bezpečnostního vydání (instaluje se samo). Výměna, ztráta a únik klíče: `docs/VYDAVANI.md`. `Core\Aktualizace::CHRANENE` = co se nepřepisuje.
  Zdroj aktualizací je `https://mirocms.eu/aktualizace.json` (`Aktualizace::VYCHOZI_URL`); balíček se podepisuje lokálně, CI zakládá jen koncept vydání.
  Podpora projektu: GitHub Sponsors (`.github/FUNDING.yml`, odkaz pod přehledem administrace jde vypnout – `odkaz_podpora`).
- **Nastavení** (`Moduly\Konfigurace`): nová volba = klíč v `Settings::DEFAULTS` + typ v `Konfigurace::POLE`
  (podle typu se hodnota čistí) + řádek `$pole(...)` v `views/admin/config/<zalozka>.php`.
- **Layouty musí vypsat `<?= $hlava ?>` před `</head>` a `<?= $pata ?>` před `</body>`** - tudy jde SEO,
  strukturovaná data, měřicí kódy a cookie lišta (`Front\Seo`). Měřicí skripty čekající na souhlas mají
  `type="text/plain" data-souhlas="analytika"` (+ `data-cookieconsent` pro Cookiebot).
- **Bloky se upravují vizuálně přímo ve stránce webu** (`/?upravit=1` → `views/front/vizual.php`, `image/vizual.js|css`):
  `Front\Bloky::zony(..., $upravit)` obalí zóny `.rs-zona[data-zona]` (display: contents – nesmí rozbít mřížku layoutu) a
  bloky `.rs-blok[data-blok]`; ukládá se přes JSON akce `Moduly\Bloky` (rychle_pridat, nastaveni_json, uloz_json, poradi).
  Nový typ bloku = `Bloky::SYSTEMOVE` + `Bloky::KATALOG` (název, popis jednou větou, ikona) + větev ve `Front\Bloky::systemovy()`
  + případná pole v panelu nastavení ve `vizual.js`. Nastavení bloku má být na pár polí – co jde odvodit, neptej se.
  Schéma bez JavaScriptu zůstává na `admin.php?modul=bloky&schema=1`.
- **Nikdy `window.confirm()`** – vestavěné prohlížeče ho potlačují. V administraci atribut `data-potvrdit="text"`
  na formuláři či tlačítku (řeší `admin.js`), ve vizuálním editoru vlastní dialog.
- **Cache stránek** (`Front\Cache`): cachuje se jen výstup `Front\Kernel::stranka()` pro nepřihlášené bez osobních
  cookies; cokoli, co se má lišit podle čtenáře, musí buď běžet v JS, nebo mít vlastní cookie `mirocms_*`, která
  cache vypíná. Každý POST v administraci i formuláře čtenářů volají `Cache::vymaz()`.
- **Obrázky:** varianty `-1200`, `-nahled` a sourozenci `.webp` vznikají v `Core\Obrazky::uloz()`; `srcset` doplňuje
  `Front\Clanky::priprav()`. WebP se podává přes `.htaccess` (a `dev-router.php`), HTML se kvůli němu nemění.
- **Formuláře čtenářů** (komentáře, hodnocení, ankety – `Front\Interakce`) nemají session ani CSRF token;
  chrání je `Core\Antispam` (podepsaný čas, honeypot, limit na otisk IP). Cokoli od čtenáře se vypisuje
  jen přes `e()`. Do `rs_kontrola_ip` a statistik se nikdy neukládá IP adresa, jen otisk.
- Hotové kusy HTML pro šablony článku (`shrnuti_html`, `faq_html`, `hodnoceni_html`, `komentare_html`) mají
  šablony v `system/views/front/` a layout je jen vypíše (nebo vynechá). Přepsat je souborem v `layout/<šablona>/` NEJDE –
  skládají se vždy ze systémových pohledů; vzhled se mění jen přes CSS.
- Editor článků a galerie: `image/editor.js` + `image/editor.css` (barvy přes proměnné `--ed-*` z obou
  admin stylesheetů). Nahrané soubory jdou vždy přes `Core\Obrazky` (překódování GD, složka `media/`).
- Bezpečnost: jen připravené dotazy (`{tabulka}` doplní předponu), výstup přes `e()`, každý POST
  má CSRF (kontroluje `Admin\Kernel`), práva článků viz `Moduly\Clanky`.
- HTML článků a bloků je důvěryhodné (píší ho autoři), komentáře a vstupy čtenářů nikdy.

- **Společné prvky článku** (fotogalerie, prohlížečka fotek, přehrávač, živá reportáž, hodnocení recenze, zámek, účet čtenáře,
  přepínač jazyků, Web Push) mají styl a skript v `image/web.css` a `image/web.js` – vkládá je `Front\Seo::hlava()`, takže fungují ve
  všech šablonách včetně cizích. `Front\TypyObsahu` vkládá hotové HTML přímo do textu článku; layouty se kvůli nim nemění.
- **Texty šablon webu jdou přes `t('Česky')`** (`Core\Jazyk`, slovníky `system/jazyky/<kód>.php`, klíčem je český text). Nový text
  v layoutu nebo `views/front/` = obalit `t()` + doplnit do slovníků en/sk/de. Administrace má vlastní slovníky `admin-<kód>.php`
  (čeština, slovenština, angličtina, němčina – nový text doplň do všech tří slovníků). Hodnoty formulářů (`value` skrytých polí a tlačítek s `name`) se NIKDY nepřekládají.
- **Jazykové verze:** sloupec `jazyk` ('' = výchozí jazyk webu) mají rubriky, stránky a články (článek ho přebírá z rubriky při uložení);
  `Jazyk::sloupecWebu()` je hodnota pro dotazy webu. `App::url()` přidává předponu `/en/` jen adresám bez přípony – soubory, `api/`,
  `mcp`, `push/` jsou společné. Každý nový dotaz na webu, který vypisuje obsah, musí filtrovat podle jazyka.
- **Zamčený obsah** řeší `Front\Ctenari::zamkni()` volané z `Front\Clanky::priprav()` – cokoli čte články jinudy, musí zámek
  respektovat samo. Čtenáři nemají session: podepsaná cookie `mirocms_ctenar` (v podpisu je otisk hesla), formuláře přes `Antispam`.
- **Platby předplatného přes Stripe** (`Core\Stripe`, `Front\Platby`): bez knihovny, jen hostovaný Checkout, Customer Portal a webhook `POST /platba/stripe`
  (společná adresa bez jazykové předpony, běží i při údržbě). `predplatne_do`, stav a platby (`rs_platby`) se zapisují VÝHRADNĚ z webhooku s ověřeným
  podpisem – nikdy z prohlížeče ani z návratové adresy; datum se nikdy nezkracuje (ruční zápis administrátora platí dál). Klíč a tajemství webhooku jsou typ
  `tajne` (nelogovat, nevypisovat, nepatří do `ExportWebu::NASTAVENI`), adresu API jde změnit jen konstantou `MIROCMS_STRIPE_URL` v `config.php` (testy).
  Web do Stripe nikdy nevolá kvůli rušení – při mazání čtenáře s běžícím předplatným jen varuje. Tvar objektů ve webhooku určuje verze API účtu, ne naše
  hlavička: čti je tolerantně (`Stripe::konecObdobi()`), každou novou větev pokryj v `tools/testy.php` a průchod v `tools/test.sh` (náhražka API `tools/fixtures/stripe-server.php`).
- **Oznámení o vydání** (webhook, IndexNow, Web Push) odchází jen přes `Core\Oznameni::zpracuj()` a sloupec `rs_clanky.oznameno`;
  nevolej `Webhook::clanekVydan()` přímo. Web Push: adresa odběru smí vést jen na služby v `Push::SLUZBY` (ochrana proti SSRF).
- **AI asistent** (`Core\Asistent`): klíč `ai_klic` je typ `tajne` – do HTML jde jen jeho konec. Odpověď modelu je nedůvěryhodný vstup
  (jen řetězce bez HTML). Modely: `Asistent::MODELY`.

- **Čas:** pásmo webu je nastavení `casove_pasmo` (`App::casovePasmo()` ho po startu nastaví PHP i relaci databáze; `Core\Db` po připojení vždy srovná
  `time_zone` s PHP). Data zapisuj přes `date()`, v dotazech porovnávej s `NOW()` – obojí pak znamená totéž i na serveru s databází v UTC.
- **Instalátor je vícejazyčný** (`Jazyk::nastav($kod, 'install-')`, slovníky `system/jazyky/install-<kód>.php` včetně ukázkového obsahu);
  úplnost slovníků hlídá `tools/testy.php`.
- **Adresa webu je nastavení `adresa_webu`, ne hlavička Host.** Absolutní adresy (e-maily, RSS, mapa webu, webhook, push) ber vždy
  z `$app->request->origin()` – oba kernely do něj po startu dosadí nastavenou adresu. Nikdy nečti `HTTP_HOST` přímo.
- **Výpisy článků nenačítají dlouhé texty** (`Front\Clanky::SLOUPCE_VYPISU`): nový sloupec `rs_clanky`, který má být vidět ve výpisu,
  doplň i tam. Nová migrace = zvýšit `MIROCMS_VERZE_DB` v `system/bootstrap.php` (hlídá `tools/test.sh`).
- **`Auth::user()` nesmí na webu založit session** anonymnímu návštěvníkovi (vypnula by cache). `Cache::vymaz()` volej až po skutečném zápisu.
- **Pošta** jde vždy přes `Core\Posta::odesli()` (SMTP nebo mail() podle Nastavení → Pošta), nikdy přímo `mail()`.
- Čtenář se registruje bez hesla; heslo nastavuje až z odkazu v e-mailu (`/ctenar/heslo/<token>`) – neměnit zpět na heslo v prvním kroku.

- **Hledání** jde přes sloupec `rs_clanky.hledani` (`Core\Hledani`): kdo ukládá článek jinudy než přes administraci nebo MCP,
  musí zavolat `Hledani::indexuj()`. **Nahrávání**: obrázky `Core\Obrazky`, přílohy `Core\Soubory` (whitelist přípon – HTML, SVG ani
  skripty nikdy). **E-maily** mají frontu (`rs_posta`, `Posta::zpracujFrontu()` z úloh na pozadí); jednorázové zprávy `doFronty: false`.
- **Testy:** logiku bez databáze (kryptografie, parsování, převody textu) pokryj v `tools/testy.php`; průchod webem hlídá `tools/test.sh`.

- **Tmavý režim webu:** šablona má na konci `style.css` blok `@media (prefers-color-scheme: dark) { :root[data-tmavy] { … } }` a `base.php`
  dává `<html data-tmavy>` podle nastavení `tmavy_rezim`. Barvy v šablonách proto piš přes proměnné, ne natvrdo.

- **Šablony článku** (Dlouhé čtení, Fotoreportáž, Rozhovor) jsou varianty: `cla_standard.php` dává `<article>` třídu
  `sablona-<soubor>` a vzhled je v `image/web.css`. Nová šablona layoutu musí tu třídu vypsat také. Co vkládáš před text článku,
  nesmí být `<p>` – šablony dávají prvnímu odstavci iniciálu.

- **Hranice napojení na Claude (rozhodnutí uživatele 2026-09-21, bezpečí na prvním místě):** přes MCP se mění jen obsah a VLASTNÍ
  šablony; žádný nástroj nesmí umět zapsat mimo `layout/<vlastní>/`, spustit kód ani dotaz. PHP šablony ukládané přes MCP musí projít
  `Core\SablonaKontrola` (povolovací seznam funkcí a konstrukcí; vestavěné šablony jím projít musí – hlídá `tools/testy.php`).
  Novou funkci do seznamu přidej jen tehdy, když nepracuje se soubory, sítí, procesy, zpětným voláním ani reflexí. Pravidla pro
  Claude pracujícího se soubory jsou v `layout/CLAUDE.md` (je součástí balíčku). Vydání nese podepsaný seznam souborů
  `system/soubory.json`; `Core\Integrita` podle něj ve Stavu systému hlásí změněné, chybějící a přidané soubory jádra.

- **E-mail čtenáři se skládá v jazyce příjemce, ne toho, kdo ho spustil:** obal vykreslení do `Jazyk::docasne($kod, fn)` (viz `Rozesilka::posli()`);
  texty e-mailů patří do slovníků webu (`en/sk/de.php`), ne administrace. Sloupec `jazyk` mají i novinky, ankety, odběratelé a vydání newsletteru.

- **Rozměry a barvu podkladu obrázků** doplňuje `Front\ObrazkyHtml::dopln()` do hotového HTML stránky (podle `rs_imggal_obr`); šablony je psát nemusí.
  V CSS šablon proto u obrázků s pevnou výškou počítej s atributem `height` – `image/web.css` má `:where(img[width][height]) { height: auto }` s nulovou vahou.

- **Skript nesmí hledat `[data-…]` prvek, který nikde nevzniká** – tak byl od zavedení fotogalerie rozbitý dialog Médií v editoru (chybělo tlačítko
  `data-vlozit`, první otevření spadlo). Hlídá to statická kontrola v `tools/testy.php`; po úpravě dialogů v `editor.js` je vždy otevři v prohlížeči.
  Atribut `hidden` platí i na tlačítkách díky `[hidden] { display: none !important }` v `image/editor.css`.

- **Vizuální editor bloků mluví jazykem administrace přihlášeného**, ne jazykem zobrazené verze webu: texty ve `vizual.js` obaluj `T()`
  (slovník `image/jazyky/admin-<kód>.js`), texty posílané z PHP překládá `$ta()` ve `views/front/vizual.php` ze slovníku `admin-<kód>.php`.

- **Administrace má Content-Security-Policy `script-src 'self'`** (posílá ji `admin.php` spolu s `no-store` a HSTS): žádné inline `<script>` ani `onclick=`/`onchange=`
  v šablonách administrace – chování patří do `image/admin.js` přes `data-` atributy (`data-odeslat-pri-zmene`, `data-ukaz-heslo`, `data-auto-odeslat`…). Hlídá `tools/testy.php`.

- **Paleta příkazů** (Ctrl/⌘+K, `views/admin/layout.php` + `admin.js`): nová obrazovka, kterou má jít rychle najít, se přidává do pole `$rychle`
  v layoutu (jen u modulu, na který má uživatel právo). Hledání článků jde přes `hledej_json&uprava=1` a respektuje `Auth::articleScope()`.

- **Úprava přímo na webu** (`Front\Kernel::upravaNaMiste()`, `views/front/upravit.php`): přihlášený s právem vidí u stránky a článku „Upravit zde“,
  `?upravit=text` vsadí do šablony webu stejný editor jako v administraci. Ukládají akce `uloz_text` v `Moduly\Clanky` a `Moduly\Stranky` – stejná oprávnění
  (`Auth::smiUpravitClanek()`), zámek, revize; návrat jen na místní adresu (`Modul::zpetNaWeb()`). Záměrně žádný stavitel stránek se sloupci.

- **Redakční předávka** (`Moduly\Clanky::upozorniRedakci()`): přechod na „Ke korektuře“ píše těm, kdo smějí vydávat, vydání a vrácení píše autorovi –
  v jazyce administrace příjemce (`Jazyk::docasne($kod, fn, 'admin-')`), respektuje `rs_user.upozorneni`. O titulní straně (hlavní stránka, připnutí) rozhoduje jen ten, kdo smí vydávat.

- **Prázdný výpis** v administraci vypisuj sdílenou šablonou `views/admin/prazdno.php` (ikona, nadpis, věta, první akce) – ne holou větou.
  Ikony bloků ve vizuálním editoru jsou ze stejné sady jako menu administrace (`views/admin/ikony.php`, klíče `b-*`); `Bloky::KATALOG` nese název ikony.

- **Překlad článku asistentem** (`Asistent::preloz()`): HTML se rozloží na kostru a úseky (`rozloz`/`sloz`); od modelu se bere jen text úseků
  (escapuje se), značky se vracejí z originálu. Prostá textová pole (titulek, SEO…) předávej v `$prosta`, jinak se escapují dvakrát.
  Překlad je vždy koncept. Adresu API jde změnit jen konstantou `MIROCMS_AI_URL` v `config.php` – nikdy ji nedávej do Nastavení.

- **Oprávnění podle rubriky** (`rs_user_rubriky`, `Auth::povoleneRubriky()` – null = bez omezení, povolení se dědí na podrubriky):
  cokoli čte nebo ukládá články v administraci či přes MCP, musí ho respektovat stejně jako `Auth::spravovaniAutori()`
  (výpisy přes `Auth::articleScope()`; jednotlivý článek `Moduly\Clanky::nacti()`; dále uložení, hromadný přesun a `Mcp\Nastroje`).

- **Překlady doplňuj nástrojem** `tools/slovnik.py <slovník> < řádky „česky|překlad“` – správně escapuje apostrofy (ruční skládání PHP
  řetězců už dvakrát rozbilo anglický slovník). Po každé změně slovníků spusť `tools/test.sh`.

## Spuštění

`php -S localhost:8080 system/dev-router.php` (preview: konfigurace `mirocms`). MySQL: `mysql.server start`,
databáze `mirocms`, uživatel `mirocms` (údaje v `config.php`, není v gitu). Čistá instalace: smazat
`config.php`, `DROP` tabulek `rs_*`, otevřít `/install.php`.

Po změně: `tools/test.sh` (lint, jednotkové testy, čistá instalace a průchod webem; potřebuje MySQL), případně jen `php tools/testy.php`, a projít dotčené stránky v prohlížeči / přes curl.

## Písmo prostředí

Administrace, přihlášení, instalátor a ovládání vizuálního editoru používají **Noto Sans** hostované u sebe (`image/pismo.css`, soubory v `image/pisma/`, licence OFL v `image/pisma/OFL.txt`). Styly si ho berou přes `@import url("pismo.css")`. Šablon webu se to netýká – jejich písma řídí Identita webu a šablona sama.

## Příručka

Uživatelská dokumentace je v `docs/prirucka/<jazyk>/` (cs je zdroj, en a de překlady se stejnými názvy souborů), pořadí a překlad adres v `docs/prirucka/osnova.json`. Web projektu (repozitář `mirocms-cms/web`, lokálně `../mirocms-web`) ji při sestavení přebírá. Při změně chování nebo popisku v administraci upravte i příručku.

## Nápověda a ukázkový obsah

- `Core\Napoveda` skládá odkazy z administrace a instalátoru do příručky na mirocms.eu v jazyce uživatele (sk → cs). Tabulka adres musí
  odpovídat `docs/prirucka/osnova.json` (hlídá `tools/testy.php`). Nová stránka příručky = zvážit odkaz z místa, kterého se týká.
  Patička administrace (verze · Nápověda · mirocms.eu · Podpořit MiroCMS) je v `views/admin/layout.php`, tedy na každé stránce.
  **Nápověda k obrazovce** je tlačítko vedle nadpisu stránky (layout, `<details class="napoveda-menu">`): které stránky příručky nabídne, říká `Napoveda::TEMATA`
  (klíč = ident modulu, případně `modul:záložka` nebo `modul:akce`). Do šablon už NEPIŠ řádky s odkazy na příručku; nová obrazovka nebo stránka příručky = položka v `TEMATA`
  (titulek = první nadpis české stránky, překlad do slovníků administrace; hlídá `tools/testy.php`). `Napoveda::odkaz()` zůstává jen pro odkaz uvnitř věty.
- `Core\Demo` + `system/demo/` (texty `obsah.php` v cs/en/de, ilustrace v `img/` jsou vlastní dílo projektu): ukázkový magazín, který jde
  nahrát při instalaci (zaškrtávátko) nebo v Nastavení → Základní a jedním kliknutím smazat. Co vzniklo, eviduje nastavení `demo_obsah`.
- Instalátor se po dokončení smaže sám (`Installer::smazSe()`); ve vývojové kopii se složkou `.git` ne.
- Zapomenuté heslo do administrace: `Admin\ObnovaHesla` (admin.php?akce=heslo) - odpověď vždy stejná, v DB jen otisk tokenu
  (`rs_user.obnova_otisk`, `obnova_cas`), odkaz platí hodinu a jednou, 2FA se nevypíná, limit 5 žádostí z IP za 15 minut.
- Aktualizace po přepsání souborů smaže ty, které staré vydání mělo v `system/soubory.json` a nové balíček už neobsahuje
  (`Aktualizace::uklidZastarale`, chráněné cesty a vlastní soubory se nemažou). Přejmenování souboru jádra je tedy bezpečné až od 3.0.0-beta.3.
- `Settings::PODLE_JAZYKA` (název a popis webu): jazyková verze webu může mít vlastní hodnotu v klíči `nazev_webu_en` apod.; prázdné = výchozí.
- Přihlašovací klíče (passkeys / WebAuthn): `Core\Passkey` jen počítá (výzva, ověření registrace a přihlášení; bez knihoven, podpis přes openssl),
  `Auth::vyzvaKlice()/overKlic()` je druhý krok přihlášení, `Admin\Ucet::klic()` registrace, `image/klice.js` prohlížeč, tabulka `rs_user_klice`.
  Klíč jde přidat jen k účtu se zapnutým TOTP (je to pohodlnější náhrada kódu; kód a záložní kódy zůstávají jako záloha); vypnutí TOTP klíče smaže.
  Ověřovací jádro hlídá softwarový autentikátor v `tools/testy.php` - každou změnu v `Passkey.php` doplňte o test včetně záporného případu.
- Rozšíření jsou samostatná položka hlavní nabídky: `Moduly\RozsireniAdmin` dědí z `Konfigurace` a drží pevnou „záložku“ `rozsireni`
  (šablony dál v `views/admin/config/`); stará adresa `?modul=config&zalozka=rozsireni` přesměrovává.

## Import z WordPressu a export webu

Modul `Moduly\Prenos` (Správa → Import a export, jen správce) je jen obsluha formulářů; práci dělají třídy v `Core/`:

- `WpSoubor` čte export WXR proudem (XMLReader, v paměti je vždy jediný `<item>`), `WpObsah` převádí obsah příspěvku na HTML editoru
  (bloky Gutenbergu, `[caption]`, `[gallery]`, adresy videí, odstavce klasického editoru, povolovací seznam značek = `POVOLENE` z `image/editor.js` bez IFRAME),
  `WpImport` zapisuje do databáze, `StahovaniObrazku` stahuje obrázky, `ExportWebu` dělá archiv `storage/zalohy/export-*.zip`.
- **Dávky:** každý požadavek zpracuje nejvýš `WpImport::DAVKA` položek nebo `SEKUND` vteřin; pozice (kolikátý `<item>`) a volby jsou ve stavovém souboru
  `storage/import/stav-<otisk>.json`, ne v session. Formulář průběhu se odesílá sám (`data-auto-odeslat`). Fáze: `analyza` → `nahled` → `import` → `hotovo` → `obrazky` → `obrazky-hotovo`.
- **Idempotence:** tabulka `rs_import_mapa` (zdroj `wp:<doména>`, typ, cizí id → naše id). Už převedený a stále existující záznam se přeskočí a NEPŘEPISUJE
  (redakce ho mohla upravit). Každý příspěvek je jedna transakce. Kdo přidá další typ obsahu, musí ho zapisovat do mapy také.
- Importovaný článek vzniká jako v `Core\Demo`: `Hledani::indexuj`, `Galerie::zapisPouziti`, `oznameno` = teď (žádný webhook, IndexNow, push ani newsletter).
  Účty se nezakládají (`externi_autor`), e-mail a IP komentujících `WpSoubor` vůbec nečte. Přesměrování jdou přes `Presmerovani::pridej`; číselnou adresu `/?p=123` obsluhuje `Front\Kernel::handle()`.
- **Bezpečnostní pravidla, která se nesmí rozvolnit** (hlídá `tools/testy.php`): XML s DOCTYPE nebo entitou se odmítá celé, čte se s `LIBXML_NONET` a bez `LIBXML_NOENT`;
  obsah exportu je nedůvěryhodný – do článku projde jen povolovací seznam značek a atributů, adresy jen http(s)/mailto/tel/místní;
  `StahovaniObrazku` stahuje jen z domény starého webu (± `www.`), jen http(s) na výchozím portu a bez přihlašovacích údajů v adrese, všechny přeložené IP adresy musí být veřejné
  (`ZAKAZANE_SITE`, včetně přechodových IPv6 rozsahů 6to4, Teredo a NAT64), spojení se připíná na ověřenou IP (`CURLOPT_RESOLVE`; bez curl přímo na IP s `Host` a `peer_name`), přesměrování nejvýš 3 a nikdy automaticky,
  5 s spojení / 20 s celkem / 15 MB, typ podle hlavičky I obsahu (SVG nikdy), žádné cookies ani hlavičky z importu; stažená data jdou jen přes `Obrazky::ulozSoubor()` (překódování GD).
- **Export** vybírá nastavení z povolovacího seznamu `ExportWebu::NASTAVENI` – nové tajné nastavení se tak do exportu nedostane samo. Nikdy do něj nepatří účty, čtenáři, odběratelé,
  e-maily a otisky adres komentujících. Stahuje se přes `akceStahni` po kouscích (`readfile`), ne přes `Response` – archiv může mít stovky MB.
- Ukázkový export pro testy je `tools/fixtures/wordpress-ukazka.xml` (smyšlený obsah); průchod importem má `tools/test.sh`.
