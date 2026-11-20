<?php

declare(strict_types=1);

use Kaleta\Core\Applications;
use Kaleta\Core\FileLink;

/* ---------- 3.46.2: download links in the notification e-mails of forms (Core\FileLink) – the signature helper ---------- */

$u4240Key = str_repeat('k', 40);
$u4240Now = 1_800_000_000;
$u4240Sig = FileLink::signature($u4240Key, 'applications', 12, 0, $u4240Now + 3600);
check('3.46.2: a link\'s signature binds the section, the record, the file and the expiry', [
    strlen($u4240Sig), $u4240Sig === FileLink::signature($u4240Key, 'applications', 12, 0, $u4240Now + 3600),
    $u4240Sig === FileLink::signature($u4240Key, 'enquiries', 12, 0, $u4240Now + 3600), $u4240Sig === FileLink::signature($u4240Key, 'applications', 13, 0, $u4240Now + 3600),
    $u4240Sig === FileLink::signature($u4240Key, 'applications', 12, 1, $u4240Now + 3600), $u4240Sig === FileLink::signature($u4240Key, 'applications', 12, 0, $u4240Now + 3601),
    $u4240Sig === FileLink::signature(str_repeat('x', 40), 'applications', 12, 0, $u4240Now + 3600),
], [64, true, false, false, false, false, false]);
check('3.46.2: check() – a true link is ok until its day, then expired; a changed record, file, expiry, signature, section or key is invalid', [
    FileLink::check($u4240Key, 'applications', 12, 0, $u4240Now + 3600, $u4240Sig, $u4240Now),
    FileLink::check($u4240Key, 'applications', 12, 0, $u4240Now + 3600, $u4240Sig, $u4240Now + 3601),
    FileLink::check($u4240Key, 'applications', 13, 0, $u4240Now + 3600, $u4240Sig, $u4240Now),
    FileLink::check($u4240Key, 'applications', 12, 1, $u4240Now + 3600, $u4240Sig, $u4240Now),
    FileLink::check($u4240Key, 'applications', 12, 0, $u4240Now + 999999, $u4240Sig, $u4240Now), // a later expiry typed in
    FileLink::check($u4240Key, 'applications', 12, 0, $u4240Now + 3600, strrev($u4240Sig), $u4240Now),
    FileLink::check($u4240Key, 'enquiries', 12, 0, $u4240Now + 3600, $u4240Sig, $u4240Now),
    FileLink::check($u4240Key, 'settings', 12, 0, $u4240Now + 3600, FileLink::signature($u4240Key, 'settings', 12, 0, $u4240Now + 3600), $u4240Now),
    FileLink::check('', 'applications', 12, 0, $u4240Now + 3600, FileLink::signature('', 'applications', 12, 0, $u4240Now + 3600), $u4240Now),
    FileLink::check($u4240Key, 'applications', 12, 0, $u4240Now - 10, FileLink::signature($u4240Key, 'applications', 12, 0, $u4240Now - 10), $u4240Now),
], ['ok', 'expired', 'invalid', 'invalid', 'invalid', 'invalid', 'invalid', 'invalid', 'invalid', 'expired']);

$u4240Path = FileLink::path($u4240Key, 'enquiries', 7, 3, $u4240Now);
parse_str((string) parse_url($u4240Path, PHP_URL_QUERY), $u4240Query);
check('3.46.2: the path goes to the existing download of the section (enquiries: field, applications: index) and rebuilds from its own query only', [
    str_starts_with($u4240Path, 'admin.php?module=enquiries&action=attachment&id=7&field=3&expires=' . $u4240Now . '&sig='),
    str_contains(FileLink::path($u4240Key, 'applications', 7, 3, $u4240Now), '&index=3&'),
    FileLink::fromQuery($u4240Query) === $u4240Path, FileLink::fromQuery($u4240Query + ['back' => 'https://evil.example/']) === $u4240Path,
    FileLink::fromQuery(['module' => 'settings'] + $u4240Query), FileLink::fromQuery(['action' => 'detail'] + $u4240Query),
    FileLink::fromQuery(['sig' => 'not-hex'] + $u4240Query), FileLink::fromQuery(['id' => '7 OR 1'] + $u4240Query), FileLink::fromQuery(['module' => ['enquiries']] + $u4240Query),
    FileLink::fromQuery(array_diff_key($u4240Query, ['expires' => 1])),
], [true, true, true, true, null, null, null, null, null, null]);
check('3.46.2: a downloaded file gets a neutral name – the kind, the record and the file number, the extension of the original', [
    FileLink::neutralName('application', 12, 0, 'CV Jana Nováková.PDF'), FileLink::neutralName('enquiry', 7, 3, '2026/11/abc.docx'), FileLink::neutralName('enquiry', 7, 0, 'noextension'),
    FileLink::neutralName('application', 1, 1, 'x.ph p'),
], ['application-12-1.pdf', 'enquiry-7-4.docx', 'enquiry-7-1', 'application-1-2']);

$u4240Text = Kaleta\Core\Language::runWith('en', fn (): string => FileLink::text([['label' => 'CV', 'name' => 'cv.pdf', 'url' => 'https://example.com/admin.php?x=1'], ['label' => '', 'name' => 'b.docx', 'url' => 'https://example.com/y']]));
check('3.46.2: the e-mail lists each file with its link and says the links need signing in and expire; no files = no lines', [
    $u4240Text, FileLink::text([]), FileLink::DAYS,
], ["Uploaded files:\nCV: cv.pdf\nhttps://example.com/admin.php?x=1\n\nb.docx\nhttps://example.com/y\nThe links work for 30 days and only for someone signed in to the administration with access to the record.", '', 30]);

$u4240Settings = (new ReflectionClass(Kaleta\Core\Settings::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(Kaleta\Core\Settings::class, 'values'))->setValue($u4240Settings, ['site_name' => 'Hartsteel']);
[, $u4240Mail] = Kaleta\Core\Language::runWith('en', fn (): array => Applications::notification($u4240Settings, 31, 'Welder', [], 'https://example.com/admin.php?module=applications&action=detail&id=31', 'Uploaded files:\nCV: cv.pdf'));
[, $u4240Plain] = Kaleta\Core\Language::runWith('en', fn (): array => Applications::notification($u4240Settings, 31, 'Welder', [], 'https://example.com/r'));
check('3.46.2: the application notification puts the file links before the link of the record; without files it is as before', [
    strpos($u4240Mail, 'Uploaded files') < strpos($u4240Mail, 'Open it in the administration'), str_contains($u4240Plain, 'Uploaded files'),
], [true, false]);
