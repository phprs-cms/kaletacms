<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Where a move to Kaleta stands (3.33, M7): one block in Site audit (above the hand-over checklist) and in MCP site_audit
 * (move), fed only by what the importers and the migration report already know – counts and links, nothing new is
 * stored. It shows once the site has imported pages or a migration report; a site built from scratch never sees it.
 *
 * The rows: pages imported (and how many are published), imported pages nobody has reworked yet (their layout is still
 * the importer's plain conversion – rebuild it from the live old page), sample pages of the installation still there,
 * redirects from old addresses, and from the newest finished migration report its forms and whether it is clean.
 */
final class MoveStatus
{
    /**
     * @return array{pages_imported: int, pages_published: int, pages_not_reworked: int, news_imported: int, sample_pages: int, redirects: int,
     *     report: array{id: string, checked_at: string, errors: int, warnings: int, on_sample_pages: int, forms_missing: int, site_wide_forms: int, clean: bool}|null,
     *     rows: list<array{key: string, label: string, done: bool, edit: string}>}|null null = no move on this site
     */
    public static function summary(App $app): ?array
    {
        $db = $app->db();
        $pages = $db->one("SELECT COUNT(*) AS n, COALESCE(SUM(s.zobrazit = 1), 0) AS published, COALESCE(SUM(s.zmenil IS NULL AND s.stavba_koncept IS NULL), 0) AS untouched
            FROM {stranky} s WHERE s.smazano IS NULL AND EXISTS (SELECT 1 FROM {import_mapa} m WHERE m.typ = 'stranka' AND m.nase_id = s.ids AND m.zdroj NOT LIKE 'form:%')") ?? [];
        $news = (int) $db->value("SELECT COUNT(*) FROM {novinky} n WHERE n.smazano IS NULL AND EXISTS (SELECT 1 FROM {import_mapa} m WHERE m.typ = 'clanek' AND m.nase_id = n.idc AND m.zdroj NOT LIKE 'form:%')");
        $report = self::latestReport();
        if ((int) ($pages['n'] ?? 0) === 0 && $news === 0 && $report === null) {
            return null;
        }
        $samples = count(array_filter(StarterPages::untouched($db, $app->settings()), fn (array $p): bool => !$p['home']));
        $redirects = (int) $db->value('SELECT COUNT(*) FROM {presmerovani}');
        $out = ['pages_imported' => (int) ($pages['n'] ?? 0), 'pages_published' => (int) ($pages['published'] ?? 0), 'pages_not_reworked' => (int) ($pages['untouched'] ?? 0),
            'news_imported' => $news, 'sample_pages' => $samples, 'redirects' => $redirects, 'report' => $report];
        $reportLink = $report !== null ? 'admin.php?module=transfer&action=report&id=' . $report['id'] : 'admin.php?module=transfer';
        $out['rows'] = [
            ['key' => 'move_imported', 'label' => t('Pages imported: %s (published: %s), news items: %s', $out['pages_imported'], $out['pages_published'], $news), 'done' => $out['pages_imported'] + $news > 0, 'edit' => 'admin.php?module=pages'],
            ['key' => 'move_layouts', 'label' => t('Imported pages nobody has reworked yet (layout to rebuild from the old page): %s', $out['pages_not_reworked']), 'done' => $out['pages_not_reworked'] === 0, 'edit' => 'admin.php?module=pages'],
            ['key' => 'move_samples', 'label' => t('Sample pages of the installation left: %s', $samples), 'done' => $samples === 0, 'edit' => 'admin.php?module=transfer#sample-pages'],
            ['key' => 'move_redirects', 'label' => t('Redirects from old addresses: %s', $redirects), 'done' => Extensions::isEnabled($app->settings(), 'presmerovani'), 'edit' => 'admin.php?module=redirects'], // a kept address needs none; the feature must be on
            ['key' => 'move_forms', 'label' => $report === null ? t('Forms: run the migration report to compare them') : t('Old pages that lost their form: %s; site-wide forms: %s', $report['forms_missing'], $report['site_wide_forms']),
                'done' => $report !== null && $report['forms_missing'] === 0, 'edit' => $reportLink],
            ['key' => 'move_report', 'label' => $report === null ? t('Migration report: not run yet') : t('Migration report of %s: %s errors, %s warnings, %s addresses on sample pages', substr($report['checked_at'], 0, 16), $report['errors'], $report['warnings'], $report['on_sample_pages']),
                'done' => $report !== null && $report['clean'], 'edit' => $reportLink],
        ];

        return $out;
    }

    /**
     * The newest finished migration report, counted without reading the old site again.
     *
     * @return array{id: string, checked_at: string, errors: int, warnings: int, on_sample_pages: int, forms_missing: int, site_wide_forms: int, clean: bool}|null
     */
    public static function latestReport(): ?array
    {
        foreach (MigrationReport::listAll() as $state) {
            if (($state['faze'] ?? '') === 'hotovo') {
                return ['id' => (string) $state['id'], 'checked_at' => (string) ($state['dokonceno'] ?? $state['zalozeno'] ?? '')] + self::counts((array) ($state['radky'] ?? []));
            }
        }

        return null;
    }

    /**
     * Errors, warnings, sample pages and lost forms of a report's rows (as MigrationReport::result counts them).
     *
     * @param array<mixed> $rows
     * @return array{errors: int, warnings: int, on_sample_pages: int, forms_missing: int, site_wide_forms: int, clean: bool}
     */
    public static function counts(array $rows): array
    {
        $rows = array_values(array_filter($rows, is_array(...)));
        $siteWide = MigrationReport::siteWideForms($rows);
        $c = ['errors' => 0, 'warnings' => 0, 'on_sample_pages' => 0, 'forms_missing' => 0, 'site_wide_forms' => count($siteWide)];
        foreach ($rows as $row) {
            $row = MigrationReport::withoutSiteWideForm($row, array_keys($siteWide));
            $c['on_sample_pages'] += (int) (($row['stav'] ?? '') === 'sample');
            foreach ((array) ($row['problemy'] ?? []) as $p) {
                $severity = MigrationReport::PROBLEMS[(string) $p] ?? 'info';
                $c['errors'] += (int) ($severity === 'error');
                $c['warnings'] += (int) ($severity === 'warning');
                $c['forms_missing'] += (int) ($p === 'form_missing');
            }
        }

        return $c + ['clean' => $c['errors'] === 0];
    }
}
