<!--
Proposal (3.42 T7, audit Q20) for a slimmer CLAUDE.md – not active; the lead decides whether it replaces CLAUDE.md.

Why: CLAUDE.md is loaded into every agent session (58 KB today, about 15 000 tokens per task). Most of it describes
features release by release; that history belongs in docs/ROADMAP.md and the code, and the map of the code is now
docs/ARCHITECTURE.md (English, for contributors too), the schema docs/schema.md, the settings tools/settings.php.
What stays below: the binding rules – the ones a change can break without a failing test, or that the tests enforce
and an agent needs to know before it starts. Everything kept is worded as in CLAUDE.md today; feature descriptions are
cut to the invariant that must not be loosened (security boundaries, single validators, single writers).
About 12 KB. Language: Czech, as CLAUDE.md.
-->

# Kaleta

Open-source CMS pro **firemní weby** (stránky, novinky, builder, kolekce, formuláře, CRM pro každý den) s napojením na
jazykové modely. Čisté PHP 8.3+ bez frameworku a bez Composeru (PSR-4 autoloader v `system/bootstrap.php`), MySQL/MariaDB
přes PDO, serverové HTML + trocha vanilla JS. Mapa kódu, průchod požadavku a „jak přidat …“: **`docs/ARCHITECTURE.md`**;
schéma s anglickým významem sloupců: **`docs/schema.md`**; vrstvy: `docs/design/code-layers.md`; testy: `tools/tests/README.md`.
Návrh a rozhodnutí: `../kaleta-interni/NAVRH.md`, historie vydání: `docs/ROADMAP.md`.

**Veřejně (README, texty, commity) se na projekt, ze kterého jádro vzniklo, neodkazuje.**

## Zásady

- **Jednoduchost nad abstrakcí.** Kód má přečíst i poučený laik. Žádné DI kontejnery, ORM, build kroky ani npm. Nová závislost = silný důvod.
- **Firemní web, ne magazín.** Žádné redakční workflow (korektura, zámky, předávka), rubriky, komentáře, čtenáři, předplatné,
  reklama ani push. Produkt je jen „Kaleta“ (žádná podznačka); odpovědná osoba = odpovědnost, nikdy schvalovací krok.
- **Standardy webu 2026/2027:** CSS vrstvy, `clamp()`, container queries, `color-mix()`/OKLCH, `:has()`, Popover API, `<dialog>`,
  `<details>`, View Transitions. Interaktivita přednostně bez JavaScriptu. Žádné polyfilly, CDN ani cizí písma.
- **Co se nevypisuje, nemá styl ani skript** (selektor ani `[data-…]`, který nikde nevzniká – hlídá unit test).
- **Anglicky:** identifikátory, komentáře, commity, dokumentace a zdrojové texty rozhraní (`t('English')` + slovníky přes
  `tools/add-translations.py`; `admin-de-du.js` ručně). Parametry adresy (query) vždy anglicky; dřívější české jsou aliasy
  v `Request::LEGACY_QUERY` a nemažou se. **Česky zůstává datový model** – starší tabulky a sloupce (`idc` = novinka, `tema`/`idt`
  = kategorie, `ido` = médium, `idu` = uživatel), klíče staveb (JSON) a design systému, hodnoty některých nastavení, CSS třídy a
  `data-` atributy administrace. **Nikdy se nepřejmenovávají**; nové tabulky, sloupce, nastavení, třídy a atributy jsou anglicky.
- **Tabulky v kódu přes `{novinky}`** (nebo anglicky `{news}`, `Core\Names`); nová tabulka = záznam v `system/sql/names.php`.
- **Změna databáze = dva zápisy:** `system/sql/schema.sql` + migrace `system/sql/migrace/NNNN-popis.sql` (datová `.php`, nejvyšší
  číslo svého vydání) + `KALETA_DB_VERSION`; pak `php tools/schema-doc.php`. Přenositelně pro MySQL i MariaDB, bez odkazu na cílovou
  tabulku v ON DUPLICATE KEY UPDATE; každá migrace musí projít `tools/test-migrations.sh`.
- **Nastavení:** klíč s výchozí hodnotou v `Settings::DEFAULTS` + typ v `Admin\Modules\Settings::FIELDS` + řádek `$field(...)` ve
  `views/admin/settings/<záložka>.php` (tajné, export, jazykové verze a přístup Clauda ukáže `php tools/settings.php <klíč>`; unit 3650).
- **Vrstvy kódu:** Core neodkazuje na Admin/Front/Builder/Fleet/Mcp, Mcp ne na `Admin\Modules`; pravidlo dat patří do Core
  (unit 3100, zmrazený seznam výjimek jen ubývá).
