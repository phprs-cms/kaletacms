<?php
/**
 * Kaleta 3.2: feature defaults. Bookings and Whistleblowing became features (Core\Extensions) that new installations
 * start without; an update must never hide what a site uses, so they are switched on here where they are in use:
 *  - Bookings where any booking service or any booking exists;
 *  - Whistleblowing where the channel is open or any case exists.
 * The built-in statistics had two switches (the Statistics feature and the setting "stats"); the feature is now the only
 * one, so a site that had the setting off loses the feature – it never starts measuring by itself.
 *
 * The choice is always written down as a list (a site that never saved one gets the defaults it had until now), so the
 * new defaults of later releases never change it. Safe to run again: it only adds what is in use, and the old statistics
 * setting is reset once its "off" has moved to the feature.
 *
 * Self-contained on purpose: the update request of the release before runs this file with its own classes already
 * loaded (a Core\Extensions without the two new keys would drop them), so the keys and their order are written here.
 */

declare(strict_types=1);

use Kaleta\Core\Db;
use Kaleta\Core\Settings;

return static function (Db $db, Settings $settings): void {
    $exists = static function (string $sql) use ($db): bool {
        try {
            return $db->value($sql) !== null;
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);

            return false; // a table this site does not have
        }
    };
    // the features of 3.2 in the order of Core\Extensions::CATALOG; a site that never saved its choice had the defaults of 3.1
    $order = ['novinky', 'poptavky', 'newsletter', 'bookings', 'statistika', 'presmerovani', 'jazyky', 'asistent', 'whistleblowing', 'fleet', 'claude'];
    $stored = $settings->get('extensions');
    $features = match ($stored) {
        '' => ['novinky', 'poptavky', 'statistika', 'presmerovani', 'claude'],
        '-' => [],
        default => array_values(array_filter(array_map('trim', explode(',', $stored)))),
    };
    if ($exists('SELECT 1 FROM {booking_services} LIMIT 1') || $exists('SELECT 1 FROM {bookings} LIMIT 1')) {
        $features[] = 'bookings';
    }
    if ($settings->bool('whistleblowing_enabled') || $exists('SELECT 1 FROM {whistleblowing_cases} LIMIT 1')) {
        $features[] = 'whistleblowing';
    }
    if ($settings->get('stats') === '0') {
        $features = array_diff($features, ['statistika']);
        // the old setting has passed its state on: back to its default, so a second run never switches Statistics off again
        // after the administrator switched the feature on (the code before 3.2 needs both, so it does not measure either)
        $settings->set('stats', '1');
    }
    // in the order of the catalogue, each once; a key this release does not know is kept (Core\Extensions ignores it)
    $features = array_values(array_unique([...array_intersect($order, $features), ...array_diff($features, $order)]));
    $settings->set('extensions', $features === [] ? '-' : implode(',', $features));
};
