<?php

declare(strict_types=1);

namespace MiroCMS\Admin\Moduly;

use MiroCMS\Admin\Modul;
use MiroCMS\Admin\StavitelAkce;
use MiroCMS\Core\Jazyk;
use MiroCMS\Core\Response;
use MiroCMS\Stavitel\Casti as CastiWebu;
use MiroCMS\Stavitel\Publikace;
use MiroCMS\Stavitel\Stavba;

/**
 * Části webu ve staviteli: záhlaví, patička a obálky detailu novinky, výpisu a stránky 404. Bez publikované stavby
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
        foreach ($this->db->all('SELECT typ, jazyk, stavba IS NOT NULL AS publikovana, stavba_koncept IS NOT NULL AND (stavba IS NULL OR stavba_koncept <> stavba) AS zmeny, zmeneno FROM {casti}') as $r) {
            $radky[$r['typ'] . ':' . $r['jazyk']] = $r;
        }

        return $this->view('vypis', 'Části webu', [
            'typy' => CastiWebu::TYPY, 'jazyky' => $jazyky, 'radky' => $radky,
            'nazvyJazyku' => array_combine($jazyky, array_map(fn (string $j): string => Jazyk::DOSTUPNE[Jazyk::obsahu($web, $j)][0], $jazyky)),
        ]);
    }

    /** Editor; část, která ještě není, se založí s konceptem podle toho, co dosud kreslila šablona. */
    protected function akceStavitel(): Response
    {
        [$typ, $jazyk] = $this->typJazyk();
        if ($typ !== null && CastiWebu::radek($this->db, $typ, $jazyk) === null) {
            $this->db->insert('casti', ['typ' => $typ, 'jazyk' => $jazyk, 'stavba_koncept' => Stavba::naJson(CastiWebu::vychozi($typ, $this->jazykObsahu($jazyk))), 'zmeneno' => date('Y-m-d H:i:s')]);
        }

        return $this->editorStavby();
    }

    /** Část se vrátí na šablonu: publikovaná stavba jde do verzí, na webu se kreslí část z layoutu. */
    protected function akceSablona(): Response
    {
        [$typ, $jazyk] = $this->typJazyk();
        $radek = $this->request->isPost() && $typ !== null ? CastiWebu::radek($this->db, $typ, $jazyk) : null;
        if ($radek !== null) {
            Publikace::verze($this->app, ['cast' => CastiWebu::klicRevize($typ, $jazyk)], $radek['stavba'], null, $radek['zmeneno']);
            $this->db->delete('casti', ['typ' => $typ, 'jazyk' => $jazyk]);
            \MiroCMS\Front\Cache::vymaz();
        }

        return $this->zpet('Část webu se vrátila na šablonu. Předchozí podobu najdete ve verzích, když ji znovu otevřete ve staviteli.');
    }

    protected function cilStavby(): ?array
    {
        [$typ, $jazyk] = $this->typJazyk();
        $radek = $typ === null ? null : CastiWebu::radek($this->db, $typ, $jazyk);

        return $radek === null ? null : [
            'radek' => $radek, 'stavba' => $radek['stavba'], 'koncept' => $radek['stavba_koncept'], 'jazyk' => $this->jazykObsahu($jazyk),
            'titulek' => t(CastiWebu::TYPY[$typ][0]), 'revize' => ['cast' => CastiWebu::klicRevize($typ, $jazyk)], 'parametry' => ['typ' => $typ, 'jazyk' => $jazyk],
        ];
    }

    protected function ulozKoncept(array $cil, ?string $koncept): void
    {
        $this->db->update('casti', ['stavba_koncept' => $koncept], ['typ' => $cil['radek']['typ'], 'jazyk' => $cil['radek']['jazyk']]);
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
        $cesta = match ($typ) {
            'novinka' => ($seo = $this->db->value('SELECT seo_link FROM {novinky} WHERE visible = 1 AND smazano IS NULL AND datum <= NOW() AND jazyk = ? ORDER BY datum DESC LIMIT 1', [$jazyk])) !== null ? 'novinky/' . $seo : 'novinky',
            'vypis' => 'novinky',
            'nenalezeno' => 'tahle-stranka-neexistuje',
            default => '',
        };
        $adresa = $this->app->url(($jazyk !== '' ? $jazyk . '/' : '') . $cesta);

        return [
            'adresa' => $adresa, 'nahled' => $adresa . '?cast=' . $typ . '&stavba=koncept&editor=1', 'zobrazena' => true, 'casti' => true,
            'zpet' => ['adresa' => $this->url(), 'text' => t('Části webu')], 'nastaveni' => null,
        ];
    }

    /** @return array{0: ?string, 1: string} typ a jazyk části z požadavku (POST i GET) */
    private function typJazyk(): array
    {
        $typ = $this->request->get('typ');
        $jazyk = $this->request->get('jazyk');

        return [isset(CastiWebu::TYPY[$typ]) ? $typ : null, in_array($jazyk, Jazyk::dalsi($this->app->settings()), true) ? $jazyk : ''];
    }
}
