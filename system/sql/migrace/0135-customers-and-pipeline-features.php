<?php
/**
 * Kaleta 3.48 (decision 3): Customers and the Pipeline became features (Core\Extensions) that new installations start
 * without; an update must never hide what a site uses, so they are switched on here where the site shows use of them:
 *  - Customers where any contact or company exists (anonymised contacts are rows too), or a person was given the section;
 *  - the Pipeline (and Customers with it – it needs them) where any deal exists, the stages were changed from the default
 *    ones, or a person was given the section.
 * "Given the section" is a row of ka_uzivatele_prava of an account that is not an administrator and has no custom role (its
 * own rights), or a custom role that names the section and is not one of the shipped presets (Admin\Modules\Roles::PRESETS
 * – a role made from "Client" or "Office / sales" names both sections on every site that created one, so it counts only
 * when it was changed). A site that shows no use keeps its stored choice as it is ('' included): the new defaults leave
 * the two sections out.
 *
 * The choice is written down as a list (a site that never saved one gets the defaults it had until now plus what is in
 * use). Safe to run again: it only adds. It follows the data, so a site that switched a section off by hand and still has
 * its records gets it back when this runs again by name.
 *
 * Self-contained on purpose: the update request of the release before runs this file with its own classes already loaded
 * (a Core\Extensions without the two new keys would drop them), so the keys, their order, the default stages and the
 * presets are written here.
 */

declare(strict_types=1);

use Kaleta\Core\Db;
use Kaleta\Core\Settings;

return static function (Db $db, Settings $settings): void {
    $rows = static function (string $sql, array $params = []) use ($db): array {
        try {
            return $db->all($sql, $params);
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);

            return []; // a table this site does not have
        }
    };
    $exists = static fn (string $table): bool => $rows('SELECT 1 FROM {' . $table . '} LIMIT 1') !== [];

    // the shipped presets of a custom role (3.45) with the sections that matter here; their names in every admin language
    $presets = [
        'Client' => ['pages', 'news', 'collections', 'categories', 'tags', 'enquiries', 'contacts', 'pipeline', 'stats', 'requests', 'business', 'appearance_area'],
        'Office / sales' => ['enquiries', 'contacts', 'pipeline', 'bookings', 'quotes', 'subscribers', 'requests'],
    ];
    $names = [];
    foreach (array_keys($presets) as $english) {
        $names[$english] = [$english];
    }
    foreach (glob(dirname(__DIR__, 2) . '/jazyky/admin-*.php') ?: [] as $file) {
        $dictionary = require $file;
        foreach (array_keys($presets) as $english) {
            if (is_array($dictionary) && is_string($dictionary[$english] ?? null)) {
                $names[$english][] = $dictionary[$english];
            }
        }
    }
    $used = static function (string $section) use ($rows, $presets, $names): bool {
        // a person's own right (no custom role, not an administrator)
        if ($rows('SELECT 1 FROM {uzivatele_prava} p JOIN {uzivatele} u ON u.idu = p.fk_id_user WHERE p.ident_modulu = ? AND u.admin < 2 AND u.role IS NULL LIMIT 1', [$section]) !== []) {
            return true;
        }
        foreach ($rows('SELECT nazev, moduly FROM {role}') as $role) {
            $sections = array_values(array_filter(array_map('trim', explode(',', (string) $role['moduly']))));
            if (!in_array($section, $sections, true)) {
                continue;
            }
            $preset = false;
            foreach ($presets as $english => $presetSections) {
                $preset = $preset || (in_array((string) $role['nazev'], $names[$english], true) && array_diff($sections, $presetSections) === []);
            }
            if (!$preset) {
                return true;
            }
        }

        return false;
    };
    // the stages as Core\Deals::saveStages stores them: '' for the default ones, JSON otherwise
    $stagesCustomised = static function () use ($settings): bool {
        $stored = $settings->get('pipeline_stages');
        if ($stored === '') {
            return false;
        }
        $stages = json_decode($stored, true);
        if (!is_array($stages)) {
            return false; // damaged: read as the defaults
        }
        $default = ['new' => 'open', 'qualified' => 'open', 'proposal' => 'open', 'won' => 'won', 'lost' => 'lost'];
        $keys = array_map(static fn (mixed $s): string => is_array($s) ? (string) ($s['key'] ?? '') : '', $stages);
        $kinds = array_map(static fn (mixed $s): string => is_array($s) ? (string) ($s['kind'] ?? '') : '', $stages);
        $labels = array_filter(array_map(static fn (mixed $s): string => is_array($s) ? (string) ($s['label'] ?? '') : '', $stages));

        return $keys !== array_keys($default) || $kinds !== array_values($default) || $labels !== [];
    };

    $pipeline = $exists('deals') || $stagesCustomised() || $used('pipeline');
    $customers = $pipeline || $exists('contacts') || $exists('companies') || $used('contacts');
    if (!$customers) {
        return; // no use shown: the stored choice stays as it is
    }
    // the features of 3.48 in the order of Core\Extensions::CATALOG; a site that never saved its choice had the defaults of 3.47
    $order = ['novinky', 'poptavky', 'customers', 'pipeline', 'newsletter', 'bookings', 'statistika', 'presmerovani', 'jazyky', 'asistent', 'applications', 'quotes', 'whistleblowing', 'fleet', 'claude'];
    $stored = $settings->get('extensions');
    $features = match ($stored) {
        '' => ['novinky', 'poptavky', 'statistika', 'presmerovani', 'claude'],
        '-' => [],
        default => array_values(array_filter(array_map('trim', explode(',', $stored)))),
    };
    $features[] = 'customers';
    if ($pipeline) {
        $features[] = 'pipeline';
    }
    // in the order of the catalogue, each once; a key this release does not know is kept (Core\Extensions ignores it)
    $features = array_values(array_unique([...array_intersect($order, $features), ...array_diff($features, $order)]));
    $settings->set('extensions', implode(',', $features));
};
