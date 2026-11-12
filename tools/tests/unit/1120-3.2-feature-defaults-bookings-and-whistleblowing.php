<?php

declare(strict_types=1);

/* ---------- 3.2: feature defaults – Bookings and Whistleblowing are features, Statistics has one switch ---------- */
check('[source-pin] 3.2: Bookings and Whistleblowing are features that new installations start without; a site that never saved its choice gets neither', [
    Kaleta\Core\Extensions::CATALOG['bookings'][2], Kaleta\Core\Extensions::CATALOG['whistleblowing'][2],
    array_values(array_intersect(['bookings', 'whistleblowing', 'statistika'], Kaleta\Core\Extensions::enabled($reportSettings(['extensions' => ''])))),
    Kaleta\Admin\Modules\Bookings::EXTENSION, Kaleta\Admin\Modules\Whistleblowing::EXTENSION, Kaleta\Builder\Elements\Booking::EXTENSION,
    in_array(Kaleta\Builder\Elements\Booking::TYPE, Kaleta\Builder\Build::disabledTypes(['novinky', 'poptavky']), true), in_array(Kaleta\Builder\Elements\Booking::TYPE, Kaleta\Builder\Build::disabledTypes(['bookings']), true),
    in_array('0073-feature-defaults', Kaleta\Core\Migration::DATA, true),
    // 0073 names the features itself (the update request of the release before runs it with its own classes): same keys, same order
    (function (): bool { preg_match('/\$order = \[([^\]]+)\]/', (string) file_get_contents(KALETA_SYSTEM . '/sql/migrace/0073-feature-defaults.php'), $m); $order = array_map(fn (string $k): string => trim($k, " '"), explode(',', $m[1] ?? ''));
        return count($order) > 9 && $order === array_values(array_intersect(array_keys(Kaleta\Core\Extensions::CATALOG), $order)) && array_diff(['bookings', 'whistleblowing', 'statistika'], $order) === []; })()],
    [false, false, ['statistika'], 'bookings', 'whistleblowing', 'bookings', true, false, true, true]);
check('[source-pin] 3.2: the public booking pages, reminders and MCP tools follow the Bookings feature; the whistleblowing channel needs the feature and its own switch; no MCP tool is gated', [
    Kaleta\Core\Booking::isOn($reportSettings(['extensions' => 'novinky,claude'])), Kaleta\Core\Booking::isOn($reportSettings(['extensions' => 'novinky,bookings'])),
    Kaleta\Core\Whistleblowing::isOn($reportSettings(['extensions' => 'claude', 'whistleblowing_enabled' => '1'])),
    Kaleta\Core\Whistleblowing::isOn($reportSettings(['extensions' => 'whistleblowing', 'whistleblowing_enabled' => '0'])),
    Kaleta\Core\Whistleblowing::isOn($reportSettings(['extensions' => 'whistleblowing', 'whistleblowing_enabled' => '1'])),
    array_values(array_unique(array_map(fn (string $tool): string => Kaleta\Mcp\Catalog::TOOLS[$tool][1], ['list_bookings', 'booking_availability', 'save_booking_service', 'save_booking_staff', 'cancel_booking', 'confirm_booking', 'decline_booking', 'propose_booking_times']))),
    substr_count((string) file_get_contents(KALETA_SYSTEM . '/src/Mcp/Handlers/BookingTools.php'), '$this->requireBookings();')],
    [false, true, false, false, true, [''], 6]);
check('[source-pin] 3.2: Statistics have one switch – the feature; the old setting is not read, not saved by the Analytics tab and still accepted over MCP', [
    Kaleta\Front\Stats::enabled($reportSettings(['extensions' => 'statistika', 'stats' => '0'])), Kaleta\Front\Stats::enabled($reportSettings(['extensions' => 'novinky,claude', 'stats' => '1'])),
    Kaleta\Admin\Modules\Settings::verifyValue('stats', '1'), str_contains((string) file_get_contents(KALETA_SYSTEM . '/views/admin/settings/analytics.php'), "\$field('stats'"),
    (bool) preg_match((new ReflectionClassConstant(Kaleta\Mcp\Tools::class, 'MCP_SETTINGS'))->getValue(), 'stats'), isset(Kaleta\Core\Settings::DEFAULTS['stats'])],
    [true, false, null, false, true, true]);

