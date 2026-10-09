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
- **Cache stránek** (`Front\Cache`): jen pro nepřihlášené; každý POST v administraci volá `Cache::clear()`. `Auth::user()` nesmí na webu
  založit session anonymnímu návštěvníkovi.
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
- **Statistika bez cookies** (`Front\Stats` při každém započítaném zobrazení, i z cache; `Core\Report` je jeden přehled pro admin Statistiky i MCP `get_stats`): žádné
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
- **Zálohy mimo server** (`Core\RemoteBackup`): záloha databáze i přírůstková kopie `media/` (`syncMedia`, manifest `storage/zalohy/media-kopie.json`)
  na FTPS nebo S3; automatická záloha denně při změně (`ka_protokol`, nové poptávky), jinak týdně. Testy: falešné S3 přes `backup_test_url`.
- **Pošta** vždy přes `Core\Mail::send()` (fronta `ka_posta`). **Nahrávání:** obrázky `Core\Images`, přílohy `Core\Files` (whitelist přípon).
- **Čas:** pásmo `time_zone` (`App::applyTimezone()`); zapisuj přes `date()`, porovnávej s `NOW()`.
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
- **Koncept vzhledu (od 1.7, `Core\Look`):** design system, změny a mazání existujících tříd a menu jdou do jednoho konceptu (`look_draft`
  v nastavení) – z Vzhledu webu, builderu, editoru menu i MCP (`update_design_system`, `save_classes`, `save_menu`); nová třída platí hned.
  Publikuje `Look::publish` (admin lišta `admin/look_bar`, MCP `publish_look`), předchozí vzhled jde do `ka_look_versions` (20), zpět
  `restore_look_version`. Koncept se vykresluje jen při `Look::activate()`: náhled celého webu (`Preview` cíl `web`, cookie `ka_nahled`,
  `?preview_end=1`) nebo správce v builderu (`?build=koncept`); háčky jsou v `DesignSystem::load`, `Build::css` a `Menu::load`.
  Zápis stavby vrací podepsaný náhled (`Core\Preview`, `?build=koncept&preview_key=`, HMAC `secret_key`, jen jeden cíl, omezená platnost). Nová novinka
  je koncept, nová stránka skrytá; vydat/zveřejnit jen na výslovný pokyn a s právem. **Hranice (bezpečí na prvním místě):** žádný nástroj nesmí zapisovat mimo obsah
  spouštět kód ani dotaz (statickou kontrolu PHP nejde udělat neprůstřelnou, proto MCP žádné PHP šablony nemění).
- **Přihlášení:** hesla `password_hash`, TOTP, passkeys (`Core\Passkey`, jen jako náhrada kódu u účtu s TOTP), obnova hesla `Admin\PasswordReset`.

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
- **Export stránky** (`kaleta-stranka` verze 2) nese sdílené třídy a komponenty (`Builder\PagePackage`); import doplní chybějící třídy (existující
  nepřepíše) a komponenty, stejnou komponentu (název, vlastnosti, stavba bez id) použije znovu.

## Vydání a aktualizace

Verze `KALETA_VERSION` v `system/bootstrap.php`; `php tools/release.php <verze> --url=…` sestaví a lokálně podepíše balíček (`docs/RELEASING.md`).
Veřejné klíče vydavatele jsou v `system/aktualizace.pub` (provozní a záložní); soukromé leží mimo repozitář (`tools/klice`, symlink) a nikdy nejdou do gitu.
Web projektu: `kaletacms.com` (kanál aktualizací `https://kaletacms.com/aktualizace.json`), veřejný repozitář `github.com/phprs-cms/kaletacms`. Denní kontrola (`denni-kontrola.yml`) hlídá kanál aktualizací a hlavičky webu projektu.

## Spuštění a testy

`php -S 127.0.0.1:8095 system/dev-router.php` (preview `kaleta`), vývojová databáze `kaleta_dev`, `config.php` není v gitu.
Po změně: `tools/test.sh` (lint, jednotkové testy `tools/unit-tests.php`, čistá instalace a průchod webem i administrací; potřebuje MySQL,
databázi `kaleta_test` smaže a vytvoří), případně jen `php tools/unit-tests.php`, a projít dotčené stránky v prohlížeči.

## Screenshoty a angličtina

- `tools/screenshots.sh` (+ `tools/screenshots.mjs`, Playwright a Chrome): čistá anglická instalace každého startovacího webu
  s ukázkovými daty a snímky do `docs/screenshots/` (web projektu, README, dokumentace). Po změně vzhledu administrace pusť znovu.
- `tools/test-english.sh` (i v CI): anglický instalátor, web všech tří startovacích webů i administrace nesmí ukázat češtinu
- `tools/test-migrations.sh` (i v CI na MySQL 8.4, MariaDB 10.6 a 11.4 a před každým vydáním): databáze z vydání `FROM` (výchozí v1.0.0) + současné migrace = stejná struktura jako čistá instalace, migrace jdou pustit znovu, datové migrace převezmou údaje. **Každá nová migrace musí projít tímhle testem** – čistá instalace migrace nespouští (1.0.8 kvůli tomu vyšla s rozbitou migrací). Migrace piš přenositelně (MySQL i MariaDB), bez odkazu na cílovou tabulku v ON DUPLICATE KEY UPDATE.
  (`tools/find-czech.php`: diakritika, český klíč slovníku s překladem, častá česká slova; `--js` = české texty skriptů administrace bez
  položky v `image/jazyky/admin-en.js`). Nový text vždy přes `t()` a překlad přes `tools/add-translations.py`; výchozí texty pro návštěvníky
  v `Settings::TRANSLATED_DEFAULTS`. Stránky startovacích webů musí projít kontrolou před publikováním (hlídá `tools/unit-tests.php`).
- Zvýraznění `<mark>` v textu nadpisu = doplňková barva bez podbarvení (tečka za titulkem v barvě Signal).

