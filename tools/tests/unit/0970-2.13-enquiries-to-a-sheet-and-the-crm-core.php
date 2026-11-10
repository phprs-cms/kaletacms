<?php

declare(strict_types=1);

/* ---------- 2.13: enquiries to a sheet and the CRM (Core\EnquiryDelivery, EnquirySheet, EnquiryCrm) ---------- */
$f13Fields = Kaleta\Core\EnquiryDelivery::fields([['Firma', 'Acme s.r.o.'], ['Jméno a příjmení', 'Jan Novák'], ['E-mail', 'jan@example.cz'], ['Telefon', '+420 777 123 456'], ['Zpráva', "Chci kuchyň.\nDo léta."], ['Souhlas', 'ano'], ['CV', 'cv.pdf (12 kB)', '2026/09/abc.pdf']],
    ['text', 'text', 'email', 'tel', 'textarea', 'souhlas', 'soubor']);
$f13Lead = Kaleta\Core\EnquiryDelivery::lead($f13Fields, 'jan@example.cz');
check('2.13 EnquiryDelivery::lead – the e-mail, the phone, the name by its label, the company, the rest as text without the consent and never the attachment path',
    [$f13Lead, $f13Fields[6]], [['name' => 'Jan Novák', 'email' => 'jan@example.cz', 'phone' => '+420 777 123 456', 'company' => 'Acme s.r.o.', 'text' => "Zpráva: Chci kuchyň.\nDo léta.\nCV: cv.pdf (12 kB)"], ['CV', 'cv.pdf (12 kB)', 'soubor']]);
check('2.13 EnquiryDelivery::lead – without a name label the first text field is the name; an empty e-mail field keeps the enquiry e-mail',
    Kaleta\Core\EnquiryDelivery::lead([['Kdo', 'Eva', 'text'], ['Město', 'Brno', 'text'], ['Mail', '', 'email']], 'eva@example.cz'), ['name' => 'Eva', 'email' => 'eva@example.cz', 'phone' => '', 'company' => '', 'text' => 'Město: Brno']);
check('2.13 EnquiryDelivery: names split at the last space, the forms list is matched by name, case and spaces aside', [
    Kaleta\Core\EnquiryDelivery::splitName('Jan Maria Novák'), Kaleta\Core\EnquiryDelivery::splitName('Novák'), Kaleta\Core\EnquiryDelivery::splitName(''),
    Kaleta\Core\EnquiryDelivery::formWanted('', 'Poptávka'), Kaleta\Core\EnquiryDelivery::formWanted('Kontakt, poptávka ', 'Poptávka'), Kaleta\Core\EnquiryDelivery::formWanted('Kontakt', 'Poptávka'),
    Kaleta\Core\EnquiryDelivery::title(['form' => 'Poptávka', 'topic' => 'Kuchyně']), Kaleta\Core\EnquiryDelivery::title(['form' => 'Poptávka', 'topic' => ''])],
    [['Jan Maria', 'Novák'], ['', 'Novák'], ['', ''], true, true, false, 'Poptávka – Kuchyně', 'Poptávka']);
$f13Payload = ['service' => 'google', 'enquiry' => 5, 'date' => '2026-10-03 10:00', 'form' => 'Poptávka', 'topic' => 'Kuchyně', 'email' => 'jan@example.cz', 'page' => 'https://example.cz/kontakt', 'fields' => $f13Fields];
check('2.13 EnquirySheet: the create body carries the title and a bold frozen header, a row has the fixed columns then every other field in its own cell', [
    Kaleta\Core\EnquirySheet::createBody('Acme – enquiries', Kaleta\Core\EnquirySheet::COLUMNS)['properties'], Kaleta\Core\EnquirySheet::createBody('A', ['Date', 'Form'])['sheets'][0]['properties'],
    array_column(array_column(Kaleta\Core\EnquirySheet::createBody('A', ['Date', 'Form'])['sheets'][0]['data'][0]['rowData'][0]['values'], 'userEnteredValue'), 'stringValue'),
    Kaleta\Core\EnquirySheet::appendBody($f13Payload)],
    [['title' => 'Acme – enquiries'], ['title' => 'Form', 'gridProperties' => ['frozenRowCount' => 1]], ['Date', 'Form'],
        ['values' => [['2026-10-03 10:00', 'Poptávka', 'Kuchyně', 'jan@example.cz', 'https://example.cz/kontakt', 'Jan Novák', '+420 777 123 456', 'Zpráva: Chci kuchyň.', 'Do léta.', 'CV: cv.pdf (12 kB)']]]]);
