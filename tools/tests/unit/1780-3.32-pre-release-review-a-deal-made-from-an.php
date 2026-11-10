<?php

declare(strict_types=1);

/* ---------- 3.32 (pre-release review): a deal made from an enquiry keeps nothing of the person once the enquiry goes ---------- */
$r332E = ['formular' => 'Contact', 'tema' => 'Contact', 'zdroj' => 'stranka:1', 'email' => 'olivia@hartsteel.example',
    'data' => json_encode([['Name', 'Olivia Hart'], ['Company', 'Hartsteel Ltd'], ['Message', 'We need forty custom brackets for a new hall in March']])];
$r332Item = ['tema' => 'Bracket BX-40', 'zdroj' => 'kolekce:3'] + $r332E;
$r332Generated = Kaleta\Core\RecordTitle::enquiry($r332E);
check('3.32: a deal title that is the generated one, names who wrote (name, company, e-mail) or quotes the message becomes "#<id> – <topic>"; a neutral one stays; every path that deletes or anonymises an enquiry retitles first', [
    $r332Generated, Kaleta\Core\Deals::neutralTitle(7, $r332Generated, $r332E), Kaleta\Core\Deals::neutralTitle(7, Kaleta\Core\RecordTitle::enquiry($r332Item), $r332Item),
    Kaleta\Core\Deals::neutralTitle(7, 'Hartsteel Ltd – yearly order', $r332E), Kaleta\Core\Deals::neutralTitle(7, 'Call olivia@hartsteel.example back', $r332E),
    Kaleta\Core\Deals::neutralTitle(7, 'Hart, Olivia: hall', $r332E), Kaleta\Core\Deals::neutralTitle(7, 'Brackets: we need forty custom brackets for', $r332E),
    Kaleta\Core\Deals::neutralTitle(7, 'Brackets for the hall', $r332E), Kaleta\Core\Deals::neutralTitle(7, '#7 – Contact', $r332E), Kaleta\Core\Deals::neutralTitle(7, 'Olivia Hart', []),
    Kaleta\Core\Deals::neutralTitle(7, 'Olivia Hart', ['tema' => '', 'formular' => 'Contact', 'email' => '', 'data' => '[]'] + ['anonymizovano' => null]),
    Kaleta\Core\Deals::neutralTitle(7, 'Olivia Hart deal', ['anonymizovano' => '2026-01-01 00:00:00'] + $r332E),
    array_map(fn (string $f): int => substr_count((string) file_get_contents(KALETA_SYSTEM . $f), 'Deals::forgetEnquiries('), ['/src/Admin/Modules/Enquiries.php', '/src/Core/Jobs.php', '/src/Core/PersonalData.php', '/src/Mcp/Handlers/EnquiryAndPopupTools.php']),
    array_map(fn (string $f): bool => (bool) preg_match('/Deals::forgetEnquiries\([^\n]*\n[^\n]*(DELETE FROM \{poptavky\}|delete\(\'poptavky\'|foreach \(array_column\(\$found)/', (string) file_get_contents(KALETA_SYSTEM . $f)), ['/src/Admin/Modules/Enquiries.php', '/src/Core/Jobs.php', '/src/Core/PersonalData.php', '/src/Mcp/Handlers/EnquiryAndPopupTools.php']),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Core/Privacy.php'), "Deals::forgetEnquiryWords(\$db, array_map('intval', array_column(\$rows, 'idp')));")],
    ['Olivia Hart – We need forty custom brackets for…', '#7 – Contact', '#7 – Bracket BX-40', '#7 – Contact', '#7 – Contact', '#7 – Contact', '#7 – Contact', null, null, null, null, '#7 – Contact',
        [3, 1, 1, 1], [true, true, true, true], true]);

