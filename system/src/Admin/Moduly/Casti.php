<?php

declare(strict_types=1);

namespace Kaleta\Admin\Moduly;

use Kaleta\Admin\Modul;
use Kaleta\Admin\StavitelAkce;
use Kaleta\Core\Jazyk;
use Kaleta\Core\Response;
use Kaleta\Stavitel\Casti as CastiWebu;
use Kaleta\Stavitel\Publikace;
use Kaleta\Stavitel\Stavba;

/**
 * Části webu v builderu: záhlaví, patička a obálky detailu novinky, výpisu a stránky 404. Bez publikované stavby
 * kreslí část šablona; „Vrátit na šablonu“ stavbu vypne (zůstane ve verzích).
 */
final class Casti extends Modul
{
    use StavitelAkce {
        akceStavitel as protected editorStavby;
    }

    public const string IDENT = 'casti';
    public const string NAZEV = 'Části webu';
    public const string SKUPINA = 'Vzhled';
    public const string IKONA = 'casti';
    public const bool JEN_ADMIN = true;

    protected function akceVypis(): Response
    {
        $web = $this->app->settings();
        $jazyky = array_merge([''], Jazyk::dalsi($web));
        $radky = [];
        $varianty = [];
        foreach ($this->db->all('SELECT typ, jazyk, varianta, nazev, stranky, stavba IS NOT NULL AS publikovana, stavba_koncept IS NOT NULL AND (stavba IS NULL OR stavba_koncept <> stavba) AS zmeny, zmeneno FROM {casti} ORDER BY nazev') as $r) {
            if ($r['varianta'] === '') {
                $radky[$r['typ'] . ':' . $r['jazyk']] = $r;
            } else {
                $varianty[$r['typ'] . ':' . $r['jazyk']][] = $r;
            }
        }

        return $this->view('vypis', 'Části webu', [
            'typy' => CastiWebu::TYPY, 'jazyky' => $jazyky, 'radky' => $radky, 'varianty' => $varianty,
            'nazvyStranek' => $this->db->pairs('SELECT ids, titulek FROM {stranky} WHERE smazano IS NULL ORDER BY poradi, titulek'),
            'nazvyJazyku' => array_combine($jazyky, array_map(fn (string $j): string => Jazyk::DOSTUPNE[Jazyk::obsahu($web, $j)][0], $jazyky)),
        ]);
    }

    /** Editor; část, která ještě není, se založí s konceptem podle toho, co dosud kreslila šablona. */
    protected function akceStavitel(): Response
    {
        [$typ, $jazyk, $varianta] = $this->typJazyk();
        if ($typ !== null && $varianta === '' && CastiWebu::radek($this->db, $typ, $jazyk) === null) {
            $this->db->insert('casti', ['typ' => $typ, 'jazyk' => $jazyk, 'stavba_koncept' => Stavba::naJson(CastiWebu::vychozi($typ, $this->jazykObsahu($jazyk))), 'zmeneno' => date('Y-m-d H:i:s')]);
        }

        return $this->editorStavby();
    }

    /** Část se vrátí na šablonu (varianta se smaže): publikovaná stavba jde do verzí, na webu se kreslí část z layoutu. */
    protected function akceSablona(): Response
    {
        [$typ, $jazyk, $varianta] = $this->typJazyk();
        $radek = $this->request->isPost() && $typ !== null ? CastiWebu::radek($this->db, $typ, $jazyk, $varianta) : null;
        if ($radek !== null) {
            Publikace::verze($this->app, ['cast' => CastiWebu::klicRevize($typ, $jazyk, $varianta)], $radek['stavba'], null, $radek['zmeneno']);
            $this->db->delete('casti', ['typ' => $typ, 'jazyk' => $jazyk, 'varianta' => $varianta]);
            \Kaleta\Front\Cache::vymaz();
        }

        return $this->zpet($varianta !== '' ? 'Varianta byla smazána – vybrané stránky mají zase výchozí podobu.' : 'Část webu se vrátila na šablonu. Předchozí podobu najdete ve verzích, když ji znovu otevřete v builderu.');
    }

    /** Formulář varianty záhlaví nebo patičky: název a stránky, na kterých platí. */
    protected function akceVarianta(): Response
    {
        [$typ, $jazyk, $varianta] = $this->typJazyk();
        if ($typ === null || !in_array($typ, CastiWebu::S_VARIANTAMI, true)) {
            return $this->chyba('Varianty mají jen záhlaví a patička.', 404);
        }
        $radek = $varianta !== '' ? CastiWebu::radek($this->db, $typ, $jazyk, $varianta) : null;

        return $this->view('varianta', t('Varianta: %s', t(CastiWebu::TYPY[$typ][0])), [
            'typ' => $typ, 'jazyk' => $jazyk, 'varianta' => $radek['varianta'] ?? '', 'nazev' => $radek['nazev'] ?? '',
            'vybrane' => array_map('intval', json_decode((string) ($radek['stranky'] ?? '[]'), true) ?: []),
            'stranky' => $this->db->all('SELECT ids, titulek FROM {stranky} WHERE jazyk = ? AND smazano IS NULL ORDER BY poradi, titulek', [$jazyk]),
        ]);
    }

