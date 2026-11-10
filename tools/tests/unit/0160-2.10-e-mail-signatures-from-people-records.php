<?php

declare(strict_types=1);

/* ---------- 2.10: e-mail signatures from people records ---------- */
$signatureClass = Kaleta\Builder\EmailSignature::class;
$peopleFields = [['klic' => 'fotka', 'popisek' => 'Fotka', 'typ' => 'obrazek'], ['klic' => 'role', 'popisek' => 'Role', 'typ' => 'text'], ['klic' => 'jazyky', 'popisek' => 'Jazyky', 'typ' => 'text'],
    ['klic' => 'telefon', 'popisek' => 'Telefon', 'typ' => 'text'], ['klic' => 'e_mail', 'popisek' => 'E-mail', 'typ' => 'text'], ['klic' => 'nepritomnost', 'popisek' => 'Nepřítomnost', 'typ' => 'text'], ['klic' => 'o_mne', 'popisek' => 'O mně', 'typ' => 'html']];
$peopleCollection = ['idk' => 5, 'nazev' => 'Tým', 'seo_link' => 'tym', 'detail' => 1, 'pole' => $peopleFields, 'schema_org' => '{"typ":"Person","pole":{},"mena":""}'];
check('2.10: EmailSignature::fields – the Czech preset by the keys and labels', $signatureClass::fields($peopleCollection), ['photo' => 'fotka', 'role' => 'role', 'phone' => 'telefon', 'email' => 'e_mail']);
$germanCollection = ['pole' => [['klic' => 'portrait', 'popisek' => 'Porträt', 'typ' => 'obrazek'], ['klic' => 'funktion', 'popisek' => 'Funktion', 'typ' => 'text'], ['klic' => 'handy', 'popisek' => 'Handy', 'typ' => 'text'],
    ['klic' => 'e_mail_adresse', 'popisek' => 'E-Mail-Adresse', 'typ' => 'text'], ['klic' => 'abwesend', 'popisek' => 'Abwesend', 'typ' => 'text']]];
check('2.10: EmailSignature::fields – a user-made collection in another language, a label with diacritics', [$signatureClass::fields($germanCollection),
    $signatureClass::fields(['pole' => [['klic' => 'pole_1', 'popisek' => 'Telefonní číslo', 'typ' => 'text'], ['klic' => 'pole_2', 'popisek' => 'Pozice ve firmě', 'typ' => 'text'], ['klic' => 'pole_3', 'popisek' => 'Mobil', 'typ' => 'cislo']]])],
    [['photo' => 'portrait', 'role' => 'funktion', 'phone' => 'handy', 'email' => 'e_mail_adresse'], ['photo' => null, 'role' => 'pole_2', 'phone' => 'pole_1', 'email' => null]]);
check('2.10: EmailSignature::fields – the schema.org Person mapping wins over the names', $signatureClass::fields(['pole' => [['klic' => 'kontakt', 'popisek' => 'Kontakt', 'typ' => 'text'], ['klic' => 'cim_jsem', 'popisek' => 'Čím jsem', 'typ' => 'text'],
    ['klic' => 'telefon', 'popisek' => 'Telefon', 'typ' => 'text'], ['klic' => 'role', 'popisek' => 'Role v týmu', 'typ' => 'text']], 'schema_org' => '{"typ":"Person","pole":{"jobTitle":"cim_jsem","email":"kontakt"}}']),
    ['photo' => null, 'role' => 'cim_jsem', 'phone' => 'telefon', 'email' => 'kontakt']);
check('2.10: EmailSignature::isPeople – Person, a photo with a contact; references and a bare phone are not people', [$signatureClass::isPeople($peopleCollection), $signatureClass::isPeople($germanCollection),
    $signatureClass::isPeople(['pole' => [['klic' => 'logo', 'popisek' => 'Logo', 'typ' => 'obrazek'], ['klic' => 'citat', 'popisek' => 'Citát', 'typ' => 'radky']]]),
    $signatureClass::isPeople(['pole' => [['klic' => 'telefon', 'popisek' => 'Telefon', 'typ' => 'text']]])], [true, true, false, false]);
