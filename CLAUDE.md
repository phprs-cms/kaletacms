# Kaleta

Open-source CMS pro **firemní weby** (stránky, novinky, builder, kolekce, formuláře, CRM pro každý den) s napojením na
jazykové modely. Čisté PHP 8.3+ bez frameworku a bez Composeru (PSR-4 autoloader v `system/bootstrap.php`), MySQL/MariaDB
přes PDO, serverové HTML + trocha vanilla JS. Mapa kódu a „jak přidat …“: **`docs/ARCHITECTURE.md`**; popis funkcí po
částech: **`docs/FEATURES.md`**; schéma: `docs/schema.md`; vrstvy: `docs/design/code-layers.md`; testy: `tools/tests/README.md`.
Návrh a rozhodnutí: `../kaleta-interni/NAVRH.md`; co vyšlo: `docs/CHANGELOG.md`; co bude: `docs/ROADMAP.md`.

Tento soubor nese jen závazná pravidla a má strop **16 KB** (unit 3870). Popis nové funkce patří do `docs/FEATURES.md`
nebo `docs/ARCHITECTURE.md`, poznámky k vydání jen do `docs/CHANGELOG.md` – sem jen nové pravidlo, které hlídá test.

**Veřejně (README, texty, commity) se na projekt, ze kterého jádro vzniklo, neodkazuje.**

## Zásady

- **Jednoduchost nad abstrakcí.** Kód má přečíst i poučený laik. Žádné DI kontejnery, ORM, build kroky ani npm. Nová závislost = silný důvod.
- **Firemní web, ne magazín.** Žádné redakční workflow (korektura, zámky, předávka), rubriky, komentáře, čtenáři, předplatné,
  reklama ani push (newsletter jen jako jedna šablona podle design systému). Produkt je jen „Kaleta“ (žádná podznačka);
  odpovědná osoba = odpovědnost, nikdy schvalovací krok.
- **Standardy webu 2026/2027:** CSS vrstvy, `clamp()`, container queries, `color-mix()`/OKLCH, `:has()`, Popover API, `<dialog>`,
  `<details>`, View Transitions. Interaktivita přednostně bez JavaScriptu. Žádné polyfilly, CDN ani cizí písma.
- **Co se nevypisuje, nemá styl ani skript** (selektor ani `[data-…]`, který nikde nevzniká – hlídá unit test).
- **Anglicky:** identifikátory, komentáře, commity, dokumentace a zdrojové texty rozhraní. **Česky zůstává datový model** –
  starší tabulky a sloupce (`idc` = novinka, `tema`/`idt` = kategorie, `ido` = médium, `idu` = uživatel), klíče staveb (JSON)
  a design systému, CSS třídy a `data-` atributy administrace; na hranici MCP je překládá `Mcp\Translator` a `Mcp\Vocabulary`.
  **Tabulky ani sloupce se nikdy nepřejmenovávají** (ROADMAP „Path to 4.0“); nové tabulky, sloupce a nastavení jsou anglicky.
- **Parametry adresy (query) vždy anglicky** (`preview_key`, `build`, `page`, `search`, `status`, `item`…) v odkazech, GET formulářích,
  `Request::get*()`, JS i testech; hodnoty a názvy polí v POST zůstávají. Dřívější české názvy jsou aliasy v `Request::LEGACY_QUERY`
  (odkazy v e-mailech a záložky se nerozbijí) – záznam se nemaže, nový odkaz píše jen anglicky (hlídá unit test).
- **Tabulky v kódu přes `{novinky}`** (nebo anglicky `{news}`, `Core\Names`); nová tabulka = záznam v `system/sql/names.php` (unit test).
  Některé tabulky smí jmenovat jen vyjmenované třídy (ceny a nabídky unit 2800, interní soubory unit 3550).
