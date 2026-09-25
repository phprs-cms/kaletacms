<?php
/**
 * Jednotkové testy jádra Kalety - bez frameworku a bez databáze: php tools/testy.php
 *
 * Hlídají to, co kouřový test (tools/test.sh) nepozná: kryptografii, parsování a převody textu.
 * Nový test = další volání over('popis', $skutecne, $ocekavane).
 */

declare(strict_types=1);

require dirname(__DIR__) . '/system/bootstrap.php';

use Kaleta\Core\Asistent;
use Kaleta\Core\Hledani;
use Kaleta\Core\Migrace;
use Kaleta\Core\Soubory;
use Kaleta\Core\Totp;
use Kaleta\Front\Seo;
use Kaleta\Front\TextNovinky;

$chyb = 0;
$celkem = 0;
function over(string $popis, mixed $skutecne, mixed $ocekavane): void
{
    global $chyb, $celkem;
    $celkem++;
    if ($skutecne === $ocekavane) {
        return;
    }
    $chyb++;
    echo "  CHYBA  {$popis}\n         čekal jsem: " . var_export($ocekavane, true) . "\n         dostal jsem: " . var_export($skutecne, true) . "\n";
}

/* ---------- převody textu ---------- */
over('slugify: diakritika a mezery', slugify('Příliš žluťoučký kůň!'), 'prilis-zlutoucky-kun');
over('slugify: prázdný vstup', slugify('***'), 'n-a');
over('slugify: délka', strlen(slugify(str_repeat('abc ', 100), 20)) <= 20, true);
over('bez_diakritiky', bez_diakritiky('Ďábelské ÓDY – Straße'), 'Dabelske ODY – Strasse');
over('e(): uvozovky a značky', e('<a href="x">\'</a>'), '&lt;a href=&quot;x&quot;&gt;&#039;&lt;/a&gt;');
over('datum', datum('2026-09-05 07:03:00', true), '5. 9. 2026 07:03');

/* ---------- hledání ---------- */
over('Hledani::normalizuj', Hledani::normalizuj('<p>Nábřeží&nbsp;<b>Vltavy</b></p><h2>Proměna!</h2>'), 'nabrezi vltavy promena');
over('Hledani::dotaz: krátká slova vypadnou', Hledani::dotaz('co je na Nábřeží'), '+nabrezi*');
over('Hledani::dotaz: operátory fulltextu se neprosadí', Hledani::dotaz('+tajne -verejne "fraze" (x) ~y*'), '+tajne* +verejne* +fraze*');
over('Hledani::dotaz: nejvýš 8 slov', substr_count(Hledani::dotaz('aaa bbb ccc ddd eee fff ggg hhh iii jjj'), '+'), 8);

/* ---------- TOTP (RFC 6238, tajemství "12345678901234567890") ---------- */
$tajemstvi = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
over('TOTP: vektor T=59', Totp::kod($tajemstvi, intdiv(59, 30)), '287082');
over('TOTP: vektor T=1111111109', Totp::kod($tajemstvi, intdiv(1111111109, 30)), '081804');
over('TOTP: vektor T=2000000000', Totp::kod($tajemstvi, intdiv(2000000000, 30)), '279037');
over('TOTP: platný kód projde', Totp::over($tajemstvi, '287082', 59), true);
over('TOTP: sousední okno projde', Totp::over($tajemstvi, '287082', 59 + 30), true);
over('TOTP: starý kód neprojde', Totp::over($tajemstvi, '287082', 59 + 300), false);
over('TOTP: nesmysl neprojde', Totp::over($tajemstvi, 'abcdef', 59), false);
over('TOTP: nové tajemství má 160 bitů', strlen(Totp::noveTajemstvi()), 32);

/* ---------- migrace: dělení SQL na příkazy ---------- */
$sql = "-- komentář\nALTER TABLE ka_novinky ADD COLUMN x INT;   -- poznámka za příkazem\nCREATE TABLE ka_nova (\n  a VARCHAR(10) DEFAULT ';'\n);\nALTER TABLE ka_a ADD CONSTRAINT fk_a FOREIGN KEY (b) REFERENCES ka_b (id);\n";
$prikazy = Migrace::prikazy($sql, 'web_');
over('Migrace::prikazy: počet', count($prikazy), 3);
over('Migrace::prikazy: předpona tabulek', str_contains($prikazy[1], 'CREATE TABLE web_nova'), true);
over('Migrace::prikazy: středník v hodnotě příkaz nerozdělí', str_contains($prikazy[1], "DEFAULT ';'"), true);
over('Migrace::prikazy: předpona omezení', str_contains($prikazy[2], 'CONSTRAINT web_fk_a') && str_contains($prikazy[2], 'REFERENCES web_b'), true);
over('Migrace: KALETA_VERZE_DB odpovídá souborům', KALETA_VERZE_DB, Migrace::posledni());

/* ---------- přílohy ---------- */
over('Soubory: PDF je příloha', Soubory::jePriloha('Zpráva.PDF'), true);
over('Soubory: PHP není příloha', Soubory::jePriloha('shell.php'), false);
over('Soubory: dvojitá přípona', Soubory::jePriloha('shell.pdf.php'), false);
over('Soubory: SVG a HTML ne', Soubory::jePriloha('x.svg') || Soubory::jePriloha('x.html'), false);
over('Soubory: velikost', Soubory::velikost(1536), '2 kB');
over('Soubory: velikost v MB', Soubory::velikost(5 * 1048576), '5,0 MB');

/* ---------- přehrávač a vložené adresy ---------- */
over('prehravac: YouTube bez cookies', str_contains(TextNovinky::prehravac('https://www.youtube.com/watch?v=dQw4w9WgXcQ', '', 'T'), 'youtube-nocookie.com/embed/dQw4w9WgXcQ'), true);
over('prehravac: youtu.be', str_contains(TextNovinky::prehravac('https://youtu.be/dQw4w9WgXcQ', '', 'T'), 'embed/dQw4w9WgXcQ'), true);
over('prehravac: MP3 je <audio>', str_contains(TextNovinky::prehravac('media/2026/09/epizoda.mp3', '/magazin', 'T'), '<audio controls preload="none" src="/magazin/media/2026/09/epizoda.mp3">'), true);
over('prehravac: neznámá adresa v režimu jenZname', TextNovinky::prehravac('https://example.com/video', '', 'T', true), '');
over('prehravac: titulek se escapuje', str_contains(TextNovinky::prehravac('https://vimeo.com/123', '', '"><script>'), '<script>'), false);
$typy = (new ReflectionClass(TextNovinky::class))->newInstanceWithoutConstructor();
$html = $typy->vlozeneAdresy('<p>Úvod</p><p>https://youtu.be/dQw4w9WgXcQ</p><p>Viz https://youtu.be/dQw4w9WgXcQ v textu.</p>');
over('vlozeneAdresy: jen samostatný řádek', [substr_count($html, 'data-vlozit'), substr_count($html, 'Viz https://youtu.be')], [1, 1]);

/* ---------- šablony webu ---------- */
over('Šablony: výchozí šablona existuje a je i výchozí hodnotou nastavení', [is_file(dirname(__DIR__) . '/layout/' . \Kaleta\Front\Layouty::VYCHOZI . '/base.php'), \Kaleta\Core\Settings::DEFAULTS['layout']], [true, \Kaleta\Front\Layouty::VYCHOZI]);
over('Šablony: zrušená šablona „default“ se nevrátila', is_dir(dirname(__DIR__) . '/layout/default'), false);

/* ---------- anglický slovník pokrývá texty webu i administrace ---------- */
$chybiPreklad = static function (string $slovnik, array $vzory): array {
    $preklady = require dirname(__DIR__) . '/system/jazyky/' . $slovnik;
    $chybi = [];
    foreach ($vzory as $vzor) {
        foreach (glob(dirname(__DIR__) . '/' . $vzor) ?: [] as $soubor) {
            preg_match_all("/\\bt\\('((?:[^'\\\\]|\\\\.)+)'/u", (string) file_get_contents($soubor), $m);
            foreach ($m[1] as $k) {
                $k = stripslashes($k);
                if (!isset($preklady[$k]) && preg_match('/[áčďéěíňóřšťúůýž]/iu', $k)) {
                    $chybi[] = basename($soubor) . ': ' . $k;
                }
            }
        }
    }

    return array_values(array_unique($chybi));
};
over('Slovník en.php: texty webu mají anglický překlad', $chybiPreklad('en.php', ['system/views/front/*.php', 'layout/*/*.php', 'system/src/Front/*.php', 'system/src/Stavitel/*.php', 'system/src/Stavitel/Prvky/*.php']), []);
over('Slovník admin-en.php: E-mail webu', isset((require dirname(__DIR__) . '/system/jazyky/admin-en.php')['E-mail webu']), true);

over('Stavba::kod: vnořený skript se nesloží znovu', [str_contains(Kaleta\Stavitel\Stavba::kod('<scr<script>x</script>ipt>alert(1)</scr<script>y</script>ipt>'), '<script'), Kaleta\Stavitel\Stavba::kod('<iframe src="https://mapy.cz/x"></iframe>')], [false, '<iframe src="https://mapy.cz/x"></iframe>']);
over('Stavba::kod: obsluhy událostí a javascript: zmizí', Kaleta\Stavitel\Stavba::kod('<a href="javascript:alert(1)" onclick="x()">A</a><iframe srcdoc="data:text/html,x"></iframe>'), '<a href="#">A</a><iframe srcdoc="#"></iframe>');
/* ---------- skripty administrace a webu bez systémových dialogů (nejdou nastylovat, přeložit a prohlížeče je potlačují) ---------- */
$nativniDialogy = [];
foreach (glob(dirname(__DIR__) . '/image/*.js') ?: [] as $soubor) {
    $kod = preg_replace(['#/\*.*?\*/#s', '#(^|[^:])//.*$#m'], ['', '$1'], (string) file_get_contents($soubor)); // bez komentářů
    if (preg_match_all('/\b(alert|prompt|confirm)\s*\(/', (string) $kod, $m)) {
        $nativniDialogy[] = basename($soubor) . ': ' . implode(', ', array_unique($m[1]));
    }
}
over('Skripty bez window.alert/prompt/confirm', $nativniDialogy, []);

/* ---------- QR kód (dvoufázové přihlášení): vlastní kodér bez knihovny ---------- */
// Reed–Solomon: známý vektor „HELLO WORLD“ verze 1-M z návodu k normě (thonky.com, QR Code Tutorial)
over('Qr: opravné kódy Reed–Solomon (známý vektor)', Kaleta\Core\Qr::opravneKody([32, 91, 11, 120, 209, 114, 220, 77, 67, 64, 236, 17, 236, 17, 236, 17], 10), [196, 35, 39, 119, 235, 215, 231, 226, 93, 23]);
$qrRadky = fn (array $m): array => array_map(fn (array $r): string => implode('', array_map(fn (bool $b): string => $b ? '#' : '.', $r)), $m);
$qr = $qrRadky(Kaleta\Core\Qr::matice('Kaleta', 2));
// formátové bity čtené z matice (sloupec 8 a řádek 8 u levého horního rohu) = tabulka normy pro úroveň M, masku 2: 101111001111100
$qrFormat = '';
foreach ([[0, 8], [1, 8], [2, 8], [3, 8], [4, 8], [5, 8], [7, 8], [8, 8], [8, 7], [8, 5], [8, 4], [8, 3], [8, 2], [8, 1], [8, 0]] as [$y, $x]) {
    $qrFormat = ($qr[$y][$x] === '#' ? '1' : '0') . $qrFormat;
}
over('Qr: formátové bity M/maska 2 podle tabulky normy', $qrFormat, '101111001111100');
over('Qr: verze 1 = 21 × 21 s hledacím vzorem', [count($qr), $qr[0], $qr[6]], [21, '#######..##.#.#######', '#######.#.#.#.#######']);
// matice ověřené nezávislou čtečkou (Chrome BarcodeDetector) – hlídá, že se kodér nerozbije
$qrOtisk = fn (string $text): string => sha1(implode("\n", array_map(fn (array $r): string => implode('', array_map('intval', $r)), Kaleta\Core\Qr::matice($text))));
over('Qr: adresa otpauth (verze 6) odpovídá ověřené matici', $qrOtisk('otpauth://totp/Acme%3Aadmin?secret=JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP&issuer=Acme&digits=6&period=30'), '21b4e92a0dd7dfcfe3d26161ea6baf9459312669');
over('Qr: verze 12 (verzní bity, víc bloků) odpovídá ověřené matici', $qrOtisk(str_repeat('Kaleta QR 0123456789 ', 12)), '12f9c4c80e2de66f316614d576e6dde5464482c8');
$qrSvg = Kaleta\Core\Qr::svg('otpauth://totp/x?secret=AB', 'QR <kód>');
over('Qr: SVG s popisem, bez skriptu', str_contains($qrSvg, 'role="img" aria-label="QR &lt;kód&gt;"') && !str_contains($qrSvg, '<script'), true);

/* ---------- velikosti obrázků: vysoké snímky se měří šířkou, srcset nese skutečné šířky ---------- */
over('Obrazky::pomer: fotka na šířku i na výšku podle delší strany', [Kaleta\Core\Obrazky::pomer(4000, 3000, 2000), Kaleta\Core\Obrazky::pomer(3000, 4000, 2000)], [0.5, 0.5]);
over('Obrazky::pomer: celostránkový snímek podle šířky, výška nejvýš trojnásobek', [Kaleta\Core\Obrazky::pomer(1440, 5000, 2000), round(Kaleta\Core\Obrazky::pomer(1440, 5000, 1200), 3), Kaleta\Core\Obrazky::pomer(1000, 9000, 2000)], [1.0, 0.72, 6000 / 9000]);
$obrSlozka = dirname(__DIR__) . '/media/' . date('Y/m');
@mkdir($obrSlozka, 0775, true);
$obrTmp = tempnam(sys_get_temp_dir(), 'obr');
$obrPng = imagecreatetruecolor(1440, 5000);
imagepng($obrPng, $obrTmp);
$obrUlozeno = Kaleta\Core\Obrazky::ulozSoubor($obrTmp, 'celostrankovy-snimek.png');
$obrSrcset = Kaleta\Core\Obrazky::srcset($obrUlozeno['obr_poloha'], '');
over('Obrazky: vysoký snímek si nechá šířku, srcset má skutečné šířky variant', [$obrUlozeno['obr_width'], $obrUlozeno['obr_height'], (bool) preg_match('/-nahled\.png 553w, .*-1200\.png 1037w, .*\.png 1440w$/', $obrSrcset)], [1440, 5000, true]);
Kaleta\Core\Obrazky::smaz($obrUlozeno['obr_poloha'], $obrUlozeno['nahl_poloha']);
@unlink($obrTmp);

