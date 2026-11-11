<?php

declare(strict_types=1);

/* ---------- 3.34 review M3: migration 0120 moves in bounded runs, each row on its own, the customer clean-up from the data ---------- */
$m3Migration = (string) file_get_contents(KALETA_SYSTEM . '/sql/migrace/0120-applications-move.php');
$m3Runner = (string) file_get_contents(KALETA_SYSTEM . '/src/Core/Migration.php');
check('3.34 review M3: every row in its own try/catch and transaction, a failure counted by number only; the run stops after its time and says so', [
    str_contains($m3Migration, "} catch (\\Throwable \$error) {\n                if (!(\$error instanceof \\UnexpectedValueException && \$error->getMessage() === 'gone')) {\n                    \$failed[] = \$idp;"), // F6: a row deleted meanwhile is no failure
    str_contains($m3Migration, "\$db->transaction(function () use (\$db, \$e, \$idp,"),
    str_contains($m3Migration, "microtime(true) - \$started > \$seconds"),
    str_contains($m3Migration, "'applications.move_failed', 'warning'"),
    str_contains($m3Migration, '$finish($left);'),
    str_contains($m3Migration, '$contacts[(int) $e[\'contact_id\']] = true;')],
    [true, true, true, true, true, false]);
check('3.34 review M3: the customer clean-up comes from the data (source_contact_id), never edited, merged (an activity) or owned records', [
    str_contains($m3Migration, "'source_contact_id' => \$e['contact_id'] !== null ? (int) \$e['contact_id'] : null"),
    str_contains($m3Migration, 'SELECT DISTINCT source_contact_id AS id FROM {applications} WHERE source_contact_id > ? ORDER BY source_contact_id LIMIT '),
    str_contains($m3Migration, 'owner_id IS NULL AND updated_at <= created_at'),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Core/ApplicationMove.php'), 'public static function forgetLoneContacts(Db $db): int'),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/sql/schema.sql'), 'source_contact_id INT UNSIGNED NULL'),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/sql/migrace/0119-applications.sql'), 'source_contact_id INT UNSIGNED NULL')],
    [true, true, true, true, true, true]);
check('3.34 review M3: Core\Migration runs an unfinished data migration again on the next request, without waiting for the lock', [
    str_contains($m3Runner, "|| \$settings->get('data_migrations_unfinished') !== '';"),
    str_contains($m3Runner, "(!in_array(\$name, \$done, true) || in_array(\$name, \$unfinished, true))"),
    str_contains($m3Runner, "'SELECT GET_LOCK(?, ' . (self::onlyUnfinished(\$db) ? 0 : 15) . ')'"),
    array_key_exists('data_migrations_unfinished', Kaleta\Core\Settings::DEFAULTS), array_key_exists('applications_move_until', Kaleta\Core\Settings::DEFAULTS)],
    [true, true, true, true, true]);
check('3.34 review M3: while moving, the enquiries still to move stay out of Enquiries (list, detail, counts, the dashboard, MCP) for a person without Applications', [
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Modules/Enquiries.php'), "\$whereParts = 'WHERE ' . implode(' AND ', \$conditions) . ' AND ' . \$this->visibleHere();"),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Modules/Enquiries.php'), "\$p = \$this->findVisible(\$this->request->getInt('id'), '*');"),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Modules/Enquiries.php'), "self::NOT_SPAM . self::stillMoving(\$db)"),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Kernel.php'), 'Modules\Enquiries::visible($db, $this->app->settings(), isset($modules[\Kaleta\Core\Applications::SECTION]))'),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Mcp/Handlers/EnquiryAndPopupTools.php'), '$whereParts[] = \Kaleta\Admin\Modules\Enquiries::visible($db, $siteSettings, $auth->hasModule(\Kaleta\Core\Applications::SECTION));'),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/views/admin/applications/list.php'), 'data-applications-moving')],
    [true, true, true, true, true, true]);
