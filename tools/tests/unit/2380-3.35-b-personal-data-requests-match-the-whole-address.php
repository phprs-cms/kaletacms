<?php

declare(strict_types=1);

use Kaleta\Core\Contacts;
use Kaleta\Core\PersonalData;

/* ---------- 3.35 B5 (N94, open since 3.28): personal-data requests match the whole address only ---------- */
check('3.35 B5 N94: an enquiry is the person\'s when it came from the address or a field holds exactly it – never a mention or a longer address', [
    PersonalData::ofEnquiry('Jane@Acme.com ', '[]', 'jane@acme.com'),
    PersonalData::ofEnquiry('bob@acme.com', '[["Colleague e-mail"," JANE@acme.com"]]', 'jane@acme.com'),
    PersonalData::ofEnquiry('carl@acme.com', '[["Message","please cc jane@acme.com"]]', 'jane@acme.com'),
    PersonalData::ofEnquiry('mary.jane@acme.com', '[["E-mail","mary.jane@acme.com"]]', 'jane@acme.com'),
    PersonalData::ofEnquiry('jo@x.cz', '[["E-mail","jo@x.cz"]]', 'o@x.cz'),
    PersonalData::ofEnquiry('a@b.cz', 'not json', 'jane@acme.com'), PersonalData::ofEnquiry('', '[["Pick",["jane@acme.com"]]]', 'jane@acme.com'),
], [true, true, false, false, false, false, false]);
check('3.35 B5 N94: only Kaleta\'s merge note names a merged address – in every admin language, with the whole address', [
    Contacts::mergeNoteNames('Merged with the contact #12 (Jane@Acme.com).', 'jane@acme.com'),
    Contacts::mergeNoteNames('Sloučeno s kontaktem #12 (jane@acme.com).', 'jane@acme.com'),
    Contacts::mergeNoteNames('Mit dem Kontakt #12 (jane@acme.com) zusammengeführt.', 'jane@acme.com'),
    Contacts::mergeNoteNames('Fusionado con el contacto #12 (jane@acme.com).', 'jane@acme.com'),
    Contacts::mergeNoteNames('Called them, cc\'d jane@acme.com.', 'jane@acme.com'),
    Contacts::mergeNoteNames('Merged with the contact #9 (jo@x.cz).', 'o@x.cz'),
    Contacts::mergeNoteNames('Merged with the contact #9 (jane@acme.com). And more text', 'jane@acme.com'),
], [true, true, true, true, false, false, false]);
$i335eContacts = (string) file_get_contents(KALETA_SYSTEM . '/src/Core/Contacts.php');
$i335ePersonal = (string) file_get_contents(KALETA_SYSTEM . '/src/Core/PersonalData.php');
check('[source-pin] 3.35 B5 N94: the LIKE of the activity log and of the enquiry fields only narrows rows – the exact rule decides', [
    str_contains($i335eContacts, 'if (self::mergeNoteNames((string) $row[\'text\'], $norm)) {'),
    str_contains($i335ePersonal, 'fn (array $r): bool => self::ofEnquiry((string) $r[\'email\'], (string) $r[\'data\'], $email)'),
], [true, true]);