over('Styl::zCss: barva rámečku (i pro stav hover)', Kaleta\Stavitel\Styl::zCss('border-color', '#F6F4EE'), ['barva_ramecku' => '#F6F4EE']);
over('Styl::zCss: zkratka background jen s barvou', Kaleta\Stavitel\Styl::zCss('background', '#EFECE5'), Kaleta\Stavitel\Styl::zCss('background-color', '#EFECE5'));
over('Styl::zCss: background s obrázkem zůstane mimo', Kaleta\Stavitel\Styl::zCss('background', 'url(a.png) no-repeat'), null);
$kontrola = Kaleta\Stavitel\Kontrola::stavby(['v' => 1, 'deti' => [['id' => 's', 'typ' => 'sekce', 'deti' => [
    ['id' => 'a', 'typ' => 'nadpis', 'znacka' => 'h2', 'obsah' => ['text' => 'Služby']],
    ['id' => 'b', 'typ' => 'nadpis', 'znacka' => 'h4', 'obsah' => ['text' => 'Detail']],
    ['id' => 'c', 'typ' => 'tlacitko', 'obsah' => ['text' => 'Poptat', 'odkaz' => '#']],
    ['id' => 'd', 'typ' => 'obrazek', 'obsah' => ['src' => 'media/a.jpg', 'alt' => '']],
    ['id' => 'e', 'typ' => 'obrazek', 'obsah' => ['src' => '{{foto}}', 'alt' => '']],
    ['id' => 'f', 'typ' => 'nadpis', 'znacka' => 'p', 'obsah' => ['text' => '01']],
]]]], true);
over('Kontrola stavby: tlačítko bez odkazu, obrázek bez popisu, chybějící h1 a přeskočená úroveň', array_column($kontrola, 'id'), ['c', 'd', 'a', 'b']);
over('Kontrola stavby: části webu osnovu nadpisů nehlídají', Kaleta\Stavitel\Kontrola::stavby(['v' => 1, 'deti' => [['id' => 'a', 'typ' => 'nadpis', 'znacka' => 'h3', 'obsah' => ['text' => 'Kontakt']]]], false), []);
over('Poptávka: kampaň z utm_* adresy stránky s formulářem', Kaleta\Front\Formulare::kampan('https://example.com/akce?utm_source=google&utm_medium=cpc&utm_campaign=jaro&gclid=x&utm_term[]=a', 'https://example.com'), 'utm_source=google&utm_medium=cpc&utm_campaign=jaro');
over('Poptávka: kampaň jen z vlastního webu', Kaleta\Front\Formulare::kampan('https://jiny.cz/?utm_source=x', 'https://example.com'), '');
over('Poptávka: kampaň pro člověka', Kaleta\Front\Formulare::kampanText('utm_source=google&utm_medium=cpc&utm_campaign=jaro'), 'google / cpc / jaro');
// MCP anglicky: každý nástroj má anglický název, každé pevné hlášení překlad, parametry a výsledky se převádějí
$mcpZdroj = (string) file_get_contents(KALETA_ROOT . '/system/src/Mcp/Nastroje.php');
preg_match_all("/^\s+\['([a-z_]+)', '/m", substr($mcpZdroj, 0, (int) strpos($mcpZdroj, 'public function zavolej')), $mcpNastroje);
over('MCP: každý nástroj má anglický název', array_values(array_diff($mcpNastroje[1], Kaleta\Mcp\Anglicky::ceske())), []);
preg_match_all("/Exception\('((?:[^'\\\\]|\\\\.)*)'\)/", $mcpZdroj . file_get_contents(KALETA_ROOT . '/system/src/Stavitel/Upravy.php'), $mcpHlaseni);
over('MCP: pevná hlášení mají anglický překlad', array_values(array_filter(array_map('stripslashes', $mcpHlaseni[1]), fn (string $z): bool => Kaleta\Mcp\Anglicky::zprava($z) === $z)), []);
over('MCP anglicky: parametry, hodnoty a položky menu', Kaleta\Mcp\Anglicky::argumenty('save_menu', ['location' => 'footer', 'items' => [['type' => 'page', 'page_id' => 2, 'children' => [['type' => 'link', 'url' => '/x', 'new_window' => true]]]]]),
    ['umisteni' => 'paticka', 'polozky' => [['typ' => 'stranka', 'ids' => 2, 'deti' => [['typ' => 'odkaz', 'url' => '/x', 'nove_okno' => true]]]]]);
over('MCP anglicky: typy polí kolekce a nastavení', [Kaleta\Mcp\Anglicky::argumenty('create_collection', ['fields' => [['label' => 'Foto', 'type' => 'image']]]), Kaleta\Mcp\Anglicky::argumenty('update_settings', ['settings' => ['site_name_de' => 'X', 'company_email' => 'a@b.c', 'nazev_webu' => 'Y']])],
    [['pole' => [['popisek' => 'Foto', 'typ' => 'obrazek']]], ['nastaveni' => ['nazev_webu_de' => 'X', 'firma_email' => 'a@b.c', 'nazev_webu' => 'Y']]]);
over('MCP anglicky: výsledek s anglickými klíči, stavba beze změny', Kaleta\Mcp\Anglicky::vysledek('save_build', ['id' => 3, 'stav' => 'publikováno', 'stavba' => ['v' => 1, 'deti' => [['typ' => 'nadpis', 'stav' => 'x']]], 'kontrola' => [['id' => 'a', 'zprava' => 'z']]]),
    ['id' => 3, 'status' => 'published', 'build' => ['v' => 1, 'deti' => [['typ' => 'nadpis', 'stav' => 'x']]], 'check' => [['id' => 'a', 'message' => 'z']]]);
over('MCP anglicky: hlášení s proměnnou částí', Kaleta\Mcp\Anglicky::zprava('Kategorie „Akce“ neexistuje. Použij nástroj seznam_kategorii.'), 'The category “Akce” does not exist. Use list_categories.');

/* ---------- porovnání verzí ---------- */
$r = Kaleta\Core\Rozdil::html('<p>Radnice schválila plán.</p><p>Druhý odstavec.</p>', '<p>Radnice včera schválila nový plán.</p><p>Druhý odstavec.</p><p>Třetí.</p>');
over('Rozdil: slova ve změněném odstavci', str_contains($r['html'], '<ins>včera </ins>') && str_contains($r['html'], '<ins>nový </ins>'), true);
over('Rozdil: nezměněný odstavec bez značek', str_contains($r['html'], '<p>Druhý odstavec.</p>'), true);
over('Rozdil: nový odstavec', str_contains($r['html'], '<p><ins>Třetí.</ins></p>'), true);
over('Rozdil: HTML ve vstupu se escapuje', str_contains(Kaleta\Core\Rozdil::html('', '<p>a &lt;script&gt; b</p>')['html'], '<script>'), false);
over('Rozdil: shodné texty', Kaleta\Core\Rozdil::html('<p>Stejné</p>', '<p>Stejné</p>')['pridano'], 0);

/* ---------- FAQ ---------- */
over('Seo::faq', Seo::faq("Kdy to začne?\nV pondělí.\n\nKolik to stojí?\nNic."), [['Kdy to začne?', 'V pondělí.'], ['Kolik to stojí?', 'Nic.']]);
over('Seo::faq: prázdný vstup', Seo::faq(null), []);

/* ---------- zálohy do S3: podpis AWS Signature V4 (hodnota ověřená nezávislým výpočtem) ---------- */
$h = Kaleta\Core\VzdalenaZaloha::podpisS3('PUT', 's3.eu-central-1.amazonaws.com', '/muj-bucket/kaleta-zaloha.sql.gz', hash('sha256', 'obsah'), 'eu-central-1', 'AKIDEXAMPLE', 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY', 1789900000);
over('S3: rozsah a podepsané hlavičky', str_contains($h['Authorization'], 'Credential=AKIDEXAMPLE/20260920/eu-central-1/s3/aws4_request, SignedHeaders=host;x-amz-content-sha256;x-amz-date, Signature='), true);
over('S3: podpis má 64 šestnáctkových znaků', (bool) preg_match('/Signature=[0-9a-f]{64}$/', $h['Authorization']), true);

/* ---------- kontrola odkazů: jen veřejné adresy (ochrana před ohledáváním vnitřní sítě) ---------- */
over('Odkazy: výběr odkazů z HTML', Kaleta\Core\Odkazy::odkazy('<p><a href="https://example.com/a?x=1&amp;y=2">a</a> <a href="mailto:a@b.cz">m</a> <a href="#kotva">k</a> <a class="x" href="/clanek/muj">c</a> <a href="https://example.com/a?x=1&amp;y=2">znovu</a></p>'), ['https://example.com/a?x=1&y=2', '/clanek/muj']);
foreach (['http://127.0.0.1/', 'http://localhost/', 'http://10.0.0.5/admin', 'http://192.168.1.1/', 'http://169.254.169.254/latest/meta-data/', 'http://[::1]/', 'ftp://example.com/', 'https://example.com:8443/', 'file:///etc/passwd', 'gopher://x/'] as $vnitrni) {
    over('Odkazy: nekontroluje se ' . $vnitrni, Kaleta\Core\Odkazy::jeVerejna($vnitrni), false);
}
over('Odkazy: veřejná adresa se kontroluje', Kaleta\Core\Odkazy::jeVerejna('https://93.184.216.34/stranka'), true);

/* ---------- asistent: překlad článku (kostra HTML z originálu, texty od modelu) ---------- */
$clanekHtml = '<h2>Nadpis oddílu</h2><p>První <strong>tučný</strong> a <a href="/x?a=1&amp;b=2">odkaz</a>.</p><figure><img src="a.jpg" alt="x"><figcaption>Popisek fotky</figcaption></figure><script>alert("nepřekládat")</script><p>2024</p>';
$r = Kaleta\Core\Asistent::rozloz($clanekHtml);
over('Asistent::rozloz: úseky k překladu', $r['useky'], ['Nadpis oddílu', 'První [[0]]tučný[[1]] a [[2]]odkaz[[3]].', 'Popisek fotky']);
over('Asistent::sloz: beze změny textu vrátí původní HTML', Kaleta\Core\Asistent::sloz($r['kostra'], $r['useky']), $clanekHtml);
over('Asistent::sloz: HTML od modelu se vypíše jako text', str_contains(Kaleta\Core\Asistent::sloz($r['kostra'], ['<script>alert(1)</script>', 'x', '<img src=x onerror=alert(1)>']), '<script>alert(1)') || str_contains(Kaleta\Core\Asistent::sloz($r['kostra'], ['a', 'b', '<img src=x onerror=alert(1)>']), '<img src=x'), false);
over('Asistent::sloz: chybějící symbol = úsek bez formátování', Kaleta\Core\Asistent::sloz($r['kostra'], ['N', 'First [[0]]bold[[1]] and link.', 'P']), '<h2>N</h2><p>First bold and link.</p><figure><img src="a.jpg" alt="x"><figcaption>P</figcaption></figure><script>alert("nepřekládat")</script><p>2024</p>');
over('Asistent::sloz: špatně vnořené symboly = úsek bez formátování', str_contains(Kaleta\Core\Asistent::sloz($r['kostra'], ['N', '[[1]]bold[[0]] [[2]]link[[3]]', 'P']), '<strong>'), false);
over('Asistent::sloz: přeházené pořadí slov formátování zachová', str_contains(Kaleta\Core\Asistent::sloz($r['kostra'], ['N', 'A [[2]]link[[3]] and [[0]]bold[[1]] first.', 'P']), '<p>A <a href="/x?a=1&amp;b=2">link</a> and <strong>bold</strong> first.</p>'), true);

$nastaveni = (new ReflectionClass(Kaleta\Core\Settings::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(Kaleta\Core\Settings::class, 'values'))->setValue($nastaveni, ['nazev_webu' => 'Test', 'ai_klic' => 'x']);
$falesny = new class($nastaveni) extends Kaleta\Core\Asistent {
    public int $volani = 0;

    protected function zavolej(array $telo): array
    {
        $this->volani++;
        preg_match('#<useky>\n(.*)\n</useky>#s', $telo['messages'][0]['content'], $m);

        return ['content' => [['type' => 'text', 'text' => json_encode(['preklady' => array_map(mb_strtoupper(...), json_decode($m[1], true))], JSON_UNESCAPED_UNICODE)]]];
    }
};
$prelozeno = $falesny->preloz(['titulek' => 'Tom & Jerry „znovu“ ve městě, tentokrát úplně jinak než kdy dřív', 'text' => '<p>Krátký <em>text</em> článku, který má aspoň pár desítek znaků.</p>', 'seo_popis' => ''], 'en', ['titulek', 'seo_popis']);
over('Asistent::preloz: prostý text se neescapuje dvakrát', $prelozeno['titulek'], 'TOM & JERRY „ZNOVU“ VE MĚSTĚ, TENTOKRÁT ÚPLNĚ JINAK NEŽ KDY DŘÍV');
over('Asistent::preloz: HTML pole drží kostru', $prelozeno['text'], '<p>KRÁTKÝ <em>TEXT</em> ČLÁNKU, KTERÝ MÁ ASPOŇ PÁR DESÍTEK ZNAKŮ.</p>');
over('Asistent::preloz: prázdné pole zůstane prázdné', $prelozeno['seo_popis'], '');
$falesny->volani = 0;
$dlouhy = $falesny->preloz(['text' => str_repeat('<p>' . str_repeat('Věta o něčem. ', 100) . '</p>', 9)], 'en');
over('Asistent::preloz: dlouhý článek jde po dávkách', [$falesny->volani > 1, substr_count($dlouhy['text'], '<p>')], [true, 9]);
try {
    $falesny->preloz(['text' => '<p>nic</p>'], 'xx');
    over('Asistent::preloz: neznámý jazyk odmítne', 'prošlo', 'výjimka');
} catch (RuntimeException) {
    over('Asistent::preloz: neznámý jazyk odmítne', 'výjimka', 'výjimka');
}

/* ---------- dočasné přepnutí jazyka (e-maily v jazyce příjemce) ---------- */
Kaleta\Core\Jazyk::nastav('cs');
over('Jazyk::docasne: uvnitř platí cizí jazyk', Kaleta\Core\Jazyk::docasne('en', fn (): string => Kaleta\Core\Jazyk::kod() . '|' . t('Číst článek →')), 'en|Read article →');
over('Jazyk::docasne: potom se jazyk vrátí', Kaleta\Core\Jazyk::kod() . '|' . t('Číst článek →'), 'cs|Číst článek →');
try {
    Kaleta\Core\Jazyk::docasne('en', function (): never { throw new RuntimeException('x'); });
} catch (RuntimeException) {
}
over('Jazyk::docasne: jazyk se vrátí i po výjimce', Kaleta\Core\Jazyk::kod(), 'cs');

/* ---------- převládající barva obrázku ---------- */
if (function_exists('imagecreatetruecolor')) {
    $docasny = tempnam(sys_get_temp_dir(), 'rs') . '.png';
    $platno = imagecreatetruecolor(40, 20);
    imagefill($platno, 0, 0, imagecolorallocate($platno, 200, 30, 60));
    imagepng($platno, $docasny);
    over('Obrazky::barva: jednobarevný obrázek', Kaleta\Core\Obrazky::barva($docasny), '#c81e3c');
    unlink($docasny);
    over('Obrazky::barva: chybějící soubor', Kaleta\Core\Obrazky::barva($docasny), null);
}

/* ---------- skripty: nesmí hledat prvek (data-atribut), který nikde nevzniká – tak se rozbil dialog Médií ---------- */
$kdeVznika = [
    'image/editor.js' => ['system/views/admin'], 'image/admin.js' => ['system/views/admin', 'system/src/Admin'], 'image/pomocnik.js' => ['system/views/admin'],
    'image/web.js' => ['system/views/front', 'system/src/Front', 'system/src/Stavitel/Prvky', 'layout'],
];
foreach ($kdeVznika as $skript => $slozky) {
    $zdroj = (string) file_get_contents(KALETA_ROOT . '/' . $skript);
    preg_match_all('/querySelector(?:All)?\(\'\[(data-[a-z0-9-]+)\]\'\)/', $zdroj, $odkazy);
    $bezHledani = (string) preg_replace('/(querySelector(All)?|closest|matches)\([^)]*\)/', '', $zdroj);
    $chybi = [];
    foreach (array_unique($odkazy[1]) as $atribut) {
        $vSablonach = false;
        foreach ($slozky as $slozka) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(KALETA_ROOT . '/' . $slozka, FilesystemIterator::SKIP_DOTS)) as $soubor) {
                $vSablonach = $vSablonach || str_contains((string) file_get_contents($soubor->getPathname()), $atribut);
            }
        }
        if (!$vSablonach && !preg_match('/[\s"\']' . preg_quote($atribut, '/') . '[\s>="\']/', $bezHledani) && !str_contains($bezHledani, "setAttribute('" . $atribut . "'")) {
            $chybi[] = $atribut;
        }
    }
    over($skript . ': každý hledaný data-atribut někde vzniká', $chybi, []);
}