- **Změna databáze = dva zápisy:** úplné `system/sql/schema.sql` + migrace `system/sql/migrace/NNNN-popis.sql` + zvýšit
  `KALETA_DB_VERSION` v `system/bootstrap.php` (hlídá `tools/test.sh`), pak `php tools/schema-doc.php`. Datová migrace je `.php`
  (vrací funkci `(Db, Settings)`) a má nejvyšší číslo svého vydání – starý kód aktualizace zná jen `.sql`. Přenositelně pro MySQL
  i MariaDB, bez odkazu na cílovou tabulku v ON DUPLICATE KEY UPDATE; každá migrace projde `php tools/check-migrations.php`
  a `tools/test-migrations.sh` (čistá instalace migrace nespouští).
- **Nastavení:** klíč s výchozí hodnotou v `Settings::DEFAULTS` + typ v `Admin\Modules\Settings::FIELDS` + řádek `$field(...)` ve
  `views/admin/settings/<záložka>.php`; `php tools/settings.php <klíč>` ukáže všechny seznamy (unit 3650). Nové pole typu `tajne`
  patří do `Vault::SECRET_SETTINGS` (unit 3050). Výchozí texty pro návštěvníky v `Settings::TRANSLATED_DEFAULTS`.
- **Vrstvy kódu:** Core neodkazuje na Admin/Front/Builder/Fleet/Mcp, Mcp ne na `Admin\Modules`; pravidlo dat patří do Core
  (unit 3100, zmrazený seznam výjimek jen ubývá).
- **Rozšíření** (`Core\Extensions::CATALOG`, `Module::EXTENSION`, `Element::EXTENSION`) jsou vestavěná; vypnuté = modul zmizí,
  prvek se nenabízí ani nevykreslí, sekce knihovny s ním se nenabízejí, trasy vrací 404. Nové rozšíření zapnuté ve výchozím stavu
  potřebuje migraci (viz 0016). **Doplňky** jiných vývojářů jen v `extensions/<slug>/` přes `Kaleta\Extension\Api` (`docs/EXTENSIONS.md`);
  kód se nikdy nenahrává z administrace ani nestahuje z internetu.
- **Role:** správce (2), editor (1 – veškerý obsah, vydává), autor novinek (0 – jen své, nevydává); `Auth::canPublish()`,
  `managedAuthors()`, `articleScope()`; práva k sekcím `ka_uzivatele_prava` (`Users::defaultModules()`); vlastní role (`ka_role`)
  přepisují práva a úroveň členů. Sekce s `Module::GRANTED_EXPLICITLY` se přidělují výslovně.
- **Administrace:** CSP `script-src 'self'` – žádné inline skripty ani `on*=`, chování do `image/admin.js` přes `data-`; nikdy
  `window.confirm()` (atribut `data-potvrdit="text"`); prázdný výpis přes `views/admin/empty.php`; jediný vzhled `image/admin.css`,
  kontrola ve světlém i tmavém režimu a v šířce telefonu; písmo Bricolage Grotesque z `image/pisma/`.
- **Značka:** „kaleta.“ malými se signální tečkou, ikona „k.“; Ink `#121212`, Paper `#F6F4EE`, Signal `#FF4F2E` (jen tečka a drobné
  akcenty, nikdy malý text). Logo vždy z `views/admin/logo.php` / `image/kaleta-*`, nikdy písmem; na weby uživatelů se nedává.

## Texty a slovníky

- Texty přes `t('English')` (`Core\Language`): web `system/jazyky/<kód>.php`, administrace `admin-<kód>.php`, instalátor
  `install-<kód>.php`, skripty administrace `image/jazyky/admin-<kód>.js` (`T()`, `php tools/js-dictionaries.php --check|--fill`, unit 3680).
  Úplnost hlídají unit testy. Doplňuj `tools/add-translations.py <soubor>` (stdin `English|překlad`); `admin-de-du.*` ručně.
  Hodnoty formulářů se nepřekládají.
