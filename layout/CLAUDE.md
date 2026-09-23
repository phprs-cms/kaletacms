# Šablony webu MiroCMS – pravidla pro Claude

Pracuješ ve složce šablon redakčního systému MiroCMS. **Upravovat smíš jen vlastní šablonu** – složku
`layout/<vlastní-název>/`. Všechno ostatní je kód systému a nemění se.

## Co se nedělá

- Neupravuj `system/`, `admin.php`, `index.php`, `install.php`, `image/` ani vestavěné šablony `classic-newspaper`,
  `modern-magazine` a `minimal`. Aktualizace systému je přepíše a Stav systému takový zásah hlásí
  jako porušené jádro.
- Nepřidávej do CMS vlastní funkce, moduly, tabulky ani úpravy databáze. Systém má být pro všechny stejný
  a aktualizovatelný. Chybí-li funkce, patří jako námět autorům MiroCMS – ne do kódu jedné instalace.
- Šablona je **jen prezentační vrstva**: vypisuje data, která dostane. Nesmí číst ani zapisovat soubory,
  volat databázi, síť ani cizí služby, vkládat jiné PHP soubory, vytvářet objekty nebo volat třídy systému.

## Jak vzniká vlastní šablona

1. Zkopíruj vestavěnou šablonu do `layout/<vlastní-název>/` (malá písmena, číslice, pomlčky). Přes napojení
   na Claude k tomu slouží nástroj `vytvor_sablonu`.
2. Uprav `info.php` (název a popis), `style.css`, `base.php`, `blok.php`, `cla_standard.php`.
3. Náhled bez přepnutí webu: `/?sablona=<vlastní-název>` (funguje přihlášenému administrátorovi).
4. Aktivuje ji administrátor ve Vzhled → Identita webu.

## Co šablona dostává

- `base.php`: `$web` (nastavení, jen `->get()`, `->int()`, `->bool()`), `$titulek`, `$meta`, `$obsah`, `$zony`
  (hlavicka, leva, nad, pod, prava, paticka), `$rozvrzeni`, `$rubriky`, `$stranky`, `$url`, `$kanonicka`,
  `$jazyk`, `$jazyky_html`, **`$hlava` (povinně vypsat před `</head>`) a `$pata` (povinně před `</body>`)** –
  tudy jde SEO, měření, cookie lišta a společné prvky článku.
- `cla_standard.php`: `$clanek`, `$rezim` (nahled | kratky | cely), `$poradi`, `$url`, `$souvisejici`.
  Hotové kusy HTML (`shrnuti_html`, `faq_html`, `hodnoceni_html`, `komentare_html`, `reklama_html`) jen vypiš.
  Na `<article>` celého článku dej třídu `sablona-<soubor>` – podle ní fungují varianty Dlouhé čtení,
  Fotoreportáž a Rozhovor.
- `blok.php`: `$nadpis`, `$obsah`, `$typ`, `$sys`, `$zona`.

## Povolený zápis PHP v šabloně

Výpis (`<?= e($x) ?>`), `if` / `foreach` / `match`, uzávěry (`$f = fn (…) => …`), `$url('cesta')`, `$web->get('klic')`,
pomocné funkce `e()`, `t()`, `datum()`, `datum_slovy()`, `slugify()` a běžné funkce pro text, čísla a pole
(`count`, `implode`, `array_map` s uzávěrem, `mb_substr`, `number_format`, `date`…). Přesný seznam a kontrola:
`system/src/Core/SablonaKontrola.php` – soubor ukládaný přes napojení na Claude, který pravidla poruší, se neuloží.

Každý text pro čtenáře obal `e()` (ochrana před XSS) a `t()` (překlad do jazyka webu). Barvy a písma ber
z proměnných `--rs-akcent`, `--rs-pismo-titulky`, `--rs-pismo-text`; na konec `style.css` patří blok tmavého
režimu `@media (prefers-color-scheme: dark) { :root[data-tmavy] { … } }`. Žádná externí písma ani skripty z CDN.

Základní vzhled společných prvků (štítky článku, Ve zkratce, otázky a odpovědi, hodnocení, komentáře, anketa, reklama, typy bloků)
dodává `image/web.css`, který se načítá až po `style.css` šablony. Tato pravidla mají nulovou váhu (`:where()`), takže je přepíše
jakékoli pravidlo šablony – do `style.css` piš jen to, co má vypadat jinak, a nepoužívej `!important`. Prvky s třídou `rs-…`
(fotogalerie, přehrávač, zámek, účet čtenáře…) mají v `web.css` běžnou váhu jedné třídy; přepíšeš je selektorem o třídu silnějším.
