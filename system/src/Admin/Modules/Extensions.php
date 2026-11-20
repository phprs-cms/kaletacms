<?php

declare(strict_types=1);

namespace Kaleta\Admin\Modules;

use Kaleta\Core\Db;
use Kaleta\Core\Extensions as Catalog;
use Kaleta\Core\Settings as SiteSettings;

/**
 * Features – the built-in extensions – as a separate item of the main menu (formerly a Settings tab; "Features" since 3.2,
 * the ident stays). Add-ons share its hub.
 *
 * The screen and saving are the same as for the Settings tabs - the module only keeps a fixed "tab" extensions,
 * renders the templates from the config/ folder and returns to its own URL (admin.php?module=extensions).
 */
final class Extensions extends Settings
{
    public const string IDENT = 'extensions';
    public const string NAME = 'Features';
    public const string HUB = 'features';
    public const string ICON = 'prepinace';

    /**
     * 3.48: what a profile would switch off is named with the records it hides – one count per feature that has data.
     * key => the count (its rows stay; the section only leaves the menu). Quotes are named without a count: only the quote
     * classes read their tables (units 2800 and 2850).
     */
    private const array RECORDS = [
        'novinky' => 'SELECT COUNT(*) FROM {novinky} WHERE smazano IS NULL',
        'poptavky' => 'SELECT COUNT(*) FROM {poptavky}',
        'customers' => 'SELECT COUNT(*) FROM {contacts} WHERE anonymised_at IS NULL',
        'pipeline' => 'SELECT COUNT(*) FROM {deals}',
        'newsletter' => 'SELECT COUNT(*) FROM {odberatele}',
        'bookings' => 'SELECT COUNT(*) FROM {bookings}',
        'presmerovani' => 'SELECT COUNT(*) FROM {presmerovani}',
        'applications' => 'SELECT COUNT(*) FROM {applications}',
        'whistleblowing' => 'SELECT COUNT(*) FROM {whistleblowing_cases}',
    ];

    protected function tab(string $tab): string
    {
        return 'extensions';
    }

    /** The records a feature holds (0 for a feature without a count, or a table this site does not have yet). */
    public static function records(Db $db, string $key): int
    {
        if (!isset(self::RECORDS[$key])) {
            return 0;
        }
        try {
            return (int) $db->value(self::RECORDS[$key]);
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);

            return 0; // a table of a later migration
        }
    }

    /**
     * The choice a profile link shows (3.48, GET only – the Save of the form stores it): the profile's keys plus the enabled
     * features that belong to no profile (a profile never decides about them); and what that would switch off. Pure apart
     * from the catalogue.
     *
     * @param list<string> $enabled
     * @return array{keys: list<string>, off: list<string>}
     */
    public static function preselect(?SiteSettings $settings, string $profile, array $enabled): array
    {
        $keys = Catalog::closed([...Catalog::profile($settings, $profile), ...array_values(array_filter($enabled, fn (string $k): bool => (Catalog::CATALOG[$k][3] ?? '') === ''))]);
        $ordered = array_values(array_intersect(array_keys(Catalog::CATALOG), $keys));

        return ['keys' => $ordered, 'off' => array_values(array_diff($enabled, $ordered))];
    }

    /**
     * Who sees a section once it is on (3.48, the cards of Customers and the Pipeline): the roles that include it and how
     * many active people may open it – the administrators and everyone whose own rights already name it.
     *
     * @return array{roles: list<string>, people: int}
     */
    public static function seenBy(Db $db, string $section): array
    {
        $roles = array_values(array_filter(array_map(fn (array $r): ?string => in_array($section, explode(',', (string) $r['moduly']), true) ? (string) $r['nazev'] : null,
            $db->all('SELECT nazev, moduly FROM {role} ORDER BY ' . $db->sortText('nazev')))));
        $people = (int) $db->value('SELECT COUNT(*) FROM {uzivatele} u WHERE u.blokovat = 0 AND (u.admin >= 2 OR EXISTS (SELECT 1 FROM {uzivatele_prava} p WHERE p.fk_id_user = u.idu AND p.ident_modulu = ?))', [$section]);

        return ['roles' => $roles, 'people' => $people];
    }
}
