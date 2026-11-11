<?php

declare(strict_types=1);

namespace Kaleta\Mcp\Handlers;

use Kaleta\Admin\Modules\Media;
use Kaleta\Admin\Modules\Categories;
use Kaleta\Admin\Modules\Pages;
use Kaleta\Core\App;
use Kaleta\Core\Language;
use Kaleta\Front\SiteIdentity;
use Kaleta\Builder\SiteParts;
use Kaleta\Builder\DesignSystem;
use Kaleta\Builder\Library;
use Kaleta\Builder\Collections;
use Kaleta\Builder\Publisher;
use Kaleta\Builder\Build;
use Kaleta\Builder\HtmlConverter;

/**
 * MCP tools: media (one method per tool, see Mcp\Catalog). Part of Mcp\Tools.
 *
 * @phpstan-ignore trait.unused
 */
trait MediaTools
{
    /** list_media (seznam_medii) */
    private function toolListMedia(string $name, array $a): mixed
    {
        $db = $this->app->db();

        $search = is_string($a['hledat'] ?? null) && trim($a['hledat']) !== '' ? '%' . addcslashes(trim($a['hledat']), '%_\\') . '%' : null;

        return array_map(fn (array $o): array => $this->medium($o),
            $db->all('SELECT * FROM {media}' . ($search !== null ? ' WHERE nazev LIKE ? OR obr_poloha LIKE ?' : '') . ' ORDER BY ido DESC LIMIT ?',
                [...($search !== null ? [$search, $search] : []), max(1, min(50, (int) ($a['limit'] ?? 20)))]));
    }

    /** import_website (importuj_web, 2.6): one batch of Core\WebImport per call */
    private function toolImportWebsite(string $name, array $a): mixed
    {
        if (!$this->app->auth()->isAdmin()) {
            throw new \DomainException('A site is imported by an administrator.');
        }
        $id = trim((string) ($a['import'] ?? ''));
        if ($id === '') {
            $url = trim((string) ($a['adresa'] ?? ''));
            $url = preg_match('#^https?://#i', $url) ? $url : 'https://' . $url;
            if (!\Kaleta\Core\WebImport::validUrl($url)) {
                throw new \InvalidArgumentException('Give the address of the site, e.g. https://www.example.com.');
            }
            $language = (string) ($a['jazyk'] ?? '');
            $state = \Kaleta\Core\WebImport::newState($url, [
                'jazyk' => in_array($language, \Kaleta\Core\Language::additional($this->app->settings()), true) ? $language : '',
                'obrazky' => ($a['obrazky'] ?? true) !== false, 'presmerovani' => ($a['presmerovani'] ?? true) !== false, 'novinky' => ($a['novinky'] ?? true) !== false,
            ]);
        } else {
            $state = \Kaleta\Core\WebImport::load($id) ?? throw new \InvalidArgumentException('The import does not exist; start a new one with the url.');
        }
        if (array_key_exists('ponechat_vzory', $a) && $state['faze'] === 'nahled') {
            // 3.33 (M2): the starter pages to keep – old addresses (["/contact"], ["*"] or true = all) of the preview's choices
            $state['volby']['ponechat_vzory'] = \Kaleta\Core\StarterPages::keepFromAddresses(
                \Kaleta\Core\WebImport::starterChoices($this->app->db(), $this->app->settings(), ['volby' => ['ponechat_vzory' => []] + $state['volby']] + $state), $a['ponechat_vzory']);
        }
        if (array_key_exists('nahradit_vzory', $a) && $state['faze'] === 'nahled') {
            // 3.33 review H1: a page recognised only by the titles of an older installation is replaced only when named here
            $state['volby']['nahradit_vzory'] = \Kaleta\Core\StarterPages::replaceFromAddresses(
                \Kaleta\Core\WebImport::starterChoices($this->app->db(), $this->app->settings(), $state), $a['nahradit_vzory']);
        }
        if (($a['potvrdit'] ?? false) === true && $state['faze'] === 'nahled') {
            $state['faze'] = 'import';
            $state['pozice'] = 0;
        }
        $created = \Kaleta\Core\WebImport::created($state);
        if (in_array($state['faze'], ['hledani', 'import'], true)) {
            (new \Kaleta\Core\WebImport($this->app->db(), $this->app->settings(), $this->app->auth()->id(), new \Kaleta\Core\ImageDownloader($state['web'], true)))
                ->step($state, fn (array $s) => \Kaleta\Core\WebImport::save($s)); // saved before and between the heavy parts (N37-23)
        }
        \Kaleta\Core\WebImport::save($state);
        $this->recordsCreated = \Kaleta\Core\WebImport::created($state) - $created; // against the hourly change limit (3.7, N37-26)
        $urls = array_keys($state['adresy']);

        $r = $state['vysledek'];

        return ['import_id' => $state['id'], 'site' => $state['web'], 'phase' => ['hledani' => 'finding', 'nahled' => 'preview', 'import' => 'importing', 'hotovo' => 'done'][$state['faze']] ?? $state['faze'],
            'found' => count($urls), 'processed' => (int) $state['pozice'],
            'result' => ['new_pages' => $r['stranky'], 'new_news' => $r['clanky'], 'images' => $r['obrazky'], 'redirects' => $r['presmerovani'], 'skipped' => $r['preskoceno'], 'failed' => $r['chyb']],
            'failures' => $state['chyby'],
            // 3.33: what the pages kept of the old site's widgets, files and SEO fields, and what needs a person
            'kept' => \Kaleta\Core\WebImport::keptSummary($state),
            'attention' => \Kaleta\Core\WebImport::attentionLines($state, false), // English templates, whoever started the import
            'notes' => \Kaleta\Core\WebImport::notes($state), // a robots.txt that was cut, sitemaps on other hosts that were not read (3.7)
            'old_site_reachable' => !isset($state['nedostupny']), // 3.33 (M6): found nothing because nothing answered – the notes say why
            'addresses' => $state['faze'] === 'nahled' ? array_map(fn (string $u): string => '/' . \Kaleta\Core\WebImport::path($u), array_slice($urls, 0, 50)) : [],
        ] + ($state['faze'] === 'nahled'
            // 3.33 (M2): the starter pages the import replaces (replace: true) or keeps (why: kept | edited) – keep_starter_pages declines one
            ? ['starter_pages' => \Kaleta\Core\WebImport::starterChoices($this->app->db(), $this->app->settings(), $state)]
            : ['starter_pages_result' => \Kaleta\Core\StarterPages::summary((array) ($state['vzory'] ?? [])),
                // 3.33 (M3): old paths kept parents and all; the ones that could not be kept as they were (shortened, flattened)
                'addresses_changed' => \Kaleta\Core\ImportPath::summary((array) ($state['zmenene_adresy'] ?? []))]) + [
            'next' => match ($state['faze']) {
                'hledani', 'import' => 'Call again with the same import id.',
                'nahled' => 'Show the user what was found and which starter pages of this site the import replaces (starter_pages); on their instruction call again with confirm: true (keep_starter_pages: ["/old-address"] keeps a starter page, ["*"] all of them; a starter page with why "legacy" is kept unless replace_starter_pages: ["/old-address"] names it).',
                default => 'Done: the pages are hidden – check them with list_pages, put the ones to keep in the menu and publish them on the user\'s instruction.'
                    . ' Fix what attention lists (a placeholder marks a form whose fields could not be read). read_old_page returns an old page as section HTML with its colours and fonts, to rebuild its layout.',
            }];
    }

