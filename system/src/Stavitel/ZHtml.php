<?php

declare(strict_types=1);

namespace MiroCMS\Stavitel;

use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;

/**
 * Převod HTML na stavbu: jazykový model (nebo import) napíše běžné sémantické HTML s blokem <style> a z něj vznikne
 * čistá stavba – jeden prvek za jednu značku, vzhled ve sdílených třídách; <form> se stane prvkem Formulář. Co převést
 * nejde (skripty, vložené styly, složité selektory), se vynechá a nahlásí, aby autor věděl, co doplnit ve staviteli.
 *
 * Třída je čistá (bez databáze): vrací stavbu, třídy z <style> a hlášení. Ukládání a kontrolu práv dělá volající.
 */
final class ZHtml
{
    /** Značky, které se převádějí na obsah prvku Text (souvislý tok textu se slučuje do jednoho prvku). */
    private const array TEXTOVE = ['p', 'ul', 'ol', 'table', 'pre', 'dl', 'address'];

    /** Obalové značky bez vlastního významu pro stavbu – převádí se jen jejich obsah. */
    private const array ROZBALIT = ['html', 'body', 'main'];

    /** Značky, které nemají ve stavbě obdobu a vynechají se vždy. */
    private const array VYNECHAT = ['script', 'noscript', 'style', 'link', 'meta', 'template', 'input', 'select', 'textarea', 'button', 'label', 'canvas', 'object', 'embed'];

    /** @var list<string> */
    private array $hlaseni = [];

    /** @var array<string, string> třída => bezpečné deklarace */
    private array $tridy = [];

    private function __construct(private readonly bool $spravce)
    {
    }

    /**
     * @param bool $spravce smí vzniknout prvek Vlastní HTML (pro SVG a vložené mapy)
     * @return array{stavba: array<string, mixed>, tridy: array<string, string>, hlaseni: list<string>}
     */
    public static function preved(string $html, bool $spravce = false): array
    {
        $prevod = new self($spravce);
        $dokument = HTMLDocument::createFromString('<!doctype html><html><body>' . $html . '</body></html>', LIBXML_NOERROR);
        foreach ($dokument->querySelectorAll('style') as $styl) {
            $prevod->styly($styl->textContent);
        }
        $prvky = $prevod->deti($dokument->body);

        // nejvyšší úroveň stavby tvoří sekce: souvislé řady jiných prvků se zabalí do jedné sekce
        $koren = [];
        $rada = [];
        foreach ($prvky as $p) {
            if ($p['typ'] === 'sekce') {
                if ($rada !== []) {
                    $koren[] = Stavba::novy('sekce', [], $rada);
                    $rada = [];
                }
                $koren[] = $p;
            } else {
                $rada[] = $p;
            }
        }
        if ($rada !== []) {
            $koren[] = Stavba::novy('sekce', [], $rada);
        }

        return ['stavba' => ['v' => Stavba::VERZE, 'deti' => $koren], 'tridy' => $prevod->tridy, 'hlaseni' => array_values(array_unique($prevod->hlaseni))];
    }

    /**
     * HTML z jazykového modelu (MCP, asistent ve staviteli) do webu: převod, uložení nových tříd z <style> (existující třída
     * webu se přepíše jen s $prepsat) a odebrání tříd bez stylu.
     *
     * @return array{stavba: array<string, mixed>, hlaseni: list<string>}
     */
    public static function doWebu(\MiroCMS\Core\Db $db, string $html, bool $spravce, bool $prepsat = false): array
    {
        $prevod = self::preved($html, $spravce);
        $hlaseni = $prevod['hlaseni'];
        $existujici = array_column($db->all('SELECT nazev FROM {tridy}'), 'nazev');
        foreach ($prevod['tridy'] as $trida => $css) {
            if (in_array($trida, $existujici, true) && !$prepsat) {
                $hlaseni[] = 'Třída .' . $trida . ' už na webu je – ponechána beze změny.';
                continue;
            }
            $db->run('INSERT INTO {tridy} (nazev, styl, css, zmeneno) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE css = VALUES(css), zmeneno = NOW()', [$trida, '{}', $css]);
        }
        $vynechane = [];
        $stavba = self::bezTrid($prevod['stavba'], array_merge($existujici, array_keys($prevod['tridy'])), $vynechane);
        if ($vynechane !== []) {
            $hlaseni[] = 'Třídy bez stylu vynechány: ' . implode(', ', array_unique($vynechane)) . '.';
        }

        return ['stavba' => $stavba, 'hlaseni' => $hlaseni];
    }

