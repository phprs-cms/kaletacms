<?php

declare(strict_types=1);

// 3.35 review L5: the address match ignores the no-break space, the other Unicode spaces and letter case; a merge note is
// recognised by its stable marker (ka_contact_activities.merged_email), older notes still by the wordings of every language
use Kaleta\Core\Contacts;
use Kaleta\Core\PersonalData;

$r358Nbsp = "\u{00A0}";
check('3.35 review L5: cleanAddress and normalise – no space of any kind (no-break, narrow, zero-width, ideographic), lower case', [
    PersonalData::cleanAddress($r358Nbsp . 'Jane@Example.COM' . "\u{202F}"), PersonalData::cleanAddress("\u{200B}jane@example.com\u{FEFF}\u{3000}"),
    PersonalData::normalise($r358Nbsp . 'JANE@example.com '), PersonalData::normalise('not an address'),
], ['jane@example.com', 'jane@example.com', 'jane@example.com', null]);
check('3.35 review L5: ofEnquiry – the sender or a whole field with Unicode spaces and another case is the person\'s; a quote is not', [
    PersonalData::ofEnquiry($r358Nbsp . 'Jane@Example.com' . $r358Nbsp, '[]', 'jane@example.com'),
    PersonalData::ofEnquiry('bob@example.com', (string) json_encode([['Colleague', "\u{2009}JANE@example.com"]]), 'jane@example.com'),
    PersonalData::ofEnquiry('bob@example.com', (string) json_encode([['Message', 'write to jane@example.com']]), 'jane@example.com'),
    PersonalData::ofEnquiry('mary.jane@example.com', '[]', 'jane@example.com'),
], [true, true, false, false]);
check('3.35 review L5: older merge notes in any shipped language, with a no-break space or upper case, still name exactly the address', [
    Contacts::mergeNoteNames('Merged with the contact #12 (' . $r358Nbsp . 'JANE@example.com).', 'jane@example.com'),
    Contacts::mergeNoteNames(Kaleta\Core\Language::runWith('de', fn (): string => t('Merged with the contact #%d (%s).', 4, 'Jane@Example.com'), 'admin-'), 'jane@example.com'),
    Contacts::mergeNoteNames(Kaleta\Core\Language::runWith('es', fn (): string => t('Merged with the contact #%d (%s).', 4, 'jane@example.com'), 'admin-'), 'jane@example.com'),
    Contacts::mergeNoteNames('Merged with the contact #12 (xjane@example.com).', 'jane@example.com'),
], [true, true, true, false]);
$r358Contacts = (string) file_get_contents(KALETA_SYSTEM . '/src/Core/Contacts.php');
check('3.35 review L5: a merge stores the marker, a request finds it, anonymising clears it; the schema and migration 0121 have the column', [
    str_contains($r358Contacts, "\$db->run('UPDATE {contact_activities} SET merged_email = ? WHERE id = ?', [\$merged, \$note]);"),
    str_contains($r358Contacts, "SELECT contact_id FROM {contact_activities} WHERE merged_email = ?"),
    str_contains($r358Contacts, "SET merged_email = '' WHERE contact_id IN ("),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/sql/schema.sql'), "merged_email VARCHAR(190) NOT NULL DEFAULT ''"),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/sql/migrace/0121-passkey-sign-in.sql'), "ALTER TABLE ka_contact_activities ADD COLUMN merged_email VARCHAR(190) NOT NULL DEFAULT '';"),
], [true, true, true, true, true]);
