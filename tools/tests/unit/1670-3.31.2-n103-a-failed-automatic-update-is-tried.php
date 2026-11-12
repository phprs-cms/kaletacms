<?php

declare(strict_types=1);

/* ---------- 3.31.2 (N103): a failed automatic update is tried again – bounded, with a growing wait ---------- */
use Kaleta\Core\Updater as U3312;
check('3.31.2 N103: update_attempt – a plain version is done with, "<version>|<tries>|<next>" waits for another try; anything else reads as a version', [
    U3312::attempt(''), U3312::attempt('3.31.3'), U3312::attempt('3.31.3|1|1800000000'), U3312::attempt('3.31.3|x|y')['final'], U3312::attempt('3.31.3|1')['version']],
    [['version' => '', 'tries' => 0, 'next' => 0, 'final' => false], ['version' => '3.31.3', 'tries' => 0, 'next' => 0, 'final' => true],
        ['version' => '3.31.3', 'tries' => 1, 'next' => 1800000000, 'final' => false], true, '3.31.3|1']);
check('3.31.2 N103: three tries – an hour after the first failure, twelve after the second, the third is final; an announcement (nothing installed) is final at once', [
    U3312::RETRY_DELAYS, U3312::attemptAfter('3.31.3', 1, true, 1000), U3312::attemptAfter('3.31.3', 2, true, 1000), U3312::attemptAfter('3.31.3', 3, true, 1000), U3312::attemptAfter('3.31.3', 1, false, 1000),
    U3312::attempt(U3312::attemptAfter('3.31.3', 2, true, 1000))['tries']],
    [[3600, 43200], '3.31.3|1|4600', '3.31.3|2|44200', '3.31.3', '3.31.3', 2]);
$u3312 = (string) file_get_contents(KALETA_SYSTEM . '/src/Core/Updater.php');
$u3312Run = substr($u3312, (int) strpos($u3312, 'public static function runInBackground('), 7000);
check('[source-pin] 3.31.2 N103: the background update takes a database lock, re-reads the attempt under it, and install() no longer marks a version as done', [
    str_contains($u3312Run, "\$db->value('SELECT GET_LOCK(?, 0)', [\$lock])"), str_contains($u3312Run, "\$db->value('SELECT RELEASE_LOCK(?)', [\$lock]);"),
    strpos($u3312Run, 'GET_LOCK') < strpos($u3312Run, "\$s->set('update_attempt', \$stored);"), strpos($u3312Run, "SELECT hodnota FROM {nastaveni} WHERE promenna = ?") < strpos($u3312Run, "\$s->set('update_attempt', \$stored);"),
    substr_count($u3312, "set('update_attempt'"), str_contains($u3312, "self::BUSY);"), str_contains($u3312Run, '$e->getCode() === self::BUSY')],
    [true, true, true, true, 3, true, true]);
// the check after an update asks a 502/503/504 address again (a busy proxy) before the answer counts; a 500 counts at once
$u3312Ask = function (array $answers): array {
    $asked = 0;
    $fetch = U3312::askingAgain(function (string $url) use (&$asked, &$answers): array {
        $asked++;

        return count($answers) > 1 ? array_shift($answers) : $answers[0];
    }, 0);

    return [$fetch('https://site.example/')[0], $asked];
};
check('[source-pin] 3.31.2 N103: the check after an update asks again when a proxy answers 502/503/504 – at most twice more; a 200, a 500 or a 403 counts at once; probe() uses it', [
    $u3312Ask([[200, 'ok']]), $u3312Ask([[503, ''], [200, 'ok']]), $u3312Ask([[502, ''], [504, ''], [200, 'ok']]), $u3312Ask([[502, '']]), $u3312Ask([[500, 'Fatal'], [200, 'ok']]), $u3312Ask([[403, ''], [200, 'ok']]),
    str_contains($u3312, '$fetch = self::askingAgain($fetch, $this->probeFetch === null ? self::PROBE_WAIT : 0);')],
    [[200, 1], [200, 2], [200, 3], [502, 3], [500, 1], [403, 1], true]);
$u3312Texts = ['That was the last automatic attempt (%d of %d) – the update now waits for an administrator.', 'Kaleta will try again by itself – this was attempt %d of %d.'];
check('3.31.2 N103: the texts of the e-mail about a failed try are translated (cs, de, es)', array_values(array_filter($u3312Texts, fn (string $k): bool => !isset($adminCs[$k], $adminDe[$k], $a3312Es[$k]))), []);

