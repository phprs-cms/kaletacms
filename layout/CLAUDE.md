# Šablony webu v Kaletě – pravidla pro Claude

Pracuješ ve složce šablon systému Kaleta. **Upravovat smíš jen vlastní šablonu** – složku `layout/<vlastní-název>/`.
Všechno ostatní je kód systému a nemění se.

## Co se nedělá

- Neupravuj `system/`, `admin.php`, `index.php`, `install.php`, `image/` ani vestavěnou šablonu `zakladni`. Aktualizace systému je
  přepíše a Stav systému takový zásah hlásí jako porušené jádro.
- Nepřidávej do CMS vlastní funkce, moduly, tabulky ani úpravy databáze. Systém má být pro všechny stejný a aktualizovatelný.
  Chybí-li funkce, patří jako námět autorům Kalety – ne do kódu jedné instalace.
- Šablona je **jen prezentační vrstva**: vypisuje data, která dostane. Nesmí číst ani zapisovat soubory, volat databázi, síť ani cizí
  služby, vkládat jiné PHP soubory, vytvářet objekty nebo volat třídy systému.

## Jak vzniká vlastní šablona

1. Zkopíruj vestavěnou šablonu do `layout/<vlastní-název>/` (malá písmena, číslice, pomlčky). Přes napojení na Claude k tomu slouží
   nástroj `vytvor_sablonu`.
2. Uprav `info.php` (název a popis), `style.css` a `base.php`. Chceš-li jinak vysázet novinku, výpis novinek nebo stránku, přidej do složky
   vlastní `novinka.php`, `vypis.php` nebo `stranka.php` – šablona tak přepíše systémový pohled ze `system/views/front/`.
3. Náhled bez přepnutí webu: `/?sablona=<vlastní-název>` (funguje přihlášenému správci).
4. Aktivuje ji správce ve Vzhledu.

## Co šablona dostává

- `base.php`: `$web` (nastavení, jen `->get()`, `->int()`, `->bool()`), `$titulek`, `$meta`, `$obsah` (hotové HTML),
  `$menu` a `$menu_paticka` (menu z Vzhled → Menu: položky `text`, `url`, `nove_okno`, `deti` = podmenu) a `$menu_html($menu, $cesta, $url(''))`
  (hotové `<li>` i s podmenu a `aria-current`), `$stranky` (starší plochý seznam stránek „v menu“), `$url`, `$kanonicka`, `$jazyk`, `$jazyky_html`,
  **`$hlava` (povinně vypsat před `</head>`) a `$pata` (povinně před `</body>`)** – tudy jde SEO, měření a cookie lišta.
  `$casti['hlavicka']` a `$casti['paticka']`: záhlaví a patička z builderu (Vzhled → Části webu). Když nejsou `null`, vypiš je místo
  vlastního `<header>` / `<footer>` – jinak by úprava v builderu na webu nebyla vidět.
- `novinka.php`: `$novinka` (titulek, uvod, text, datum, obrazek, tema_jm, tema_seo, autor_jm, stitky, faq_html…), `$url`, `$souvisejici`.
- `vypis.php`: `$nadpis`, `$popis`, `$novinky`, `$strana`, `$stran`, `$strankaUrl`, `$hledano`, `$nalezeneStranky`, `$url`.
- `stranka.php`: `$stranka` (titulek, text…), `$uvod` (jde o úvodní stránku webu).

## Povolený zápis PHP v šabloně

Výpis (`<?= e($x) ?>`), `if` / `foreach` / `match`, uzávěry (`$f = fn (…) => …`), `$url('cesta')`, `$web->get('klic')`,
pomocné funkce `e()`, `t()`, `datum()`, `datum_slovy()`, `slugify()` a běžné funkce pro text, čísla a pole
(`count`, `implode`, `array_map` s uzávěrem, `mb_substr`, `number_format`, `date`…). Přesný seznam a kontrola:
`system/src/Core/SablonaKontrola.php`. Přes napojení na Claude jde uložit jen CSS vlastní šablony; PHP soubory šablon mění správce na serveru.

Každý text pro návštěvníka obal `e()` (ochrana před XSS) a `t()` (překlad do jazyka webu). Barvy a písma ber z proměnných
`--ka-akcent`, `--ka-pismo-titulky`, `--ka-pismo-text`; tmavý režim patří do bloku
`@media (prefers-color-scheme: dark) { :root[data-tmavy] { … } }`. Žádná externí písma ani skripty z CDN.

Piš moderní CSS (vrstvy `@layer`, `clamp()`, container queries, `color-mix()`) a interaktivitu bez JavaScriptu (Popover API,
`<details>`, `<dialog>`). Společné prvky (fotogalerie, prohlížečka, video, sdílení, osnova, FAQ) dodává `image/web.css` s nulovou vahou
(`:where()`) – do `style.css` piš jen to, co má vypadat jinak, a nepoužívej `!important`.