/* ---------- administrace má Content-Security-Policy bez 'unsafe-inline': žádné inline skripty ani obsluhy událostí ---------- */
$inline = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(KALETA_ROOT . '/system/views/admin', FilesystemIterator::SKIP_DOTS)) as $soubor) {
    $zdroj = (string) file_get_contents($soubor->getPathname());
    if (preg_match('#<script(?![^>]*\bsrc=)(?![^>]*type="application/json")[^>]*>|\son(?:click|change|input|submit|load|error|key\w+|mouse\w+)="#i', $zdroj)) {
        $inline[] = substr($soubor->getPathname(), strlen(KALETA_ROOT) + 1);
    }
}
over('šablony administrace neobsahují inline skripty (CSP)', $inline, []);

/* ---------- podpisy vydavatele: víc klíčů, výměna a odvolání klíče ---------- */
if (function_exists('sodium_crypto_sign_keypair')) {
    $par = fn (): array => (fn (string $p): array => [sodium_crypto_sign_secretkey($p), sodium_crypto_sign_publickey($p)])(sodium_crypto_sign_keypair());
    [[$skProvozni, $pkProvozni], [$skZalozni, $pkZalozni], [$skNovy, $pkNovy], [$skCizi]] = [$par(), $par(), $par(), $par()];
    $podepis = fn (string $zprava, string $sk): string => base64_encode(sodium_crypto_sign_detached($zprava, $sk));
    $pub = tempnam(sys_get_temp_dir(), 'rs');
    file_put_contents($pub, "# poznámka\n" . base64_encode($pkProvozni) . " provozni\n\nnesmysl-ktery-neni-klic\n" . base64_encode($pkZalozni) . " zalozni 2026-09-20\n");
    $zprava = Kaleta\Core\Podpis::zpravaBalicku('3.0.1', str_repeat('A', 64), false);
    over('Podpis::klice: dva platné klíče, poznámky a nesmysly se přeskočí', count(Kaleta\Core\Podpis::klice($pub)), 2);
    over('Podpis: provozní klíč platí', Kaleta\Core\Podpis::plati($zprava, $podepis($zprava, $skProvozni), $pub), true);
    over('Podpis: záložní klíč platí také', Kaleta\Core\Podpis::plati($zprava, $podepis($zprava, $skZalozni), $pub), true);
    over('Podpis: cizí klíč neplatí', Kaleta\Core\Podpis::plati($zprava, $podepis($zprava, $skCizi), $pub), false);
    over('Podpis: poškozený podpis neplatí', Kaleta\Core\Podpis::plati($zprava, 'AAAA', $pub), false);
    over('Podpis: běžné vydání nejde prohlásit za bezpečnostní', Kaleta\Core\Podpis::plati(Kaleta\Core\Podpis::zpravaBalicku('3.0.1', str_repeat('A', 64), true), $podepis($zprava, $skProvozni), $pub), false);
    over('Podpis: otisk balíčku se porovnává bez ohledu na velikost písmen', Kaleta\Core\Podpis::zpravaBalicku('3.0.1', 'ABC', false), '3.0.1|abc|bezne');
    // únik provozního klíče: vydání podepsané záložním přinese soubor bez něj a s novým provozním
    file_put_contents($pub, base64_encode($pkNovy) . " provozni\n" . base64_encode($pkZalozni) . " zalozni\n");
    over('výměna klíče: odvolaný klíč už neplatí', Kaleta\Core\Podpis::plati($zprava, $podepis($zprava, $skProvozni), $pub), false);
    over('výměna klíče: nový provozní klíč platí', Kaleta\Core\Podpis::plati($zprava, $podepis($zprava, $skNovy), $pub), true);
    file_put_contents($pub, '');
    over('Podpis: bez klíčů neplatí nic', Kaleta\Core\Podpis::plati($zprava, $podepis($zprava, $skNovy), $pub), false);
    unlink($pub);
}
// Klíče vydavatele Kalety vzniknou až před prvním vydáním (docs/VYDAVANI.md); do té doby smí být soubor bez klíče, ale musí jít přečíst.
over('system/aktualizace.pub jde přečíst', is_array(Kaleta\Core\Podpis::klice(KALETA_ROOT . '/system/aktualizace.pub')), true);

/* ---------- instalátor: každý text má překlad ve všech jazycích ---------- */
$klice = [];
foreach (['system/views/install/formular.php', 'system/views/install/hotovo.php', 'system/src/Install/Installer.php'] as $soubor) {
    preg_match_all("/\\bt\\('((?:[^'\\\\]|\\\\.)*)'/", (string) file_get_contents(KALETA_ROOT . '/' . $soubor), $nalezene);
    foreach ($nalezene[1] as $text) {
        $klice[stripslashes($text)] = true;
    }
}
// karty rozšíření a ukázkových webů vypisuje instalátor přes t() z konstant
foreach ([...array_values(Kaleta\Core\Rozsireni::SEZNAM), ...array_values(Kaleta\Stavitel\Knihovna::WEBY)] as $karta) {
    $klice[$karta['nazev'] ?? $karta[0]] = true;
    $klice[$karta['popis'] ?? $karta[1]] = true;
}
foreach (['en'] as $kod) {
    $slovnik = require KALETA_ROOT . '/system/jazyky/install-' . $kod . '.php';
    // mezinárodní slova se nepřekládají (nástroj na slovníky shodné položky nezapisuje)
    $chybi = array_values(array_diff(array_keys($klice), array_keys($slovnik), ['Server', 'Port', 'E-mail', 'Newsletter']));
    over('instalátor: úplný slovník ' . $kod, $chybi, []);
}

/* ---------- čísla podle jazyka ---------- */
over('pocet: česky mezera jako oddělovač tisíců', Kaleta\Core\Jazyk::docasne('cs', fn () => pocet(1234567)), "1\u{00A0}234\u{00A0}567");
over('pocet: anglicky čárka a desetinná tečka', Kaleta\Core\Jazyk::docasne('en', fn () => pocet(12345.678, 2)), '12,345.68');
over('Soubory::velikost: anglicky desetinná tečka', Kaleta\Core\Jazyk::docasne('en', fn () => Kaleta\Core\Soubory::velikost(3 * 1048576 + 524288)), '3.5 MB');

/* ---------- marketingové kódy a souhlas ---------- */
over('Seo::cekaNaSouhlas: bez lišty beze změny', Kaleta\Front\Seo::cekaNaSouhlas('<script src="x.js"></script>', 'zadna'), '<script src="x.js"></script>');
over('Seo::cekaNaSouhlas: vestavěná lišta balí do <template>', Kaleta\Front\Seo::cekaNaSouhlas('<ins></ins><script>a()</script>', 'vestavena'), '<template data-souhlas="marketing"><ins></ins><script>a()</script></template>');
over('Seo::cekaNaSouhlas: externí služba dostane značené skripty', Kaleta\Front\Seo::cekaNaSouhlas('<ins></ins><SCRIPT async src="x.js"></script><script type="application/json">{}</script>', 'externi'),
    '<ins></ins><script type="text/plain" data-cookieconsent="marketing" async src="x.js"></script><script type="application/json">{}</script>');

/* ---------- aktualizace: úklid souborů, které nové vydání už neobsahuje ---------- */
$uklid = sys_get_temp_dir() . '/kaleta-uklid-' . bin2hex(random_bytes(4));
mkdir($uklid . '/system/stare', 0775, true);
mkdir($uklid . '/media', 0775, true);
foreach (['index.php', 'system/stare/zrusene.php', 'system/zustava.php', 'media/foto.jpg', 'config.php', 'vlastni.php'] as $f) {
    file_put_contents($uklid . '/' . $f, 'x');
}
$smazano = Kaleta\Core\Aktualizace::uklidZastarale($uklid, ['index.php', 'system/stare/zrusene.php', 'system/zustava.php', 'media/foto.jpg', 'config.php', '../mimo.php'], ['index.php', 'system/zustava.php']);
over('Aktualizace: smaže jen soubor zrušený novým vydáním', $smazano, 1);
over('Aktualizace: zrušený soubor i jeho prázdná složka jsou pryč', is_dir($uklid . '/system/stare'), false);
over('Aktualizace: chráněné cesty a vlastní soubory zůstávají', [is_file($uklid . '/media/foto.jpg'), is_file($uklid . '/config.php'), is_file($uklid . '/vlastni.php'), is_file($uklid . '/system/zustava.php')], [true, true, true, true]);
exec('rm -rf ' . escapeshellarg($uklid));

