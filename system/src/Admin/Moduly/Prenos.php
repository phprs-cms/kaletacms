<?php

declare(strict_types=1);

namespace MiroCMS\Admin\Moduly;

use MiroCMS\Admin\Modul;
use MiroCMS\Core\ExportWebu;
use MiroCMS\Core\Jazyk;
use MiroCMS\Core\Response;
use MiroCMS\Core\StahovaniObrazku;
use MiroCMS\Core\WpImport;
use MiroCMS\Core\WpSoubor;

/**
 * Import a export: přechod z WordPressu (soubor WXR) a export celého webu do otevřeného formátu.
 *
 * Import má tři kroky na jedné obrazovce: 1. soubor (nahraný formulářem, nebo přes FTP do storage/import/),
 * 2. náhled – co v souboru je a co se nepřevede, 3. import po dávkách (formulář se odesílá sám, data-auto-odeslat).
 * Obrázky ze starého webu se stahují až ve zvláštním kroku na výslovné přání. Všechnu práci dělá Core\WpImport;
 * tady je jen obsluha formulářů. Stav rozpracovaného importu je v souboru vedle exportu, ne v session.
 */
final class Prenos extends Modul
{
    public const string IDENT = 'prenos';
    public const string NAZEV = 'Import a export';
    public const string SKUPINA = 'Správa';
    public const string IKONA = 'b-archiv';
    public const bool JEN_ADMIN = true;

    protected function akceVypis(): Response
    {
        $soubory = [];
        foreach (WpSoubor::seznam() as $s) {
            $soubory[] = $s + ['stav' => WpImport::nactiStav($s['soubor'])];
        }

        return $this->view('vypis', 'Import a export', [
            'soubory' => $soubory,
            'limitNahrani' => min(self::bajty((string) ini_get('upload_max_filesize')), self::bajty((string) ini_get('post_max_size'))),
            'chybiXml' => !class_exists(\XMLReader::class) || !class_exists(\Dom\HTMLDocument::class),
            'exporty' => ExportWebu::seznam(),
            'umiZip' => class_exists(\ZipArchive::class),
        ]);
    }

    /* ---------- import: 1. soubor ---------- */

