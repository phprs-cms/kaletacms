# Kaleta

Open-source CMS pro **firemní weby** (stránky, novinky/blog, později builder stránek, kolekce a formuláře) s napojením na
jazykové modely. Návrh, rozhodnutí a fáze: `../kaleta-interni/NAVRH.md`. Čisté PHP 8.4+ bez frameworku a bez Composeru
(vlastní PSR-4 autoloader v `system/bootstrap.php`), MySQL přes PDO, serverové HTML + trocha vanilla JS.

**Veřejně (README, texty, commity) se na projekt, ze kterého jádro vzniklo, neodkazuje.**

## Zásady

- **Jednoduchost nad abstrakcí.** Kód má přečíst i poučený laik. Žádné DI kontejnery, ORM, build kroky ani npm. Nová závislost = silný důvod.
- **Firemní web, ne magazín.** Žádné redakční workflow (korektura, zámky, předávka), rubriky, komentáře, čtenáři, předplatné, reklama,
  ani push – fáze 0 je odstranila, nevracej je (newsletter se vrátil v 1.5 záměrně jen jako jedna šablona podle design systému). Co firmy potřebují navíc (formuláře a poptávky, údaje o firmě, kolekce,
  builder), přibývá podle `NAVRH.md`.
- **Standardy webu 2026/2027 bez ohledu na staré prohlížeče:** CSS vrstvy, `clamp()`, container queries, `color-mix()`/OKLCH, `:has()`,
  Popover API, `<dialog>`, `<details>`, View Transitions. Interaktivita přednostně bez JavaScriptu. Žádné polyfilly, CDN ani cizí písma.
- **Co se nevypisuje, nemá styl ani skript.** Do `image/web.css` ani `style.css` šablony nepatří selektor, který nikde nevzniká; skript nesmí
  hledat `[data-…]` prvek, který nikde nevzniká (hlídá `tools/unit-tests.php`).
- **Tabulky** mají významové názvy (`ka_novinky`, `ka_kategorie`, `ka_uzivatele`, `ka_media`, `ka_nastaveni`…); v kódu vždy přes `{novinky}`.
  Anglický název z mapy `system/sql/names.php` (`Core\Names`) funguje stejně (`{news}` = `ka_novinky`, 3.10); nová tabulka = záznam v mapě (hlídá `tools/unit-tests.php`).
  Starší názvy sloupců zůstaly: `idc` = novinka, `tema`/`idt` = kategorie, `ido` = médium, `idu` = uživatel.
- Identifikátory v kódu anglicky (od 1.4); komentáře v kódu anglicky, texty rozhraní anglicky se slovníky (`t()`). Česky zůstává
  datový model: sloupce databáze, klíče staveb (JSON) a design systému – na hranici MCP je překládá `Mcp\Translator` a `Mcp\Vocabulary`.
- **Parametry adresy (query) jsou vždy anglicky** (`preview_key`, `build`, `page`, `search`, `status`, `item`…) – v odkazech, formulářích s GET, `Request::get*()`,
  JS i testech; hodnoty a názvy polí v POST zůstávají, jak jsou. Dřívější české názvy fungují dál jako aliasy (`Request::LEGACY_QUERY`, `get*()`/`has()` je zkusí, když chybí anglický; odkazy v odeslaných e-mailech, záložky a sdílené náhledy se nikdy nerozbijí) – záznam z mapy se nemaže, nový odkaz píše jen anglicky. Hlídá to `tools/unit-tests.php` (seznam dřívějších českých názvů).
- **Změna databáze = dva zápisy:** úplné schéma `system/sql/schema.sql` a migrace `system/sql/migrace/NNNN-popis.sql` + zvýšit
  `KALETA_DB_VERSION` v `system/bootstrap.php` (hlídá `tools/test.sh`). Výchozí stav je migrace 0001.
- **Rozšíření** (`Core\Extensions::CATALOG`) jsou vestavěné části Kalety. **Doplňky (add-ons, 3.0)** jiných vývojářů jsou
  v `extensions/<slug>/` a mluví jen přes `Kaleta\Extension\Api` (kontrakt `tools/contracts/extension-api.json`,
  návod `docs/EXTENSIONS.md`); kód se nikdy nenahrává z administrace ani nestahuje z internetu, zapíná je správce v Add-ons.
- **Role:** správce (2), editor (1 – veškerý obsah, vydává), autor novinek (0 – jen své novinky, nevydává). `Auth::canPublish()`,
  `Auth::managedAuthors()`, `Auth::articleScope()`; práva k sekcím navíc `ka_uzivatele_prava` (výchozí podle role, `Users::defaultModules()`).
  Vlastní role (`ka_role`, modul `Roles`): úroveň 0/1 + sada sekcí; uložení role přepíše `ka_uzivatele_prava` a úroveň
  všem členům (`ka_uzivatele.role`), `Auth` se tak nemění.
- **Rozšíření modulu a prvku:** `Module::EXTENSION` a `Element::EXTENSION` (novinky, poptavky, newsletter…). Vypnuté
  rozšíření: modul zmizí, prvek se nenabízí a na webu nevykreslí, sekce knihovny s ním se nenabízejí, trasy webu vrací 404.
  Nové rozšíření zapnuté ve výchozím stavu potřebuje migraci, která ho doplní webům s uloženým výběrem (viz 0016).
- **Nastavení:** nová volba = klíč v `Settings::DEFAULTS` + typ v `Settings::FIELDS` + řádek `$pole(...)` ve `views/admin/settings/<zalozka>.php`.
- **Nikdy `window.confirm()`** – v administraci atribut `data-potvrdit="text"`.
- **Administrace má CSP `script-src 'self'`:** žádné inline skripty ani `on*=` atributy; chování do `image/admin.js` přes `data-` atributy.
- **Prázdný výpis** v administraci přes `views/admin/empty.php`. Vzhled administrace je jediný (`image/admin.css`); změny kontroluj ve světlém
  i tmavém režimu a v šířce telefonu. Písmo administrace je Bricolage Grotesque (písmo značky, SIL OFL), hostované u sebe (`image/pisma/`).
- **Značka Kaleta** podle manuálu (logo manual v1.0): slovní značka „kaleta.“ malými písmeny se signální tečkou, ikona „k.“.
  Barvy: Ink `#121212`, Paper `#F6F4EE`, Signal `#FF4F2E` (jen tečka a drobné akcenty – nikdy malý text, na Paper má nízký kontrast).
  Logo se nikdy nepřepisuje písmem – vždy `views/admin/logo.php` nebo `image/kaleta-*`. Na weby uživatelů se nedává.

## Web (front)

- `Front\Kernel`: `/` = úvodní stránka (nastavení `home_page`, v jazykové verzi její protějšek `preklad_z`), bez ní výpis novinek;
  úvodní stránka na své vlastní adrese přesměruje 301 na `/`. Novinky na `/novinky`, `/novinky/<seo>` (+ `.md`), `/novinky/kategorie/<seo>`,
  `/novinky/stitek/<seo>`. Stránky na `/<seo>` – vyhrazené adresy `Modules\Pages::RESERVED_SLUGS`.
- **Themeless (od 1.6):** rámec stránky je `system/views/front/base.php` + `image/sablona.css`, pohledy `system/views/front/` nejdou přepsat
  a vlastní PHP layouty (`layout/`) se nepoužívají – Stav systému na zbylou složku upozorní. Vzhled = design system, sdílené třídy, komponenty
  a části webu. `base.php` vypisuje `<?= $hlava ?>` před `</head>` a `<?= $pata ?>` před `</body>` (SEO, strukturovaná data, měření, cookie lišta – `Front\Seo`).
  Barvy, písma, škálu a rozměry ber z tokenů design systému (`--ka-barva-*`, `--ka-krok-*`, `--ka-mezera-*`, `--ka-sirka`…) s vlastní výchozí hodnotou.
  **Vrstvy kaskády** celého webu: `@layer tokeny, spolecne, sablona, stavitel, tridy, prvky;` (`DesignSystem::LAYERS`) – šablona píše do `sablona`,
  nic nevrstveného (to by přebilo vše) a bez `!important`.
- **Tmavý režim:** `<html data-tmavy>` podle `dark_mode`, v CSS `@media (prefers-color-scheme: dark) { :root[data-tmavy] { … } }`.
- **Společné prvky** (galerie, prohlížečka fotek, video, osnova, sdílení, FAQ, úprava na webu) mají styl a skript v `image/web.css` a `image/web.js`
  (vkládá `Seo::head()`); pravidla v `:where()` s nulovou vahou, aby je šablona přebila. Doplňky textu novinky vkládá `Front\NewsText`.