    /** Uložení varianty; nová začíná kopií výchozí podoby (nebo podoby ze šablony) jako koncept. */
    protected function akceUlozVariantu(): Response
    {
        [$typ, $jazyk] = $this->typJazyk();
        if (!$this->request->isPost() || $typ === null || !in_array($typ, CastiWebu::S_VARIANTAMI, true)) {
            return $this->zpet();
        }
        $nazev = mb_substr(trim($this->request->post('nazev')), 0, 100);
        if ($nazev === '') {
            return $this->zpet('Varianta musí mít název.', 'varianta', ['typ' => $typ, 'jazyk' => $jazyk], 'chyba');
        }
        $stranky = (string) json_encode(array_values(array_unique(array_map('intval', $this->request->postList('stranky')))));
        $varianta = $this->request->post('varianta');
        if (preg_match(CastiWebu::VZOR_VARIANTY, $varianta) && CastiWebu::radek($this->db, $typ, $jazyk, $varianta) !== null) {
            $this->db->update('casti', ['nazev' => $nazev, 'stranky' => $stranky, 'zmeneno' => date('Y-m-d H:i:s')], ['typ' => $typ, 'jazyk' => $jazyk, 'varianta' => $varianta]);
        } else {
            $varianta = substr(slugify($nazev, 40), 0, 40);
            for ($i = 2, $zaklad = $varianta; CastiWebu::radek($this->db, $typ, $jazyk, $varianta) !== null; $i++) {
                $varianta = substr($zaklad, 0, 36) . '-' . $i;
            }
            $vychozi = CastiWebu::radek($this->db, $typ, $jazyk);
            $this->db->insert('casti', ['typ' => $typ, 'jazyk' => $jazyk, 'varianta' => $varianta, 'nazev' => $nazev, 'stranky' => $stranky, 'zmeneno' => date('Y-m-d H:i:s'),
                'stavba_koncept' => $vychozi['stavba'] ?? Stavba::naJson(CastiWebu::vychozi($typ, $this->jazykObsahu($jazyk)))]);
        }
        \Kaleta\Front\Cache::vymaz();

        return \Kaleta\Core\Response::redirect($this->url('stavitel', ['typ' => $typ, 'jazyk' => $jazyk, 'varianta' => $varianta]));
    }

    protected function cilStavby(): ?array
    {
        [$typ, $jazyk, $varianta] = $this->typJazyk();
        $radek = $typ === null ? null : CastiWebu::radek($this->db, $typ, $jazyk, $varianta);

        return $radek === null ? null : [
            'radek' => $radek, 'stavba' => $radek['stavba'], 'koncept' => $radek['stavba_koncept'], 'jazyk' => $this->jazykObsahu($jazyk),
            'titulek' => t(CastiWebu::TYPY[$typ][0]) . ($varianta !== '' ? ' – ' . $radek['nazev'] : ''),
            'revize' => ['cast' => CastiWebu::klicRevize($typ, $jazyk, $varianta)], 'parametry' => ['typ' => $typ, 'jazyk' => $jazyk] + ($varianta !== '' ? ['varianta' => $varianta] : []),
        ];
    }

    protected function ulozKoncept(array $cil, ?string $koncept): void
    {
        $this->db->update('casti', ['stavba_koncept' => $koncept], ['typ' => $cil['radek']['typ'], 'jazyk' => $cil['radek']['jazyk'], 'varianta' => $cil['radek']['varianta']]);
    }

    protected function publikujCil(array $cil): void
    {
        Publikace::cast($this->app, $cil['radek']);
    }

    protected function editorCile(array $cil): array
    {
        $typ = $cil['radek']['typ'];
        $jazyk = $cil['radek']['jazyk'];
        // náhled: stránka, na které se část ukáže (obálka novinky na nejnovější novince, 404 na neexistující adrese)
        // varianta se ukazuje na první stránce, pro kterou platí
        $stranka = $cil['radek']['varianta'] !== '' ? (json_decode((string) $cil['radek']['stranky'], true) ?: [])[0] ?? null : null;
        $cesta = $stranka !== null ? (string) $this->db->value('SELECT seo_link FROM {stranky} WHERE ids = ?', [(int) $stranka]) : match ($typ) {
            'novinka' => ($seo = $this->db->value('SELECT seo_link FROM {novinky} WHERE visible = 1 AND smazano IS NULL AND datum <= NOW() AND jazyk = ? ORDER BY datum DESC LIMIT 1', [$jazyk])) !== null ? 'novinky/' . $seo : 'novinky',
            'vypis' => 'novinky',
            'nenalezeno' => 'tahle-stranka-neexistuje',
            default => '',
        };
        $adresa = $this->app->url(($jazyk !== '' ? $jazyk . '/' : '') . $cesta);

        return [
            'adresa' => $adresa, 'nahled' => $adresa . '?cast=' . $typ . '&stavba=koncept&editor=1' . ($cil['radek']['varianta'] !== '' ? '&varianta=' . rawurlencode($cil['radek']['varianta']) : ''),
            'zobrazena' => true, 'casti' => true,
            'zpet' => ['adresa' => $this->url(), 'text' => t('Části webu')], 'nastaveni' => null, 'podpis' => 'cast:' . $typ . ':' . $jazyk,
        ];
    }

    /** @return array{0: ?string, 1: string, 2: string} typ, jazyk a varianta části z adresy požadavku */
    private function typJazyk(): array
    {
        $typ = $this->request->get('typ');
        $jazyk = $this->request->get('jazyk');
        $varianta = $this->request->get('varianta');

        return [isset(CastiWebu::TYPY[$typ]) ? $typ : null, in_array($jazyk, Jazyk::dalsi($this->app->settings()), true) ? $jazyk : '',
            in_array($typ, CastiWebu::S_VARIANTAMI, true) && preg_match(CastiWebu::VZOR_VARIANTY, $varianta) ? $varianta : ''];
    }
}