$signatureSite = ['name' => 'Firma & spol.', 'url' => 'https://example.com/', 'base' => 'https://example.com', 'phone' => '+420 222 000 111', 'address' => 'Dlouhá 1, 110 00 Praha', 'color' => '#0f766e',
    'text_font' => 'Georgia, serif', 'heading_font' => '"Helvetica Neue", Arial, sans-serif', 'logo' => 'https://example.com/media/logo.png'];
$signaturePerson = ['nazev' => 'Jana <Nová>', 'data' => ['fotka' => 'media/2026/10/jana.jpg', 'role' => 'Obchodní ředitelka', 'jazyky' => 'CZ, EN', 'telefon' => '+420 777 123 456', 'e_mail' => 'jana@example.com',
    'nepritomnost' => 'Dovolená do pátku', 'o_mne' => '<p>Deset let v oboru.</p>']];
$signature = $signatureClass::render($peopleCollection, $signaturePerson, $signatureSite);
check('2.10: the signature is one table with inline styles only, at most 600 px wide', [preg_match('/<style|<script|class=|\son[a-z]+=/i', $signature['html']), str_starts_with($signature['html'], '<table role="presentation"'), str_contains($signature['html'], 'max-width:600px')], [0, true, true]);
check('2.10: the signature has the escaped name, the role, the phone with a tel: link, the brand colour and font, absolute photo and logo', [str_contains($signature['html'], 'Jana &lt;Nová&gt;'), str_contains($signature['html'], 'Obchodní ředitelka'),
    str_contains($signature['html'], 'href="tel:+420777123456"'), str_contains($signature['html'], 'href="mailto:jana@example.com"'), str_contains($signature['html'], 'src="https://example.com/media/2026/10/jana.jpg" width="72" height="72"'),
    str_contains($signature['html'], 'src="https://example.com/media/logo.png"'), str_contains($signature['html'], 'border-left:3px solid #0f766e'), str_contains($signature['html'], 'Georgia, serif'), str_contains($signature['html'], 'Firma &amp; spol.')],
    [true, true, true, true, true, true, true, true, true]);
check('2.10: the signature never carries the absence, the about text or the languages', [str_contains($signature['html'] . $signature['text'], 'Dovolen'), str_contains($signature['html'] . $signature['text'], 'Deset let'), str_contains($signature['html'] . $signature['text'], 'CZ, EN')], [false, false, false]);
check('2.10: the plain-text version', $signature['text'], "Jana <Nová>\nObchodní ředitelka\n+420 777 123 456 · jana@example.com\nFirma & spol. · example.com\nDlouhá 1, 110 00 Praha");
$bareSignature = $signatureClass::render(['pole' => $peopleFields], ['nazev' => 'Petr', 'data' => ['e_mail' => 'not an address', 'fotka' => '']],
    ['name' => 'Web', 'url' => '', 'base' => 'https://example.com', 'phone' => '+420 222 000 111', 'address' => '', 'color' => 'red', 'text_font' => '', 'heading_font' => '', 'logo' => '']);
check('2.10: a person without a photo and with an invalid e-mail: no image, the company phone, a safe colour and font', [str_contains($bareSignature['html'], '<img'), str_contains($bareSignature['html'], 'not an address'),
    str_contains($bareSignature['html'], 'border-left:3px solid #121212'), str_contains($bareSignature['html'], 'system-ui'), $bareSignature['text']], [false, false, true, true, "Petr\n+420 222 000 111\nWeb"]);
// 3.14: the account ↔ person link compares e-mails without case and blanks, and only real addresses
$teamClass = Kaleta\Builder\TeamRecord::class;
check('3.14 TeamRecord::normalize and emailOf – case and blanks do not matter, a non-address never links', [
    $teamClass::normalize('  Jana.Nova@Example.COM '), $teamClass::normalize('not an address'), $teamClass::normalize(''),
    $teamClass::emailOf($peopleCollection, ['data' => ['e_mail' => ' jana@example.com ']]), $teamClass::emailOf($peopleCollection, ['data' => '{"e_mail":"petr@example.com"}']),
    $teamClass::emailOf(['pole' => [['klic' => 'fotka', 'popisek' => 'Fotka', 'typ' => 'obrazek'], ['klic' => 'telefon', 'popisek' => 'Telefon', 'typ' => 'text']]], ['data' => ['telefon' => 'x@example.com']])],
    ['jana.nova@example.com', '', '', 'jana@example.com', 'petr@example.com', '']);