- **Texty webu přes `t('Česky')`** (`Core\Language`, slovníky `system/jazyky/<kód>.php`; administrace `admin-<kód>.php`, instalátor
  `install-<kód>.php` – úplnost hlídá `tools/unit-tests.php`). Jazyky: čeština a angličtina (`Language::AVAILABLE`, `Language::CODES`).
  Doplňuj nástrojem `tools/add-translations.py`. Hodnoty formulářů se nepřekládají.
- **Jazykové verze:** sloupec `jazyk` ('' = výchozí) mají stránky, kategorie a novinky (novinka ho přebírá z kategorie). Každý dotaz webu
  vypisující obsah filtruje `Language::siteColumn()`. `App::url()` přidává `/en/` jen adresám bez přípony (soubory, `api/`, `mcp` jsou společné).
- **Cache stránek** (`Front\Cache`): jen pro nepřihlášené; POST v administraci volá `Cache::clear()`, jen když může změnit web (3.24: `Admin\Kernel::clearsPageCache` –
  sekce `SITE_CHANGING_MODULES` / `SITE_NEUTRAL_MODULES`, akce `SITE_NEUTRAL_ACTIONS`, záložky `SITE_NEUTRAL_TABS`; MCP `Catalog::SITE_NEUTRAL`). Nová sekce nebo zapisovací
  nástroj MCP = zařazení do jednoho z nich (hlídá `tools/unit-tests.php`), v pochybnostech maže. `Auth::user()` nesmí na webu
  založit session anonymnímu návštěvníkovi. Lehké úlohy na pozadí spouští po odpovědi i `admin.php` (`Notifications::afterAdminResponse`).
- **Adresa webu je nastavení `site_url`,** ne hlavička Host – absolutní adresy ber z `$app->request->origin()`.
- **Obrázky:** varianty a WebP vznikají v `Core\Images::save()`, `srcset` doplňuje `Front\NewsRepository::prepare()`, rozměry `Front\ImageHtml::complete()`.
- **Výpisy novinek nenačítají dlouhé texty** (`Front\NewsRepository::LIST_COLUMNS`) – nový sloupec pro výpis doplň i tam.
- **Úprava přímo na webu** (`Kernel::editInPlace()`, `views/front/upravit.php`): „Upravit zde“ pro přihlášené s právem; ukládají akce
  `uloz_text` v `Modules\News` a `Modules\Pages`.

## Builder stránek a design systém

- **Design systém** (`Builder\DesignSystem`, nastavení `design_system` JSON, admin Vzhled webu): pár rozhodnutí → tokeny v `@layer tokeny`.
  Fluidní škály přes `clamp()`, odstíny `color-mix(in oklch)`, kontrast WCAG počítá PHP (`contrasts()`). Starší `brand_*` se čtou jen jako záloha.
  Živý náhled ve Vzhledu i předvolby počítá jen PHP (akce `nahled`) – výpočet tokenů nikdy neduplikuj v JS.
- **Stavba** = `ka_stranky.stavba` (publikovaná) a `stavba_koncept` (editor, MCP): `{"v":1,"deti":[{id,typ,znacka,obsah,styl,tridy,kotva,popis,deti}]}`.
  Jeden prvek = jedna značka. **Jediný validátor** `Build::sanitize()` (editor, MCP, import – nikdy neukládej stavbu bez něj) a **jediný vykreslovač**
  `Build::render()`; CSS stránky jen z použitých typů, tříd (`ka_tridy`) a stylů prvků. Na webu se vadný prvek vynechá, nikdy výjimka.
- **Prvek** = třída v `Builder\Elements\` (dědí `Element`, zapsaná v `Build::ELEMENTS`): pole obsahu (`properties()`), povolené značky, základní CSS
  do vrstvy `stavitel` přes `:where()`. **Styl** (`Builder\Style::PROPERTIES`) má stavy `zaklad`/`tablet` (≤1023 px)/`mobil` (≤767 px)/`hover`; hodnoty
  jsou tokeny nebo bezpečné volné hodnoty. Vlastní CSS tříd projde `Style::customCss()` (bez `url()`, bloků, `@`).
- **Publikování** (`Builder\Publisher`, i z MCP): předchozí verze do `ka_stavba_revize` (20, `ids` stránky nebo `cast` = "typ:jazyk"), do `text` se uloží obsah bez rozložení
  (`Build::asText`) – z něj čerpá hledání, llms.txt, API i návrat k textu. Náhled konceptu `?build=koncept` jen s právem Stránky, `&editor=1` přidá `data-ka-id`.
- **Editor** `image/stavitel.js` + `stavitel.css` (samostatná stránka `akce=stavitel`): plátno je skutečná stránka v iframe (počítač vykreslený v 1280 px
  a zmenšený), průběžné ukládání konceptu (`stavba_uloz`, vrací vyčištěný strom), knihovna sekcí `Builder\Library`, verze.
- **Části webu** (`Builder\SiteParts`, tabulka `ka_casti` typ+jazyk, admin `Modules\SiteParts`, jen správce): záhlaví, patička a obálky `novinka`/`vypis`/`nenalezeno`
  (prvek `obsah` = místo pro obsah systému). Web je skládá v `Front\Kernel::siteParts()` se stavbou stránky v jednom `Context` → jedno CSS.
  Layout vypisuje `$casti['hlavicka']`/`['paticka']`, když nejsou `null`. Prvky `PARTS_ONLY` (logo, navigace, udaje, obsah) se nabízejí jen v částech.
  Záhlaví a patička mohou mít varianty (`ka_casti.varianta`, `stranky` = JSON čísel stránek; `SiteParts::pageVariant`), prázdná varianta část skryje.
  Akce builderu sdílí trait `Admin\BuilderActions` (stránky i části), publikování a verze `Builder\Publisher`.
- **Pop-up okna** (`Builder\Popups`, tabulka `ka_popupy`, admin `Modules\Popups` pod Vzhledem, jen správce, MCP `seznam_popupu`, `uloz_popup`
  a stavba_* s parametrem `popup`): obsah je stavba (verze pod `popup:<id>`, podepsaný náhled `popup:<id>`), plátno builderu `/_popup/<id>?build=koncept&editor=1`.
  `Kernel::popups()` vloží zapnutá publikovaná okna podle pravidel serveru (`Popups::matches` – místa, jazyk, období; okno s obdobím vypne cache stránky)
  na konec `<body>`; spouštěč, zařízení, kampaň, odkud, počet stránek a četnost řeší `image/web.js` (sessionStorage/localStorage, bez cookies).
  Počitadla `POST /popup` (zobrazeni|zavreni|konverze), formulář v okně má zdroj `popup:<id>`. Starý prvek `okno` (okno uvnitř jedné stránky) převedla 2.0 na pop-up okna webu (`Builder\ModalConversion`, migrace 0034, i při importu).
- **Mailingové služby** (`Core\Newsletter`, Rozšíření → Newsletter: `newsletter_sluzba|klic|seznam|webhook`): potvrzení a odhlášení odběru (`Front\Subscription`)
  a smazání v Odběratelích zařadí úlohu do `ka_odber_fronta`, odešle ji `Notifications::runInBackground` (opakování 5 min → 12 h, pak `ka_odberatele.sync = chyba`).
  Adaptéry Brevo, MailerLite, Mailchimp, Ecomail, SmartEmailing a webhook jsou v `Newsletter::apply()`; testy je přesměrují na falešný server
  nastavením `newsletter_test_url` (jen `http://127.0.0.1:<port>`, jen přes databázi).
- **Newslettery** (`Core\Mailing`, modul `newsletters`, tabulky `ka_newsletters` a `ka_newsletter_queue`, anglické sloupce): jedna šablona
  `views/email/newsletter.php` (tabulky, inline styly z design systému) – žádný e-mailový builder. Odesílá jen SMTP (`mail_mode = smtp`) a jen
  cron: `/ulohy` zapíše `tasks_last_run` a pošle dávku (`Mailing::processQueue`, nejvýš 100 a `newsletter_hourly_limit` za hodinu); bez cronu
  do 30 minut odeslání odmítne. Při startu se vykreslený e-mail zmrazí (`html`, `text` s `{{unsubscribe}}`), každý příjemce dostane vlastní
  odkaz a `List-Unsubscribe` + `List-Unsubscribe-Post` (RFC 8058). Příjemci nejdou do `ka_posta` (`Mail::deliverNow`), fronta se den po
  dokončení smaže. MCP: `list_newsletters`, `draft_newsletter`, `send_test_newsletter`, `send_newsletter` (právo vydávat), `delete_newsletter` –
  jen anglicky (v `Translator::TOOLS` se stejným jménem na obou stranách). Testy: `tools/fake-smtp.php` v `tools/test.sh`.
