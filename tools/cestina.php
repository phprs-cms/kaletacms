<?php
/**
 * Hledá češtinu v anglickém rozhraní (tools/test-english.sh). Dva režimy:
 *
 *   php tools/cestina.php stranka.html…   viditelný text stránek (text, title, placeholder, aria-label, alt, tlačítka, data-potvrdit)
 *   php tools/cestina.php --js            české texty ve skriptech administrace (image/*.js) bez položky ve slovníku image/jazyky/admin-en.js
 *
 * Češtinu pozná podle tří znaků: písmena s háčkem a čárkou; text, který je českým klíčem slovníku s anglickým překladem
 * (= chybí t() nebo překlad se nepoužil); častá česká slova bez diakritiky jako samostatná slova (ne adresy /novinky).
 * Vypíše nálezy (soubor: text) a skončí kódem 1, když nějaké jsou.
 */

declare(strict_types=1);

$koren = dirname(__DIR__);

/** Česká slova bez diakritiky, která v anglickém textu nemají co dělat (malými písmeny, porovnává se celé slovo). „Seznam“ chybí schválně – je to i název služby. */
const SLOVA = ['nebo', 'jsou', 'jako', 'pokud', 'bude', 'byla', 'bylo', 'jsme', 'jste', 'nelze', 'zde', 'tento', 'tato', 'toto', 'tyto',
    'novinky', 'novinka', 'novinek', 'kontakt', 'odkaz', 'odkazu', 'soubor', 'soubory', 'nadpis', 'nadpisy', 'obsah', 'upravit', 'smazat',
    'zobrazit', 'hledat', 'hledani', 'kotva', 'heslo', 'stavba', 'stavby', 'verze', 'firma', 'adresa', 'popis', 'popisek', 'chyba', 'druh',
    'koncept', 'kategorie', 'nastavit', 'nastaveni', 'vlastnosti', 'barva', 'sekce', 'kontejner', 'galerie', 'podklad', 'odstavec',
    'titulek', 'perex', 'aktuality', 'pravidla', 'kolekce', 'komponenta', 'komponenty', 'obnovit', 'zahodit', 'odebrat', 'posunout',
    'stranka', 'stranky', 'polozka', 'polozky', 'uzivatel', 'sluzby', 'uvod', 'znacka'];

/** Slova s diakritikou, která do angličtiny patří (názvy jazyků, přejatá slova). */
const POVOLENA = ['Čeština', 'café', 'Café'];

/** Klíče slovníků, které jsou zároveň anglickým slovem (List = seznam i list stromu…), se jako klíč nehledají. */
const DVOJZNACNE = ['List', 'Reference', 'Web', 'Region', 'Standard', 'Video', 'Menu', 'Tablet', 'Logo', 'Text', 'E-mail'];

/** @return array<string, string> český text => překlad ze všech anglických slovníků */
function slovniky(string $koren): array
{
    $vse = [];
    foreach (['en', 'admin-en', 'install-en'] as $s) {
        $vse += require $koren . '/system/jazyky/' . $s . '.php';
    }

    return $vse + slovnikJs($koren);
}

/** @return array<string, string> */
function slovnikJs(string $koren): array
{
    preg_match('/window\.KALETA_PREKLAD = (\{.*\});/s', (string) file_get_contents($koren . '/image/jazyky/admin-en.js'), $m);

    return (array) json_decode((string) preg_replace(['#^\s*//.*$#m', '/,\s*\}$/'], ['', '}'], $m[1] ?? '{}'), true);
}

function maDiakritiku(string $text): bool
{
    return preg_match('/[ěščřžůťďňáéíóúýĚŠČŘŽŮŤĎŇÁÉÍÓÚÝ]/u', str_replace(POVOLENA, '', $text)) === 1;
}

/** Česká slova v textu; slova s lomítkem, tečkou uvnitř, @, = nebo podtržítkem jsou adresy a kód, ne text. */
function ceskaSlova(string $text): array
{
    $nalez = [];
    foreach (preg_split('/\s+/u', $text) ?: [] as $slovo) {
        $slovo = trim($slovo, " \t,;:!?()[]{}\"'„“”‚‘’«».…–—-");
        if ($slovo === '' || preg_match('#[/@=_\#.&%]#', $slovo)) {
            continue;
        }
        if (in_array(mb_strtolower($slovo), SLOVA, true)) {
            $nalez[] = $slovo;
        }
    }

    return $nalez;
}

if (($argv[1] ?? '') === '--js') {
    // každý český text skriptů administrace (i ten, který se překládá až nepřímo přes T(popisek)) musí mít položku ve slovníku
    $slovnik = slovnikJs($koren);
    $nalezy = [];
    foreach (['admin', 'editor', 'menu', 'pomocnik', 'stavitel', 'klice', 'tema'] as $soubor) {
        foreach (retezce((string) file_get_contents($koren . '/image/' . $soubor . '.js')) as [$radek, $text, $vT]) {
            // jednoslovné řetězce bez diakritiky jsou v kódu klíče a názvy (stranka, sekce), ne texty pro člověka
            $cesky = maDiakritiku($text) || preg_match('/\s/u', trim($text)) && ceskaSlova($text) !== [];
            if (($cesky || $vT) && !isset($slovnik[$text]) && preg_match('/\p{L}/u', $text) && !in_array($text, DVOJZNACNE, true)) {
                $nalezy[] = 'image/' . $soubor . '.js:' . $radek . ': ' . $text;
            }
        }
    }
    echo $nalezy === [] ? '' : implode("\n", array_unique($nalezy)) . "\n";
    exit($nalezy === [] ? 0 : 1);
}

