<?php

declare(strict_types=1);

// 3.37 B (step 4a of the CRM path): phone and position on the account, the colleagues, an e-mail signature for everyone –
// the Team record or the site's fallback (Builder\UserSignature), list_colleagues in the work set
use Kaleta\Builder\UserSignature;
use Kaleta\Core\Colleagues;
use Kaleta\Mcp\Catalog;

$b337Site = ['name' => 'Hartsteel & Sons', 'url' => 'https://hartsteel.example/', 'base' => 'https://hartsteel.example', 'phone' => '+44 20 7946 0000',
    'address' => 'Mill Lane 4, LS1 4AB Leeds', 'color' => '#0055aa', 'text_font' => '', 'heading_font' => '', 'logo' => ''];
$b337User = ['user' => 'olivia', 'jmeno' => 'Olivia <i>Hart</i> & Co', 'pozice' => 'Head of sales', 'phone' => '+44 7700 900123', 'email' => 'olivia@hartsteel.example', 'foto' => 'media/olivia.jpg'];

$b337Standard = UserSignature::fallback($b337User, $b337Site, '');
check('3.37 B: without a template the fallback has the standard layout – name, position, the own phone as a link, the e-mail, the photo, the company; escaped', [
    str_contains($b337Standard['html'], 'Olivia Hart &amp; Co'), str_contains($b337Standard['html'], 'Head of sales'), str_contains($b337Standard['html'], 'href="tel:+447700900123"'),
    str_contains($b337Standard['html'], 'mailto:olivia@hartsteel.example'), str_contains($b337Standard['html'], 'src="https://hartsteel.example/media/olivia.jpg"'),
    str_contains($b337Standard['html'], 'Hartsteel &amp; Sons'), str_contains($b337Standard['html'], '<i>'),
    $b337Standard['text']],
    [true, true, true, true, true, true, false, "Olivia Hart & Co\nHead of sales\n+44 7700 900123 · olivia@hartsteel.example\nHartsteel & Sons · hartsteel.example\nMill Lane 4, LS1 4AB Leeds"]);

$b337Template = "{name}\n{position}\nMobile: {phone}\nFax: {nothing}\n<b>{company}</b> · {website}\n\n\n{email}";
$b337Made = UserSignature::fallback($b337User, $b337Site, $b337Template);
$b337NoPhone = UserSignature::fallback(['phone' => '', 'pozice' => ''] + $b337User, ['phone' => ''] + $b337Site, $b337Template);
check('3.37 B: a template – one line per line, the first as the heading, links for the phone, the e-mail and the website, text escaped, unknown placeholders stay text', [
    $b337Made['text'],
    str_contains($b337Made['html'], 'Mobile: <a href="tel:+447700900123"'), str_contains($b337Made['html'], '&lt;b&gt;Hartsteel &amp; Sons&lt;/b&gt;'),
    str_contains($b337Made['html'], '<a href="https://hartsteel.example/" style="color:#0055aa;text-decoration:none;">hartsteel.example</a>'),
    str_contains($b337Made['html'], 'font-weight:700;">Olivia Hart &amp; Co</td>'), str_contains($b337Made['html'], 'border-left:3px solid #0055aa'),
    $b337NoPhone['text']],
    ["Olivia Hart & Co\nHead of sales\nMobile: +44 7700 900123\nFax: {nothing}\n<b>Hartsteel & Sons</b> · hartsteel.example\nolivia@hartsteel.example",
        true, true, true, true, true,
        "Olivia Hart & Co\nFax: {nothing}\n<b>Hartsteel & Sons</b> · hartsteel.example\nolivia@hartsteel.example"]);
check('3.37 B: the phone falls back to the company phone; a template that leaves nothing falls back to the standard layout; at most 12 lines', [
    UserSignature::values(['phone' => ''] + $b337User, $b337Site)['phone'],
    UserSignature::values(['jmeno' => ''] + $b337User, $b337Site)['name'],
    UserSignature::fallback(['pozice' => ''] + $b337User, $b337Site, '{position}') === UserSignature::fallback(['pozice' => ''] + $b337User, $b337Site, ''),
    count(explode("\n", UserSignature::fallback($b337User, $b337Site, str_repeat("{name}\n", 20))['text'])),
    UserSignature::values(['email' => 'not an address'] + $b337User, $b337Site)['email']],
    ['+44 20 7946 0000', 'olivia', true, UserSignature::MAX_LINES, '']);

check('3.37 B: a work phone takes what the company phone takes; a photo only from the site\'s media; list_colleagues output is compact', [
    Colleagues::phone('  +420  777 123 456 '), Colleagues::phone(''), Colleagues::phone('call me'), Colleagues::phone('+44 (0)20 7946-0000'),
    Colleagues::photo('media/team/olivia.webp'), Colleagues::photo('/media/a.png'), Colleagues::photo('https://evil.example/a.png'), Colleagues::photo('media/../config.php'), Colleagues::photo('media/x.php'),
    Colleagues::compact([['id' => 3, 'name' => 'Olivia', 'position' => '', 'phone' => '+44 1', 'email' => '', 'photo' => 'media/o.jpg']])],
    ['+420 777 123 456', '', null, '+44 (0)20 7946-0000', 'media/team/olivia.webp', '/media/a.png', '', '', '', [['id' => 3, 'name' => 'Olivia', 'phone' => '+44 1']]]);

$b337Defs = array_column(Kaleta\Mcp\Translator::listAll(Kaleta\Mcp\Tools::definitions()), null, 'name');
check('3.37 B: the fallback template is a setting of Business details; list_colleagues is a read tool of the work set (never the essentials); get_email_signature needs no collection', [
    Kaleta\Core\Settings::DEFAULTS['email_signature_template'], (new ReflectionClassConstant(Kaleta\Admin\Modules\Settings::class, 'FIELDS'))->getValue()['company']['email_signature_template'],
    Catalog::access('list_colleagues'), in_array('list_colleagues', Catalog::WORK, true), in_array('list_colleagues', Catalog::ESSENTIALS, true),
    in_array('list_colleagues', Kaleta\Mcp\Translator::names(), true), $b337Defs['get_email_signature']['inputSchema']['required'] ?? null,
    array_keys((array) $b337Defs['list_colleagues']['inputSchema']['properties'])],
    ['', 'radky', 'read', true, false, true, [], []]);