    /** Nahrání exportu formulářem; soubor skončí ve storage/import/ stejně jako ten nahraný přes FTP. */
    protected function akceNahraj(): Response
    {
        $file = $this->request->file('soubor');
        if (!$this->request->isPost() || $file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
            return $this->zpet('Soubor se nepodařilo nahrát. Je-li větší, než server dovoluje, nahrajte ho přes FTP do složky storage/import/.', typ: 'chyba');
        }
        if (strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION)) !== 'xml') {
            return $this->zpet('Soubor musí mít příponu .xml – je to export z WordPressu (Nástroje → Export).', typ: 'chyba');
        }
        try {
            $nazev = WpSoubor::nazevProNahrani((string) $file['name']);
            $cil = WpSoubor::slozka() . '/' . $nazev;
            // nejdřív ověřit, až potom uložit: do složky se nedostane nic, co není export z WordPressu
            (new WpSoubor((string) $file['tmp_name']))->overObsah();
            if (!move_uploaded_file((string) $file['tmp_name'], $cil)) {
                throw new \RuntimeException('Soubor se nepodařilo uložit – zkontrolujte práva k zápisu do storage/import.');
            }
        } catch (\RuntimeException $e) {
            return $this->zpet(self::hlaska($e), typ: 'chyba');
        }

        return $this->zacni($nazev);
    }

    /** Výběr souboru, který už ve storage/import/ leží (nahraný přes FTP nebo dříve). */
    protected function akceVyber(): Response
    {
        $soubor = $this->request->post('soubor');
        $cesta = WpSoubor::cesta($soubor);
        if (!$this->request->isPost() || $cesta === null) {
            return $this->zpet('Soubor neexistuje.', typ: 'chyba');
        }
        try {
            (new WpSoubor($cesta))->over();
        } catch (\RuntimeException $e) {
            return $this->zpet(self::hlaska($e), typ: 'chyba');
        }

        return $this->zacni($soubor);
    }

    private function zacni(string $soubor): Response
    {
        WpImport::ulozStav(WpImport::novyStav($soubor));

        return $this->zpet('', 'prubeh', ['soubor' => $soubor]);
    }

    protected function akceSmazSoubor(): Response
    {
        $cesta = WpSoubor::cesta($this->request->post('soubor'));
        if ($this->request->isPost() && $cesta !== null) {
            unlink($cesta);
            WpImport::smazStav($this->request->post('soubor'));
        }

        return $this->zpet('Soubor byl smazán. Převedený obsah na webu zůstává.');
    }

    /* ---------- import: 2. náhled a volby ---------- */

    protected function akceNahled(): Response
    {
        $stav = $this->stav();
        if ($stav === null || $stav['faze'] === 'analyza') {
            return $this->zpet('', $stav === null ? '' : 'prubeh', $stav === null ? [] : ['soubor' => $stav['soubor']]);
        }
        $nastaveni = $this->app->settings();

        return $this->view('nahled', 'Import z WordPressu', [
            'stav' => $stav,
            'jazyky' => array_merge([Jazyk::vychozi($nastaveni)], Jazyk::dalsi($nastaveni)),
            'rubriky' => $this->db->all('SELECT idt, nazev, jazyk FROM {topic} ORDER BY jazyk, nazev'),
            'presmerovaniZapnuto' => \MiroCMS\Core\Rozsireni::je($nastaveni, 'presmerovani'),
        ]);
    }

    /** Uloží volby z náhledu a spustí import. */
    protected function akceSpust(): Response
    {
        $stav = $this->stav();
        if (!$this->request->isPost() || $stav === null || $stav['faze'] === 'analyza') {
            return $this->zpet();
        }
        $r = $this->request;
        $stav['volby'] = [
            'jazyk' => in_array($r->post('jazyk'), Jazyk::dalsi($this->app->settings()), true) ? $r->post('jazyk') : '',
            'koncepty' => $r->postBool('koncepty'), 'stranky' => $r->postBool('stranky'),
            'presmerovani' => $r->postBool('presmerovani'), 'rubrika' => $r->postInt('rubrika'),
        ];
        $stav['faze'] = 'import';
        $stav['pozice'] = 0;
        $stav['vysledek'] = WpImport::novyStav($stav['soubor'])['vysledek'];
        WpImport::ulozStav($stav);

        return $this->zpet('', 'prubeh', ['soubor' => $stav['soubor']]);
    }

    /* ---------- import: 3. průběh po dávkách (náhled, obsah i obrázky) ---------- */

    /**
     * GET jen ukáže, kde import je; POST udělá jednu dávku. Dokud není hotovo, šablona formulář sama znovu odešle.
     * Zámek na stavovém souboru brání tomu, aby dvě okna prohlížeče importovala současně.
     */
    protected function akcePrubeh(): Response
    {
        $stav = $this->stav();
        if ($stav === null) {
            return $this->zpet('Soubor neexistuje.', typ: 'chyba');
        }
        $chyba = '';
        if ($this->request->isPost() && in_array($stav['faze'], ['analyza', 'import', 'obrazky'], true)) {
            $zamek = fopen(WpSoubor::slozka() . '/import.zamek', 'c');
            if ($zamek !== false && flock($zamek, LOCK_EX | LOCK_NB)) {
                try {
                    @set_time_limit(60);
                    $stav = WpImport::nactiStav($stav['soubor']) ?? $stav; // čerstvý stav až pod zámkem
                    $this->davka($stav);
                } catch (\RuntimeException $e) {
                    $chyba = self::hlaska($e);
                } finally {
                    WpImport::ulozStav($stav);
                    flock($zamek, LOCK_UN);
                }
            }
        }
        if ($stav['faze'] === 'nahled' && $chyba === '') {
            return $this->zpet('', 'nahled', ['soubor' => $stav['soubor']]);
        }

        return $this->view('prubeh', 'Import z WordPressu', [
            'stav' => $stav, 'chyba' => $chyba,
            'stahovaniMozne' => StahovaniObrazku::jeMozne() && extension_loaded('gd'),
            'domena' => StahovaniObrazku::domenaZAdresy((string) $stav['web']['adresa']),
        ]);
    }

    /** @param array<string, mixed> $stav */
    private function davka(array &$stav): void
    {
        $import = new WpImport($this->db, $this->app->settings(), $this->request->basePath(), $this->app->auth()->id());
        match ($stav['faze']) {
            'analyza' => WpImport::analyzuj($stav),
            'import' => $import->importuj($stav),
            'obrazky' => $import->obrazky($stav, new StahovaniObrazku((string) $stav['web']['adresa'])),
        };
    }

    /** Výslovné spuštění stahování obrázků ze starého webu (až po importu obsahu). */
    protected function akceObrazky(): Response
    {
        $stav = $this->stav();
        if (!$this->request->isPost() || $stav === null || !in_array($stav['faze'], ['hotovo', 'obrazky-hotovo'], true) || !StahovaniObrazku::jeMozne()) {
            return $this->zpet();
        }
        (new WpImport($this->db, $this->app->settings(), $this->request->basePath(), $this->app->auth()->id()))->zacniObrazky($stav);
        WpImport::ulozStav($stav);

        return $this->zpet('', 'prubeh', ['soubor' => $stav['soubor']]);
    }

    /** @return array<string, mixed>|null stav importu souboru z adresy nebo formuláře */
    private function stav(): ?array
    {
        $soubor = $this->request->isPost() && $this->request->post('soubor') !== '' ? $this->request->post('soubor') : $this->request->get('soubor');

        return WpSoubor::cesta($soubor) === null ? null : WpImport::nactiStav($soubor);
    }

    /* ---------- export ---------- */

    protected function akceExport(): Response
    {
        if (!$this->request->isPost()) {
            return $this->zpet();
        }
        try {
            $vysledek = ExportWebu::vytvor($this->db, $this->app->settings());
        } catch (\RuntimeException $e) {
            return $this->zpet($e->getMessage(), typ: 'chyba');
        }
        if ($vysledek['duvod'] !== '') {
            $this->app->session->flash('info', $vysledek['duvod']);
        }

        return $this->zpet('Export je hotový – stáhněte si ho ze seznamu níže.');
    }

    /**
     * Stažení exportu. Archiv může mít stovky MB, proto se neposílá přes Response (ta drží celé tělo v paměti),
     * ale po kouscích přímo ze souboru. Přístup hlídá administrace (modul je jen pro správce), název souboru Core\ExportWebu::cesta().
     */
    protected function akceStahni(): Response
    {
        $cesta = ExportWebu::cesta($this->request->get('soubor'));
        if ($cesta === null) {
            return $this->chyba('Export neexistuje.', 404);
        }
        session_write_close();
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($cesta) . '"');
        header('Content-Length: ' . filesize($cesta));
        header('Cache-Control: no-store, private');
        header('X-Content-Type-Options: nosniff');
        readfile($cesta);
        exit;
    }

    protected function akceSmazExport(): Response
    {
        $cesta = ExportWebu::cesta($this->request->post('soubor'));
        if ($this->request->isPost() && $cesta !== null) {
            unlink($cesta);
        }

        return $this->zpet('Export byl smazán.');
    }

    /** Hláška výjimky v jazyce administrace; číslo (řádek XML, kód odpovědi) nese getCode(), aby šel text přeložit. */
    private static function hlaska(\RuntimeException $e): string
    {
        return t($e->getMessage()) . (is_int($e->getCode()) && $e->getCode() > 0 ? ' ' . $e->getCode() : '');
    }

    /** "8M" z php.ini → bajty. */
    private static function bajty(string $hodnota): int
    {
        $cislo = (int) $hodnota;

        return $cislo <= 0 ? PHP_INT_MAX : $cislo * match (strtolower(substr(trim($hodnota), -1))) {
            'g' => 1024 ** 3, 'm' => 1024 ** 2, 'k' => 1024, default => 1,
        };
    }
}
