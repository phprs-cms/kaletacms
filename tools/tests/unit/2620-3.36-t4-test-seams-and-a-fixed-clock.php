<?php

declare(strict_types=1);

// 3.36 T4 (Q4, Q10): what exists only for the tests goes through Core\TestSeams, which answers "off" unless the test copy's
// config.php defines KALETA_TEST_SEAMS (the unit tests define it in bootstrap.php); and the site's clock can be fixed
use Kaleta\Core\Clock;
use Kaleta\Core\TestSeams;

/** @return list<string> file:line of the product code that reads a test switch itself instead of through TestSeams */
function t4_switch_readers(string $root): array
{
    $out = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/system/src', FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() !== 'php' || $file->getFilename() === 'TestSeams.php') {
            continue;
        }
        foreach (file($file->getPathname()) ?: [] as $n => $line) {
            // KALETA_UPDATE_PROBE=off is the host's switch (a server that cannot reach itself skips the update probe), no test seam
            if (preg_match("/getenv\\(\\s*'KALETA_(?!UPDATE_PROBE')|->get\\(\\s*'[a-z_]+_test_url'/", $line)) {
                $out[] = substr($file->getPathname(), strlen($root) + 1) . ':' . ($n + 1);
            }
        }
    }

    return $out;
}
check('3.36 T4 (Q10): no product file reads a KALETA_* test variable or a *_test_url setting itself – only Core\TestSeams', t4_switch_readers(KALETA_ROOT), []);
check('3.36 T4 (Q10): TestSeams knows the six switches and the three test addresses, nothing else', [array_keys(TestSeams::SWITCHES), TestSeams::ADDRESSES],
    [['KALETA_IMPORT_LOCAL', 'KALETA_LINKS_LOCAL', 'KALETA_FIREWALL_LOCAL', 'KALETA_FLEET_LOCAL', 'KALETA_CONNECTORS_FAKE', 'KALETA_CAPTCHA_VERIFY'], ['backup_test_url', 'newsletter_test_url', 'webhook_test_url']]);

// a real site (no KALETA_TEST_SEAMS in config.php): a variable set in the environment and a test address in the database change nothing
$t4Off = shell_exec('KALETA_FLEET_LOCAL=1 KALETA_CONNECTORS_FAKE=http://127.0.0.1:9 KALETA_LINKS_LOCAL=1 ' . escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg(
    'require ' . var_export(KALETA_SYSTEM . '/bootstrap.php', true) . '; $s = (new ReflectionClass(Kaleta\Core\Settings::class))->newInstanceWithoutConstructor();'
    . ' $p = new ReflectionProperty(Kaleta\Core\Settings::class, "values"); $p->setValue($s, ["webhook_test_url" => "http://127.0.0.1:9/x"]);'
    . ' $read = fn () => [Kaleta\Core\TestSeams::enabled(), Kaleta\Core\TestSeams::on("KALETA_FLEET_LOCAL"), Kaleta\Core\TestSeams::value("KALETA_CONNECTORS_FAKE"), Kaleta\Core\TestSeams::address($s, "webhook_test_url"),'
    . ' Kaleta\Fleet\Http::allowedUrl("http://127.0.0.1:9/heartbeat")];'
    . ' echo json_encode($read()); define("KALETA_TEST_SEAMS", true); echo json_encode($read());'));
check('3.36 T4 (Q10): without KALETA_TEST_SEAMS the switches are off whatever the environment and the settings say; with it they answer',
    $t4Off, '[false,false,"","",false][true,true,"http:\/\/127.0.0.1:9","http:\/\/127.0.0.1:9\/x",true]');

// the fixed clock: a moment just before the spring change of summer time in Prague
$t4Zone = date_default_timezone_get();
date_default_timezone_set('Europe/Prague');
Clock::freeze('2026-03-29 01:30:00');
$t4Spring = [Clock::now(), Clock::today(), Clock::at('+1 hour'), Clock::at('+1 day'), Clock::day('-1 day'), Clock::offset(), Clock::nowUtc(), Clock::toDb(Clock::time())];
Clock::freeze('2026-03-31 12:00:00');
$t4Month = [Clock::monthsAgo(1), Clock::day('+30 days')];
Clock::freeze('2026-10-25 01:30:00'); // the autumn change: summer time until 03:00, then winter time
$t4Autumn = [Clock::offset(), Clock::at('+1 hour'), Clock::day('+1 day')];
Clock::freeze('2026-10-25 12:00:00');
$t4Autumn[] = Clock::offset();
Clock::freeze(null);
$t4Running = abs(strtotime(Clock::now()) - time()) <= 1;
date_default_timezone_set($t4Zone);
check('3.36 T4 (Q4): Clock::freeze fixes now for every method – an hour over the spring change is 03:30, a day is a calendar day, days are dates, the offset follows the moment', $t4Spring,
    ['2026-03-29 01:30:00', '2026-03-29', '2026-03-29 03:30:00', '2026-03-30 01:30:00', '2026-03-28', '+01:00', '2026-03-29 00:30:00', '2026-03-29 01:30:00']);
check('3.36 T4 (Q4): … a month before 31 March is the last day of February, as MySQL counts it; thirty days are thirty dates', $t4Month, ['2026-02-28 12:00:00', '2026-04-30']);
check('3.36 T4 (Q4): … the autumn change: summer time in the night, winter time at noon of the same day (each database connection sets the offset of its moment)', $t4Autumn,
    ['+02:00', '2026-10-25 02:30:00', '2026-10-26', '+01:00']);
check('3.36 T4 (Q4): Clock::freeze(null) lets the clock run again', $t4Running, true);
check('3.36 T4 (Q4): a real site cannot fix the clock – without KALETA_TEST_SEAMS freeze() refuses',
    shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg('require ' . var_export(KALETA_SYSTEM . '/bootstrap.php', true) . '; try { Kaleta\Core\Clock::freeze("2026-01-01 00:00:00"); echo "fixed"; } catch (LogicException) { echo "refused"; }')),
    'refused');

// the background-job helper of the smoke test: a check never writes the clock of the background jobs itself
$t4Direct = [];
foreach (glob(KALETA_ROOT . '/tools/tests/smoke/[0-9][0-9][0-9][0-9]-*.sh') ?: [] as $t4File) {
    foreach (file($t4File) ?: [] as $t4N => $t4Line) {
        if (preg_match("/(promenna|setting)[^;|]*\\bnotification_check\\b|'notification_check',/", $t4Line) && !str_contains($t4Line, 'SELECT hodnota FROM ka_nastaveni WHERE promenna = \'notification_check\'')) {
            $t4Direct[] = basename($t4File) . ':' . ($t4N + 1);
        }
    }
}
check('3.36 T4 (Q4): smoke sections hold, resume, make due or settle the background jobs only through background_jobs (bootstrap.sh)', $t4Direct, []);
