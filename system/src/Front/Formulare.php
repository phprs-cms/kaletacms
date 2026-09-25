<?php

declare(strict_types=1);

namespace Kaleta\Front;

use Kaleta\Core\Antispam;
use Kaleta\Core\App;
use Kaleta\Core\Posta;
use Kaleta\Core\Response;
use Kaleta\Stavitel\Casti;
use Kaleta\Stavitel\Komponenty;
use Kaleta\Stavitel\Prvky;
use Kaleta\Stavitel\Prvky\Formular;
use Kaleta\Stavitel\Stavba;

/**
 * Odeslání formuláře z builderu (POST /formular). Pole a příjemce bere z PUBLIKOVANÉ stavby podle zdroje a id prvku –
 * návštěvník nemůže přidat pole ani změnit adresáta. Výsledek: poptávka v ka_poptavky, upozornění e-mailem a návrat
 * na stránku s kódem výsledku (?formular=<id>&vysledek=ok|pole|limit|rychle|overeni).
 */
final class Formulare
{
    /** Kolik zpráv smí jedna IP adresa odeslat za 10 minut. */
    private const int LIMIT = 5;

    public function __construct(private readonly App $app)
    {
    }

    public function zpracuj(): Response
    {
        $r = $this->app->request;
        if (!$r->isPost()) {
            return new Response('', 405, ['Allow' => 'POST']);
        }
        $zdroj = $r->post('zdroj');
        $zpet = $r->post('zpet');
        $zpet = preg_match('#^/[^\s\\\\]*$#', $zpet) && !str_starts_with($zpet, '//') ? $zpet : $this->app->url('');
        $prvek = $this->prvek($zdroj, $r->post('prvek'));
        if ($prvek === null) {
            return Response::redirect($zpet, 303);
        }
        $navrat = fn (string $vysledek, int $pole = -1): Response => Response::redirect($zpet . '?formular=' . rawurlencode($prvek['id']) . '&vysledek=' . $vysledek . ($pole >= 0 ? '&pole=' . $pole : '') . '#' . Formular::kotva($prvek), 303);

        $antispam = new Antispam($this->app->db(), $this->app->settings());
        $duvod = $antispam->duvod($r, 'formular|' . $zdroj . '|' . $prvek['id']);
        if ($duvod === 'robot') {
            return $navrat('ok'); // robot se nedozví, že neprošel
        }
        if ($duvod !== null) {
            // příliš rychlé odeslání (automatické vyplnění) má vlastní hlášení: stačí chvilku počkat, obnovovat stránku netřeba
            return $navrat($duvod === 'rychle' ? 'rychle' : 'overeni');
        }
        if ($antispam->pocet($r->ip(), 'formular', 0, 10) >= self::LIMIT) {
            return $navrat('limit');
        }

        $data = [];
        $email = '';
        $prilohy = [];
        foreach ($prvek['obsah']['pole'] as $i => $pole) {
            if ($pole['typ'] === 'soubor') {
                $soubor = $_FILES['p' . $i] ?? null;
                $nahrany = is_array($soubor) && ($soubor['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && is_uploaded_file((string) $soubor['tmp_name']);
                $pripona = $nahrany ? strtolower(pathinfo((string) $soubor['name'], PATHINFO_EXTENSION)) : '';
                if ($nahrany && (!in_array($pripona, Formular::PRIPONY_PRILOH, true) || (int) $soubor['size'] > Formular::MAX_PRILOHA)) {
                    return $navrat('pole', $i);
                }
                if (!$nahrany && $pole['povinne']) {
                    return $navrat('pole', $i);
                }
                $data[] = [$pole['popisek'], $nahrany ? mb_substr(basename((string) $soubor['name']), 0, 120) . ' (' . \Kaleta\Core\Soubory::velikost((int) $soubor['size']) . ')' : ''];
                if ($nahrany) {
                    $prilohy[count($data) - 1] = [(string) $soubor['tmp_name'], $pripona];
                }
                continue;
            }
            $hodnota = trim(str_replace("\r\n", "\n", $r->post('p' . $i)));
            $hodnota = match ($pole['typ']) {
                'textarea' => mb_substr($hodnota, 0, 5000),
                'email' => filter_var($hodnota, FILTER_VALIDATE_EMAIL) !== false ? mb_substr($hodnota, 0, 190) : ($hodnota === '' ? '' : null),
                'tel' => $hodnota === '' || preg_match('/^[+()\d\s\/.-]{6,30}$/', $hodnota) ? $hodnota : null,
                'vyber', 'volba' => $hodnota === '' || in_array($hodnota, Formular::moznosti($pole), true) ? $hodnota : null,
                'datum' => $hodnota === '' || (preg_match('/^\d{4}-\d{2}-\d{2}$/', $hodnota) && checkdate((int) substr($hodnota, 5, 2), (int) substr($hodnota, 8, 2), (int) substr($hodnota, 0, 4))) ? $hodnota : null,
                'cislo' => $hodnota === '' || preg_match('/^-?\d{1,12}([.,]\d{1,6})?$/', $hodnota) ? $hodnota : null,
                'souhlas' => $hodnota === '1' ? t('ano') : '',
                default => mb_substr(str_replace("\n", ' ', $hodnota), 0, 300),
            };
            if ($hodnota === null || ($pole['povinne'] && $hodnota === '')) {
                return $navrat('pole', $i);
            }
            if ($pole['typ'] === 'email' && $email === '') {
                $email = $hodnota;
            }
            $data[] = [$pole['popisek'], $hodnota];
        }
        $antispam->zapis($r->ip(), 'formular', 0);
        // přílohy mimo veřejné složky (storage/ je z webu nepřístupné); stáhne je jen přihlášený v Poptávkách
        foreach ($prilohy as $index => [$tmp, $pripona]) {
            $cesta = date('Y/m') . '/' . bin2hex(random_bytes(12)) . '.' . $pripona;
            $cil = KALETA_ROOT . '/storage/prilohy/' . $cesta;
            if ((is_dir(dirname($cil)) || mkdir(dirname($cil), 0775, true)) && move_uploaded_file($tmp, $cil)) {
                $data[$index][2] = $cesta;
            }
        }

        $db = $this->app->db();
        $kampan = self::kampan($r->referer(), $r->origin());
        $idp = $db->insert('poptavky', [
            'datum' => date('Y-m-d H:i:s'), 'formular' => mb_substr((string) $prvek['obsah']['nazev'], 0, 120), 'zdroj' => $zdroj, 'prvek' => $prvek['id'],
            'stranka' => mb_substr($zpet, 0, 255), 'kampan' => $kampan, 'email' => $email, 'data' => (string) json_encode($data, JSON_UNESCAPED_UNICODE), 'stav' => 0,
        ]);
        $this->upozorni($idp, $prvek, $data, $email, $kampan);
        \Kaleta\Core\Webhook::poptavka($this->app, $idp, (string) $prvek['obsah']['nazev'], $data, $email, $zpet, $kampan);
        if (!empty($prvek['obsah']['potvrzeni']) && $email !== '') {
            // potvrzení odesílateli: jen poděkování a název formuláře – obsah zprávy ne, aby formulář nešel zneužít k rozesílání cizích textů
            $web = $this->app->settings();
            Posta::odesli($web, $email, t('Potvrzení: %s', $web->get('nazev_webu')), $prvek['obsah']['dekujeme'] . "\n\n—\n" . $web->get('nazev_webu') . "\n" . rtrim($web->get('adresa_webu') ?: $r->origin(), '/'), '');
        }
        $dekovna = (string) ($prvek['obsah']['dekovna'] ?? '');
        if ($dekovna !== '' && (str_starts_with($dekovna, '/') && !str_starts_with($dekovna, '//') || preg_match('#^https://#', $dekovna))) {
            // adresa na webu je celá cesta (i s jazykem, /en/…), jen se doplní složka instalace
            // ?odeslano=<název> na děkovné stránce ohlásí konverzi měření (image/web.js), stejně jako poděkování na místě
            $dekovna = (str_starts_with($dekovna, '/') ? $r->basePath() . $dekovna : $dekovna);
            $dekovna .= (str_contains($dekovna, '?') ? '&' : '?') . 'odeslano=' . rawurlencode((string) $prvek['obsah']['nazev']);

            return Response::redirect($dekovna, 303);
        }

        return $navrat('ok');
    }

    /** Formulář z publikované stavby stránky nebo části webu. @return array<string, mixed>|null */
    private function prvek(string $zdroj, string $id): ?array
    {
        $db = $this->app->db();
        $stavba = match (true) {
            (bool) preg_match('/^stranka:(\d+)$/', $zdroj, $m) => Stavba::zJson($db->value('SELECT stavba FROM {stranky} WHERE ids = ? AND zobrazit = 1', [(int) $m[1]])),
            (bool) preg_match('/^cast:([a-z]+):([a-z]{0,2})(?::([a-z0-9-]{1,40}))?$/', $zdroj, $m) && isset(Casti::TYPY[$m[1]]) => Casti::stavba($db, $m[1], $m[2], false, $m[3] ?? ''),
            (bool) preg_match('/^kolekce:(\d+)$/', $zdroj, $m) => Stavba::zJson($db->value('SELECT stavba FROM {kolekce} WHERE idk = ? AND detail = 1', [(int) $m[1]])),
            default => null,
        };
        // formulář může být i uvnitř komponenty (její publikovaná stavba); hloubka jako při vykreslení
        $najdi = function (array $deti, array $zanoreni = []) use (&$najdi, $id, $db): ?array {
            foreach ($deti as $p) {
                if (($p['id'] ?? '') === $id) {
                    return ($p['typ'] ?? '') === Formular::TYP ? $p : null;
                }
                if (($nalezeny = $najdi($p['deti'] ?? [], $zanoreni)) !== null) {
                    return $nalezeny;
                }
                $idm = ($p['typ'] ?? '') === Prvky\Komponenta::TYP ? (int) ($p['obsah']['komponenta'] ?? 0) : 0;
                if ($idm > 0 && !in_array($idm, $zanoreni, true) && count($zanoreni) < Komponenty::MAX_ZANORENI) {
                    $komponenta = Komponenty::podleId($db, $idm);
                    $vnitrek = $komponenta === null ? null : Stavba::zJson($komponenta['stavba'] ?? $komponenta['stavba_koncept']);
                    if ($vnitrek !== null && ($nalezeny = $najdi($vnitrek['deti'] ?? [], [...$zanoreni, $idm])) !== null) {
                        return $nalezeny;
                    }
                }
            }

            return null;
        };

        return $stavba === null || $id === '' ? null : $najdi($stavba['deti'] ?? []);
    }

    /** @param list<array{0:string, 1:string}> $data */
    public const array UTM = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];

    /**
     * Kampaň z adresy stránky s formulářem (hlavička Referer odeslání): jen parametry utm_*, jen z vlastního webu.
     * Bez cookies a bez ukládání v prohlížeči – kampaň se zapíše, když je formulář přímo na stránce, na kterou reklama vede.
     */
    public static function kampan(string $referer, string $origin): string
    {
        $host = strtolower((string) parse_url($referer, PHP_URL_HOST));
        if ($host === '' || $host !== strtolower((string) parse_url($origin, PHP_URL_HOST))) {
            return '';
        }
        parse_str((string) parse_url($referer, PHP_URL_QUERY), $dotaz);
        $utm = [];
        foreach (self::UTM as $klic) {
            $hodnota = is_string($dotaz[$klic] ?? null) ? trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $dotaz[$klic])) : '';
            if ($hodnota !== '') {
                $utm[$klic] = mb_substr($hodnota, 0, 80);
            }
        }
        $kampan = http_build_query($utm);