- **Rozšíření** (`Core\Extensions::CATALOG`) jsou vestavěná; vypnuté = modul zmizí, prvek se nenabízí ani nevykreslí, trasy vrací 404.
  Nové rozšíření zapnuté ve výchozím stavu potřebuje migraci. **Doplňky** jiných vývojářů jen v `extensions/<slug>/` přes
  `Kaleta\Extension\Api`; kód se nikdy nenahrává z administrace ani nestahuje z internetu.
- **Role:** správce (2), editor (1 – veškerý obsah, vydává), autor novinek (0 – jen své, nevydává); `Auth::canPublish()`,
  `managedAuthors()`, `articleScope()`; práva k sekcím `ka_uzivatele_prava`; vlastní role (`ka_role`) přepisují práva členů.
- **Administrace:** CSP `script-src 'self'` – žádné inline skripty ani `on*=`, chování do `image/admin.js` přes `data-`; nikdy
  `window.confirm()` (atribut `data-potvrdit`); prázdný výpis přes `views/admin/empty.php`; jediný vzhled `image/admin.css`,
  kontrola ve světlém i tmavém režimu a v šířce telefonu; písmo Bricolage Grotesque z `image/pisma/`.
- **Značka:** „kaleta.“ malými se signální tečkou, ikona „k.“; Ink `#121212`, Paper `#F6F4EE`, Signal `#FF4F2E` (jen tečka a drobné
  akcenty, nikdy malý text). Logo vždy z `views/admin/logo.php` / `image/kaleta-*`, nikdy písmem; na weby uživatelů se nedává.

## Web

- **Themeless:** rámec `system/views/front/base.php` + `image/sablona.css`, vzhled = design systém, třídy, komponenty a části webu.
  Barvy, písma a rozměry z tokenů (`--ka-barva-*`, `--ka-krok-*`, `--ka-mezera-*`) s výchozí hodnotou. Vrstvy
  `@layer tokeny, spolecne, sablona, stavitel, tridy, prvky;` – nic nevrstveného, žádné `!important`. Tmavý režim
  `:root[data-tmavy]` v `prefers-color-scheme: dark`. Společné prvky v `image/web.css`/`web.js` v `:where()`.
- **Jazykové verze:** každý dotaz webu vypisující obsah filtruje `Language::siteColumn()`; `App::url()` přidává `/en/` jen adresám bez přípony.
- **Cache stránek** jen pro nepřihlášené; `Auth::user()` nesmí na webu založit session anonymnímu návštěvníkovi.
- **Adresa webu je nastavení `site_url`,** nikdy hlavička Host (`$app->request->origin()`).
- **Výpisy novinek nenačítají dlouhé texty** (`Front\NewsRepository::LIST_COLUMNS`). Hledání: kdo ukládá novinku mimo admin a MCP, volá `Search::index()`.
- **Statistika bez cookies a identifikátorů** – jen součty po dnech, počítá jen zobrazení potvrzené prohlížečem (`POST /hit`).
- **Rychlost:** žádné kritické CSS ani build krok; vlastní písma `font-display: swap`, předem jen textový a titulkový řez.

## Builder

- **Jediný validátor `Build::sanitize()`** (editor, MCP, import – nikdy neukládej stavbu bez něj) a **jediný vykreslovač `Build::render()`**;
  na webu se vadný prvek vynechá, nikdy výjimka. Jeden prvek = jedna značka. Třídy `ka-…` jsou vyhrazené Kaletě.