    /** upload_file (nahraj_soubor) */
    private function toolUploadFile(string $name, array $a): mixed
    {
        return $this->uploadFile($a);
    }

    /** update_media and delete_media */
    private function toolUpdateMedia(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();
        $id = (int) ($a['id'] ?? 0);
        $need = function (bool $allowed, string $message): void {
            if (!$allowed) {
                throw new \DomainException($message);
            }
        };

        $file = $db->one('SELECT * FROM {media} WHERE ido = ?', [$id]) ?? throw new \InvalidArgumentException('The file does not exist. Use list_media.');
        $need($auth->isAdmin() || (int) $file['vlastnik'] === $auth->id(), 'Only the owner of the file or an administrator can change it.');
        if ($name === 'update_media') {
            $changes = array_filter(['nazev' => isset($a['alt']) ? mb_substr(trim((string) $a['alt']), 0, 150) : null, 'popis' => isset($a['caption']) ? mb_substr(trim((string) $a['caption']), 0, 500) : null,
                'autor' => isset($a['author']) ? mb_substr(trim((string) $a['author']), 0, 120) : null], fn (?string $v): bool => $v !== null);
            if ($changes !== []) {
                $db->update('media', $changes, ['ido' => $id]);
                \Kaleta\Front\Cache::clear();
            }

            return ['id' => $id, 'path' => $file['obr_poloha'], 'changed' => array_keys($changes)];
        }
        $usedAt = array_keys(\Kaleta\Admin\Modules\Media::findUsagesElsewhere($db)[$id] ?? []);
        if ($usedAt !== [] || $db->value('SELECT 1 FROM {media_pouziti} WHERE ido = ? LIMIT 1', [$id]) !== null) {
            throw new \DomainException('The file is still used on the site' . ($usedAt !== [] ? ': ' . implode(', ', array_slice($usedAt, 0, 5)) : ' (in a news item)') . ' – remove it from there first.');
        }
        \Kaleta\Core\Images::delete($file['obr_poloha'], $file['nahl_poloha']);
        \Kaleta\Core\Files::delete($file['obr_poloha']);
        $db->delete('media', ['ido' => $id]);

        return ['deleted' => $id, 'path' => $file['obr_poloha']];
    }

    /** delete_media: the same as update_media */
    private function toolDeleteMedia(string $name, array $a): mixed
    {
        return $this->toolUpdateMedia($name, $a);
    }

    /** list_media_without_alt (2.14, Core\MediaHygiene): images whose description is empty, with where they are used */
    private function toolListMediaWithoutAlt(string $name, array $a): mixed
    {
        $limit = max(1, min(200, (int) ($a['limit'] ?? 50)));
        $report = Language::runWith('en', fn (): array => \Kaleta\Core\MediaHygiene::report($this->app->db()), 'admin-');
        $base = $this->app->request->origin();

        return [
            'total' => count($report['without_alt']),
            'images' => array_map(fn (array $o): array => ['id' => (int) $o['ido'], 'path' => $o['obr_poloha'], 'url' => $base . $this->app->url($o['obr_poloha']),
                'width' => (int) $o['obr_width'], 'height' => (int) $o['obr_height'], 'caption' => $o['popis'], 'used_in' => $o['kde'], 'used_in_news' => (int) $o['v_novinkach']],
                array_slice($report['without_alt'], 0, $limit)),
            'next' => count($report['without_alt']) === 0 ? 'Every image has a description.'
                : 'For each image call update_media with alt: what the image shows in a few words, in the site language (never "image" or the file name). Decorative images get a short neutral description too, since the site reads alt as the image name.',
        ];
    }
}