    /**
     * Odebere třídy, které nemají styl (z cizích CSS frameworků, WordPressu…) – jen by zabíraly místo.
     *
     * @param list<string> $zname třídy, které styl mají
     * @param list<string> $vynechane sem se zapíšou odebrané
     */
    public static function bezTrid(array $uzel, array $zname, array &$vynechane = []): array
    {
        foreach ($uzel['deti'] ?? [] as $i => $p) {
            if (isset($p['tridy'])) {
                $vynechane = array_merge($vynechane, array_diff($p['tridy'], $zname));
                $p['tridy'] = array_values(array_intersect($p['tridy'], $zname));
                if ($p['tridy'] === []) {
                    unset($p['tridy']);
                }
            }
            $uzel['deti'][$i] = self::bezTrid($p, $zname, $vynechane);
        }

        return $uzel;
    }

    /** @return list<array<string, mixed>> */
    private function deti(Node $rodic, int $hloubka = 0): array
    {
        $vystup = [];
        $tok = '';          // souvislý text (odstavce, seznamy) čekající na sloučení do jednoho prvku Text
        $otazky = [];       // souvislé <details> čekající na sloučení do prvku Otázky a odpovědi
        $dokonciTok = function () use (&$vystup, &$tok, &$otazky): void {
            if (trim(strip_tags($tok, '<img>')) !== '') {
                $vystup[] = Stavba::novy('text', ['html' => $tok]);
            }
            $tok = '';
            if ($otazky !== []) {
                $vystup[] = Stavba::novy('faq', ['polozky' => $otazky]);
                $otazky = [];
            }
        };

        foreach ($rodic->childNodes as $uzel) {
            if ($uzel->nodeType === XML_TEXT_NODE) {
                if (trim($uzel->textContent) !== '') {
                    $tok .= '<p>' . e(trim($uzel->textContent)) . '</p>';
                }
                continue;
            }
            if (!$uzel instanceof Element) {
                continue;
            }
            $znacka = strtolower($uzel->localName);
            if ($znacka === 'details' && ($otazka = $uzel->querySelector('summary')) !== null) {
                if ($tok !== '') {
                    $vystup[] = Stavba::novy('text', ['html' => $tok]);
                    $tok = '';
                }
                $odpoved = $uzel->cloneNode(true);
                $odpoved->querySelector('summary')?->remove();
                $otazky[] = ['otazka' => trim($otazka->textContent), 'odpoved' => trim($odpoved->innerHTML)];
                continue;
            }
            // odstavec bez třídy se připojí k souvislému textu; s třídou je samostatný prvek, aby třída měla kam
            if (in_array($znacka, self::TEXTOVE, true) && $this->tridyZ($uzel) === [] && !$this->jeBlokovy($uzel)) {
                if ($otazky !== []) {
                    $dokonciTok();
                }
                $tok .= $uzel->outerHTML;
                continue;
            }
            $dokonciTok();
            foreach ($this->prvek($uzel, $hloubka) as $p) {
                $vystup[] = $p;
            }
        }
        $dokonciTok();

        return $vystup;
    }

    /** @return list<array<string, mixed>> jeden prvek, víc prvků (rozbalený obal) nebo nic */
    private function prvek(Element $el, int $hloubka): array
    {
        $znacka = strtolower($el->localName);
        if (in_array($znacka, self::ROZBALIT, true)) {
            return $this->deti($el, $hloubka);
        }
        if (in_array($znacka, self::VYNECHAT, true)) {
            if ($znacka !== 'style') {
                $this->hlaseni[] = 'Značka <' . $znacka . '> mimo formulář nemá ve stavbě obdobu – vynechána.';
            }

            return [];
        }
        if ($el->hasAttribute('style')) {
            $this->hlaseni[] = 'Vložené styly (atribut style) se nepřevádějí – vzhled patří do tříd v <style>.';
        }
        if ($hloubka >= Stavba::MAX_HLOUBKA - 1) {
            $this->hlaseni[] = 'Příliš hluboké vnoření – nejhlubší část převedena jako text.';

            return [Stavba::novy('text', ['html' => $el->outerHTML])];
        }

        $p = match (true) {
            in_array($znacka, ['section', 'header', 'footer', 'aside', 'article', 'nav', 'div', 'li'], true) => $this->obal($el, $znacka, $hloubka),
            (bool) preg_match('/^h[1-6]$/', $znacka) => ['znacka' => $znacka] + Stavba::novy('nadpis', ['text' => trim($el->innerHTML)]),
            in_array($znacka, self::TEXTOVE, true) => $this->textNeboObal($el, $znacka, $hloubka),
            $znacka === 'img' => Stavba::novy('obrazek', ['src' => $el->getAttribute('src') ?? '', 'alt' => $el->getAttribute('alt') ?? '']),
            $znacka === 'figure' => $this->figura($el, $hloubka),
            $znacka === 'a' => $this->odkaz($el, $hloubka),
            $znacka === 'blockquote' => $this->citat($el),
            $znacka === 'hr' => Stavba::novy('oddelovac'),
            $znacka === 'form' => $this->formular($el),
            in_array($znacka, ['iframe', 'video'], true) && ($video = $this->video($el)) !== null => $video,
            in_array($znacka, ['svg', 'iframe', 'video', 'picture', 'audio'], true) => $this->vlastniHtml($el),
            default => Stavba::novy('text', ['html' => '<p>' . trim($el->innerHTML) . '</p>']),
        };
        if ($p === null) {
            return [];
        }
        if (($tridy = $this->tridyZ($el)) !== []) {
            $p['tridy'] = $tridy;
        }
        if (($id = $el->getAttribute('id')) !== null && preg_match('/^[a-z][a-z0-9-]{0,40}$/', $id)) {
            $p['kotva'] = $id;
        }

        return [$p];
    }