check('2.13 HubSpot bodies: the search by e-mail, the contact with only the filled properties, the note associated to the contact', [
    Kaleta\Connectors\HubSpot::searchBody('jan@example.cz')['filterGroups'][0]['filters'][0], Kaleta\Connectors\HubSpot::contactBody($f13Lead), Kaleta\Connectors\HubSpot::contactBody(['name' => 'Eva', 'email' => '', 'phone' => '', 'company' => '']),
    Kaleta\Connectors\HubSpot::noteBody('777', 'Text', 1700000000)],
    [['propertyName' => 'email', 'operator' => 'EQ', 'value' => 'jan@example.cz'], ['properties' => ['email' => 'jan@example.cz', 'firstname' => 'Jan', 'lastname' => 'Novák', 'phone' => '+420 777 123 456', 'company' => 'Acme s.r.o.']], ['properties' => ['lastname' => 'Eva']],
        ['properties' => ['hs_timestamp' => '1700000000000', 'hs_note_body' => 'Text'], 'associations' => [['to' => ['id' => '777'], 'types' => [['associationCategory' => 'HUBSPOT_DEFINED', 'associationTypeId' => 202]]]]]]);
check('2.13 Pipedrive: the API of the company domain (nothing else is an address), the person with primary e-mail and phone, the lead and its note', [
    Kaleta\Connectors\Pipedrive::api('Acme-1'), Kaleta\Connectors\Pipedrive::api('evil.example.com'), Kaleta\Connectors\Pipedrive::api(''), Kaleta\Connectors\Pipedrive::authHeaders('x', ''),
    Kaleta\Connectors\Pipedrive::personBody($f13Lead), Kaleta\Connectors\Pipedrive::personBody(['name' => 'Eva', 'email' => '', 'phone' => '']), Kaleta\Connectors\Pipedrive::leadBody('Poptávka – Kuchyně', 42), Kaleta\Connectors\Pipedrive::noteBody('Text', 'lead-1', 42)],
    ['https://acme-1.pipedrive.com/api/v1', null, null, [], ['name' => 'Jan Novák', 'email' => [['value' => 'jan@example.cz', 'primary' => true]], 'phone' => [['value' => '+420 777 123 456', 'primary' => true]]], ['name' => 'Eva'],
        ['title' => 'Poptávka – Kuchyně', 'person_id' => 42], ['content' => 'Text', 'lead_id' => 'lead-1', 'person_id' => 42]]);
check('2.13 Raynet: HTTP Basic from the user and the key, the lead with the contact and the notice, empty parts left out', [
    Kaleta\Connectors\Raynet::authHeaders('rn-key', 'user@example.cz'), Kaleta\Connectors\Raynet::leadBody('Poptávka – Kuchyně', $f13Lead, 'Text'), Kaleta\Connectors\Raynet::leadBody('Poptávka', ['name' => 'Eva', 'email' => '', 'phone' => '', 'company' => ''], '')],
    [['Authorization' => 'Basic ' . base64_encode('user@example.cz:rn-key')], ['topic' => 'Poptávka – Kuchyně', 'firstName' => 'Jan', 'lastName' => 'Novák', 'companyName' => 'Acme s.r.o.', 'contactInfo' => ['email' => 'jan@example.cz', 'tel1' => '+420 777 123 456'], 'notice' => 'Text'],
        ['topic' => 'Poptávka', 'lastName' => 'Eva']]);
check('2.13 Connectors: the three CRMs are in the curated list with the enquiry switch in their settings; the queue prefixes sheets and crm have their handlers', [
    array_map(fn (string $c): string => $c::KEY, Kaleta\Core\Connectors::SERVICES), array_map(fn (string $c): bool => isset($c::settings()['enquiries']) && $c::settings()['enquiries'][2] === 'check', Kaleta\Core\Connectors::SERVICES),
    Kaleta\Core\Connectors::handler('sheets.append'), Kaleta\Core\Connectors::handler('crm.lead'), Kaleta\Core\Connectors::handler('other.x')],
    [['google', 'bing', 'hubspot', 'pipedrive', 'raynet'], [true, false, true, true, true], Kaleta\Core\EnquirySheet::class, Kaleta\Core\EnquiryCrm::class, null]);