- **Prvek** = třída v `Builder\Elements\` zapsaná v `Build::ELEMENTS`, základní CSS do vrstvy `stavitel` přes `:where()`; nový prvek,
  pole, vlastnost stylu nebo token = záznam v `Mcp\Vocabulary` (unit test převádí knihovnu tam a zpět).
- **Výpočet tokenů design systému jen v PHP** (`DesignSystem`, náhled akcí `nahled`), nikdy duplikovat v JS. Vlastní CSS tříd přes
  `Style::customCss()` (bez `url()`, bloků, `@`).
- **Publikování** přes `Builder\Publisher` (verze v `ka_stavba_revize`, text bez rozložení `Build::asText`). Koncept vzhledu (`Core\Look`):
  změny design systému, existujících tříd a menu jdou do `look_draft`, publikuje `Look::publish`.
- **Formuláře:** pole a příjemce vždy z PUBLIKOVANÉ stavby, nikdy z požadavku; výsledek jen jako kód v adrese; `Core\Antispam` bez cookies.
- **Kolekce a komponenty:** `{{pole}}` jen přes `Collections::fill()` (escapuje podle typu cílového pole); ochrana proti zanoření.

## Obsah, služby, bezpečnost

- **Pošta vždy přes `Core\Mail::send()`** (fronta `ka_posta`); newslettery jen SMTP a jen z cronu. Nikdy skutečný e-mail z testu.
- **Nahrávání:** obrázky `Core\Images`, přílohy `Core\Files` (povolené přípony), SVG přes `Svg::sanitize`.
- **Čas:** databáze drží čas webu; nový kód přes `Core\Clock` (`now()`, `toDb()`, `at('-1 hour')`, nikdy `NOW() - INTERVAL`).
- **Tajné hodnoty** (`Vault::SECRET_SETTINGS`, typ `tajne`) zapečetěné klíčem z `config.php` – čti a zapisuj přes Settings / `Vault`.
  Nikdy nečti `tools/klice/*.key` ani `config.php`.
- **Oznámení a webhooky:** jen `Core\Notifications::process()` a `Core\Webhook` (fronta, podpis HMAC); adresy a klíče nikdy přes MCP.
- **AI asistent:** odpověď modelu je nedůvěryhodný vstup; překlad bere od modelu jen text, výsledek je vždy koncept.
- **Import z WordPressu a webu — co se nesmí rozvolnit:** XML s DOCTYPE/entitou se odmítá, `LIBXML_NONET`; obsah jen přes povolovací
  seznam značek; stahování obrázků jen z domény starého webu, jen veřejné IP, připnuté spojení, limity, nikdy SVG. Export jen nastavení
  ze `SiteExport::SETTINGS` (nikdy účty, hesla, klíče); import Kalety jen do prázdného webu a každý řádek přes sanitizéry.
- **CRM a reporty:** souhrny jsou počty, nikdy hodiny ani částky (měsíční report jen počty obchodů); ceny jen v `ka_prices`.
- **Flotila:** konzole nikdy nesahá do webů, odkazy jsou obyčejné odkazy; heartbeat nese počty, data, výčty a krátké systémové texty,
  nikdy osobní údaje; konzole ohraničí každý nový klíč.

## MCP

- MCP (`/mcp`) je hlavní cesta, jak se na Kaletě stavějí weby – **co jde v administraci, musí jít i tady** (mapa `$parity` v unit testech:
  čtení, nástroj, nebo „admin: důvod“). Vlastní PHP šablony ani kód přes MCP nikdy; žádný nástroj nesmí zapisovat mimo obsah.
- **Rozhraní je anglické** (`Mcp\Translator`, `Mcp\Vocabulary`); české názvy jsou skryté aliasy. Nový nástroj nebo parametr = záznam
  v `Translator::TOOLS`, nové hlášení v `MESSAGES`; nový nástroj nikdy v `Catalog::ESSENTIALS`. Kontrakt `php tools/contracts.php`
  jen přibývá; nic nesmí rozbít existující spojení (názvy, aliasy, parametry).
- Nástroj, který posílá e-mail mimo tým nebo trvale maže, patří do `Mcp\Confirmation::TOOLS`. Nová stránka je skrytá, nová novinka
  koncept; vydat jen na výslovný pokyn a s právem.

## Testy a vydání

- Po změně: `tools/test.sh` (lint, unit, čistá instalace, web, admin, MCP; databázi `kaleta_test` smaže), `tools/test-english.sh`,
  `tools/test-migrations.sh`, `tools/test-browser.sh`, `php tools/contracts.php`; jen unit: `php tools/unit-tests.php [číslo|část názvu]`.
- Nové téma = nový soubor `tools/tests/unit|smoke/NNNN-tema` s dalším volným číslem, nikdy připisovat do cizího.
- **Žádné nové kontroly zdrojového textu** (`[source-pin]`, strop v unit 2610) – testuj chování. `catch (\PDOException $e)` volá
  `Db::rethrowUnlessMissing($e)` nebo řekne proč (unit 2600). Testovací přepínače jen přes `Core\TestSeams`; v unit testech
  `Clock::freeze()`; smoke používá `background_jobs` a nikdy nezapisuje `notification_check`; zámky MySQL přes `$db->lockName()`;
  rozpočty dotazů `X-Kaleta-Queries` (smoke 2610).
- Žádné tokeny ani klíče tvaru tajemství v testech (gitleaks) – skládat za běhu. Nikdy nezastavovat MySQL.
- Vývoj: `php -S 127.0.0.1:8095 system/dev-router.php`, databáze `kaleta_dev`, `config.php` není v gitu.
- Vydání: `KALETA_VERSION` v `system/bootstrap.php`, `php tools/release.php` (`docs/RELEASING.md`); soukromé klíče jen mimo repozitář.