/* ---------- přihlašovací klíče (WebAuthn): softwarový autentikátor proti ověřovacímu jádru ---------- */
$pkRp = 'redakce.example'; $pkPuvod = 'https://redakce.example';
$pkKlic = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
$pkPopis = openssl_pkey_get_details($pkKlic);
$pkDer = base64_decode(preg_replace('/-----[^-]+-----|\s/', '', $pkPopis['key']));
$pkX = str_pad($pkPopis['ec']['x'], 32, "\0", STR_PAD_LEFT); $pkY = str_pad($pkPopis['ec']['y'], 32, "\0", STR_PAD_LEFT);
$pkCose = "\xA5\x01\x02\x03\x26\x20\x01\x21\x58\x20" . $pkX . "\x22\x58\x20" . $pkY;
$pkId = random_bytes(20);
$pkKlient = static fn (string $typ, string $vyzva, string $puvod): string => Kaleta\Core\Passkey::b64((string) json_encode(['type' => $typ, 'challenge' => $vyzva, 'origin' => $puvod, 'crossOrigin' => false], JSON_UNESCAPED_SLASHES));
$pkRegData = static fn (string $rp, int $priznaky = 0x45): string => hash('sha256', $rp, true) . chr($priznaky) . pack('N', 0) . str_repeat("\0", 16) . pack('n', strlen($pkId)) . $pkId . $pkCose;
$pkVyzva = Kaleta\Core\Passkey::vyzva();
$pkReg = ['clientDataJSON' => $pkKlient('webauthn.create', $pkVyzva, $pkPuvod), 'authenticatorData' => Kaleta\Core\Passkey::b64($pkRegData($pkRp)), 'publicKey' => Kaleta\Core\Passkey::b64($pkDer), 'publicKeyAlgorithm' => -7];
$pkUlozeno = Kaleta\Core\Passkey::overRegistraci($pkReg, $pkVyzva, $pkPuvod, $pkRp);
over('Passkey: registrace vrátí id klíče', $pkUlozeno['id'], Kaleta\Core\Passkey::b64($pkId));
over('Passkey: registrace vrátí veřejný klíč v PEM', str_contains($pkUlozeno['klic'], 'BEGIN PUBLIC KEY'), true);
$pkOdmitne = static function (callable $f): bool { try { $f(); return false; } catch (RuntimeException) { return true; } };
over('Passkey: registrace s cizí výzvou neprojde', $pkOdmitne(fn () => Kaleta\Core\Passkey::overRegistraci($pkReg, Kaleta\Core\Passkey::vyzva(), $pkPuvod, $pkRp)), true);
over('Passkey: registrace z jiného původu neprojde', $pkOdmitne(fn () => Kaleta\Core\Passkey::overRegistraci($pkReg, $pkVyzva, 'https://podvrh.example', $pkRp)), true);
over('Passkey: registrace pro jinou doménu neprojde', $pkOdmitne(fn () => Kaleta\Core\Passkey::overRegistraci($pkReg, $pkVyzva, $pkPuvod, 'jina.example')), true);
$pkCizi = openssl_pkey_get_details(openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']));
over('Passkey: podstrčený veřejný klíč neprojde', $pkOdmitne(fn () => Kaleta\Core\Passkey::overRegistraci(['publicKey' => Kaleta\Core\Passkey::b64(base64_decode(preg_replace('/-----[^-]+-----|\s/', '', $pkCizi['key'])))] + $pkReg, $pkVyzva, $pkPuvod, $pkRp)), true);
over('Passkey: odpověď z přihlášení nejde použít k registraci', $pkOdmitne(fn () => Kaleta\Core\Passkey::overRegistraci(['clientDataJSON' => $pkKlient('webauthn.get', $pkVyzva, $pkPuvod)] + $pkReg, $pkVyzva, $pkPuvod, $pkRp)), true);
$pkPrihlas = static function (string $vyzva, int $pocitadlo, string $rp = 'redakce.example', string $puvod = 'https://redakce.example', int $priznaky = 0x05) use ($pkKlic, $pkKlient): array {
    $data = hash('sha256', $rp, true) . chr($priznaky) . pack('N', $pocitadlo);
    $klient = $pkKlient('webauthn.get', $vyzva, $puvod);
    openssl_sign($data . hash('sha256', Kaleta\Core\Passkey::zB64($klient), true), $podpis, $pkKlic, OPENSSL_ALGO_SHA256);

    return ['clientDataJSON' => $klient, 'authenticatorData' => Kaleta\Core\Passkey::b64($data), 'signature' => Kaleta\Core\Passkey::b64($podpis)];
};
$pkV2 = Kaleta\Core\Passkey::vyzva();
over('Passkey: platné přihlášení vrátí nové počitadlo', Kaleta\Core\Passkey::overPrihlaseni($pkPrihlas($pkV2, 5), $pkV2, $pkPuvod, $pkRp, $pkUlozeno['klic'], 4), 5);
over('Passkey: synchronizovaný klíč s nulovým počitadlem projde', Kaleta\Core\Passkey::overPrihlaseni($pkPrihlas($pkV2, 0), $pkV2, $pkPuvod, $pkRp, $pkUlozeno['klic'], 0), 0);
over('Passkey: přehraná odpověď (jiná výzva) neprojde', $pkOdmitne(fn () => Kaleta\Core\Passkey::overPrihlaseni($pkPrihlas($pkV2, 6), Kaleta\Core\Passkey::vyzva(), $pkPuvod, $pkRp, $pkUlozeno['klic'], 5)), true);
over('Passkey: počitadlo, které neroste, neprojde', $pkOdmitne(fn () => Kaleta\Core\Passkey::overPrihlaseni($pkPrihlas($pkV2, 5), $pkV2, $pkPuvod, $pkRp, $pkUlozeno['klic'], 5)), true);
over('Passkey: podpis jiným klíčem neprojde', $pkOdmitne(fn () => Kaleta\Core\Passkey::overPrihlaseni($pkPrihlas($pkV2, 6), $pkV2, $pkPuvod, $pkRp, $pkCizi['key'], 5)), true);
over('Passkey: odpověď z podvržené domény neprojde', $pkOdmitne(fn () => Kaleta\Core\Passkey::overPrihlaseni($pkPrihlas($pkV2, 6, 'redakce.example', 'https://redakce.example.podvrh.cz'), $pkV2, $pkPuvod, $pkRp, $pkUlozeno['klic'], 5)), true);
over('Passkey: klíč jiné domény neprojde', $pkOdmitne(fn () => Kaleta\Core\Passkey::overPrihlaseni($pkPrihlas($pkV2, 6, 'jina.example'), $pkV2, $pkPuvod, $pkRp, $pkUlozeno['klic'], 5)), true);
over('Passkey: bez potvrzení přítomnosti uživatele neprojde', $pkOdmitne(fn () => Kaleta\Core\Passkey::overPrihlaseni($pkPrihlas($pkV2, 6, 'redakce.example', 'https://redakce.example', 0x00), $pkV2, $pkPuvod, $pkRp, $pkUlozeno['klic'], 5)), true);
$pkZmeneno = $pkPrihlas($pkV2, 6); $pkZmeneno['authenticatorData'] = Kaleta\Core\Passkey::b64(Kaleta\Core\Passkey::zB64($pkZmeneno['authenticatorData']) . 'x');
over('Passkey: pozměněná data zařízení neprojdou', $pkOdmitne(fn () => Kaleta\Core\Passkey::overPrihlaseni($pkZmeneno, $pkV2, $pkPuvod, $pkRp, $pkUlozeno['klic'], 5)), true);
over('Passkey: původ a doména z adresy webu', [Kaleta\Core\Passkey::puvod('https://WWW.Web.cz/'), Kaleta\Core\Passkey::puvod('http://localhost:8080'), Kaleta\Core\Passkey::rpId('https://www.web.cz:8443/x')], ['https://www.web.cz', 'http://localhost:8080', 'www.web.cz']);

/* ---------- .htaccess: cíle přepisů jsou adresy, ne relativní cesty ---------- */
// Relativní cíl (RewriteRule ^ index.php) skončí na hostinzích, které mapují subdomény do složky mimo kořen webu, smyčkou a chybou 500.
$htaccess = (string) file_get_contents(KALETA_ROOT . '/.htaccess');
preg_match_all('/^\s*RewriteRule\s+\S+\s+(\S+)/m', $htaccess, $cile);
over('.htaccess: žádný přepis nemá relativní cíl', array_values(array_filter($cile[1], static fn (string $c): bool => $c !== '-' && !str_starts_with($c, '%{ENV:BASE}/'))), []);
over('.htaccess: složka webu se počítá z adresy požadavku', str_contains($htaccess, 'E=BASE:%1'), true);

/* ---------- cesty v nabídce jako odkazy (hlášky, Stav systému, nápovědy) ---------- */
Kaleta\Core\Jazyk::nastav('cs', 'admin-');
$cestyHtml = Kaleta\Admin\Cesty::odkazy('/admin.php', 'Je k dispozici nová verze 3.0.1 – nainstalujete ji v Nastavení → Zálohy a aktualizace. <b>', ['config']);
over('Cesty: známá cesta je odkaz', str_contains($cestyHtml, '<a href="/admin.php?modul=config&amp;zalozka=zalohy">Nastavení → Zálohy a aktualizace</a>'), true);
over('Cesty: zbytek textu zůstává escapovaný', str_contains($cestyHtml, '&lt;b&gt;'), true);
over('Cesty: delší cesta má přednost a odkaz se nevnořuje', substr_count($cestyHtml, '<a '), 1);
over('Cesty: bez práva k modulu žádný odkaz', str_contains(Kaleta\Admin\Cesty::odkazy('/admin.php', 'Nastavení → Pošta', []), '<a '), false);
Kaleta\Core\Jazyk::nastav('en', 'admin-');
over('Cesty: v angličtině se odkazuje přeložená cesta', str_contains(Kaleta\Admin\Cesty::odkazy('/admin.php', t('Je k dispozici nová verze %s – nainstalujete ji v Nastavení → Zálohy a aktualizace.', '3.0.1'), ['config']), '>Settings → Backups and updates</a>'), true);
Kaleta\Core\Jazyk::nastav('cs', 'admin-');

/* ---------- antispam: otisk IP ---------- */
over('Antispam::otisk: není to IP adresa', str_contains(Kaleta\Core\Antispam::otisk('203.0.113.7'), '203'), false);
over('Antispam::otisk: stejná adresa = stejný otisk', Kaleta\Core\Antispam::otisk('203.0.113.7'), Kaleta\Core\Antispam::otisk('203.0.113.7'));

/* ---------- import z WordPressu: čtení exportu (tools/fixtures/wordpress-ukazka.xml), náhled, bezpečné XML ---------- */
$wpCesta = KALETA_ROOT . '/tools/fixtures/wordpress-ukazka.xml';
$wpOdmitne = static function (callable $f): bool { try { $f(); return false; } catch (RuntimeException) { return true; } };
$wp = new Kaleta\Core\WpSoubor($wpCesta);
over('WpSoubor: ukázkový export projde ověřením', $wpOdmitne(fn () => $wp->over()), false);
$wpHlavicka = $wp->hlavicka();
over('WpSoubor: starý web z <channel><link>', [$wpHlavicka['nazev'], $wpHlavicka['adresa']], ['Podhorský zpravodaj', 'https://www.podhorsky-zpravodaj.example']);
over('WpSoubor: autoři jako přihlašovací jméno => zobrazované jméno', $wpHlavicka['autori'], ['redakce' => 'Redakce Zpravodaje', 'bhorakova' => 'Běla Horáková']);
over('WpSoubor: rubriky s hierarchií', $wpHlavicka['rubriky'], ['zpravy' => ['nazev' => 'Zprávy', 'predek' => ''], 'z-radnice' => ['nazev' => 'Z radnice', 'predek' => 'zpravy']]);
over('WpSoubor: tři štítky', array_keys($wpHlavicka['stitky']), ['most', 'doprava', 'slavnosti']);
$wpPolozky = iterator_to_array($wp->polozky());
over('WpSoubor: devět položek, typy v pořadí souboru', array_column($wpPolozky, 'typ'), ['post', 'post', 'post', 'post', 'page', 'nav_menu_item', 'attachment', 'attachment', 'attachment']);
over('WpSoubor: přeskočení už zpracovaných položek drží pořadí', array_keys(iterator_to_array($wp->polozky(7))), [7, 8]);
over('WpSoubor: první příspěvek', [$wpPolozky[0]['id'], $wpPolozky[0]['stav'], $wpPolozky[0]['pripnuty'], $wpPolozky[0]['nahled'], $wpPolozky[0]['rubriky'], array_keys($wpPolozky[0]['stitky'])], [101, 'publish', true, 201, ['z-radnice' => 'Z radnice'], ['most', 'doprava']]);
over('WpSoubor: komentáře se nečtou', array_key_exists('komentare', $wpPolozky[0]), false);
over('WpSoubor: e-mail ani IP se z exportu nikam nedostanou', (bool) preg_match('/posta\.example|198\.51\.100|203\.0\.113/', (string) json_encode($wpPolozky)), false);
$wpStav = Kaleta\Core\WpImport::novyStav('wordpress-ukazka.xml');
Kaleta\Core\WpImport::analyzuj($wpStav, 30, $wpCesta);
over('WpImport náhled: fáze a počet položek', [$wpStav['faze'], $wpStav['celkem'], $wpStav['pozice']], ['nahled', 9, 0]);
over('WpImport náhled: příspěvky podle stavu a stránky', [$wpStav['prehled']['clanky'], $wpStav['prehled']['stranky']], [['publish' => 3, 'draft' => 1], ['publish' => 1]]);
over('WpImport náhled: kategorie, štítky, autoři, přílohy', [$wpStav['prehled']['rubriky'], $wpStav['prehled']['stitky'], $wpStav['prehled']['autori'], $wpStav['prehled']['prilohy']], [2, 3, 2, 3]);
over('WpImport náhled: upozorní na cizí typ obsahu a zkratku doplňku', [$wpStav['prehled']['jine'], $wpStav['prehled']['zkratky']], [['nav_menu_item' => 1], ['kontaktni-formular' => 1]]);
over('WpImport náhled: adresy příloh pro galerie a hlavní obrázky', $wpStav['prilohy'][202] ?? '', 'https://www.podhorsky-zpravodaj.example/wp-content/uploads/2026/05/pohled.jpg');

$wpTmp = sys_get_temp_dir() . '/kaleta-wp-' . bin2hex(random_bytes(4));
mkdir($wpTmp);
$wpHlava = '<rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/" xmlns:content="http://purl.org/rss/1.0/modules/content/"><channel><title>T</title><link>https://stary.example</link>';
file_put_contents($wpTmp . '/tajne.txt', 'TAJNY-OBSAH-SERVERU');
$wpSkodlive = [
    'vnější entita (XXE)' => '<?xml version="1.0"?><!DOCTYPE rss [<!ENTITY xxe SYSTEM "file://' . $wpTmp . '/tajne.txt">]>' . $wpHlava . '<item><title>&xxe;</title><content:encoded>&xxe;</content:encoded></item></channel></rss>',
    'miliarda smíchů' => '<?xml version="1.0"?><!DOCTYPE rss [<!ENTITY a "haha"><!ENTITY b "&a;&a;&a;&a;&a;&a;&a;&a;"><!ENTITY c "&b;&b;&b;&b;&b;&b;&b;&b;">]>' . $wpHlava . '<item><title>&c;</title></item></channel></rss>',
    'vnější DTD' => '<?xml version="1.0"?><!DOCTYPE rss SYSTEM "http://127.0.0.1:1/zly.dtd">' . $wpHlava . '</channel></rss>',
    'nedefinovaná entita' => '<?xml version="1.0"?>' . $wpHlava . '<item><title>&neexistuje;</title></item></channel></rss>',
    'jiné XML než export WordPressu' => '<?xml version="1.0"?><rss version="2.0"><channel><title>Obyčejné RSS</title><item><title>x</title></item></channel></rss>',
    'poškozené XML' => '<?xml version="1.0"?>' . $wpHlava . '<item><title>neuzavřeno</item>',
    'HTML místo XML' => '<html><body>xmlns:wp="http://wordpress.org/export/1.2/"</body></html>',
];
foreach ($wpSkodlive as $popis => $xml) {
    file_put_contents($wpTmp . '/zly.xml', $xml);
    $precteno = '';
    $odmitnuto = $wpOdmitne(function () use ($wpTmp, &$precteno): void {
        $zly = new Kaleta\Core\WpSoubor($wpTmp . '/zly.xml');
        $zly->over();
        $precteno = (string) json_encode([$zly->hlavicka(), iterator_to_array($zly->polozky())]);
    });
    over('WpSoubor odmítne: ' . $popis, [$odmitnuto, str_contains($precteno, 'TAJNY-OBSAH') || str_contains($precteno, 'hahahaha')], [true, false]);
}
file_put_contents($wpTmp . '/dobry.xml', '<?xml version="1.0"?>' . $wpHlava . '<item><title>A &amp; B</title></item></channel></rss>');
over('WpSoubor: běžné entity (&amp;) jsou v pořádku', iterator_to_array((new Kaleta\Core\WpSoubor($wpTmp . '/dobry.xml'))->polozky())[0]['titulek'], 'A & B');
exec('rm -rf ' . escapeshellarg($wpTmp));
foreach (['export.xml' => true, 'Můj web.WordPress.2026-09-21.XML' => true, '../config.xml' => false, 'slozka/export.xml' => false, '.skryty.xml' => false, 'export.php' => false, 'export.xml.php' => false, "export\0.xml" => false, '' => false] as $nazev => $ocekavano) {
    over('WpSoubor::platnyNazev ' . json_encode((string) $nazev), Kaleta\Core\WpSoubor::platnyNazev((string) $nazev), $ocekavano);
}
over('WpSoubor: název nahraného souboru bez diakritiky a vždy .xml', Kaleta\Core\WpSoubor::nazevProNahrani('Můj web.WordPress.2026-09-21.xml'), 'muj-web-wordpress-2026-09-21.xml');

/* ---------- import z WordPressu: čištění obsahu ---------- */
$wpCisti = Kaleta\Core\WpObsah::vycisti(...);
over('WpObsah: klasický editor – odstavce z prázdných řádků, <br> z konců řádků', $wpCisti("První řádek\ndruhý řádek\n\nDruhý odstavec"), "<p>První řádek<br>\ndruhý řádek</p>\n<p>Druhý odstavec</p>");
over('WpObsah: blokové značky se do <p> nebalí', $wpCisti("Úvod\n\n<h2>Titulek</h2>\n<ul>\n<li>a</li>\n<li>b</li>\n</ul>"), "<p>Úvod</p>\n<h2>Titulek</h2>\n<ul>\n<li>a</li>\n<li>b</li>\n</ul>");
over('WpObsah: komentáře Gutenbergu mizí, odstavce zůstávají', $wpCisti("<!-- wp:paragraph -->\n<p>Text</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:heading {\"level\":1} -->\n<h1 class=\"wp-block-heading\">Nadpis</h1>\n<!-- /wp:heading -->"), "<p>Text</p>\n<h2>Nadpis</h2>");
over('WpObsah: [caption] → figure s popiskem, odkaz na velký obrázek mizí', $wpCisti('[caption id="attachment_5" align="alignnone" width="300"]<a href="https://stary.example/wp-content/uploads/most.jpg"><img class="size-medium" src="https://stary.example/wp-content/uploads/most-300x200.jpg" alt="Most" width="300" height="200" /></a> Most přes řeku[/caption]'), '<figure><img src="https://stary.example/wp-content/uploads/most-300x200.jpg" alt="Most" width="300" height="200" loading="lazy"><figcaption>Most přes řeku</figcaption></figure>');
over('WpObsah: [gallery ids] → naše galerie jen ze známých obrázků', $wpCisti('[gallery ids="5,6,7,99" columns="2"]', [5 => 'https://stary.example/a.jpg', 6 => 'https://stary.example/b.png', 7 => 'https://stary.example/dokument.pdf']), '<figure class="galerie"><img src="https://stary.example/a.jpg" alt="" loading="lazy"><img src="https://stary.example/b.png" alt="" loading="lazy"></figure>');
over('WpObsah: blok galerie Gutenbergu → naše galerie', $wpCisti('<!-- wp:gallery {"linkTo":"none"} --><figure class="wp-block-gallery"><!-- wp:image {"id":5} --><figure class="wp-block-image"><img src="https://stary.example/a.jpg" alt="A" class="wp-image-5"/></figure><!-- /wp:image --></figure><!-- /wp:gallery -->'), '<figure class="galerie"><img src="https://stary.example/a.jpg" alt="A" loading="lazy"></figure>');
over('WpObsah: adresa YouTube na samostatném řádku je vlastní odstavec', $wpCisti("Text před\nhttps://www.youtube.com/watch?v=dQw4w9WgXcQ\nText po"), "<p>Text před</p>\n<p>https://www.youtube.com/watch?v=dQw4w9WgXcQ</p>\n<p>Text po</p>");
over('WpObsah: takový odstavec web promění v přehrávač', str_contains((new ReflectionClass(TextNovinky::class))->newInstanceWithoutConstructor()->vlozeneAdresy($wpCisti("https://www.youtube.com/watch?v=dQw4w9WgXcQ")), 'youtube-nocookie.com/embed/dQw4w9WgXcQ'), true);
over('WpObsah: blok embed → adresa v odstavci', $wpCisti('<!-- wp:embed {"url":"https://vimeo.com/76979871","type":"video"} --><figure class="wp-block-embed"><div class="wp-block-embed__wrapper">https://vimeo.com/76979871</div></figure><!-- /wp:embed -->'), '<p>https://vimeo.com/76979871</p>');
over('WpObsah: iframe YouTube → adresa, cizí iframe pryč', $wpCisti('<iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ" width="560"></iframe><iframe src="https://zly.example/"></iframe>'), '<p>https://www.youtube.com/watch?v=dQw4w9WgXcQ</p>');
over('WpObsah: zkratky doplňků mizí, jejich text a [sic] zůstávají', $wpCisti('[vc_row][vc_column width="1/2"]Text uvnitř[/vc_column][/vc_row] [contact-form-7 id="1"] citace [sic] a [[ukázka]]'), '<p>Text uvnitř  citace [sic] a [[ukázka]]</p>');
over('WpObsah: v ukázce kódu se závorky nemění', $wpCisti("<pre>pole[muj_klic] = 1;\n\nkonec</pre>"), "<pre>pole[muj_klic] = 1;\n\nkonec</pre>");
$wpNebezpecne = $wpCisti('<p onclick="x()" style="color:red">Klik <a href="java&#9;script:alert(1)" onmouseover="x()">odkaz</a> <a href="https://dobry.example/" target="_blank">ven</a></p><script>alert(1)</script><style>p{}</style><img src="data:image/svg+xml;base64,AAAA"><img src="https://stary.example/a.jpg" onerror="alert(1)" srcset="x 2x"><svg onload="alert(1)"><circle/></svg><form action="/x"><input name="a"></form><object data="x"></object><div class="obal"><span>Text v divu</span></div>');
over('WpObsah: skripty, styly, obsluhy událostí, javascript: a data: adresy neprojdou', $wpNebezpecne, "<p>Klik odkaz <a href=\"https://dobry.example/\" target=\"_blank\" rel=\"noopener\">ven</a></p>\n<figure><img src=\"https://stary.example/a.jpg\" alt=\"\" loading=\"lazy\"></figure>\n<p>Text v divu</p>");
over('WpObsah: po čištění nezbyde nic nebezpečného', (bool) preg_match('/<script|<style|<svg|<form|<iframe|<object|\son[a-z]+=|javascript:|data:|style=|srcset=/i', $wpNebezpecne), false);
over('WpObsah::bezpecnaAdresa', array_map(Kaleta\Core\WpObsah::bezpecnaAdresa(...), ['https://a.cz/', '/clanek/x', '#kotva', 'mailto:a@b.cz', "java\nscript:alert(1)", ' JAVASCRIPT:alert(1)', 'data:text/html,x', 'vbscript:x', '']), [true, true, true, true, false, false, false, false, false]);
over('WpObsah: perex z výtahu WordPressu, text celý', Kaleta\Core\WpObsah::perexAText('Ruční <b>výtah</b> &amp; spol.', "Odstavec jedna\n\nOdstavec dva"), ['<p>Ruční výtah &amp; spol.</p>', "<p>Odstavec jedna</p>\n<p>Odstavec dva</p>"]);
over('WpObsah: bez výtahu je perexem první odstavec a v textu se neopakuje', Kaleta\Core\WpObsah::perexAText('', "[caption]<img src=\"https://stary.example/a.jpg\" alt=\"\"> Popisek[/caption]\n\nOdstavec jedna\n\nOdstavec dva"), ['<p>Odstavec jedna</p>', "<figure><img src=\"https://stary.example/a.jpg\" alt=\"\" loading=\"lazy\"><figcaption>Popisek</figcaption></figure>\n<p>Odstavec dva</p>"]);
over('WpObsah: značka „Číst dál“ dělí perex a text', Kaleta\Core\WpObsah::perexAText('', "Před značkou\n<!--more-->\nZa značkou"), ['<p>Před značkou</p>', '<p>Za značkou</p>']);
over('WpObsah: cizí zkratky pro varování v náhledu', Kaleta\Core\WpObsah::ciziZkratky('[gallery ids="1"] [caption]x[/caption] [et_pb_section]a[/et_pb_section] [sic] <code>[muj_klic]</code>'), ['et_pb_section']);
[$wpUvod, $wpText] = Kaleta\Core\WpObsah::perexAText($wpPolozky[0]['perex'], $wpPolozky[0]['obsah'], $wpStav['prilohy']);
over('WpObsah: ukázkový příspěvek – perex, obrázek s popiskem, video, galerie, bez skriptu a zkratky', [str_starts_with($wpUvod, '<p>Po dvanácti měsících'), substr_count($wpText, '<figcaption>'), str_contains($wpText, '<p>https://www.youtube.com/watch?v=dQw4w9WgXcQ</p>'), substr_count($wpText, 'class="galerie"'), (bool) preg_match('/script|onclick|kontaktni-formular|javascript/i', $wpText)], [true, 1, true, 1, false]);

/* ---------- import z WordPressu: stav, datum, adresy ---------- */
$wpStavy = [];
foreach (['publish', 'future', 'draft', 'pending', 'private', 'trash', 'auto-draft', 'inherit', 'nesmysl'] as $wpS) {
    $wpM = Kaleta\Core\WpImport::stavClanku($wpS);
    $wpStavy[$wpS] = $wpM === null ? 'vynechat' : ($wpM['visible'] ? 'vydany' : 'koncept');
}
over('WpImport::stavClanku', $wpStavy, ['publish' => 'vydany', 'future' => 'vydany', 'draft' => 'koncept', 'pending' => 'koncept', 'private' => 'vynechat', 'trash' => 'vynechat', 'auto-draft' => 'vynechat', 'inherit' => 'vynechat', 'nesmysl' => 'vynechat']);
over('WpImport::stavClanku: příspěvek chráněný heslem se nezveřejní', Kaleta\Core\WpImport::stavClanku('publish', true), ['visible' => 0]);
over('WpImport::datum: místní čas starého webu', Kaleta\Core\WpImport::datum(['datum' => '2026-05-12 09:30:00', 'datum_gmt' => '2026-05-12 07:30:00']), '2026-05-12 09:30:00');
over('WpImport::datum: koncept s nulovým datem dostane dnešek', Kaleta\Core\WpImport::datum(['datum' => '0000-00-00 00:00:00', 'datum_gmt' => '0000-00-00 00:00:00', 'vydano' => ''], 1789000000), date('Y-m-d H:i:s', 1789000000));
$wpObsazene = ['lavka', 'lavka-2'];
over('WpImport::volnaAdresa: obsazená adresa dostane číslo', Kaleta\Core\WpImport::volnaAdresa('lavka', fn (string $a): bool => in_array($a, $wpObsazene, true)), 'lavka-3');
over('WpImport::volnaAdresa: volná zůstává', Kaleta\Core\WpImport::volnaAdresa('most', fn (string $a): bool => in_array($a, $wpObsazene, true)), 'most');
over('WpImport::staraCesta', array_map(Kaleta\Core\WpImport::staraCesta(...), ['https://stary.example/2026/05/lavka/', 'https://stary.example/?p=104', 'https://stary.example/blog/p%C5%99%C3%ADklad/', 'https://stary.example/' . str_repeat('x', 300)]), ['2026/05/lavka', '', 'blog/příklad', '']);
over('WpImport::bezRozmeru', array_map(Kaleta\Core\WpImport::bezRozmeru(...), ['https://s.example/u/foto-300x200.jpg', 'https://s.example/u/foto-1024x683.JPG?ver=2', 'https://s.example/u/foto.png', 'https://s.example/u/plan-2x4.pdf']), ['https://s.example/u/foto.jpg', 'https://s.example/u/foto.JPG', 'https://s.example/u/foto.png', 'https://s.example/u/plan-2x4.pdf']);
over('WpImport::zdroj: doména starého webu, nejvýš 40 znaků', [Kaleta\Core\WpImport::zdroj('https://WWW.Stary.example/blog'), Kaleta\Core\WpImport::zdroj(''), strlen(Kaleta\Core\WpImport::zdroj('https://' . str_repeat('a', 60) . '.example'))], ['wp:stary.example', 'wp', 40]);
over('ExportWebu::cesta: jen názvy exportů, nic mimo složku', [Kaleta\Core\ExportWebu::cesta('../config.php'), Kaleta\Core\ExportWebu::cesta('export-20260921-101500.zip/../../config.php'), Kaleta\Core\ExportWebu::cesta('kaleta-20260918-130917-rucni-7d777965.sql.gz')], [null, null, null]);

/* ---------- import z WordPressu: stahování obrázků jen ze starého webu a jen z veřejných adres (ochrana před SSRF) ---------- */
$wpStahovani = new Kaleta\Core\StahovaniObrazku('https://www.stary-web.example/blog/');
over('StahovaniObrazku: doména starého webu bez www', $wpStahovani->domena(), 'stary-web.example');
foreach ([
    'https://www.stary-web.example/wp-content/uploads/a.jpg' => true,
    'http://stary-web.example/a.png' => true,
    'https://STARY-WEB.example./a.png' => true,
    'https://stary-web.example:443/a.png' => true,
    'https://stary-web.example:8443/a.png' => false,                 // jiný port
    'http://stary-web.example:22/a.png' => false,
    'https://cdn.stary-web.example/a.png' => false,                  // jiná (pod)doména
    'https://stary-web.example.utocnik.example/a.png' => false,
    'https://utocnik.example/stary-web.example/a.png' => false,
    'https://stary-web.example@utocnik.example/a.png' => false,      // doména schovaná za jménem
    'https://uzivatel:heslo@stary-web.example/a.png' => false,       // přihlašovací údaje v adrese
    'ftp://stary-web.example/a.png' => false,
    'file:///etc/passwd' => false,
    'gopher://stary-web.example/' => false,
    '//stary-web.example/a.png' => false,
    'http://127.0.0.1/a.png' => false,
    'http://169.254.169.254/latest/meta-data/' => false,
    "https://stary-web.example/a.png\r\nHost: jinam" => false,       // vložené hlavičky
    'https://stary-web.example\\@utocnik.example/a.png' => false,
    '' => false,
] as $wpUrl => $ocekavano) {
    over('StahovaniObrazku::povolenaAdresa ' . json_encode((string) $wpUrl), $wpStahovani->povolenaAdresa((string) $wpUrl), $ocekavano);
}
over('StahovaniObrazku: bez adresy starého webu se nestahuje nic', (new Kaleta\Core\StahovaniObrazku(''))->povolenaAdresa('https://cokoli.example/a.png'), false);
foreach ([
    '93.184.216.34' => true, '8.8.8.8' => true, '172.32.0.1' => true, '100.128.0.1' => true, '2606:4700:4700::1111' => true, '::ffff:93.184.216.34' => true,
    '10.0.0.5' => false, '172.16.0.1' => false, '172.31.255.255' => false, '192.168.1.1' => false, '127.0.0.1' => false, '127.255.255.254' => false,
    '169.254.169.254' => false, '100.64.0.1' => false, '100.127.255.255' => false, '0.0.0.0' => false, '0.1.2.3' => false, '224.0.0.1' => false, '255.255.255.255' => false,
    '192.0.2.10' => false, '198.18.0.1' => false, '::1' => false, '::' => false, 'fc00::1' => false, 'fd12:3456::1' => false, 'fe80::1' => false, 'ff02::1' => false,
    '::ffff:10.0.0.1' => false, '::ffff:127.0.0.1' => false, '64:ff9b::a00:1' => false, '::10.0.0.1' => false, '2002:a00:1::1' => false, '2001:0:4136:e378:8000:63bf:3fff:fdd2' => false,
    '2001:4860:4860::8888' => true, '[::1]' => false, 'neni-ip' => false, '' => false,
] as $wpIp => $ocekavano) {
    over('StahovaniObrazku::verejnaIp ' . $wpIp, Kaleta\Core\StahovaniObrazku::verejnaIp((string) $wpIp), $ocekavano);
}
over('StahovaniObrazku: IP adresa místo domény se posuzuje stejně', [$wpStahovani->overenaIp('127.0.0.1'), $wpStahovani->overenaIp('[::1]'), $wpStahovani->overenaIp('93.184.216.34')], [null, null, '93.184.216.34']);
over('StahovaniObrazku: přesměrování na jinou doménu neprojde dalším kolem kontroly', $wpStahovani->povolenaAdresa(Kaleta\Core\StahovaniObrazku::cilPresmerovani('https://stary-web.example/a.png', 'https://utocnik.example/a.png')), false);
over('StahovaniObrazku: přesměrování //jinam a do vnitřní sítě neprojde', [$wpStahovani->povolenaAdresa(Kaleta\Core\StahovaniObrazku::cilPresmerovani('https://stary-web.example/a.png', '//utocnik.example/a.png')), $wpStahovani->povolenaAdresa(Kaleta\Core\StahovaniObrazku::cilPresmerovani('https://stary-web.example/a.png', 'http://169.254.169.254/'))], [false, false]);
over('StahovaniObrazku: relativní přesměrování zůstává na starém webu', [Kaleta\Core\StahovaniObrazku::cilPresmerovani('https://stary-web.example/u/a.png', '/jinde/b.png'), Kaleta\Core\StahovaniObrazku::cilPresmerovani('https://stary-web.example/u/a.png', 'b.png')], ['https://stary-web.example/jinde/b.png', 'https://stary-web.example/u/b.png']);
$wpPng = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
$wpSvg = '<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"><script>alert(1)</script></svg>';
over('StahovaniObrazku::typObrazku: PNG podle hlavičky i obsahu', Kaleta\Core\StahovaniObrazku::typObrazku('image/png; charset=binary', $wpPng), 'image/png');
over('StahovaniObrazku::typObrazku: hlavička tvrdí obrázek, obsah je HTML', Kaleta\Core\StahovaniObrazku::typObrazku('image/jpeg', '<html><body>přihlášení</body></html>'), null);
over('StahovaniObrazku::typObrazku: obsah je obrázek, hlavička ne', Kaleta\Core\StahovaniObrazku::typObrazku('text/html', $wpPng), null);
over('StahovaniObrazku::typObrazku: SVG se odmítá vždy', [Kaleta\Core\StahovaniObrazku::typObrazku('image/svg+xml', $wpSvg), Kaleta\Core\StahovaniObrazku::typObrazku('image/png', $wpSvg)], [null, null]);
over('StahovaniObrazku::typObrazku: prázdná odpověď', Kaleta\Core\StahovaniObrazku::typObrazku('image/png', ''), null);
over('StahovaniObrazku: limity podle zadání (15 MB, 3 přesměrování, 5 s spojení, 20 s celkem)', [Kaleta\Core\StahovaniObrazku::MAX_BAJTU, Kaleta\Core\StahovaniObrazku::MAX_PRESMEROVANI, Kaleta\Core\StahovaniObrazku::CAS_SPOJENI, Kaleta\Core\StahovaniObrazku::CAS_CELKEM], [15 * 1024 * 1024, 3, 5, 20]);
$wpZdrojak = (string) file_get_contents(KALETA_ROOT . '/system/src/Core/StahovaniObrazku.php');
over('StahovaniObrazku: přesměrování se nikdy nenásledují automaticky a nic se neposílá navíc', [substr_count($wpZdrojak, 'CURLOPT_FOLLOWLOCATION => false'), str_contains($wpZdrojak, "'follow_location' => 0"), (bool) preg_match('/CURLOPT_(COOKIE\w*|USERPWD|HTTPHEADER|HTTPAUTH)\b/', $wpZdrojak), str_contains($wpZdrojak, "'Kaleta-import'")], [1, true, false, true]);

/* ---------- builder: validátor, styl, design system, knihovna ---------- */
[$stS, $stChyby] = Kaleta\Stavitel\Stavba::vycisti(['deti' => [
    ['typ' => 'nadpis', 'id' => 'abc', 'obsah' => ['text' => '<script>x</script>Ahoj <b>světe</b>']],
    ['typ' => 'neznamy'],
    ['typ' => 'html', 'obsah' => ['kod' => '<p>a</p>']],
    ['typ' => 'tlacitko', 'obsah' => ['odkaz' => 'javascript:alert(1)']],
    ['typ' => 'nadpis', 'id' => 'abc', 'deti' => [['typ' => 'text']]],
]], false);
over('Stavba::vycisti: skript z nadpisu pryč, tučné zůstane', $stS['deti'][0]['obsah']['text'], 'Ahoj <b>světe</b>');
over('Stavba::vycisti: neznámý typ, cizí HTML a vnořené děti nadpisu vypadnou', array_map(fn (array $p): string => $p['typ'], $stS['deti']), ['nadpis', 'tlacitko', 'nadpis']);
over('Stavba::vycisti: javascript: odkaz se zahodí a nahlásí', [$stS['deti'][1]['obsah']['odkaz'], isset($stChyby['deti[3].obsah.odkaz'])], ['', true]);
over('Stavba::vycisti: duplicitní id dostane nové', $stS['deti'][2]['id'] !== 'abc', true);
over('Stavba::vycisti: chyby mají cestu', array_keys($stChyby), ['deti[1]', 'deti[2]', 'deti[3].obsah.odkaz', 'deti[4].deti']);
$stHtml = ['deti' => [['typ' => 'html', 'id' => 'h1x', 'obsah' => ['kod' => '<p onclick="x()">a</p><script>1</script><a href="javascript:x">b</a>']]]];
[$stSpravce] = Kaleta\Stavitel\Stavba::vycisti($stHtml, true);
over('Stavba::vycisti: vlastní HTML správce bez skriptů a obsluh', $stSpravce['deti'][0]['obsah']['kod'], '<p>a</p><a href="#">b</a>');
[$stEditor] = Kaleta\Stavitel\Stavba::vycisti(['deti' => [['typ' => 'html', 'id' => 'h1x', 'obsah' => ['kod' => '<p>podvrh</p>']]]], false, $stSpravce);
over('Stavba::vycisti: editor nezmění vlastní HTML správce, jen ho ponechá', $stEditor['deti'][0]['obsah']['kod'], '<p>a</p><a href="#">b</a>');
$stHluboka = ['typ' => 'text'];
for ($i = 0; $i < 20; $i++) {
    $stHluboka = ['typ' => 'kontejner', 'deti' => [$stHluboka]];
}
[, $stChyby] = Kaleta\Stavitel\Stavba::vycisti(['deti' => [$stHluboka]]);
over('Stavba::vycisti: hloubka je omezená', count($stChyby), 1);
[$stMnoho, $stChyby] = Kaleta\Stavitel\Stavba::vycisti(['deti' => array_fill(0, 900, ['typ' => 'oddelovac'])]);
over('Stavba::vycisti: počet prvků je omezený', [count($stMnoho['deti']), count($stChyby)], [Kaleta\Stavitel\Stavba::MAX_PRVKU, 1]);
over('Stavba::vycisti: obrázek jen z Médií nebo https', Kaleta\Stavitel\Stavba::vycisti(['deti' => [['typ' => 'obrazek', 'obsah' => ['src' => 'http://x.cz/a.jpg']], ['typ' => 'obrazek', 'obsah' => ['src' => 'media/2026/a.jpg']]]])[0]['deti'][1]['obsah']['src'], 'media/2026/a.jpg');
over('Stavba::zTextu: nadpis h1 a text', array_map(fn (array $p): string => $p['znacka'], Kaleta\Stavitel\Stavba::zTextu('O nás', '<p>x</p>')['deti'][0]['deti']), ['h1', 'div']);
$stStylChyby = [];
over('Styl::vycisti: vloženo CSS, neznámá vlastnost a stav vypadnou', Kaleta\Stavitel\Styl::vycisti(['zaklad' => ['barva' => 'red;}body{x:y', 'neznama' => '1', 'sirka' => '50%'], 'tisk' => []], 's', $stStylChyby), ['zaklad' => ['sirka' => '50%']]);
over('Styl::vycisti: chyby', array_keys($stStylChyby), ['s.zaklad.barva', 's.zaklad.neznama', 's.tisk']);
over('Styl::css: tokeny, sloupce, hover a breakpoint', Kaleta\Stavitel\Styl::css('#s-a', ['zaklad' => ['odsazeni_y' => 'xl', 'barva' => 'primarni', 'sloupce' => '3'], 'mobil' => ['sloupce' => '1'], 'hover' => ['barva' => '#ff0000']]),
    "#s-a { padding-block: var(--ka-mezera-xl); color: var(--ka-barva-primarni); grid-template-columns: repeat(3, minmax(0, 1fr)); }\n#s-a:is(:hover, :focus-visible) { color: #ff0000; }\n@media (max-width: 767px) { #s-a { grid-template-columns: repeat(1, minmax(0, 1fr)); } }\n");
over('Html::bezpecne: bez skriptů, obsluh událostí a javascript:, se strukturou a třídami', Kaleta\Core\Html::bezpecne('<p class="x" onclick="a()">A <a href="javascript:alert(1)">b</a><img src="x" onerror="alert(1)"><script>alert(1)</script></p><iframe src="https://x"></iframe><a href="/k" target="_blank" data-vlozit="javascript:x">k</a>'),
    '<p class="x">A <a>b</a><img src="x"></p><a href="/k" target="_blank" rel="noopener">k</a>');
over('Stavba: háčky skriptů webu nejdou vložit jako vlastní atribut', [preg_match(Kaleta\Stavitel\Stavba::VZOR_ATRIBUT, 'data-vlozit'), preg_match(Kaleta\Stavitel\Stavba::VZOR_ATRIBUT, 'data-samo'), preg_match(Kaleta\Stavitel\Stavba::VZOR_ATRIBUT, 'data-sledovat')], [0, 0, 1]);
over('Styl::css: najetí a stisk zvlášť pro tablet a mobil', Kaleta\Stavitel\Styl::css('#x', ['mobil' => ['mezera' => 's'], 'hover_mobil' => ['barva' => 'primarni'], 'aktivni_tablet' => ['meritko' => '0.95']]),
    "@media (max-width: 1023px) { #x:active { scale: 0.95; } }\n@media (max-width: 767px) { #x { gap: var(--ka-mezera-s); } #x:is(:hover, :focus-visible) { color: var(--ka-barva-primarni); } }\n");
over('Styl::css: typografický styl první, jednotlivé vlastnosti ho doladí', Kaleta\Stavitel\Styl::css('#x', ['zaklad' => ['velikost_pisma' => '3', 'typ_styl' => 'nadtitulek']]),
    "#x { font: var(--ka-typ-nadtitulek); text-transform: uppercase; letter-spacing: 0.08em; font-size: var(--ka-krok-3); }\n");
over('Styl: vlastní stín a rámeček s tokeny barev', [Kaleta\Stavitel\Styl::hodnota('stin', '0 8px 24px 0 primarni'), Kaleta\Stavitel\Styl::hodnota('stin', 'inset 0 1px 0 #ffffff33, 0 4px 12px rgb(0 0 0 / 0.1)'), Kaleta\Stavitel\Styl::hodnota('ramecek', '2px dashed primarni')],
    ['0 8px 24px 0 var(--ka-barva-primarni)', 'inset 0 1px 0 #ffffff33, 0 4px 12px rgb(0 0 0 / 0.1)', '2px dashed var(--ka-barva-primarni)']);
over('Styl: stín a rámeček nepustí nic nebezpečného', [Kaleta\Stavitel\Styl::hodnota('stin', '0 0 1px url(x)'), Kaleta\Stavitel\Styl::hodnota('stin', '0 0 red; color: red'), Kaleta\Stavitel\Styl::hodnota('ramecek', '1px solid red}')], [null, null, null]);
over('Styl: mřížka – řádky, oblasti a oblast prvku', [Kaleta\Stavitel\Styl::hodnota('radky', '3'), Kaleta\Stavitel\Styl::hodnota('oblasti', 'hlava hlava / bok obsah'), Kaleta\Stavitel\Styl::hodnota('oblasti', 'a b / c'), Kaleta\Stavitel\Styl::hodnota('oblast', 'bok'), Kaleta\Stavitel\Styl::hodnota('oblast', 'x"y')],
    ['repeat(3, auto)', '"hlava hlava" "bok obsah"', null, 'bok', null]);
over('DesignSystem: typografické styly jako tokeny, úprava ve Vzhledu', [str_contains(Kaleta\Stavitel\DesignSystem::css(Kaleta\Stavitel\DesignSystem::vycisti([])), '--ka-typ-perex: 400 var(--ka-krok-1)/1.55 var(--ka-pismo-text);'),
    str_contains(Kaleta\Stavitel\DesignSystem::css(Kaleta\Stavitel\DesignSystem::vycisti(['typografie' => ['perex' => ['krok' => '2', 'tloustka' => '500'], 'titulek' => ['krok' => '99']]])), '--ka-typ-perex: 500 var(--ka-krok-2)/1.55'),
    Kaleta\Stavitel\DesignSystem::vycisti(['typografie' => ['titulek' => ['krok' => '99']]])['typografie']], [true, true, []]);
over('Styl::css: obrázek pozadí z Médií od kořene instalace', str_contains(Kaleta\Stavitel\Styl::css('#s', ['zaklad' => ['obrazek_pozadi' => 'media/2026/09/a.jpg']], '', '/web'), 'url("/web/media/2026/09/a.jpg")'), true);
over('Kontejner jako odkaz: odkazy uvnitř se změní na span', Kaleta\Stavitel\Prvky\Kontejner::vykresli(['znacka' => 'div', 'obsah' => ['odkaz' => '/k']], '', '<p>x</p><a class="ka-tlacitko" href="/y" target="_blank">B</a><abbr>z</abbr>', new Kaleta\Stavitel\Kontext((new ReflectionClass(Kaleta\Core\App::class))->newInstanceWithoutConstructor())),
    '<a class="ka-karta-odkaz" href="/k"><p>x</p><span class="ka-tlacitko">B</span><abbr>z</abbr></a>');
over('Menu::vycisti: neznámý typ, nebezpečná adresa a třetí úroveň vypadnou', Kaleta\Core\Menu::vycisti([
    ['typ' => 'skript'], ['typ' => 'odkaz', 'text' => 'X', 'url' => 'javascript:alert(1)'],
    ['typ' => 'skupina', 'text' => 'Služby', 'deti' => [['typ' => 'stranka', 'ids' => 3, 'deti' => [['typ' => 'novinky']]], ['typ' => 'odkaz', 'text' => 'Ceník', 'url' => '/cenik', 'nove_okno' => 1]]],
]), [['typ' => 'skupina', 'text' => 'Služby', 'deti' => [['typ' => 'stranka', 'text' => '', 'ids' => 3], ['typ' => 'odkaz', 'text' => 'Ceník', 'url' => '/cenik', 'nove_okno' => true]]]]);
over('Menu::html: podmenu, aktivní položka a větev, úvod jen přesnou shodou', Kaleta\Core\Menu::html([
    ['text' => 'Úvod', 'url' => '/', 'nove_okno' => false, 'deti' => []],
    ['text' => 'Služby', 'url' => '', 'nove_okno' => false, 'deti' => [['text' => 'Kuchyně', 'url' => '/kuchyne', 'nove_okno' => false, 'deti' => []]]],
], '/kuchyne/detail', '/'), '<li><a href="/">Úvod</a></li><li class="podmenu aktivni"><button type="button" class="menu-skupina">Služby</button><ul><li><a href="/kuchyne" aria-current="page">Kuchyně</a></li></ul></li>');
over('Hledani::najdi: shoda v názvu má přednost', array_column(Kaleta\Core\Hledani::najdi('search', [
    ['titulek' => 'Menus', 'adresa' => 'menus', 'text' => 'Link to site search from the menu.'],
    ['titulek' => 'Site search', 'adresa' => 'site-search', 'text' => 'How search works.'],
    ['titulek' => 'SEO', 'adresa' => 'seo', 'text' => 'Search engines and search results; search console.'],
]), 'adresa'), ['site-search', 'seo', 'menus']);
over('Hledani::najdi: bez diakritiky, všechna slova, úryvek', Kaleta\Core\Hledani::najdi('zkusenosti kuchyne', [
    ['titulek' => 'O nás', 'adresa' => 'o-nas', 'text' => '<p>Máme dvacet let zkušeností s nábytkem.</p>'],
    ['titulek' => 'Kuchyně', 'adresa' => 'kuchyne', 'text' => '<p>Kuchyně na míru – bohaté zkušenosti.</p>'],
]), [['titulek' => 'Kuchyně', 'adresa' => 'kuchyne', 'uryvek' => 'Kuchyně na míru – bohaté zkušenosti.']]);
over('Styl::css: bílé pozadí si nese tmavý text i v tmavém režimu', str_contains(Kaleta\Stavitel\Styl::css('#s', ['zaklad' => ['pozadi' => 'bila']]), '--ka-barva-text: var(--ka-barva-text-svetle); color: var(--ka-barva-text-svetle)'), true);
over('Styl::css: vlastní barva textu na bílém pozadí se nepřepíše', str_contains(Kaleta\Stavitel\Styl::css('#s', ['zaklad' => ['pozadi' => 'bila', 'barva' => 'primarni']]), 'text-svetle'), false);
$stZahozeno = [];
over('Styl::vlastniCss: jen bezpečné deklarace', Kaleta\Stavitel\Styl::vlastniCss('color:red; background:url(javascript:x); --ka-x: 1; @import url(x); width: expression(1); a{b:c}', $stZahozeno), 'color: red; --ka-x: 1;');
over('Styl::vlastniCss: zahozené se hlásí', count($stZahozeno), 4);
over('DesignSystem::kontrast: černá na bílé', round(Kaleta\Stavitel\DesignSystem::kontrast('#ffffff', '#000000'), 1), 21.0);
over('DesignSystem::css: pořadí vrstev na začátku', str_starts_with(Kaleta\Stavitel\DesignSystem::css(Kaleta\Stavitel\DesignSystem::VYCHOZI), Kaleta\Stavitel\DesignSystem::VRSTVY), true);
over('DesignSystem::vycisti: nesmysl nahradí výchozí', Kaleta\Stavitel\DesignSystem::vycisti(['barvy' => ['primarni' => 'red;}']])['barvy']['primarni'], Kaleta\Stavitel\DesignSystem::VYCHOZI['barvy']['primarni']);
$stKnihovnaChyby = [];
// surové stavby (sekci() už čistí, neplatná hodnota by tak zmizela potichu)
foreach ((new ReflectionMethod(Kaleta\Stavitel\Knihovna::class, 'sekce'))->invoke(null) as $stKlic => $stSekce) {
    $stSekce['klic'] = $stKlic;
    [, $stChyby] = Kaleta\Stavitel\Stavba::vycisti(['deti' => [($stSekce['stavba'])()]]);
    $stKnihovnaChyby += array_map(fn (string $c): string => $stSekce['klic'] . ': ' . $c, $stChyby);
}
over('Knihovna: všechny hotové sekce projdou validátorem', $stKnihovnaChyby, []);
over('Stavba::schema: bez vlastního HTML pro ne-správce', in_array('html', array_column(Kaleta\Stavitel\Stavba::schema(false)['prvky'], 'typ'), true), false);

$zh = Kaleta\Stavitel\ZHtml::preved('<style>.hero { padding: 2rem; background: url(x) } .hero h1 { color: red } @media (max-width: 9px) { .hero { padding: 0 } }</style>'
    . '<header class="hero container-x"><div class="wrap"><h1>A <em>b</em></h1><p>Jedna.</p><p>Dvě.</p><a class="btn btn-outline" href="/k">K</a></div></header>'
    . '<p>Volný text</p><details><summary>Otázka?</summary><p>Odpověď.</p></details><form></form><svg></svg><script>x</script>');
$zhTypy = fn (array $deti): array => array_map(fn (array $p): string => $p['typ'] . '<' . $p['znacka'] . '>', $deti);
over('ZHtml: sekce z <header>, vnitřní obal bez stylu odpadne', $zhTypy($zh['stavba']['deti'][0]['deti']), ['nadpis<h1>', 'text<div>', 'tlacitko<a>']);
over('ZHtml: souvislé odstavce v jednom prvku Text', $zh['stavba']['deti'][0]['deti'][1]['obsah']['html'], '<p>Jedna.</p><p>Dvě.</p>');
over('ZHtml: tlačítko s variantou podle třídy', [$zh['stavba']['deti'][0]['deti'][2]['obsah']['varianta'], $zh['stavba']['deti'][0]['deti'][2]['tridy']], ['obrys', ['btn', 'btn-outline']]);
over('ZHtml: volné prvky na konci se zabalí do sekce, details → FAQ, form → Formulář', $zhTypy($zh['stavba']['deti'][1]['deti']), ['text<div>', 'faq<div>', 'formular<form>']);
over('ZHtml: třída z <style> jen s bezpečnými deklaracemi', $zh['tridy'], ['hero' => 'padding: 2rem;']);
over('ZHtml: hlášení o @media, složitém selektoru, url(), formuláři, SVG a skriptu', count($zh['hlaseni']), 6);
$zh2 = Kaleta\Stavitel\ZHtml::preved('<style>.mriz { display: grid; grid-template-columns: repeat(3, 1fr); gap: var(--ka-mezera-l) } .karta:hover { box-shadow: var(--ka-stin-m); transform: translateY(-4px) }'
    . ' @media (max-width: 1023px) { .mriz { grid-template-columns: repeat(2, 1fr) } } @media (max-width: 767px) { .mriz { grid-template-columns: 1fr; gap: var(--ka-mezera-m) } .karta { padding: var(--ka-mezera-m) var(--ka-mezera-s) } }'
    . ' @media (min-width: 768px) { .mriz { gap: 0 } }</style><section><div class="mriz"><div class="karta"><h3>A</h3></div><div>B</div></div></section>');
over('ZHtml: @media (max-width) a :hover jako stavy třídy', $zh2['tridy_styl'], ['mriz' => ['tablet' => ['sloupce' => '2'], 'mobil' => ['sloupce' => '1', 'mezera' => 'm']],
    'karta' => ['mobil' => ['odsazeni_y' => 'm', 'odsazeni_x' => 's'], 'hover' => ['stin' => 'm', 'posun' => '0 -4px']]]);
over('ZHtml: prvek se stylovanou třídou nemá výchozí styl (přebil by třídu), bez třídy ho má', [$zh2['stavba']['deti'][0]['deti'][0]['styl'], $zh2['stavba']['deti'][0]['deti'][0]['deti'][1]['styl']['zaklad']['zobrazeni'] ?? null], [[], 'flex']);
over('ZHtml: mobile-first @media (min-width) se nahlásí', count(array_filter($zh2['hlaseni'], fn (string $h): bool => str_contains($h, 'min-width'))), 1);
over('Styl::zCss: tokeny, zkratky a mřížka', [Kaleta\Stavitel\Styl::zCss('padding', 'var(--ka-mezera-l) 2rem'), Kaleta\Stavitel\Styl::zCss('margin', '0 auto'), Kaleta\Stavitel\Styl::zCss('grid-template-columns', 'repeat(auto-fit, minmax(16rem, 1fr))'),
    Kaleta\Stavitel\Styl::zCss('color', 'var(--ka-barva-tlumeny)'), Kaleta\Stavitel\Styl::zCss('font-size', 'var(--ka-krok--1)'), Kaleta\Stavitel\Styl::zCss('color', 'expression(1)'), Kaleta\Stavitel\Styl::zCss('filter', 'blur(2px)')],
    [['odsazeni_y' => 'l', 'odsazeni_x' => '2rem'], ['okraj_nahore' => '0', 'okraj_dole' => '0', 'na_stred' => 'auto'], ['sloupce' => 'auto:16rem'], ['barva' => 'tlumeny'], ['velikost_pisma' => '-1'], null, null]);

$upStavba = ['v' => 1, 'deti' => [['id' => 'sek1', 'typ' => 'sekce', 'deti' => [['id' => 'nad1', 'typ' => 'nadpis', 'obsah' => ['text' => 'A'], 'styl' => ['zaklad' => ['barva' => 'primarni']]], ['id' => 'tl1', 'typ' => 'tlacitko', 'obsah' => ['text' => 'B', 'odkaz' => '/docs']]]], ['id' => 'sek2', 'typ' => 'sekce']]];
$upChyby = [];
$up = Kaleta\Stavitel\Upravy::proved($upStavba, [
    ['op' => 'uprav', 'id' => 'tl1', 'obsah' => ['odkaz' => '/guide']],
    ['op' => 'uprav', 'id' => 'nad1', 'styl' => ['zaklad' => ['barva' => null], 'mobil' => ['zarovnani_textu' => 'center']], 'tridy' => ['nadpis-sekce']],
    ['op' => 'vloz', 'do' => 'sek2', 'prvky' => [['id' => 'txt1', 'typ' => 'text']]],
    ['op' => 'presun', 'id' => 'tl1', 'za' => 'txt1'],
    ['op' => 'vloz', 'pozice' => 0, 'prvek' => ['id' => 'sek0', 'typ' => 'sekce']],
    ['op' => 'smaz', 'id' => 'neni'],
    ['op' => 'presun', 'id' => 'sek2', 'do' => 'txt1'],
    ['op' => 'kouzlo'],
], $upChyby);
over('Upravy: úprava obsahu a stylu (null odebere), vložení, přesun, vložení na začátek', [array_column($up['deti'], 'id'), $up['deti'][1]['deti'][0]['styl'], $up['deti'][1]['deti'][0]['tridy'], array_column($up['deti'][2]['deti'], 'id'), $up['deti'][2]['deti'][1]['obsah']['odkaz']],
    [['sek0', 'sek1', 'sek2'], ['mobil' => ['zarovnani_textu' => 'center']], ['nadpis-sekce'], ['txt1', 'tl1'], '/guide']);
over('Upravy: chybné operace se nahlásí a přeskočí (i přesun do potomka)', array_keys($upChyby), ['op[5]', 'op[6]', 'op[7]']);
$upChyby2 = [];
over('Upravy: přesun prvku do jeho potomka nejde', Kaleta\Stavitel\Upravy::proved($upStavba, [['op' => 'presun', 'id' => 'sek1', 'do' => 'nad1']], $upChyby2) === $upStavba && isset($upChyby2['op[0]']), true);

[$kmStavba] = Kaleta\Stavitel\Stavba::vycisti(['v' => 1, 'deti' => [['typ' => 'sekce', 'id' => 'abc', 'deti' => [['typ' => 'tlacitko', 'id' => 'def', 'obsah' => ['text' => 'Jdi']], ['typ' => 'kontejner', 'id' => 'ghi', 'styl' => ['zaklad' => ['zobrazeni' => 'flex', 'smer' => 'column', 'mezera' => 'm']]]]]]]);
$km = Kaleta\Stavitel\Stavba::kompaktni($kmStavba);
over('Stavba::kompaktni: bez výchozích hodnot, styl zůstane', $km, ['v' => 1, 'deti' => [['id' => 'abc', 'typ' => 'sekce', 'deti' => [['id' => 'def', 'typ' => 'tlacitko', 'obsah' => ['text' => 'Jdi']], ['id' => 'ghi', 'typ' => 'kontejner', 'styl' => ['zaklad' => ['zobrazeni' => 'flex', 'smer' => 'column', 'mezera' => 'm']]]]]]]);
over('Stavba: zvýraznění <mark> v nadpisu zůstane, třídy a styly ne', Kaleta\Stavitel\Stavba::vycisti(['v' => 1, 'deti' => [['typ' => 'nadpis', 'id' => 'mk1', 'obsah' => ['text' => 'Publish<mark class="x" style="color:red">.</mark>']]]])[0]['deti'][0]['obsah']['text'], 'Publish<mark>.</mark>');
$tlKontext = null;
over('Styl::zCss: aliasy margin-top a flex-start', [Kaleta\Stavitel\Styl::zCss('margin-bottom', '24px'), Kaleta\Stavitel\Styl::zCss('align-items', 'flex-start')], [['okraj_dole' => '24px'], ['zarovnani' => 'start']]);
over('Styl::zCss: text-align left/right', [Kaleta\Stavitel\Styl::zCss('text-align', 'left'), Kaleta\Stavitel\Styl::zCss('text-align', 'right')], [['zarovnani_textu' => 'start'], ['zarovnani_textu' => 'end']]);
over('Ikony: GitHub v sadě', str_contains(Kaleta\Stavitel\Ikony::svg('github'), 'M9 19c-4'), true);
over('Tlačítko: ikona za textem a vlevo od textu', [
    (bool) preg_match('#>Start<svg#', Kaleta\Stavitel\Prvky\Tlacitko::vykresli(['obsah' => ['text' => 'Start', 'odkaz' => '/x', 'varianta' => 'primarni', 'nove_okno' => false, 'ikona' => 'sipka', 'ikona_vlevo' => false]], '', '', (new ReflectionClass(Kaleta\Stavitel\Kontext::class))->newInstanceWithoutConstructor())),
    (bool) preg_match('#</svg>GitHub</a>#', Kaleta\Stavitel\Prvky\Tlacitko::vykresli(['obsah' => ['text' => 'GitHub', 'odkaz' => '/x', 'varianta' => 'obrys', 'nove_okno' => false, 'ikona' => 'github', 'ikona_vlevo' => true]], '', '', (new ReflectionClass(Kaleta\Stavitel\Kontext::class))->newInstanceWithoutConstructor())),
    Kaleta\Stavitel\Prvky\Tlacitko::vykresli(['obsah' => ['text' => 'Bez', 'odkaz' => '/x', 'varianta' => 'primarni', 'nove_okno' => false]], '', '', (new ReflectionClass(Kaleta\Stavitel\Kontext::class))->newInstanceWithoutConstructor()) === '<a class="ka-tlacitko ka-tlacitko--primarni" href="/x">Bez</a>',
], [true, true, true]);
over('Stavba::kompaktni: po vyčištění stejná stavba', Kaleta\Stavitel\Stavba::vycisti($km)[0], $kmStavba);
$prehled = Kaleta\Stavitel\Stavba::prehled(Kaleta\Stavitel\Stavba::schema());
over('Stavba::prehled: prvek na řádek, výchozí možnost s hvězdičkou, schéma výrazně menší', [str_contains($prehled['prvky']['tlacitko'], 'varianta:vyber(primarni*|'), strlen((string) json_encode($prehled)) < strlen((string) json_encode(Kaleta\Stavitel\Stavba::schema())) / 2],
    [true, true]);
over('ZHtml: výsledek projde validátorem bez chyb', Kaleta\Stavitel\Stavba::vycisti($zh['stavba'])[1], []);
over('Stavba::jakoText: sémantický obsah bez rozložení', Kaleta\Stavitel\Stavba::jakoText($zh['stavba']), "<h1>A <em>b</em></h1>\n<p>Jedna.</p><p>Dvě.</p>\n<p><a href=\"/k\">K</a></p>\n<p>Volný text</p>\n<h3>Otázka?</h3><p>Odpověď.</p>");

// vypnutá rozšíření: prvky ani sekce s nimi builder nenabízí
$typySchematu = array_column(Kaleta\Stavitel\Stavba::schema(true, 'cs', false, ['statistika'])['prvky'], 'typ');
over('Rozšíření: bez novinek a poptávek schéma nemá jejich prvky', [in_array('novinky', $typySchematu, true), in_array('formular', $typySchematu, true), in_array('nadpis', $typySchematu, true)], [false, false, true]);
$klicKnihovny = array_column(Kaleta\Stavitel\Knihovna::seznam(['statistika']), 'klic');
over('Rozšíření: knihovna bez sekcí s formulářem a novinkami', [in_array('kontakt-formular', $klicKnihovny, true), in_array('novinky', $klicKnihovny, true), in_array('uvod', $klicKnihovny, true)], [false, false, true]);
over('Rozšíření: bez omezení je knihovna celá', count(Kaleta\Stavitel\Knihovna::seznam()) > count($klicKnihovny), true);
$knEn = Kaleta\Stavitel\Knihovna::sekci('uvod', 'en')['prvek'];
over('Knihovna: sekce v angličtině včetně odkazů na stránky', [$knEn['deti'][0]['obsah']['text'], $knEn['deti'][2]['deti'][0]['obsah']['odkaz'], $knEn['deti'][2]['deti'][1]['obsah']['odkaz']], ['We help businesses grow – quickly and hassle-free', '/contact', '/services']);
over('Knihovna: česky se odkazuje na české adresy', Kaleta\Stavitel\Knihovna::sekci('uvod')['prvek']['deti'][2]['deti'][0]['obsah']['odkaz'], '/kontakt');
over('Knihovna: jazyk se po sestavení sekce vrátí', Kaleta\Core\Jazyk::kod(), 'cs');
$knSchema = array_column(Kaleta\Stavitel\Stavba::schema(true, 'en')['prvky'], 'vlastnosti', 'typ');
over('Stavba::schema: výchozí obsah prvků v jazyce stránky', [$knSchema['nadpis']['text']['vychozi'], $knSchema['tlacitko']['text']['vychozi']], ['Heading', 'Contact us']);
$knEnSlovnik = require KALETA_ROOT . '/system/jazyky/en.php';
preg_match_all("/\bt\('((?:[^'\\\\]|\\\\.)*)'\)/", file_get_contents(KALETA_ROOT . '/system/src/Stavitel/Knihovna.php') . implode('', array_map('file_get_contents', glob(KALETA_ROOT . '/system/src/Stavitel/Prvky/*.php'))), $knTexty);
over('Knihovna a prvky: všechny ukázkové texty mají anglický překlad', array_values(array_diff(array_unique($knTexty[1]), array_keys($knEnSlovnik), ['Menu', 'Standard', 'Video'])), []);

over('Firma::hodiny: rozsah dnů, víc úseků, zavřeno', Kaleta\Front\Firma::hodiny("Po–Pá 8:00–17:00\nÚt 8-12, 13-17\nNe zavřeno"), [
    ['dny' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'], 'od' => '08:00', 'do' => '17:00'],
    ['dny' => ['Tuesday'], 'od' => '08:00', 'do' => '12:00'], ['dny' => ['Tuesday'], 'od' => '13:00', 'do' => '17:00'],
]);
over('Firma::hodiny: nesrozumitelný řádek se odmítne', [Kaleta\Front\Firma::hodiny('každý den 8-17'), Kaleta\Front\Firma::hodiny('Po 8-25')], [null, null]);
over('Firma: typy v Nastavení odpovídají Firma::TYPY', (new ReflectionClassConstant(Kaleta\Admin\Moduly\Konfigurace::class, 'TYPY_FIRMY'))->getValue(), implode('|', array_keys(Kaleta\Front\Firma::TYPY)));

/* ---------- kolekce ---------- */
$koPole = Kaleta\Stavitel\Kolekce::vycistiPole([['popisek' => 'Citát zákazníka', 'typ' => 'radky'], ['popisek' => 'Název', 'typ' => 'text'], ['popisek' => 'Logo', 'typ' => 'nesmysl'], ['popisek' => '']]);
over('Kolekce::vycistiPole: klíč z popisku, vestavěný název se nepřepíše, neznámý typ = text', array_map(fn (array $p): string => $p['klic'] . ':' . $p['typ'], $koPole), ['citat_zakaznika:radky', 'nazev_2:text', 'logo:text']);
$koChyby = [];
$koData = Kaleta\Stavitel\Kolekce::vycistiData([['klic' => 'web', 'popisek' => 'Web', 'typ' => 'odkaz'], ['klic' => 'foto', 'popisek' => 'Foto', 'typ' => 'obrazek'], ['klic' => 'cena', 'popisek' => 'Cena', 'typ' => 'cislo'], ['klic' => 'bio', 'popisek' => 'Bio', 'typ' => 'html']],
    ['web' => 'javascript:alert(1)', 'foto' => 'media/2026/a.jpg', 'cena' => '1 200', 'bio' => '<p onclick="x">Ahoj</p><script>1</script>'], $koChyby);
over('Kolekce::vycistiData: nebezpečný odkaz pryč, obrázek z médií, číslo bez mezer, HTML vyčištěné', [$koData['web'], $koData['foto'], $koData['cena'], $koData['bio'], array_keys($koChyby)], ['', 'media/2026/a.jpg', '1200', '<p>Ahoj</p>', ['web']]);
$koHodnoty = ['nazev' => ['Jan <b>Novák</b>', 'text'], 'bio' => ['<p>Truhlář</p>', 'html'], 'poznamka' => ["řádek 1\nřádek 2", 'radky'], 'url' => ['/tym/jan', 'odkaz'], 'zly' => ['javascript:x', 'odkaz']];
over('Kolekce::dosad: značky v dosazené hodnotě se znovu nedosazují', Kaleta\Stavitel\Kolekce::dosad('<p>{{text}}</p><p>{{nazev}}</p>', 'html', ['text' => ['<p>Napište {{nazev}} nebo {{url}}.</p>', 'html'], 'nazev' => ['Návod', 'text'], 'url' => ['/navod', 'text']]), '<p>Napište {{nazev}} nebo {{url}}.</p><p>Návod</p>');
over('Kolekce::dosad: jeden průchod i v řádkovém textu', Kaleta\Stavitel\Kolekce::dosad('{{popis}} – {{nazev}}', 'inline', ['popis' => ['Viz {{nazev}}', 'radky'], 'nazev' => ['X', 'text']]), 'Viz {{nazev}} – X');
over('Kolekce::dosad: text se escapuje až prvkem, inline a html hned, html pole zůstane HTML', [
    Kaleta\Stavitel\Kolekce::dosad('{{nazev}}', 'text', $koHodnoty), Kaleta\Stavitel\Kolekce::dosad('Tým: {{nazev}}', 'inline', $koHodnoty),
    Kaleta\Stavitel\Kolekce::dosad('{{bio}}', 'html', $koHodnoty), Kaleta\Stavitel\Kolekce::dosad('<p>{{poznamka}}</p>', 'html', $koHodnoty),
    Kaleta\Stavitel\Kolekce::dosad('{{url}}', 'odkaz', $koHodnoty), Kaleta\Stavitel\Kolekce::dosad('{{zly}}', 'odkaz', $koHodnoty), Kaleta\Stavitel\Kolekce::dosad('{{neni}}', 'inline', $koHodnoty),
], ['Jan <b>Novák</b>', 'Tým: Jan &lt;b&gt;Novák&lt;/b&gt;', '<p>Truhlář</p>', '<p>řádek 1<br>' . "\n" . 'řádek 2</p>', '/tym/jan', '', '']);
[$koStavba, $koChyby] = Kaleta\Stavitel\Stavba::vycisti(['deti' => [['typ' => 'kolekce', 'obsah' => ['kolekce' => 'tym'], 'deti' => [['typ' => 'obrazek', 'obsah' => ['src' => '{{foto}}']], ['typ' => 'tlacitko', 'obsah' => ['odkaz' => '{{url}}']]]]]]);
over('Stavba::vycisti: značky {{pole}} v obrázku a odkazu projdou', [$koStavba['deti'][0]['deti'][0]['obsah']['src'], $koStavba['deti'][0]['deti'][1]['obsah']['odkaz'], $koChyby], ['{{foto}}', '{{url}}', []]);

/* ---------- angličtina builderu: texty editoru (JS) a popisky schématu (PHP) ---------- */
preg_match_all("/\bT\('((?:[^'\\\\]|\\\\.)*)'\)/", (string) file_get_contents(KALETA_ROOT . '/image/stavitel.js'), $enJs);
preg_match('/window\.KALETA_PREKLAD = (\{.*\});/s', (string) file_get_contents(KALETA_ROOT . '/image/jazyky/admin-en.js'), $enJsSlovnik);
$enJsKlice = array_keys((array) json_decode((string) preg_replace(['#^\s*//.*$#m', '/,\s*\}$/'], ['', '}'], $enJsSlovnik[1] ?? '{}'), true));
over('Builder: všechny texty editoru mají anglický překlad', array_values(array_diff(array_unique(array_map('stripslashes', $enJs[1])), $enJsKlice, ['Tablet', 'Menu'])), []);
$enAdmin = require KALETA_ROOT . '/system/jazyky/admin-en.php';
$enSchema = Kaleta\Stavitel\Stavba::schema(true, 'cs', true);
$enTexty = array_merge(array_column($enSchema['prvky'], 'nazev'), array_column($enSchema['prvky'], 'popis'), array_column($enSchema['prvky'], 'skupina'), array_values($enSchema['skupiny_stylu']));
$enPole = function (array $vlastnosti) use (&$enPole, &$enTexty): void {
    foreach ($vlastnosti as $d) {
        $enTexty[] = (string) ($d['popisek'] ?? '');
        array_push($enTexty, ...array_values($d['moznosti'] ?? []));
        if (isset($d['pole'])) {
            $enPole($d['pole']);
        }
    }
};
foreach ($enSchema['prvky'] as $p) {
    $enPole($p['vlastnosti']);
}
$enPole($enSchema['styl']);
over('Builder: všechny popisky schématu mají anglický překlad', array_values(array_filter(array_unique($enTexty), fn (string $x): bool => $x !== '' && preg_match('/\p{L}/u', $x) === 1 && !isset($enAdmin[$x]) && !in_array($x, ['Video', 'Logo', 'HTML', 'Text', 'text'], true))), []);

$webyChyby = [];
$webySekce = array_column(Kaleta\Stavitel\Knihovna::seznam(), 'klic');
foreach (Kaleta\Stavitel\Knihovna::WEBY as $webKlic => $web) {
    if (!isset(Kaleta\Stavitel\DesignSystem::PREDVOLBY[$web['predvolba']])) {
        $webyChyby[] = $webKlic . ': předvolba ' . $web['predvolba'];
    }
    foreach ($web['stranky'] as $sekceStranky) {
        foreach (array_diff($sekceStranky, $webySekce) as $chybi) {
            $webyChyby[] = $webKlic . ': sekce ' . $chybi;
        }
    }
}
over('Knihovna::WEBY: předvolby a sekce ukázkových webů existují', $webyChyby, []);

// stránky ukázkových webů projdou kontrolou před publikováním z builderu (image/stavitel.js, kontrola()): tlačítka s odkazem,
// žádný prázdný obrázek, jediný h1 a osnova bez přeskočené úrovně – česky i anglicky, se všemi rozšířeními i bez nich
$kontrolaStranky = function (array $stavba): array {
    $nalezy = [];
    $nadpisy = [];
    $projdi = function (array $deti) use (&$projdi, &$nalezy, &$nadpisy): void {
        foreach ($deti as $p) {
            $o = $p['obsah'] ?? [];
            if ($p['typ'] === 'tlacitko' && in_array($o['odkaz'] ?? '', ['', '#'], true)) {
                $nalezy[] = 'tlačítko bez odkazu: ' . ($o['text'] ?? '');
            }
            if ($p['typ'] === 'obrazek' && (($o['src'] ?? '') === '' || ($o['alt'] ?? '') === '')) {
                $nalezy[] = 'obrázek bez souboru nebo popisu';
            }
            if ($p['typ'] === 'nadpis' && preg_match('/^h([1-6])$/', $p['znacka'] ?? 'h2', $m)) {
                $nadpisy[] = (int) $m[1];
            }
            $projdi($p['deti'] ?? []);
        }
    };
    $projdi($stavba['deti']);
    if (count(array_keys($nadpisy, 1)) !== 1) {
        $nalezy[] = count(array_keys($nadpisy, 1)) . '× h1';
    }
    foreach ($nadpisy as $i => $u) {
        if ($i > 0 && $u > $nadpisy[$i - 1] + 1) {
            $nalezy[] = 'h' . $nadpisy[$i - 1] . ' → h' . $u;
        }
    }

    return $nalezy;
};
$webyKontrola = [];
foreach (Kaleta\Stavitel\Knihovna::WEBY as $webKlic => $web) {
    foreach (['cs', 'en'] as $jazyk) {
        foreach (['všechna rozšíření' => array_keys(Kaleta\Core\Rozsireni::SEZNAM), 'bez rozšíření' => []] as $varianta => $zapnuta) {
            foreach ($web['stranky'] as $i => $sekceStranky) {
                if ($sekceStranky === []) {
                    continue; // textová stránka: nadpis h1 dává šablona
                }
                [$stavba] = Kaleta\Stavitel\Knihovna::sestav($sekceStranky, 'Stránka', $jazyk, Kaleta\Stavitel\Stavba::vypnuteTypy($zapnuta), true);
                foreach ($kontrolaStranky($stavba) as $nalez) {
                    $webyKontrola[] = "$webKlic/$jazyk/$varianta/stránka $i: $nalez";
                }
            }
        }
    }
}
over('Knihovna::WEBY: stránky ukázkových webů projdou kontrolou před publikováním', array_values(array_unique($webyKontrola)), []);
$kontaktBezFormulare = Kaleta\Stavitel\Knihovna::sestav(Kaleta\Stavitel\Knihovna::WEBY['remeslo']['stranky'][3], 'Kontakt', 'cs', Kaleta\Stavitel\Stavba::vypnuteTypy([]), true)[0];
over('Knihovna: kontakt bez rozšíření Formuláře má údaje firmy', str_contains((string) json_encode($kontaktBezFormulare), '"udaj":"adresa"'), true);

/* ---------- AI asistent: poskytovatelé a builder ---------- */
$aiTelo = ['model' => 'm1', 'max_tokens' => 50, 'system' => 'S', 'messages' => [['role' => 'user', 'content' => [['type' => 'image', 'source' => ['media_type' => 'image/png', 'data' => 'QQ==']], ['type' => 'text', 'text' => 'Ahoj']]]]];
over('Asistent::naOpenAi: systém, obrázek jako data URL, limit tokenů podle poskytovatele', [Asistent::naOpenAi($aiTelo, 'openai'), array_keys(Asistent::naOpenAi($aiTelo, 'mistral'))], [
    ['model' => 'm1', 'messages' => [['role' => 'system', 'content' => 'S'], ['role' => 'user', 'content' => [['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,QQ==']], ['type' => 'text', 'text' => 'Ahoj']]]], 'max_completion_tokens' => 50],
    ['model', 'messages', 'max_tokens'],
]);
over('Asistent::zOpenAi: odpověď do tvaru Claude API', Asistent::zOpenAi(['choices' => [['message' => ['content' => 'Text'], 'finish_reason' => 'length']]]), ['content' => [['type' => 'text', 'text' => 'Text']], 'stop_reason' => 'max_tokens']);
$aiNastaveni = (new ReflectionClass(Kaleta\Core\Settings::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(Kaleta\Core\Settings::class, 'values'))->setValue($aiNastaveni, ['nazev_webu' => 'Test', 'ai_klic' => 'x', 'ai_poskytovatel' => 'anthropic', 'ai_model' => 'claude-sonnet-5']);
$aiFalesny = new class($aiNastaveni) extends Asistent {
    public string $odpoved = '';
    public array $posledni = [];

    protected function zavolej(array $telo): array
    {
        $this->posledni = $telo;

        return ['content' => [['type' => 'text', 'text' => $this->odpoved]]];
    }
};
$aiFalesny->odpoved = "Tady je sekce:\n```html\n<section class=\"sluzby-ai\"><h2>Služby</h2><p>Text <script>x</script></p><a class=\"btn\" href=\"javascript:alert(1)\">Klik</a></section><style>.sluzby-ai { padding: var(--ka-mezera-l); }</style>\n```";
$aiHtml = $aiFalesny->navrhniSekci('Tři karty se službami a odkazem na kontakt.', 'cs', 'Služby');
$aiPrevod = Kaleta\Stavitel\ZHtml::preved($aiHtml);
[$aiStavba] = Kaleta\Stavitel\Stavba::vycisti($aiPrevod['stavba'], false);
over('Asistent::navrhniSekci: HTML z bloku ```html, zadání uvnitř <zadani>, výsledek bez skriptu a javascript: odkazu', [
    str_starts_with($aiHtml, '<section'), str_contains((string) $aiFalesny->posledni['messages'][0]['content'], '<zadani>'), str_contains(json_encode($aiStavba), 'script'), str_contains(json_encode($aiStavba), 'javascript'), $aiPrevod['tridy'],
], [true, true, false, false, ['sluzby-ai' => 'padding: var(--ka-mezera-l);']]);
$aiFalesny->odpoved = '<p>Kratší <strong>text</strong> <img src=x onerror=alert(1)></p>';
over('Asistent::prepis: HTML odpověď vyčištěná, prostý text bez značek', [$aiFalesny->prepis('<p>Dlouhý text k přepsání.</p>', 'kratsi', true), $aiFalesny->prepis('Nadpis', 'formalne', false)], ['<p>Kratší <strong>text</strong> </p>', 'Kratší text']);
(new ReflectionProperty(Kaleta\Core\Settings::class, 'values'))->setValue($aiNastaveni, ['nazev_webu' => 'Test', 'ai_klic' => 'x', 'ai_poskytovatel' => 'openai', 'ai_model' => 'claude-sonnet-5']);
try {
    $aiFalesny->prepis('Text', 'kratsi', false);
    $aiChyba = '';
} catch (RuntimeException $e) {
    $aiChyba = $e->getMessage();
}
over('Asistent: u jiného poskytovatele než Claude je potřeba zadat jeho model', str_contains($aiChyba, 'Zadejte název modelu'), true);

echo $chyb === 0 ? "  ok     jednotkové testy ({$celkem})\n" : "  NALEZENO CHYB: {$chyb} z {$celkem}\n";
exit($chyb === 0 ? 0 : 1);
