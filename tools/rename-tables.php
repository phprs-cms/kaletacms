<?php
/**
 * Kaleta – renames the tables of an installation as Kaleta 4.0 will (3.15; docs/design/4.0-data-model.md §4, option C):
 * one atomic RENAME TABLE of every table in system/sql/names.php whose English name differs – or back to today's names.
 * For tests and the rollback drill of 4.0, never for a live site: tools/test.sh renames a test site, proves that 3.15
 * runs on the English names (front, admin, sign-in, MCP), renames it back and proves it again.
 *
 *   php tools/rename-tables.php <site folder> english     ka_novinky → ka_news, ka_stranky → ka_pages …
 *   php tools/rename-tables.php <site folder> today       and back
 *
 * The marker the site keeps (Core\Db, storage/cache/tables-english-*) is left alone on purpose: the site must find out by
 * itself, as it does after a rollback from 4.0.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('Command line only.');
}
[, $site, $to] = $argv + [1 => '', 2 => ''];
if (!is_file($site . '/system/bootstrap.php') || !is_file($site . '/config.php') || !in_array($to, ['english', 'today'], true)) {
    fwrite(STDERR, "Usage: php tools/rename-tables.php <site folder> english|today\n");
    exit(2);
}
require $site . '/system/bootstrap.php';

$db = Kaleta\Core\App::boot()->db();
$pdo = $db->pdo();
$stmt = $pdo->prepare('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE ?');
$stmt->execute([addcslashes($db->prefix, '_%') . '%']);
$existing = array_flip(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
$pairs = [];
foreach (Kaleta\Core\Names::map() as $today => [$english]) {
    [$from, $target] = $to === 'english' ? [$today, $english] : [$english, $today];
    if ($from !== $target && isset($existing[$db->prefix . $from])) {
        if (isset($existing[$db->prefix . $target])) {
            fwrite(STDERR, "Both {$db->prefix}{$from} and {$db->prefix}{$target} exist – nothing renamed.\n");
            exit(1);
        }
        $pairs[] = '`' . $db->prefix . $from . '` TO `' . $db->prefix . $target . '`';
    }
}
if ($pairs !== []) {
    $pdo->exec('RENAME TABLE ' . implode(', ', $pairs)); // one statement: all tables or none
}
echo count($pairs) . ' tables renamed to ' . ($to === 'english' ? 'their English names' : "today's names") . "\n";