    /** section/div/… – na nejvyšší úrovni sekce (section, header, footer), jinde kontejner nebo mřížka. */
    private function obal(Element $el, string $znacka, int $hloubka): array
    {
        $deti = $this->deti($el, $hloubka + 1);
        if ($hloubka === 0 && in_array($znacka, ['section', 'header', 'footer', 'aside', 'article'], true)) {
            // vnitřní obal webu (.container, .wrapper) je u sekce zbytečný – sekce má vlastní; zůstane, jen když jeho třída má styl
            if (count($deti) === 1 && $deti[0]['typ'] === 'kontejner' && array_intersect($deti[0]['tridy'] ?? [], array_keys($this->tridy)) === []) {
                $deti = $deti[0]['deti'];
            }

            return ['znacka' => $znacka] + Stavba::novy('sekce', [], $deti);
        }
        $znacka = in_array($znacka, Prvky\Kontejner::ZNACKY, true) ? $znacka : 'div';

        return ['znacka' => $znacka] + Stavba::novy('kontejner', [], $deti);
    }

    /** Seznam s třídou nebo se složitými položkami (karty v <ul>) je kontejner, jednoduchý seznam s třídou je prvek Seznam. */
    private function textNeboObal(Element $el, string $znacka, int $hloubka): array
    {
        if (in_array($znacka, ['ul', 'ol'], true)) {
            if (!$this->jeBlokovy($el)) {
                $polozky = [];
                foreach ($el->children as $li) {
                    $polozky[] = trim($li->textContent);
                }

                return ['znacka' => $znacka] + Stavba::novy('seznam', ['polozky' => implode("\n", $polozky)]);
            }

            return ['znacka' => 'ul'] + Stavba::novy('kontejner', [], array_map(
                fn (array $p): array => $p['typ'] === 'kontejner' ? ['znacka' => 'li'] + $p : ['znacka' => 'li'] + Stavba::novy('kontejner', [], [$p]),
                $this->deti($el, $hloubka + 1),
            ));
        }

        return Stavba::novy('text', ['html' => $el->outerHTML]);
    }

    private function figura(Element $el, int $hloubka): ?array
    {
        $obrazek = $el->querySelector('img');
        if ($obrazek === null) {
            return ['znacka' => 'div'] + Stavba::novy('kontejner', [], $this->deti($el, $hloubka + 1));
        }

        return Stavba::novy('obrazek', ['src' => $obrazek->getAttribute('src') ?? '', 'alt' => $obrazek->getAttribute('alt') ?? '', 'popisek' => trim($el->querySelector('figcaption')?->textContent ?? '')]);
    }

    /** Odkaz s blokovým obsahem (karta) je kontejner-odkaz, samostatný textový odkaz je tlačítko. */
    private function odkaz(Element $el, int $hloubka): array
    {
        $adresa = $el->getAttribute('href') ?? '';
        if ($this->jeBlokovy($el) || $el->querySelector('img') !== null) {
            return ['znacka' => 'div'] + Stavba::novy('kontejner', ['odkaz' => $adresa], $this->deti($el, $hloubka + 1));
        }
        $trida = strtolower((string) $el->getAttribute('class'));
        $varianta = match (true) {
            (bool) preg_match('/outline|obrys|ghost|secondary|sekundar/', $trida) => 'obrys',
            (bool) preg_match('/\blink\b|odkaz/', $trida) => 'odkaz',
            default => 'primarni',
        };

        return Stavba::novy('tlacitko', ['text' => trim($el->textContent), 'odkaz' => $adresa, 'varianta' => $varianta, 'nove_okno' => $el->getAttribute('target') === '_blank']);
    }