- **Firma** (`Front\Company`, Nastavení → Firma, klíče `firma_*`): prvek `udaje` (Údaje firmy) je vypisuje na webu, `Seo` z nich skládá
  Organization/LocalBusiness (`@id` …#firma) s adresou, otevírací dobou a geo. Otevírací doba se píše lidsky po řádcích, `Company::parseOpeningHours()` ji rozebere.
- **Kolekce** (`Builder\Collections`, tabulky `ka_kolekce` + `ka_kolekce_polozky`, admin `Modules\Collections`, MCP `seznam_kolekci`, `vytvor_kolekci`,
  `uloz_polozku_kolekce`): prvek `kolekce` (Výpis kolekce) zopakuje svůj vnitřek pro každou položku a `{{pole}}` v obsahu nahradí přes `Collections::fill()`
  podle typu cílového pole (text se escapuje až prvkem, inline/html hned, odkaz se znovu ověří). Prvky uvnitř výpisu dostávají styl přes třídu `s-<id>`,
  ne přes id. Detail `/<kolekce>/<položka>` kreslí šablona z builderu (`ka_kolekce.stavba`, `Front\Kernel::showCollectionItem`).
- **Komponenty** (`Builder\Components`, `ka_komponenty`, admin `Modules\Components`, v editoru „Uložit jako komponentu“): prvek `komponenta`
  vloží publikovanou stavbu komponenty s hodnotami `{{vlastností}}` (stejné `Collections::fill`); uvnitř bez značek editoru a se stylem přes třídu,
  ochrana proti zanoření (`Context::$nesting`). Náhled pro editor `/_komponenta/<id>` (jen správce).
- **Formuláře** (prvek `formular`, `Front\Forms` na `POST /formular`): pole a příjemce se berou z PUBLIKOVANÉ stavby podle `zdroj` + id prvku,
  nikdy z požadavku. Ochrana `Core\Antispam` (podpis času, honeypot, limit na IP) – bez cookies, stránka zůstává v cache. Výsledek jen jako kód
  v adrese (`?form=<id>&result=ok|pole|limit|overeni`), text hlášení nikdy z adresy. Poptávky v `ka_poptavky` (admin `Modules\Enquiries`,
  CSV, samy se mažou po `enquiries_months`), upozornění přes `Mail::send` s Reply-To návštěvníka.
- **HTML → stavba** (`Builder\HtmlConverter`, MCP `stavba_z_html`): sémantické HTML + `<style>` s pravidly jedné třídy → prvky a třídy; `.trida:hover` a `@media (max-width: 1023px|767px)`
  se převedou na stavy třídy (`Style::fromCss` – deklarace s obdobou ve stylu builderu). Prvek se stylovanou třídou nedostane výchozí styl typu (vrstva `prvky` je
  v kaskádě za `tridy` a přebila by ji). Co převést nejde (mobile-first `min-width`, složité selektory), se nahlásí.

## Obsah a služby

- **Novinky** (`Modules\News`): koncept / vydaná (i naplánovaná), koš 30 dní, revize (20 posledních) s porovnáním, rozepsaný stav na serveru,
  kontrola nefunkčních odkazů, AI asistent a překlad. Změna adresy vydané novinky, stránky nebo kategorie zapíše přesměrování (`Redirects::add`).
- **Hledání** přes `ka_novinky.hledani` (`Core\Search`): kdo ukládá novinku jinudy než administrací nebo MCP, volá `Search::index()`.
- **Oznámení o vydání** (webhook, IndexNow) jen přes `Core\Notifications::process()` a sloupec `oznameno`.
- **Webhooky** (1.8, `Core\Webhook`): volání se uloží do `ka_webhook_deliveries` a odejde po odeslání odpovědi (`Webhook::afterResponse` v index.php
  a admin.php), opakování `Webhook::RETRY_DELAYS` z úloh na pozadí a cronu. Podpis `X-Kaleta-Signature: sha256=HMAC(timestamp.body, webhook_secret)`;
  adresy a klíč nikdy přes MCP. Testy přesměrují volání na falešný server přes `webhook_test_url`.
- **Položky kolekcí jako stránky** (1.9): sloupce `seo_titulek`, `popis`, `obrazek`, `noindex`, `zverejnit_od` (`Collections::pageFields`, plán v `Notifications::process`),
  verze v `ka_stavba_revize` pod `cast = 'polozka:<idp>'` (`Collections::saveVersion/loadVersion`); noindex a koš mimo sitemap, llms.txt a hledání.
  Strukturovaná data kolekce `ka_kolekce.schema_org` (`Builder\CollectionSchema`, uzel v `Seo::structuredData` přes `$meta['polozka']`).
- **Audit webu** (1.9, `Core\Audit`, modul `audit`, MCP `site_audit`): interní odkazy přes `Audit::resolves`, popisy, titulky, menu, `Check::builds`, 404.
- **Statistika bez cookies** (`Front\Stats`; od 3.29 počítá jen zobrazení potvrzená prohlížečem: `image/vitals.js` pošle po zviditelnění stránky `POST /hit` s cestou podepsanou serverem, `Stats::hit` znovu ověří roboty, prefetch, původ, přihlášení a limit `DAILY_VIEWS`/`BURST_VIEWS` – vykreslení ani cache nic nepočítají; od 3.29.1 je `Front\Stats::spansSwitch`/`recentSwitch` jediné pravidlo všude, kde se návštěvy porovnávají nebo kreslí (přehled, měsíční report, MCP, konzole Flotily): pokles přes přechod na počítání potvrzené prohlížečem se označí („počítání se změnilo …“), nikdy šipka dolů ani upozornění; heartbeat nese `stats_confirmed_since` (ohraničené `Fleet\Console::day`); `Core\Report` je jeden přehled pro admin Statistiky i MCP `get_stats`): žádné
  identifikátory, jen součty po dnech, zapnutí = rozšíření `statistika` + nastavení `stats` (`Stats::isOn`). **Rychlost u návštěvníků** (2.8, `Core\WebVitals`,
  `image/vitals.js`): LCP, CLS a INP přes `PerformanceObserver`, jeden beacon na zobrazení (`navigator.sendBeacon` na `POST /vitals`) jen se zapnutou statistikou,
  nikdy v náhledech (noindex) ani pro přihlášené; skript je samostatný a `defer` (ne `web.js`, který se na stránkách bez prvků s JS vynechává). Ukládá se histogram
  s pevnými přihrádkami – `ka_web_vitals` (den, cesta, metrika, přihrádka, počet; `WebVitals::BUCKETS`, hranice Googlu jsou hranami přihrádek), p75 = horní hrana
  přihrádky s 75. vzorkem. Přijmou se jen cesty, které statistika za poslední dva dny viděla. Audit (`speed`) hlásí zhoršení p75 LCP o > 25 % proti předchozím
  30 dnům při ≥ 30 měřeních v obou obdobích. Data starší 400 dní se mažou.
- **Rychlost stránky** (2.8): CSS zůstává – tokeny design systému a styl stavby inline v `<style>`, `image/sablona.css` a `image/web.css` externí s `?v=`
  (cache sdílená mezi stránkami); Lighthouse na startovacích webech ukázal úsporu inliningu 0–40 ms při skóre 99–100, kritické CSS ani build krok se proto
  nezavádí. Vlastní písma (`vlastni_pisma`, WOFF2 z médií) mají `font-display: swap` a `Seo::head()` předem načítá (`DesignSystem::fontPreloads`) jen soubor
  textového a titulkového řezu (titulky = tučný soubor, když existuje); systémová písma se nenačítají, žádný jiný řez se předem nenačítá.
- **2.0 bez vrstev kompatibility:** žádné aliasy tříd (`class-aliases.php` je od 2.0.1 pryč; balíček ho nese jen jako „legacy“ pro aktualizace z 1.4–2.0), žádné staré adresy administrace ani
  pomocné funkce, veřejné API pryč. Staré klíče nastavení jen v `Core\OldSettingsKeys` (migrace, MCP `update_settings`, import). **Datová migrace** je
  `system/sql/migrace/NNNN-*.php` (vrací funkci `(Db, Settings)`) a musí mít nejvyšší číslo svého vydání – starý kód aktualizace zná jen `.sql`.
- **Obchody** (3.31, `Core\Deals`, tabulky `ka_deals` + `ka_deal_stage_history`, migrace 0117, admin `Modules\Pipeline` ve skupině Workspace, MCP
  `list_deals`, `save_deal`, `move_deal`, `pipeline_summary` – mimo ESSENTIALS): jedna pipeline, etapy v nastavení `pipeline_stages` (open | won | lost),
  hodnota jen číslo + měna, žádné prognózy. Každý přesun do historie; won/lost uzavře, lost chce důvod (`LOST_REASONS`); etapa, ve které jsou obchody,
  mění druh jen tehdy, když mu všechny už odpovídají (3.31.1, `Deals::dealsFitKind`). Měsíční report a souhrny jen počty – nikdy hodnoty. Cíl `deal:<id>` pro komentáře, úkoly, upozornění a odpovědnost
  (`Assignment::KINDS['deal'] = 'pipeline'`); zvonek a e-mail jmenují obchod jen číslem, kdo nemá Pipeline, vidí u zákazníka jen „obchod“ (3.31.1).
  Nový druh cíle = `Inbox::TARGETS`, `Inbox::parse`, `Tasks::cleanTarget` + `sweep`, `Assignment::KINDS` a popisek v `my_work/list.php` i `team.php`
  (hlídá `tools/unit-tests.php`). Retence: poptávka obchodu se drží, dokud je otevřený, a po uzavření ještě po dobu retence; zákazník s otevřeným
  obchodem se neanonymizuje. Smazání/anonymizace zákazníka nechá obchod bez osoby (`Deals::unlinkContacts`) a komentáře na jeho obchodech jdou stejně
  jako jeho vlastní (smazání → pryč, anonymizace → bez textu). Obchody se nežurnálují pro undo.
- **CRM pro každý den (3.32):** `Core\RecordTitle` je jediný zobrazovaný název záznamu (poptávka = kdo – co; `Inbox::places` nese `name`
  jen pro toho, kdo má sekci; e-maily, MCP `my_work` a protokol změn ho nepoužívají). Slova Workspace drží `Core\Glossary` + unit test.
  Hodnota obchodu na obrazovce jen přes `Deals::formatValue`/`moneyFormat` (MCP nechává čísla); výchozí měna `Deals::defaultCurrency`
  (`default_currency`, '' = automaticky: země firmy → nejčastější měna obchodů webu → CZK na českém webu, jinak EUR; časové pásmo ne).
  Obchod z poptávky ji nepřežije s osobou: každá cesta, která poptávku maže nebo anonymizuje, volá `Deals::forgetEnquiries`
  (resp. `forgetEnquiryWords`) PŘED smazáním – název s kým/čím se změní na „#<id> – <téma>“ (`Deals::neutralTitle`). Předmět
  s osobou (upozornění z formuláře: jméno nebo firma, nikdy e-mail) jde jen do e-mailu – `Mail::send(…, $logSubject)` drží v `ka_posta.predmet`
  a `mail.failed` jen „<formulář>: <web>“. Mé práce: nepřiřazená nezodpovězená poptávka jen nová nebo do `MyWork::UNASSIGNED_DAYS` (30).
  Kdo nevidí žádnou sekci obsahu, přistane na Mé práci (`Admin\Kernel::landsOnMyWork`); lišta vzhledu jen na nástěnce a ve Vzhledu.
- **Žádosti o místo (3.34, `Core\Applications`, tabulka `ka_applications`, migrace 0119 + datová 0120, rozšíření a sekce `applications`
  ve skupině Workspace):** žádost není druh poptávky – žádný zákazník, obchod, triáž, webhook ani událost poptávky. Formulář je uchazečský,
  když je zapnuté rozšíření a leží v šabloně položky kolekce pracovních míst (`Jobs::sources`) nebo má vlastnost Účel `ucel = uchazec`
  (`Applications::PURPOSE`, `isApplicationForm`; měnit ho smí jen člověk se sekcí – `Build::$purposeAllowed`, jinak se drží předchozí
  hodnota); pozici hledá server (`position()`), importovaný formulář zůstává poptávkový. Sekce má
  `Module::GRANTED_EXPLICITLY` – po aktualizaci ji má jen správce, dál ji dává v Uživatelích nebo hotová role HR (Applications, Collections,
  Requests). Životopisy: úzký seznam typů s kontrolou obsahu (`checkUpload`), každý soubor šifrovaný vlastním klíčem do `storage/applications/`
  (Apache ho neservíruje díky `.htaccess`, nginx potřebuje pravidlo z `nginx.example.conf`), seznam klíčů zapečetěný klíčem `Vault` účelu
  `applications` (`Vault::SEALED`); seznam, který klíč neotevře, je „životopisy nejdou otevřít – šifrovací klíč“ (`attachmentsLocked`,
  nikdy „bez příloh“, úklid ho nemaže), ve stavu `missing` se pečetí klíčem z databáze s předponou `db:` (`resealHeld` po návratu klíče;
  nový podpisový klíč je nejdřív zapečetí znovu, jinak odmítne); každý soubor nese svůj klíč zabalený klíčem `Vault` (hlavička `KCV1`, zápis
  vedle a přejmenování) a sloupec `attachment_paths` říká, kde soubory jsou (jen náhodná jména) – smazání a výmaz je najdou i u zamčeného
  seznamu, žádost bez něj zůstane a hlásí se (`applications_held`). Přesunutý životopis se pečetí po krocích (`sealEntry`: kopie pod jménem
  odvozeným ze staré cesty, zápis do seznamu, ověření uloženým klíčem, teprve pak smazání prostého souboru; přerušený běh dokončí další),
  `.part` maže úklid po 7 dnech, starý šifrovaný soubor bez řádku jen odloží (`.orphan`, čas = teď) a smaže ho, až 7 dní
  zůstane bez řádku – obnova starší zálohy tak životopis nesmaže hned; řádek, který ho zase uvádí, nebo otevření ho vrátí (`sweepOrphans`, jednou denně); pečetění i přesuny drží jeden zámek (`lockFiles`);
  životopis, který nejde uložit, formulář odmítne s hlášením (`ulozeni`, víc než `MAX_FILES` = `soubory`). Stahuje jen člověk se sekcí,
  MCP soubor nikdy nedostane. Odpovědi jdou strojem poptávek (`EnquiryReply`: jen SMTP, podpis, historie) se šablonami received/interview/rejection
  (`application_templates`); formulář bez vlastního potvrzení pošle uchazeči „received“ bez textu návštěvníka (jméno jen přes `safeName`). `ka_posta.predmet` a log drží jen
  „Reply to application #N: <web>“, upozornění kolegům jen číslo a pozici – nikdy jméno. Přesun poptávek formuláře tam a zpět a jedné poptávky
  („Přesunout do žádostí o místo“ pro toho, kdo má obě sekce) jen přes `Core\ApplicationMove` (opakovatelné díky `source_enquiry_id`;
  zpět jen po ověřené kopii životopisu a jen se stejným seznamem, jaký se přečetl, jinak žádost zůstane; zpět nikdy to, co by retence
  poptávek hned smazala, přijaté a se souhlasem pro budoucí místa jen po zaškrtnutí – `backReason`, potvrzení s počty předem). Migrace 0120
  běží po dávkách (řádek = vlastní transakce, nejvýš `Migration::$seconds` – 15 s z cronu a administrace, 2 s při návštěvě webu, zbytek další požadavky přes `data_migrations_unfinished` v `Core\Migration`;
  starý kód aktualizace jen zapíše stav a nic nepřesune – `applications_move_deferred`, ani během kontroly aktualizace – `update_probe` starší 10 minut se nebere); dosud nepřesunuté
  skryje lidem bez sekce, retencím i asistentovi `Enquiries::visible` – jediná podmínka každého čtení řádků poptávek (i dvojčete žádosti
  po obnově zálohy – smaže ho `Applications::forgetTwins` se žádostí i v denním úklidu; unit test hlídá každý dotaz); zákazníka jen z žádostí maže podle dat (`source_contact_id`) po dávkách, kontrolou je
  sám DELETE v transakci (záznam, jehož jiná poptávka už prošla retencí, se smaže).
  MCP `list_applications`, `get_application`, `update_application`, `reply_to_application` (mimo ESSENTIALS) jen pro uživatele se sekcí
  a se zapnutým `applications_claude`. Cíl `application:<id>` pro komentáře, úkoly, upozornění a odpovědnost (`Assignment::KINDS`,
  `Handover::KINDS`; `deletion_due` a přesunutá upozornění jen lidem se sekcí). Retence `applications_months` (od rozhodnutí,
  nerozhodnutá od poslední aktivity; aktualizace ji nikdy nezkrátí – `startingRetention` = `job_applications_months`, jinak
  `enquiries_months`), přesunuté žádosti se nemažou, dokud správce neuloží nastavení (`applications_retention_confirmed` = nejvyšší
  potvrzené id, pozdější přesun čeká jen se svými řádky; Stav systému po 30 dnech čekání podle `moved_at`); obrazovka obnovy varuje
  u zálohy starší než první zašifrovaný životopis (`applications_files_since`); souhlas pro
  budoucí místa `applications_pool_months`, `applications_notify_full`; MCP úkoly respektují `applications_claude` na všech cestách;
  osobní údaje jen podle vlastního e-mailu žádosti. Žádosti se nikdy nežurnálují pro undo a nejdou do exportu webu ani heartbeatu.
- **Záchranná síť (3.32):** zastaralá funkce PHP se loguje, nehází (`App::handleError`; `Core\ErrorLog` je jediný zapisovatel `chyby.log`;
  testy definují `KALETA_STRICT_DEPRECATIONS`; rotace pod zámkem s novou kontrolou souboru). Žádné mazání při GET – úklid patří do úlohy
  Scheduleru (`trash`, `cleanup`; Stav systému varuje, když `cleanup` neuspěl přes 2 dny – `Scheduler::cleanupStale`). Nová migrace
  musí projít `tools/check-migrations.php`.
- **Bezpečnost 3.31.2:** třídy `ka-…` jsou vyhrazené Kaletě (`Build::CLASS_PATTERN`, `Build::userClasses` i při vykreslení; hlídá unit test háčků
  tříd ve web.js). `/ulohy` běží i v režimu údržby. Automatická aktualizace má tři pokusy (`Updater::RETRY_DELAYS`, `update_attempt` = `verze` nebo
  `verze|pokusy|další`; drží-li zámek jiná instalace, záloha se nedělá a pokus se odloží o `Updater::BUSY_WAIT`). Token a zapnutí 2FA v Můj účet chtějí současné heslo; změna hesla ruší osobní tokeny, pokud formulář nepošle `zrusit_tokeny=0`.
  Každé „současné heslo“ jen přes `Auth::confirmPassword` (stejné počítadlo a zámek jako přihlášení, sezení zůstává); v Uživatelích se vlastní
  e-mail, heslo a reset 2FA ignorují (odkaz na Můj účet) a heslo či reset 2FA jiného účtu chce heslo správce. `Html::safe` zahazuje třídy `ka-…` (`Html::textClasses`).
- **Zálohy 3.31.1:** poslední záloha = `Backup::listLocal()` (nahraná kopie `.enc` se nepočítá); rotace drží 10 místních a zvlášť 10 nahraných kopií
  (`Backup::prunable`), obnova drží soubor vlastní značkou `.hold-<název>.<token>` (`Backup::hold`/`release`, 1 h); neúspěšná kopie mimo server =
  `backup.failed` s textem `RemoteBackup::failureText` (bez hesla, uživatele, e-mailů a cest). Flotila: připomínky certifikátů
  `fleet_certificate_reminders` (14,7), domény `fleet_expiry_reminders` (30,7); zadržený rollout > 3 dny = položka Dnes (`Rollout::heldItem`).
- **Zálohy mimo server** (`Core\RemoteBackup`): záloha databáze i přírůstková kopie `media/` (`syncMedia`, manifest `storage/zalohy/media-kopie.json`)
  na FTPS nebo S3; automatická záloha denně při změně (`ka_protokol`, nové poptávky), jinak týdně. Testy: falešné S3 přes `backup_test_url`.
- **Pošta** vždy přes `Core\Mail::send()` (fronta `ka_posta`). **Nahrávání:** obrázky `Core\Images`, přílohy `Core\Files` (whitelist přípon).
- **Čas:** pásmo `time_zone` (`App::applyTimezone()`); databáze drží čas webu (wall time). Nový kód zapisuje přes `Core\Clock` (`now()`, `toDb()`, `fromDb()`, `at('-1 hour')` místo `NOW() - INTERVAL`) – do 4.1 dává totéž co `date()` (návrh `docs/design/4.0-data-model.md` §6). Stav systému hlídá, že PHP, nastavení a relace databáze počítají čas stejně.
- **AI asistent** (`Core\Assistant`): poskytovatel `ai_provider` (anthropic | openai | google | mistral, pevné adresy v `PROVIDERS`),
  uvnitř se pracuje s tvarem Claude API a `call()` ho převádí (`naOpenAi`/`zOpenAi`). V builderu `suggestSection()` (HTML → `HtmlConverter::saveToSite`) a `rewrite()`.
  Klíč `ai_key` je typ `tajne`; odpověď modelu je nedůvěryhodný vstup. Překlad (`Assistant::translate()`) bere od modelu
  jen text úseků, značky z originálu; výsledek je vždy koncept. Adresa API jen konstantou `KALETA_AI_URL` v `config.php`.
- **MCP** (`Mcp\Server`, `Mcp\Tools`, `/mcp`, OAuth nebo token z Můj účet) je hlavní cesta, jak se na Kaletě stavějí weby – co jde v editoru, musí jít i tady:
  stránky (i builder: `stavba_schema` – stručný přehled `Build::overview`, `stavba_z_html`, `stavba_nacti` – `Build::compact`, `stavba_uloz`, `stavba_uprav` –
  dílčí operace `Builder\Edits`, `vloz_sekci`, `publikuj_stavbu`, `nahled_odkaz`), třídy (`seznam_trid`, `uloz_tridy`), design systém, média
  (`nahraj_soubor` přes `Images::saveFile` / `Files::saveFile` / `Gallery::saveSvgContent`), nastavení (`uprav_nastaveni` – jen klíče z `MCP_SETTINGS`,
  validace `Settings::verifyValue`), přesměrování, koš stránek, novinky a kategorie. Vlastní PHP šablony přes MCP nevznikají ani se nemění (od 1.1). Výstup je kompaktní JSON bez výchozích hodnot.
  **Rozhraní je anglické** (od 1.1, `Mcp\Translator`): tools/list, parametry, klíče výsledků, stavy, hlášení a pokyny serveru anglicky (`create_page`,
  `build_from_html`, `save_build`…); `Tools` dál implementuje nástroje česky a `Translator` překládá vstup i výstup. České názvy jsou skryté aliasy
  a chovají se jako dřív – existující napojení se nerozbijí. Datový model builderu (JSON stavby, `stavba_schema`, klíče design systému) se nepřekládá.
  Nový nástroj nebo parametr = záznam v `Translator::TOOLS`, nové hlášení = `MESSAGES`/`MESSAGE_PATTERNS`; hlídá to `tools/unit-tests.php`.
  **Slovník builderu anglicky (od 1.6, `Mcp\Vocabulary`):** stavby, obsah prvků, styly a `builder_schema` jdou přes MCP anglicky (`type`, `content`,
  `style`, `children`, `button`, `mobile`, `primary`…), uložené stavby zůstávají české; na vstupu projde i česká podoba. Nový prvek, pole, vlastnost
  stylu nebo token = záznam ve `Vocabulary` (jinak selže unit test, který převádí všechny sekce knihovny tam a zpět).
  **Parita:** každá akce administrace je v mapě `$parity` v `tools/unit-tests.php` – čtení, nástroj MCP, nebo „admin: důvod“.
  **Sady nástrojů (3.36):** každý nástroj z `Catalog::TOOLS` patří aspoň do jedné z `ESSENTIALS`, `WORK`, `MIGRATION`, `FLEET`,
  nebo do `ON_DEMAND` (jen sada all), nikdy do `ON_DEMAND` a zároveň jinam; ESSENTIALS nové nástroje neberou. Každá sada se vejde
  do `Catalog::BUDGETS` (hlídá unit 2550), Fleet se nabízí jen se zapnutým rozšířením (`SET_NEEDS`), nová spojení začínají na essentials.
  Pokyny serveru: `Translator::instructions` + `INSTRUCTION_TASKS`/`INSTRUCTION_FEATURES`.
- **Koncept vzhledu (od 1.7, `Core\Look`):** design system, změny a mazání existujících tříd a menu jdou do jednoho konceptu (`look_draft`
  v nastavení) – z Vzhledu webu, builderu, editoru menu i MCP (`update_design_system`, `save_classes`, `save_menu`); nová třída platí hned.
  Publikuje `Look::publish` (admin lišta `admin/look_bar`, MCP `publish_look`), předchozí vzhled jde do `ka_look_versions` (20), zpět
  `restore_look_version`. Koncept se vykresluje jen při `Look::activate()`: náhled celého webu (`Preview` cíl `web`, cookie `ka_nahled`,
  `?preview_end=1`) nebo správce v builderu (`?build=koncept`); háčky jsou v `DesignSystem::load`, `Build::css` a `Menu::load`.
  Zápis stavby vrací podepsaný náhled (`Core\Preview`, `?build=koncept&preview_key=`, HMAC `secret_key`, jen jeden cíl, omezená platnost). Nová novinka
  je koncept, nová stránka skrytá; vydat/zveřejnit jen na výslovný pokyn a s právem. **Hranice (bezpečí na prvním místě):** žádný nástroj nesmí zapisovat mimo obsah
  spouštět kód ani dotaz (statickou kontrolu PHP nejde udělat neprůstřelnou, proto MCP žádné PHP šablony nemění).
- **Přihlášení (3.35):** hesla `password_hash`, TOTP, passkeys (`Core\Passkey`) – přihlášení klíčem bez jména a hesla (`Auth::signInWithPasskey`,
  tlačítko i automatické vyplnění, discoverable klíč s ověřením uživatele, neprůhledný `passkey_handle` (vzniká compare-and-swap), limity hesla, jedna odpověď
  pro každé selhání; zámek po chybných heslech platný klíč nikdy neblokuje – přihlášení, druhý krok i potvrzení klíčem ho ukončí, zablokovaný účet zůstává zavřený). Pravidla jen v `Core\SignInPolicy`: účet s klíčem potřebuje po hesle vždy druhý krok (kód z aplikace, klíč, záložní kód);
  „jen klíčem“ (účet `passkey_only`, web `signin_passkey_only`) platí až s funkčním klíčem a po hesle bere jen klíč nebo záložní kód;
  `KALETA_PASSKEY_ONLY_OFF` v `config.php` ho vypne a správce, jehož jediným druhým krokem je klíč (bez aplikace a záložních kódů), pustí samotným
  heslem; každá stránka administrace i Stav systému pak varují (dokumentace `config.sample.php`, README, návod). Klíč nese svého hostitele
  (`ka_uzivatele_klice.rp_id`); pravidla počítají jen klíče hostitele `site_url` (`SignInPolicy::keysForHost`, `Auth::workingKeys`) – po přesunu
  na jiný hostitel heslo a aplikace/záložní kódy, nikdy slepá ulička; nový hostitel v `site_url` chce zaškrtnuté potvrzení a čerstvý důkaz
  (`Settings::signInChangeRefusal`, i vypnutí `signin_passkey_only` a změna `require_2fa`); `site_url` se ukládá bez výchozího portu, tečky a lomítka
  (`normaliseSiteUrl`). Klíče jen na hostiteli `site_url` (`hostMatches`), starší klíč = „jen druhý krok“,
  „Zaregistrovat znovu“. Klíč se počítá jako druhý faktor pro `require_2fa` (i MCP). Obnova: záložní kód, reset v Uživatelích, e-mail mění jen heslo.
  Přidání/odebrání klíče, „jen klíčem“ a „Nové záložní kódy“ (účet s klíčem bez aplikace) chtějí čerstvé potvrzení (`Auth::isFreshlyVerified`
  nebo současné heslo ve formuláři); obnova hesla `Admin\PasswordReset`. Záložní kódy jen přes `Core\BackupCodes` (64 bitů, HMAC se solí
  seznamu, starší seznam sha256 platí dál) a compare-and-swap (`Auth::consumeBackupCode`, `replaceBackupCodes`).
- **Bezpečnost 3.35:** čerstvý důkaz je jeden pojem v `Core\Auth` – zapisuje jen `markVerified(bool $strong)`, jedno okno `VERIFIED_WINDOW`
  (10 min): `verified_at` = každé dokončené přihlášení, správné současné heslo, potvrzení klíčem (`recentlyVerified` – souhlas OAuth s plným
  přístupem; jen-klíčový účet dostane „Potvrdit klíčem“, `confirmWithPasskey`); `strong_at` = jen krok klíčem nebo záložním kódem, nikdy kód
  z aplikace (`isFreshlyVerified`). Každá citlivá změna jde přes `Auth::freshProof($heslo, 'password'|'strong'|'recent')` – jen-klíčový
  účet (`isPasskeyOnly`) heslem nic nepotvrdí, jen silným důkazem nebo „Potvrdit klíčem“ (Můj účet `krok=passkey_confirm*`, formuláře
  Nastavení a Uživatelů na něj míří přes `data-klice`). E-mail jiného účtu i nový správce (úroveň, povýšení, všechny sekce – `Users::elevates`)
  v Uživatelích chtějí důkaz správce. `Catalog::mayFetch`: adresu stahuje jen plné spojení
  (`upload_file` url, nová média `save_collection_items`, `migration_report` a `read_old_page` v `Catalog::NEEDS`, nástroj doplňku s `openWorld`).
  Text zvenku jde do MCP s jedním `Mcp\Untrusted::mark([klíče])` – nový výsledek s textem návštěvníků, uchazečů či cizích webů ho musí nést.
  `PersonalData` bere jen celou adresu (`ofEnquiry`, poznámka sloučení – značka `ka_contact_activities.merged_email`, starší podle znění
  `Contacts::mergeNoteNames` –, žádosti vlastní adresou); mezery všech druhů a velikost písmen nerozhodují (`PersonalData::cleanAddress`). Role bez práva
  vydávat pracuje v Kolekcích jen s koncepty (`Collections::isDraft`, i MCP přes každé spojení) a štítky nepřejmenuje ani nesmaže (`Tags::LIVE_ACTIONS`).

## Import z WordPressu a export

`Modules\Transfer` obsluhuje formuláře, práci dělají `Core\WpFile` (proudové čtení WXR), `Core\WpContent` (čištění obsahu, bloky Gutenbergu),
`Core\WpImport` (zápis) a `Core\ImageDownloader`; `Core\SiteExport` dělá otevřený archiv obsahu.
- Příspěvky → novinky, stránky → stránky (skryté v navigaci), kategorie → kategorie (strom se zplošťuje), štítky → štítky, **přesměrování všech starých adres**
  (hezké i `/?p=123`). Komentáře se nepřenášejí. Účty se nezakládají – novinky patří tomu, kdo importuje.
- Dávky (`WpImport::BATCH`, `SECONDS`) se stavem v `storage/import/`, idempotence přes `ka_import_mapa` (převedené se nepřepisuje).
- **Bezpečnost, která se nesmí rozvolnit** (hlídá `tools/unit-tests.php`): XML s DOCTYPE/entitou se odmítá, `LIBXML_NONET`; obsah projde jen povolovacím seznamem
  značek; `ImageDownloader` jen z domény starého webu, jen veřejné IP, připnuté spojení, limity velikosti a času, SVG nikdy.
- Export vybírá nastavení z povolovacího seznamu `SiteExport::SETTINGS`; účty, hesla ani klíče do něj nikdy nepatří. Formát `obsah.json` má verzi
  `SiteExport::FORMAT_VERSION` (2 od 1.8: koncepty staveb, celé řádky médií, `media_slozky`, typ přesměrování); jeden řádek = jeden záznam.
- **Import exportu Kalety** (1.8, `Core\SiteImport`, Import a export → Import z Kalety, v instalátoru „Začít z exportu“ = prázdný web): jen do prázdného
  webu (`SiteImport::siteContent`), záznamy si nechají svá čísla (žádné přemapování), každý řádek prochází sanitizéry (`Build::sanitize`, `Style`,
  `Menu::sanitize`, `Collections`, `Popups`, `DesignSystem`), nastavení jen ze `SiteExport::SETTINGS` (bez `site_url`). Kroky: příprava (rozdělení
  `obsah.json` po tabulkách do `storage/import/kaleta-<hash>/`), náhled, data (první dávka = záloha `predimportem` + vyprázdnění obsahu), média
  (`SiteImport::mediaTarget` – jen do `media/`, jen povolené typy, SVG přes `Svg::sanitize`). Dávky posílá formulář s `data-auto-odeslat` (image/admin.js).
- **Ukázkové stránky (3.33, `Core\StarterPages`):** instalátor zapíše otisk každé ukázkové stránky do nastavení `starter_pages`;
  „Začít z exportu“ a import exportu Kalety zapíšou prázdný záznam `{}` (žádné ukázkové stránky, nikdy starší pravidlo). Web z 3.20–3.32
  bez záznamu má starší pravidlo (`legacyRows`), které musí dokázat řádek instalátoru: k-tá nejnižší stránka má k-tý titulek a adresu,
  jedno společné `zmeneno` do 60 s po `potvrzeno` prvního účtu, bez popisu/SEO titulku/obrázku/noindex, bez verzí, překladu, řádku
  v `ka_import_mapa` a importu exportu – v pochybnostech není ukázková. Volba instalace „Prázdný web pro stěhování“ (`web=move`)
  založí jen zástupnou Úvodní stránku a skryté zásady ochrany soukromí. Nedotčená ukázková stránka (bez `zmenil`, bez konceptu
  a překladu, stejný otisk) na adrese importované stránky jde do koše pod adresou `-sample` (`makeRoom`, `retire` – podstránky
  odpojí, `linkParents` je napojí na importovanou) a importovaná stránka převezme adresu; upravená se nikdy nenahrazuje. Stránka
  poznaná jen starším pravidlem (`how` = legacy) se ve výchozím stavu nenahrazuje: v administraci nezaškrtnutá, přes MCP jen
  jmenovitě v `replace_starter_pages`; „Přesunout ukázkové stránky do koše“ zveřejněnou takovou nechá. Ukázková Úvodní se nahradí
  (a `home_page` přepne) jen za viditelnou importovanou – importy přinášejí skryté, takže zůstává a výsledek nese `home_waiting`.
  Volba v náhledu: zaškrtávátka v administraci, MCP `keep_starter_pages` (`["*"]` = všechny), výsledek `starter_pages`/
  `starter_pages_result`. Import WordPressu analyzovaný před 3.33 (bez `cile_stranek`) nenahradí nic.
- **Vnořené adresy (3.33, `Core\ImportPath`):** oba importy drží starou cestu i s rodiči (`target`, `plan`), nadřazenou stránku propojí
  i dodatečně (`linkParents`); přes 120 znaků se zkrátí poslední část (nejméně 20), první část, kterou používá systém, spadne na
  poslední část. Přesměrování jen při změně adresy, změněné adresy ve výsledku (`addresses_changed`, `zmenene_adresy`).
- **Kontrola přesunu a blok Stěhování (3.33):** `MigrationReport` hlásí adresu na nedotčené ukázkové stránce jako chybu `sample_page`
  (stav `sample`), formulář na většině starých stránek jednou jako formulář celého webu (`siteWideForms`), změnu titulku jako
  poznámku a nedostupný starý web otevřeně (`old_site_reachable`). `Core\MoveStatus` skládá blok Stěhování v Auditu webu a MCP
  `site_audit` (`move`) jen z toho, co už vědí importy a poslední kontrola – počty a odkazy, nic nového se neukládá; „nepřestavěné“
  stránky počítá `ImportLayout::pending`.
- **Widgety starých stránek (3.33, `Builder\HtmlWidgets`):** rozpoznává se obecně podle struktury a významu (ARIA tlačítko
  s `aria-controls` a panel, `role=tab`/`tabpanel`, `<details>`, adresy rámců map a videí, přehrávače kreslené skriptem, počitadla
  v data atributech) – žádný builder nemá vlastní cestu kódu. `WebImport` je vyjme před čištěním (značky `<kaleta-widget>`),
  `HtmlConverter` z nich udělá prvky Formulář, FAQ, Záložky, Video, Mapa, Vložení a Počitadlo; formulář kreslený skriptem = viditelný
  zástupný text. Odkazované soubory do Médií (`files`), starý `<title>` → `seo_titulek`, `og:image` → `obrazek`. Výsledek `kept`
  a `attention` (`pozor` ve stavu drží anglickou šablonu + hodnoty, překládá `WebImport::attentionLines` až při zobrazení).
  Odkazované soubory nejvýš 10 na stránku a 200 souborů / 300 MB na běh importu (`filesLeft`); `<video>`, jehož soubor není
  v Médiích, se vynechá s upozorněním (`localVideosOnly`); pole honeypotu a captchy se do formuláře nepřevádějí (`HtmlWidgets::trapField`);
  `<video>` s autoplay/loop/muted zůstane správci jako Vlastní HTML.
  `build_from_html` používá stejné rozpoznávače. Testy: `tools/fixtures/builder-pages/` (Elementor, Divi, WPBakery, Gutenberg,
  Breakdance, Squarespace, Webflow, Wix).
- **MCP `read_old_page` (3.33, `Core\OldPage`):** stará stránka po sekcích (záhlaví, bloky obsahu, patička) jako vyčištěné HTML
  s pozadím, barvami a písmy a paletou celé stránky, pro přestavbu rozložení. Čte jako `import_website` (`ImageDownloader`,
  robots.txt, rozestup), jen správce s právem importu (`Catalog::NEEDS` – jiné spojení ho ani nevidí); odpověď je nedůvěryhodná
  data (`untrusted`). CSS lineárně (`declarations`, tělo pravidla nad 20 KB se přeskočí), styly a rozestupy sdílí 20 s – pak `partial`.
- **Fronta přestavby (3.33, `Core\ImportLayout`):** import si do `ka_import_mapa` (zdroj `layout`, `cizi_id` = „<ids>:<sha256 stavby>“,
  bez migrace) zapamatuje uloženou stavbu (`remember`, u druhého průchodu WordPressu `replaced`); `pending` = publikovaná stavba se
  stejným otiskem a bez konceptu. Štítek „rozložení zatím nepřestavěné“ a záložka „Nepřestavěné rozložení“ (`status=layout`)
  ve Stránkách, MCP `list_pages` `layout_not_rebuilt`; každá úprava stavby příznak zruší. Otisk přestavěné stránky maže úloha
  `cleanup` (`ImportLayout::prune`, nikdy při GET).
- **Souhrn týmu (3.41, `Core\TeamSummary`):** Moje práce → Souhrn a MCP `team_summary` (sada Work) – jen počty za období pro sekce,
  které člověk smí otevřít; po lidech jen správce, ostatní vlastní řádek a součty; měsíční report jen součty týmu bez jmen. Karta
  obchodu: dní v etapě (`Deals::stageSince`) a další krok (`Tasks::nextFor`).
- **Bezpečnost 3.41:** odkazy na obnovu hesla a pozvánky končí změnou e-mailu nebo hesla a přihlášením (`PasswordReset::cancel`); pošta
  s odkazem předává `expires`. Každá změna druhého faktoru volá `Auth::newEpoch` (migrace 0130). Kořenový .htaccess drží pravidla Kalety
  v bloku `# BEGIN Kaleta` … `# END Kaleta sha256:` – úprava bloku = nový otisk (hlídá unit test). Zdroj aktualizací jen https (http jen
  na testovací kopii). Odpovědi konzole nesou `site_id` a `ts`. Vložené skripty náhledů přes `Csp::attribute()`, kód správce přes
  `Csp::withNonce()`.
- **Nabídky II a přihlášky II (3.40, migrace 0128 a 0129):** PDF nabídky vlastním `Core\Pdf` (standardní písma, WinAnsi +
  Differences, ToUnicode; bez cizí knihovny), `Front\QuotePdf` ze snapshotu; `/quote/<token>.pdf` a admin akce `pdf` vždy jako příloha,
  bez cache; písmo, které PDF nenakreslí (řečtina, cyrilice, CJK, RTL) = jen tisková stránka; přílohy jen přes `Mail::send(..., $attachments)`.
  Ceník zákazníka (`price_list_id` u kontaktu a firmy), Přijmout/Odmítnout zákazníkem (`quote_customer_decision`, `Front\QuoteDecision`)
  a „poprvé otevřeno“ (`quote_track_opened`) – obojí výchozí vypnuto; cíl `quote:<id>` pro úkoly; sady řádků `Core\QuoteLineSets`.
  Přihlášky: počty `ka_application_counts` (`Core\ApplicationCounts`, nikdy osoba), hromadné zamítnutí, nástěnka pozice, zásoba uchazečů
  `Core\TalentPool` (`/application-consent/<token>`), webhook `webhook_applications` jen číslo a pozice.
- **Flotila 3.39:** Dnes po kontrolách (`Fleet\Health::groups`); přijetí stavu (`Fleet\Acceptances`, `ka_fleet_acceptances`, migrace 0125):
  jedna kontrola jednoho webu, poznámka + poslední den, jen v konzoli; platí do dne nebo do změny stavu; upozornění, připomínky a souhrn
  ho přeskočí; MCP značí `accepted` aditivně. Šest sekcí `Fleet::SECTIONS`, dřívější záložky zůstávají jako pohledy.
- **Bezpečnost 3.39:** tajná nastavení `Vault::SECRET_SETTINGS` a TOTP semínka jsou zapečetěná (`kaleta-sealed:`) jen s klíčem
  v config.php – čti a zapisuj přes Settings / `Vault::openSecret`/`sealSecret`; nové pole typu `tajne` patří do seznamu (unit 3050).
  Nástroj MCP, který posílá e-mail mimo tým nebo trvale maže, patří do `Mcp\Confirmation::TOOLS` (první volání vrátí kód, druhé nese
  `confirmation`); smoke používá `mcp_call`. Poznámky ze spojení jen na koncepty jsou návrhy (`via`/`access`), nikdy připnuté.
- **Tabulky ani sloupce se nikdy nepřejmenovávají do angličtiny** (rozhodnutí 21.–22. 10. a 10. 11. 2026, ROADMAP „Path to 4.0“);
  `Core\Names` jsou jen anglické aliasy. **Vrstvy kódu** (`docs/design/code-layers.md`, unit 3100): Core neodkazuje na
  Admin/Front/Builder/Fleet/Mcp, Mcp ne na `Admin\Modules`; zmrazený seznam výjimek jen ubývá.
- **Interní ceník a nabídky (3.38, migrace 0123):** `Core\Money` (jen celá čísla), `Core\Prices`, `Core\Quotes`, `Core\QuoteDelivery`;
  rozšíření a sekce `quotes` (přidělují se výslovně), oblast `prices_edit`. Ceny jen v `ka_prices`, nikdy v položce; pět tabulek smí
  jmenovat jen třídy z povolovacího seznamu (unit 2800). Číslo nabídky vzniká při prvním odeslání nebo Dokončení, počitadlo po rocích.
  Dokument zákazníka `/quote/<token>` (`Front\QuotePage`, HMAC, uložen jen sha256, nikdy v cache, robots Disallow); odeslání jen přes
  `QuoteDelivery::send` (SMTP, vlastní adresa zákazníka). Protokol změn, zvonky ani reporty nikdy nenesou částky. MCP nástroje nabídek
  a cen jsou na vyžádání; `mark_quote` bere `decline_reason` (`reason` je ochranný argument serveru). `send_quote` odmítne adresu, kterou zapsalo
  spojení Clauda (`ka_contacts.email_by_agent_at`, migrace 0124) nebo importovaná poptávka; odkaz na nabídku je náhodný a znovu se ukáže
  jen v relaci, která ho vytvořila.
- **Stránky záznamů (3.37):** poptávka, obchod a zákazník mají jednu šablonu (lišta `.zaznam-lista`: zpět, stav, jedna hlavní
  akce, vedlejší, Další; `.zaznam` = hlavní sloupec + boční panely, na telefonu jeden sloupec). Poptávka má jeden formulář Vyřízení
  (`actionTriage`; třídění se stane osobním jen při změně). Karty na nástěnce skládají fázi a Přesunout za ⋯ jen pro myš.
- **Kolegové a podpisy (3.37, migrace 0122):** `ka_uzivatele.phone` + `pozice` (Můj účet, Uživatelé); Můj účet → Kolegové
  (`Core\Colleagues`, jen aktivní účty, nikdy v heartbeatu ani reportu); podpis každého je `Builder\UserSignature::forUser` – záznam
  v Týmu, jinak záložní z účtu a údajů o firmě (šablona `email_signature_template`), vzniká při každém čtení; nese ho
  `EnquiryReply::signature`, MCP `get_email_signature` bez kolekce a `list_colleagues` (sada Work).
- **Přesun z libovolného builderu (3.36):** `read_old_page` s `format: semantic` (`Core\SemanticHtml`) vrací sekce staré stránky
  jako čisté HTML pro `build_from_html`, widgety ve tvarech převodníku, `widgets` u sekce; nic podle konkrétního builderu.
  Fronta rozvržení: `ImportLayout::markRebuilt` (Stránky „Layout done“, hromadně `rebuilt`) a MCP `layout_queue` (`old_url` je jen nápověda).
- **Export stránky** (`kaleta-stranka` verze 2) nese sdílené třídy a komponenty (`Builder\PagePackage`); import doplní chybějící třídy (existující
  nepřepíše) a komponenty, stejnou komponentu (název, vlastnosti, stavba bez id) použije znovu.

## Vydání a aktualizace

Verze `KALETA_VERSION` v `system/bootstrap.php`; `php tools/release.php <verze> --url=…` sestaví a lokálně podepíše balíček (`docs/RELEASING.md`).
Veřejné klíče vydavatele jsou v `system/aktualizace.pub` (provozní a záložní); soukromé leží mimo repozitář (`tools/klice`, symlink) a nikdy nejdou do gitu.
Web projektu: `kaletacms.com` (kanál aktualizací `https://kaletacms.com/aktualizace.json`), veřejný repozitář `github.com/phprs-cms/kaletacms`. Denní kontrola (`denni-kontrola.yml`) hlídá kanál aktualizací a hlavičky webu projektu.

## Spuštění a testy

`php -S 127.0.0.1:8095 system/dev-router.php` (preview `kaleta`), vývojová databáze `kaleta_dev`, `config.php` není v gitu.
**Testy a chyby (3.36, T3/T4):** `catch (\PDOException $e)` volá `Db::rethrowUnlessMissing($e)`, chybu použije, nebo v komentáři
řekne „any database error“ a proč (unit 2600) – jen chybějící tabulka nebo sloupec znamená „před migrací“. Žádné nové kontroly
zdrojového textu (`[source-pin]`, strop `UNIT_SOURCE_PIN_CEILING`, unit 2610) – testuj chování. Testovací přepínače jen přes
`Core\TestSeams` (`KALETA_TEST_SEAMS`), v jednotkových testech `Clock::freeze()`, smoke používá `background_jobs` a nikdy nezapisuje
`notification_check`; pojmenované zámky MySQL vždy přes `$db->lockName()`; rozpočty dotazů `X-Kaleta-Queries` (smoke 2610).
Po změně: `tools/test.sh` (lint, jednotkové testy, čistá instalace a průchod webem i administrací; potřebuje MySQL,
databázi `kaleta_test` smaže a vytvoří), případně jen `php tools/unit-tests.php` (jeden soubor: `php tools/unit-tests.php 0310` nebo část názvu),
a projít dotčené stránky v prohlížeči. Testy leží v `tools/tests/unit/NNNN-tema.php` a `tools/tests/smoke/NNNN-tema.sh` (běží v pořadí čísel,
smoke v jednom shellu); nové téma = nový soubor s dalším volným číslem, nikdy připisovat na konec cizího souboru (`tools/tests/README.md`).

## Screenshoty a angličtina

- `tools/screenshots.sh` (+ `tools/screenshots.mjs`, Playwright a Chrome): čistá anglická instalace každého startovacího webu
  s ukázkovými daty a snímky do `docs/screenshots/` (web projektu, README, dokumentace). Po změně vzhledu administrace pusť znovu.
- `tools/test-english.sh` (i v CI): anglický instalátor, web všech tří startovacích webů i administrace nesmí ukázat češtinu
- `tools/test-migrations.sh` (i v CI na MySQL 8.4, MariaDB 10.6 a 11.4 a před každým vydáním): databáze z vydání `FROM` (výchozí v1.0.0) + současné migrace = stejná struktura jako čistá instalace, migrace jdou pustit znovu, datové migrace převezmou údaje. **Každá nová migrace musí projít tímhle testem** – čistá instalace migrace nespouští (1.0.8 kvůli tomu vyšla s rozbitou migrací). Migrace piš přenositelně (MySQL i MariaDB), bez odkazu na cílovou tabulku v ON DUPLICATE KEY UPDATE.
  (`tools/find-czech.php`: diakritika, český klíč slovníku s překladem, častá česká slova; `--js` = české texty skriptů administrace bez
  položky v `image/jazyky/admin-en.js`). Nový text vždy přes `t()` a překlad přes `tools/add-translations.py`; výchozí texty pro návštěvníky
  v `Settings::TRANSLATED_DEFAULTS`. Stránky startovacích webů musí projít kontrolou před publikováním (hlídá `tools/unit-tests.php`).
- Zvýraznění `<mark>` v textu nadpisu = doplňková barva bez podbarvení (tečka za titulkem v barvě Signal).