- **Slovníky se slučují `merge=union`** (`.gitattributes`): jedna položka = jeden řádek a **každá končí čárkou, i poslední** před
  `];`/`};`. Duplicitní klíč nebo poslední položka bez čárky shodí unit 3860 – po sloučení větví ho pusť.
- `tools/test-english.sh` (i v CI): anglický instalátor, web i administrace bez češtiny (`tools/find-czech.php`, `--js`).
  Stránky startovacích webů musí projít kontrolou před publikováním (unit test).

## Web

- **Themeless:** rámec `system/views/front/base.php` + `image/sablona.css` (pohledy nejdou přepsat, vlastní PHP layouty ne); vzhled
  = design systém, třídy, komponenty a části webu. Barvy, písma a rozměry z tokenů (`--ka-barva-*`, `--ka-krok-*`, `--ka-mezera-*`,
  `--ka-sirka`) s výchozí hodnotou. **Vrstvy** `@layer tokeny, spolecne, sablona, stavitel, tridy, prvky;` (`DesignSystem::LAYERS`) –
  nic nevrstveného, žádné `!important`. Tmavý režim `:root[data-tmavy]` v `@media (prefers-color-scheme: dark)`. Společné prvky
  v `image/web.css`/`web.js` v `:where()` s nulovou vahou.
- **Jazykové verze:** každý dotaz webu vypisující obsah filtruje `Language::siteColumn()`; `App::url()` přidává `/en/` jen adresám bez přípony.
- **Cache stránek** jen pro nepřihlášené; `Auth::user()` nesmí na webu založit session anonymnímu návštěvníkovi. Nová sekce
  administrace nebo zapisovací nástroj MCP = zařazení do `Admin\Kernel::SITE_CHANGING_MODULES`/`SITE_NEUTRAL_*` resp.
  `Catalog::SITE_NEUTRAL` (unit test); v pochybnostech maže.
- **Adresa webu je nastavení `site_url`,** nikdy hlavička Host (`$app->request->origin()`).
- **Výpisy novinek nenačítají dlouhé texty** (`Front\NewsRepository::LIST_COLUMNS`). Kdo ukládá novinku mimo admin a MCP, volá
  `Search::index()`. Oznámení o vydání jen přes `Core\Notifications::process()`; změna adresy zapíše přesměrování (`Redirects::add`).
- **Statistika bez cookies a identifikátorů** – jen součty po dnech, jen zobrazení potvrzená prohlížečem (`POST /hit`).
- **Rychlost:** žádné kritické CSS ani build krok; vlastní písma `font-display: swap`, předem jen textový a titulkový řez.
- Kořenový `.htaccess` drží pravidla v bloku `# BEGIN Kaleta` … `# END Kaleta sha256:` – úprava bloku = nový otisk (unit test).

## Builder

- **Jediný validátor `Build::sanitize()`** (editor, MCP, import – nikdy neukládej stavbu bez něj) a **jediný vykreslovač `Build::render()`**;
  na webu se vadný prvek vynechá, nikdy výjimka. Jeden prvek = jedna značka. Třídy `ka-…` jsou vyhrazené Kaletě (`Build::CLASS_PATTERN`, `Html::safe`).