        return strlen($kampan) <= 255 ? $kampan : '';
    }

    /** Kampaň pro člověka: „google / cpc / jarni-akce“ (zdroj / médium / kampaň, případně klíčové slovo a obsah). */
    public static function kampanText(string $kampan): string
    {
        parse_str($kampan, $utm);

        return implode(' / ', array_filter(array_map(fn (string $k): string => is_string($utm[$k] ?? null) ? $utm[$k] : '', self::UTM), fn (string $h): bool => $h !== ''));
    }

    private function upozorni(int $idp, array $prvek, array $data, string $email, string $kampan): void
    {
        $web = $this->app->settings();
        $komu = filter_var($prvek['obsah']['prijemce'], FILTER_VALIDATE_EMAIL) !== false ? $prvek['obsah']['prijemce'] : $web->get('email_webu');
        if ($komu === '') {
            return; // poptávka je uložená v administraci i bez e-mailu
        }
        $adresa = rtrim($web->get('adresa_webu') !== '' ? $web->get('adresa_webu') : $this->app->request->origin(), '/');
        $text = implode("\n\n", array_map(fn (array $d): string => $d[0] . ":\n" . $d[1], $data))
            . ($kampan !== '' ? "\n\n" . t('Kampaň') . ":\n" . self::kampanText($kampan) : '')
            . "\n\n—\n" . t('Poptávka v administraci: %s', $adresa . $this->app->url('admin.php?modul=poptavky&akce=detail&id=' . $idp));
        Posta::odesli($web, $komu, t('%s: %s', $prvek['obsah']['nazev'], $web->get('nazev_webu')), $text, '', $email !== '' ? ['Reply-To' => $email] : []);
    }
}
