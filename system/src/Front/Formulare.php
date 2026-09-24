<?php

declare(strict_types=1);

namespace MiroCMS\Front;

use MiroCMS\Core\Antispam;
use MiroCMS\Core\App;
use MiroCMS\Core\Posta;
use MiroCMS\Core\Response;
use MiroCMS\Stavitel\Casti;
use MiroCMS\Stavitel\Komponenty;
use MiroCMS\Stavitel\Prvky;
use MiroCMS\Stavitel\Prvky\Formular;
use MiroCMS\Stavitel\Stavba;

/**
 * Odeslání formuláře ze stavitele (POST /formular). Pole a příjemce bere z PUBLIKOVANÉ stavby podle zdroje a id prvku –
 * návštěvník nemůže přidat pole ani změnit adresáta. Výsledek: poptávka v mc_poptavky, upozornění e-mailem a návrat
 * na stránku s kódem výsledku (?formular=<id>&vysledek=ok|pole|limit|overeni).
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
        $navrat = fn (string $vysledek): Response => Response::redirect($zpet . '?formular=' . rawurlencode($prvek['id']) . '&vysledek=' . $vysledek . '#' . Formular::kotva($prvek), 303);

        $antispam = new Antispam($this->app->db(), $this->app->settings());
        $duvod = $antispam->over($r, 'formular|' . $zdroj . '|' . $prvek['id']);
        if ($duvod === 'robot') {
            return $navrat('ok'); // robot se nedozví, že neprošel
        }
        if ($duvod !== null) {
            return $navrat('overeni');
        }
        if ($antispam->pocet($r->ip(), 'formular', 0, 10) >= self::LIMIT) {
            return $navrat('limit');
        }

        $data = [];
        $email = '';
        foreach ($prvek['obsah']['pole'] as $i => $pole) {
            $hodnota = trim(str_replace("\r\n", "\n", $r->post('p' . $i)));
            $hodnota = match ($pole['typ']) {
                'textarea' => mb_substr($hodnota, 0, 5000),
                'email' => filter_var($hodnota, FILTER_VALIDATE_EMAIL) !== false ? mb_substr($hodnota, 0, 190) : ($hodnota === '' ? '' : null),
                'tel' => $hodnota === '' || preg_match('/^[+()\d\s\/.-]{6,30}$/', $hodnota) ? $hodnota : null,
                'vyber' => $hodnota === '' || in_array($hodnota, Formular::moznosti($pole), true) ? $hodnota : null,
                'souhlas' => $hodnota === '1' ? t('ano') : '',
                default => mb_substr(str_replace("\n", ' ', $hodnota), 0, 300),
            };
            if ($hodnota === null || ($pole['povinne'] && $hodnota === '')) {
                return $navrat('pole');
            }
            if ($pole['typ'] === 'email' && $email === '') {
                $email = $hodnota;
            }
            $data[] = [$pole['popisek'], $hodnota];
        }
        $antispam->zapis($r->ip(), 'formular', 0);

        $db = $this->app->db();
        $idp = $db->insert('poptavky', [
            'datum' => date('Y-m-d H:i:s'), 'formular' => mb_substr((string) $prvek['obsah']['nazev'], 0, 120), 'zdroj' => $zdroj, 'prvek' => $prvek['id'],
            'stranka' => mb_substr($zpet, 0, 255), 'email' => $email, 'data' => (string) json_encode($data, JSON_UNESCAPED_UNICODE), 'stav' => 0,
        ]);
        $this->upozorni($idp, $prvek, $data, $email);

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
    private function upozorni(int $idp, array $prvek, array $data, string $email): void
    {
        $web = $this->app->settings();
        $komu = filter_var($prvek['obsah']['prijemce'], FILTER_VALIDATE_EMAIL) !== false ? $prvek['obsah']['prijemce'] : $web->get('email_webu');
        if ($komu === '') {
            return; // poptávka je uložená v administraci i bez e-mailu
        }
        $adresa = rtrim($web->get('adresa_webu') !== '' ? $web->get('adresa_webu') : $this->app->request->origin(), '/');
        $text = implode("\n\n", array_map(fn (array $d): string => $d[0] . ":\n" . $d[1], $data))
            . "\n\n—\n" . t('Poptávka v administraci: %s', $adresa . $this->app->url('admin.php?modul=poptavky&akce=detail&id=' . $idp));
        Posta::odesli($web, $komu, t('%s: %s', $prvek['obsah']['nazev'], $web->get('nazev_webu')), $text, '', $email !== '' ? ['Reply-To' => $email] : []);
    }
}