check('Položky jako stránky: SEO pole a plán zveřejnění', [Kaleta\Builder\Collections::pageFields(['obrazek' => 'javascript:alert(1)', 'noindex' => '1', 'zverejnit_od' => '2099-01-01T08:00', 'popis' => str_repeat('a', 400)], false),
    Kaleta\Builder\Collections::pageFields(['zverejnit_od' => '2001-01-01 08:00'], false)['zobrazit']],
    [['seo_titulek' => '', 'popis' => str_repeat('a', 300), 'obrazek' => '', 'noindex' => 1, 'zverejnit_od' => '2099-01-01 08:00:00', 'zobrazit' => 0], 1]);
$privacy = Kaleta\Core\Language::runWith('en', fn (): string => Kaleta\Builder\Library::privacyPolicyText());
check('Zásady ochrany údajů: šablona s upozorněním, bez nastavení jen poptávky', [str_contains($privacy, 'not legal advice'), str_contains($privacy, 'enquiry form'), str_contains($privacy, 'newsletter'),
    str_contains(Kaleta\Core\Language::runWith('cs', fn (): string => Kaleta\Builder\Library::privacyPolicyText()), 'nikoli právní rada')], [true, true, false, true]);
// import of a Kaleta export (1.8): which files from the archive may go into media/, which names are exports
check('Import Kalety: soubory do media/', array_map(Kaleta\Core\SiteImport::mediaTarget(...), ['media/2026/09/foto.jpg', 'media/2026/09/foto.jpg.webp', 'media/x.php', 'media/../config.php',
    'media/.htaccess', 'obsah.json', 'media/2026/09/dokument.pdf', 'media/a/b.phtml', 'media/2026/09/logo.svg']),
    ['media/2026/09/foto.jpg', 'media/2026/09/foto.jpg.webp', null, null, null, null, 'media/2026/09/dokument.pdf', null, 'media/2026/09/logo.svg']);
check('Import Kalety: názvy souborů', array_map(Kaleta\Core\SiteImport::isValidName(...), ['export-20260928-101010.zip', 'web.json', 'stav-0123456789abcdef.json',
    'kaleta-stav-0123456789abcdef.json', '../web.zip', 'web.xml', '.web.zip']), [true, true, false, false, false, false, false]);
// webhook signature (1.8): HMAC-SHA256 of "timestamp.body" – the receiver recomputes it with the shared secret
check('Webhook: podpis HMAC časové značky a těla', Kaleta\Core\Webhook::signature('whsec_test', '{"udalost":"test"}', 1789900000),
    ['1789900000', 'sha256=' . hash_hmac('sha256', '1789900000.{"udalost":"test"}', 'whsec_test')]);
check('Webhook: jiné tělo = jiný podpis', Kaleta\Core\Webhook::signature('whsec_test', '{"udalost":"test2"}', 1789900000)[1] !== Kaleta\Core\Webhook::signature('whsec_test', '{"udalost":"test"}', 1789900000)[1], true);
$h = Kaleta\Core\RemoteBackup::signS3('PUT', 's3.eu-central-1.amazonaws.com', '/muj-bucket/kaleta-zaloha.sql.gz', hash('sha256', 'obsah'), 'eu-central-1', 'AKIDEXAMPLE', 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY', 1789900000);
check('S3: rozsah a podepsané hlavičky', str_contains($h['Authorization'], 'Credential=AKIDEXAMPLE/20260920/eu-central-1/s3/aws4_request, SignedHeaders=host;x-amz-content-sha256;x-amz-date, Signature='), true);
check('S3: podpis má 64 šestnáctkových znaků', (bool) preg_match('/Signature=[0-9a-f]{64}$/', $h['Authorization']), true);