/**
 * Řetězce skriptu bez komentářů a regulárních výrazů: [řádek, text, je-li přímo v T(…)].
 * Řetězce skládané s proměnnou (`…${x}`) se berou po částech.
 *
 * @return list<array{0:int, 1:string, 2:bool}>
 */
function retezce(string $js): array
{
    $vysledek = [];
    $n = strlen($js);
    $radek = 1;
    for ($i = 0; $i < $n; $i++) {
        $c = $js[$i];
        $dalsi = $js[$i + 1] ?? '';
        if ($c === "\n") {
            $radek++;
        } elseif ($c === '/' && $dalsi === '/') {
            $i = (strpos($js, "\n", $i) ?: $n) - 1;
        } elseif ($c === '/' && $dalsi === '*') {
            $konec = strpos($js, '*/', $i + 2) ?: $n;
            $radek += substr_count(substr($js, $i, $konec - $i), "\n");
            $i = $konec + 1;
        } elseif ($c === '/' && preg_match('/[(,=:\[!&|?{};]\s*$/', substr($js, max(0, $i - 20), min(20, $i)))) {
            // regulární výraz: přeskočit až po neescapované lomítko mimo [třídu]
            for ($j = $i + 1, $trida = false; $j < $n && $js[$j] !== "\n"; $j++) {
                if ($js[$j] === '\\') {
                    $j++;
                } elseif ($js[$j] === '[') {
                    $trida = true;
                } elseif ($js[$j] === ']') {
                    $trida = false;
                } elseif ($js[$j] === '/' && !$trida) {
                    break;
                }
            }
            $i = $j;
        } elseif ($c === "'" || $c === '"' || $c === '`') {
            for ($j = $i + 1; $j < $n && $js[$j] !== $c; $j++) {
                $j += $js[$j] === '\\' ? 1 : 0;
            }
            $surovy = substr($js, $i + 1, $j - $i - 1);
            $vT = (bool) preg_match('/\bT\(\s*$/', substr($js, max(0, $i - 10), min(10, $i)));
            $text = $c === '`' ? $surovy : (string) json_decode('"' . str_replace(['\\\'', '"'], ["'", '\\"'], $surovy) . '"');
            foreach ($c === '`' ? preg_split('/\$\{[^}]*\}/', $text) ?: [] : [$text] as $cast) {
                $vysledek[] = [$radek, $cast, $vT];
            }
            $radek += substr_count($surovy, "\n");
            $i = $j;
        }
    }

    return $vysledek;
}

// ---------- viditelný text stránek ----------

$klice = [];
$vzory = [];
foreach (slovniky($koren) as $cesky => $preklad) {
    $cesky = (string) $cesky;
    if ($cesky === $preklad || !preg_match('/\p{L}{3}/u', $cesky) || in_array($cesky, DVOJZNACNE, true) || preg_match('/^[a-z_]+$/', $cesky) && str_contains($cesky, '_')) {
        continue;
    }
    if (preg_match('/%(\d\$)?[sd]/', $cesky)) {
        // text s doplněnou hodnotou (Nalezeno: %s): hledá se jako vzor, jen když má kolem hodnoty dost textu
        $pevne = trim((string) preg_replace('/%(\d\$)?[sd]/', ' ', $cesky));
        if (preg_match('/\p{L}{4}/u', $pevne)) {
            $vzory[] = '/^' . implode('.+?', array_map(fn (string $x): string => preg_quote($x, '/'), preg_split('/%(?:\d\$)?[sd]/', $cesky) ?: [])) . '$/u';
        }
        continue;
    }
    $klice[$cesky] = true;
}

$nalezy = 0;
foreach (array_slice($argv, 1) as $soubor) {
    $html = (string) file_get_contents($soubor);
    if (trim($html) === '') {
        continue;
    }
    $dom = Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR | Dom\HTML_NO_DEFAULT_NS);
    $xp = new Dom\XPath($dom);
    $texty = [];
    foreach ($xp->query('//text()[not(ancestor::script or ancestor::style)]') as $uzel) {
        $texty[] = $uzel->textContent;
    }
    foreach ($xp->query('//@title | //@placeholder | //@aria-label | //@alt | //@data-potvrdit | //button/@value | //input[@type="submit" or @type="button" or @type="reset"]/@value | //meta[@name="description"]/@content | //optgroup/@label') as $atribut) {
        $texty[] = $atribut->value;
    }
    $vypsane = [];
    foreach ($texty as $text) {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if ($text === '' || isset($vypsane[$text])) {
            continue;
        }
        $duvod = match (true) {
            maDiakritiku($text) => 'diakritika',
            isset($klice[$text]) || isset($klice[rtrim($text, ':')]) => 'český klíč slovníku',
            (bool) array_filter($vzory, fn (string $v): bool => preg_match($v, $text) === 1) => 'český klíč slovníku',
            ($slova = ceskaSlova($text)) !== [] => 'české slovo „' . implode('“, „', $slova) . '“',
            default => '',
        };
        if ($duvod !== '') {
            $vypsane[$text] = true;
            echo '         ', mb_strimwidth($text, 0, 160, '…'), '   [', $duvod, "]\n";
            $nalezy++;
        }
    }
}
exit($nalezy > 0 ? 1 : 0);
