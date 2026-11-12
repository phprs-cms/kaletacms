<?php

declare(strict_types=1);

use Kaleta\Core\Applications;

/* ---------- 3.34 review M4: the automatic "received" e-mail carries no text the visitor typed – a plain name at most ---------- */
check('3.34 review M4: safeName lets through letters, spaces, hyphens and apostrophes up to 60 characters, nothing else', [
    Applications::safeName('Jana Nováková'), Applications::safeName("O'Brien-Smith"), Applications::safeName('  Ana   María '),
    Applications::safeName('Win a prize at http://spam.example'), Applications::safeName('Jan <b>'), Applications::safeName('Agent 007'),
    Applications::safeName(str_repeat('a', 61)), Applications::safeName('-dash'), Applications::safeName('')],
    ['Jana Nováková', "O'Brien-Smith", 'Ana María', '', '', '', '', '', '']);
$m4Settings = (new ReflectionClass(Kaleta\Core\Settings::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(Kaleta\Core\Settings::class, 'values'))->setValue($m4Settings, ['site_name' => 'Northfield', 'site_language' => 'en', 'company_name' => '']);
$m4Spam = ['name' => 'Cheap pills at spam.example – click', 'job_title' => 'Welder', 'language' => 'en'];
check('[source-pin] 3.34 review M4: the automatic compose drops a name that is not a plain name (a person replying still sees it); the position stays the job\'s', [
    str_starts_with(Applications::compose($m4Settings, $m4Spam, 'received', true)['text'], "Hello,\n\nthank you for your application for Welder."),
    str_contains(Applications::compose($m4Settings, $m4Spam, 'received', true)['text'], 'spam.example'),
    str_starts_with(Applications::compose($m4Settings, ['name' => 'Jana Nováková'] + $m4Spam, 'received', true)['text'], 'Hello Jana Nováková,'),
    str_contains(Applications::compose($m4Settings, $m4Spam, 'received')['text'], 'spam.example'),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Core/Applications.php'), "\$mail = self::compose(\$s, \$row, 'received', true);")],
    [true, false, true, true, true]);