    /** Formulář → prvek Formulář: pole podle ovládacích prvků a jejich popisků; odesílá se vždy do Poptávek webu. */
    private function formular(Element $el): array
    {
        $pole = [];
        $prepinace = []; // skupiny <input type="radio"> podle name → jedno pole výběru
        foreach ($el->querySelectorAll('input, select, textarea') as $vstup) {
            $typ = strtolower((string) ($vstup->getAttribute('type') ?? 'text'));
            if (in_array($typ, ['hidden', 'submit', 'button', 'reset', 'image', 'file', 'password'], true)) {
                if (in_array($typ, ['file', 'password'], true)) {
                    $this->hlaseni[] = 'Pole typu ' . $typ . ' formulář nepodporuje – vynecháno.';
                }
                continue;
            }
            $popisek = $this->popisekPole($el, $vstup);
            $povinne = $vstup->hasAttribute('required');
            if ($typ === 'radio') {
                $jmeno = (string) $vstup->getAttribute('name');
                if (!isset($prepinace[$jmeno])) {
                    $prepinace[$jmeno] = count($pole);
                    $skupina = $vstup->closest('fieldset')?->querySelector('legend')?->textContent;
                    $pole[] = ['popisek' => trim($skupina ?? $jmeno), 'typ' => 'vyber', 'povinne' => $povinne, 'moznosti' => ''];
                }
                $pole[$prepinace[$jmeno]]['moznosti'] = ltrim($pole[$prepinace[$jmeno]]['moznosti'] . "\n" . $popisek);
                continue;
            }
            $pole[] = match (true) {
                strtolower($vstup->localName) === 'textarea' => ['popisek' => $popisek, 'typ' => 'textarea', 'povinne' => $povinne, 'moznosti' => ''],
                strtolower($vstup->localName) === 'select' => ['popisek' => $popisek, 'typ' => 'vyber', 'povinne' => $povinne, 'moznosti' => implode("\n", array_filter(array_map(
                    fn (Element $o): string => ($o->getAttribute('value') ?? 'x') === '' ? '' : trim($o->textContent), iterator_to_array($vstup->querySelectorAll('option')),
                )))],
                $typ === 'checkbox' => ['popisek' => $popisek, 'typ' => 'souhlas', 'povinne' => $povinne, 'moznosti' => ''],
                default => ['popisek' => $popisek, 'typ' => in_array($typ, ['email', 'tel'], true) ? $typ : 'text', 'povinne' => $povinne, 'moznosti' => ''],
            };
        }
        $tlacitko = $el->querySelector('button:not([type="button"]):not([type="reset"]), input[type="submit"]');
        $text = trim($tlacitko === null ? '' : ($tlacitko->localName === 'input' ? (string) $tlacitko->getAttribute('value') : $tlacitko->textContent));
        $this->hlaseni[] = 'Formulář převeden na prvek Formulář: odesílá se do Poptávek webu a e-mailem (adresa v action se nepoužije).';

        return Stavba::novy('formular', array_filter(['pole' => array_slice($pole, 0, 20), 'tlacitko' => $text], fn (mixed $v): bool => $v !== '' && $v !== []));
    }

    private function popisekPole(Element $formular, Element $vstup): string
    {
        $id = $vstup->getAttribute('id');
        $label = $id !== null && $id !== '' ? $formular->querySelector('label[for="' . addcslashes($id, '"\\') . '"]') : null;
        $label ??= $vstup->closest('label');
        if ($label !== null) {
            $kopie = $label->cloneNode(true);
            foreach ($kopie->querySelectorAll('input, select, textarea') as $v) {
                $v->remove();
            }
            $text = trim((string) preg_replace('/\s+/', ' ', $kopie->textContent));
            if ($text !== '') {
                return rtrim($text, ' *:');
            }
        }

        $prvniVolba = strtolower($vstup->localName) === 'select' ? $vstup->querySelector('option[value=""]')?->textContent : null;

        return trim((string) ($vstup->getAttribute('placeholder') ?? $vstup->getAttribute('aria-label') ?? $prvniVolba ?? $vstup->getAttribute('name') ?? ''));
    }