- **Prvek** = třída v `Builder\Elements\` zapsaná v `Build::ELEMENTS`, základní CSS do vrstvy `stavitel` přes `:where()`; nový prvek,
  pole, vlastnost stylu nebo token = záznam v `Mcp\Vocabulary` (unit test převádí knihovnu tam a zpět).
- **Výpočet tokenů design systému jen v PHP** (`DesignSystem`, náhled akcí `nahled`), nikdy duplikovat v JS. Vlastní CSS tříd přes
  `Style::customCss()` (bez `url()`, bloků, `@`).
- **Publikování** přes `Builder\Publisher` (verze v `ka_stavba_revize`, text bez rozložení `Build::asText`). Koncept vzhledu (`Core\Look`):
  změny design systému, existujících tříd a menu jdou do `look_draft`, publikuje `Look::publish`.
- **Formuláře:** pole a příjemce vždy z PUBLIKOVANÉ stavby, nikdy z požadavku; výsledek jen jako kód v adrese, text hlášení nikdy
  z adresy; `Core\Antispam` bez cookies.
- **Kolekce a komponenty:** `{{pole}}` jen přes `Collections::fill()` (escapuje podle typu cílového pole); ochrana proti zanoření.

## Obsah, služby, bezpečnost

- **Pošta vždy přes `Core\Mail::send()`** (fronta `ka_posta`; osoba v předmětu jen do e-mailu, ne do logu); newslettery jen SMTP
  a jen z cronu. Nikdy skutečný e-mail z testu.
- **Nahrávání:** obrázky `Core\Images`, přílohy `Core\Files` (povolené přípony), SVG přes `Svg::sanitize`.
- **Čas:** databáze drží čas webu; nový kód přes `Core\Clock` (`now()`, `toDb()`, `fromDb()`, `at('-1 hour')`, nikdy `NOW() - INTERVAL`).
- **Tajné hodnoty** (`Vault::SECRET_SETTINGS`, typ `tajne`, TOTP semínka) zapečetěné klíčem z `config.php` – čti a zapisuj přes
  Settings / `Vault`. Nikdy nečti `tools/klice/*.key` ani nevypisuj `config.php`.
- **Přihlášení:** pravidla jen v `Core\SignInPolicy`; každá citlivá změna přes `Auth::freshProof(…)`, „současné heslo“ jen přes
  `Auth::confirmPassword`; každá změna druhého faktoru volá `Auth::newEpoch`; záložní kódy jen `Core\BackupCodes`.
- **Akce administrace jen v kanonickém tvaru** (`Module::methodFor`); brána vydávání `Module::LIVE_ACTIONS` porovnává metodu, ne text.
- **Webhooky** jen `Core\Webhook` (fronta, podpis HMAC); adresy a klíče nikdy přes MCP. Testy míří na falešné servery
  (`*_test_url`, jen `http://127.0.0.1:<port>`, jen přes databázi).
- **AI asistent:** odpověď modelu je nedůvěryhodný vstup; překlad bere od modelu jen text, výsledek je vždy koncept; adresa API
  jen z konstanty `KALETA_AI_URL`.
- **Náhledy konceptů** jen podepsané (`Core\Preview`, jeden cíl, omezená platnost).
- **Import z WordPressu a webu — co se nesmí rozvolnit** (unit testy): XML s DOCTYPE/entitou se odmítá, `LIBXML_NONET`; obsah jen
  přes povolovací seznam značek; `ImageDownloader` jen z domény starého webu, jen veřejné IP, připnuté spojení, limity, nikdy SVG.
  Export jen nastavení ze `SiteExport::SETTINGS` (nikdy účty, hesla, klíče); import Kalety jen do prázdného webu a každý řádek přes sanitizéry.
- **Poptávky:** `Enquiries::visible` je podmínka každého čtení řádků poptávek (unit test). Žádosti o místo nejsou poptávky;
  životopisy MCP nikdy nedostane. Nový druh cíle = `Inbox::TARGETS`, `Inbox::parse`, `Tasks::cleanTarget` + `sweep`,
  `Assignment::KINDS` a popisek v `my_work` (unit test).
- **CRM a reporty:** souhrny jsou počty, nikdy hodiny ani částky (měsíční report jen počty obchodů); ceny jen v `ka_prices`.
- **Flotila:** konzole nikdy nesahá do webů, odkazy jsou obyčejné odkazy; heartbeat nese počty, data, výčty a krátké systémové texty,
  nikdy osobní údaje; konzole ohraničí každý nový klíč.
- **Žádné mazání při GET** – úklid patří do úlohy Scheduleru (`trash`, `cleanup`).

## MCP

- MCP (`/mcp`) je hlavní cesta, jak se na Kaletě stavějí weby – **co jde v administraci, musí jít i tady** (mapa `$parity` v unit testech:
  čtení, nástroj, nebo „admin: důvod“). Vlastní PHP šablony ani kód přes MCP nikdy; žádný nástroj nesmí zapisovat mimo obsah,
  spouštět kód ani dotaz.
- **Rozhraní je anglické** (`Mcp\Translator`, `Mcp\Vocabulary`); české názvy jsou skryté aliasy. Nový nástroj nebo parametr = záznam
  v `Translator::TOOLS`, nové hlášení v `MESSAGES`/`MESSAGE_PATTERNS`. Nový nástroj do sady z `Catalog` (nikdy `ESSENTIALS`), sady
  se vejdou do `Catalog::BUDGETS` (unit 2550). Kontrakt `php tools/contracts.php` jen přibývá; nic nesmí rozbít existující spojení.
- Text zvenku (návštěvníci, uchazeči, cizí weby) jde do výsledku s `Mcp\Untrusted::mark`; adresy stahuje jen plné spojení (`Catalog::mayFetch`).
- Nástroj, který posílá e-mail mimo tým nebo trvale maže, patří do `Mcp\Confirmation::TOOLS`. Nová stránka je skrytá, nová novinka
  koncept; vydat jen na výslovný pokyn a s právem. Spojení jen na koncepty píše koncepty.
- `update_settings` jen klíče z `MCP_SETTINGS`, hodnoty přes `Settings::verifyValue`.
- Hodinový limit změn Claude platí na uživatele (všechna jeho připojení); undo podle názvu připojení a uživatele
  (`AgentJournal::connectionLabel`).

## Testy a vydání

- Po změně: `tools/test.sh` (lint, unit, čistá instalace, web, admin, MCP; databázi `kaleta_test` smaže), `tools/test-english.sh`,
  `tools/test-migrations.sh`, `tools/test-browser.sh`, `php tools/contracts.php`; jen unit: `php tools/unit-tests.php [číslo|část názvu]`.
- Nové téma = nový soubor `tools/tests/unit|smoke/NNNN-tema` s dalším volným číslem, nikdy připisovat do cizího.
- **Žádné nové kontroly zdrojového textu** (`[source-pin]`, strop v unit 2610) – testuj chování. `catch (\PDOException $e)` volá
  `Db::rethrowUnlessMissing($e)` nebo řekne proč (unit 2600). Testovací přepínače jen přes `Core\TestSeams`; v unit testech
  `Clock::freeze()`; smoke používá `background_jobs` a nikdy nezapisuje `notification_check`; zámky MySQL přes `$db->lockName()`;
  rozpočty dotazů `X-Kaleta-Queries` (smoke 2610).
- **Kouřový test:** jeden falešný SMTP celé sady (PORT+21, `test.sh` používá PORT…PORT+21), `mail()` v sadě vždy selže; sekce,
  která zkouší `mail()`, ho nastaví sama a vrátí `$MAIL_SINK_VALUES`. Nikdy holé `wait` – jen `wait "$PID"` nebo `await_pids` (unit 3830).
- Žádné tokeny ani klíče tvaru tajemství v testech (gitleaks) – skládat za běhu. Nikdy nezastavovat MySQL.
- Vývoj: `php -S 127.0.0.1:8095 system/dev-router.php`, databáze `kaleta_dev`, `config.php` není v gitu. Snímky: `tools/screenshots.sh`.
- **Vydání:** `KALETA_VERSION` v `system/bootstrap.php`, `php tools/release.php <verze> --url=…` (`docs/RELEASING.md`), záznam do
  `docs/CHANGELOG.md` (nahoru); tento soubor se při vydání nemění. Veřejné klíče `system/aktualizace.pub`, soukromé jen mimo repozitář.
  Kanál aktualizací `https://kaletacms.com/aktualizace.json`, repozitář `github.com/phprs-cms/kaletacms`.