    private function citat(Element $el): array
    {
        $podpis = $el->querySelector('footer, cite, figcaption');
        $autor = trim($podpis?->textContent ?? '');
        $podpis?->remove();
        $text = trim(preg_replace('#</?p[^>]*>#', ' ', $el->innerHTML) ?? '');

        return Stavba::novy('citat', ['text' => $text, 'autor' => ltrim($autor, "—–- \t")]);
    }

    private function video(Element $el): ?array
    {
        $src = $el->getAttribute('src') ?? $el->querySelector('source')?->getAttribute('src') ?? '';
        if (preg_match('#(youtube\.com/embed/|youtube-nocookie\.com/embed/)([\w-]{6,})#', $src, $m)) {
            return Stavba::novy('video', ['url' => 'https://www.youtube.com/watch?v=' . $m[2], 'titulek' => $el->getAttribute('title') ?? '']);
        }
        if (preg_match('#player\.vimeo\.com/video/(\d+)#', $src, $m)) {
            return Stavba::novy('video', ['url' => 'https://vimeo.com/' . $m[1], 'titulek' => $el->getAttribute('title') ?? '']);
        }

        return null;
    }

    private function vlastniHtml(Element $el): ?array
    {
        if (!$this->spravce) {
            $this->hlaseni[] = 'Značka <' . strtolower($el->localName) . '> jde vložit jen jako Vlastní HTML, a to smí jen správce webu – vynechána.';

            return null;
        }

        return Stavba::novy('html', ['kod' => $el->outerHTML]);
    }

    /** Obsahuje prvek blokové značky (pak nejde o prostý text, ale o strukturu)? */
    private function jeBlokovy(Element $el): bool
    {
        return $el->querySelector('div, section, article, header, footer, aside, nav, h1, h2, h3, h4, h5, h6, figure, img, blockquote, details, a.btn, a.button, a[class*="tlacitko"]') !== null;
    }

    /** @return list<string> třídy ve tvaru, který stavba přijme */
    private function tridyZ(Element $el): array
    {
        $tridy = preg_split('/\s+/', trim((string) $el->getAttribute('class'))) ?: [];

        return array_values(array_filter($tridy, fn (string $t): bool => preg_match(Stavba::VZOR_TRIDA, $t) === 1));
    }

    /** Pravidla „.trida { … }“ z <style> se stanou sdílenými třídami; ostatní selektory a @media se nahlásí. */
    private function styly(string $css): void
    {
        $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);
        if (preg_match_all('/@(media|supports|container|keyframes|font-face|import|layer)\b/i', $css, $m)) {
            $this->hlaseni[] = 'Pravidla @' . implode(', @', array_unique(array_map('strtolower', $m[1]))) . ' se nepřevádějí – breakpointy a stavy nastavte ve stylu prvku nebo třídy ve staviteli.';
            // vnořené bloky se odstraní, aby nepřevzaly deklarace do nesprávných tříd
            do {
                $css = (string) preg_replace('/@[a-z-]+[^{;]*\{(?:[^{}]*\{[^{}]*\})*[^{}]*\}|@[a-z-]+[^{;]*;/i', '', $css, -1, $pocet);
            } while ($pocet > 0);
        }
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $pravidla, PREG_SET_ORDER);
        $jine = [];
        foreach ($pravidla as [, $selektory, $deklarace]) {
            foreach (array_map('trim', explode(',', $selektory)) as $selektor) {
                if (preg_match('/^\.([a-z][a-z0-9_-]*)$/', $selektor, $t) && preg_match(Stavba::VZOR_TRIDA, $t[1])) {
                    $zahozeno = [];
                    $bezpecne = Styl::vlastniCss($deklarace, $zahozeno);
                    $this->tridy[$t[1]] = trim(($this->tridy[$t[1]] ?? '') . ' ' . $bezpecne);
                    foreach ($zahozeno as $d) {
                        $this->hlaseni[] = 'Třída .' . $t[1] . ': nepovolená deklarace „' . mb_substr($d, 0, 60) . '“ vynechána.';
                    }
                } elseif ($selektor !== '') {
                    $jine[] = $selektor;
                }
            }
        }
        if ($jine !== []) {
            $this->hlaseni[] = 'Převádějí se jen selektory jedné třídy (.karta); vynecháno: ' . mb_substr(implode(', ', array_unique($jine)), 0, 200) . '.';
        }
    }
}
