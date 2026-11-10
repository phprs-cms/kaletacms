<?php

declare(strict_types=1);

/* ---------- 3.20 pre-release review (N320-1 … N320-12) ---------- */
$n320Origin = (string) file_get_contents(KALETA_SYSTEM . '/src/Admin/EnquiryOrigin.php');
$n320Links = (string) file_get_contents(KALETA_SYSTEM . '/views/admin/news/links.php');
check('3.20 review (N320-1, N320-5): the footer link to the report form is stored root-relative (Core\\Menu adds the base path); "Check again" posts to, and returns to, the module the screen was opened from', [
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Modules/Whistleblowing.php'), "\$path = '/_report';"),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Modules/BrokenLinks.php'), "protected function actionLinks(): Response\n    {\n        return \$this->actionList();"),
    str_contains($n320Links, "\$checkUrl = \$app->url('admin.php?module=' . (\$app->request->get('module') === Kaleta\\Admin\\Modules\\BrokenLinks::IDENT ? Kaleta\\Admin\\Modules\\BrokenLinks::IDENT : Kaleta\\Admin\\Modules\\News::IDENT) . '&action=links');"),
    str_contains($n320Links, 'action="<?= e($checkUrl) ?>"') && !str_contains($n320Links, "url('links')"),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Modules/News.php'), "\$module = \$this->request->get('module') === BrokenLinks::IDENT ? BrokenLinks::IDENT : static::IDENT;"),
], [true, true, true, true, true]);
check('3.20 review (N320-3, N320-7, N320-11): enquiry origins drop the .html suffix of url_slash = html, an item path keeps its language, an old row links a known page even without the form', [
    substr_count($n320Origin, "preg_replace('~\\.html$~', '', trim((string) parse_url(\$path, PHP_URL_PATH), '/'))"),
    str_contains($n320Origin, 'AND p.seo_link = ? AND p.jazyk = ? AND p.smazano IS NULL LIMIT 1') && !str_contains($n320Origin, "ORDER BY p.jazyk"),
    !str_contains($n320Origin, "if (\$source === '' && \$id === '')"),
], [2, true, true]);
$n320Media = new ReflectionMethod(Kaleta\Admin\Modules\Media::class, 'allowedLink');
check('3.20 review (N320-6): "Used in" links only to the modules the person may open (the same rule as BuilderActions::dataLinks); null = every link', [
    $n320Media->invoke(null, 'admin.php?module=users&action=edit&id=3', ['pages', 'media']), $n320Media->invoke(null, 'admin.php?module=pages&action=edit&id=3', ['pages', 'media']),
    $n320Media->invoke(null, 'admin.php?module=settings', ['pages']), $n320Media->invoke(null, 'admin.php?module=settings', null),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Modules/Media.php'), 'self::findUsagesWithLinks($this->db, $modules)'),
    substr_count((string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Modules/Media.php'), 'self::allowedLink('),
], ['', 'admin.php?module=pages&action=edit&id=3', '', 'admin.php?module=settings', true, 2]);
$n320Stats = (string) file_get_contents(KALETA_SYSTEM . '/views/admin/stats/list.php');
check('3.20 review (N320-8, N320-9): the cookies tab says when the privacy page is hidden and links its settings; Statistics renders empty texts through Module::hint() like every view', [
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Modules/Settings.php'), "SELECT ids, titulek, seo_link, zobrazit FROM {stranky}") && str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Modules/Settings.php'), "'visible' => (int) \$row['zobrazit'] === 1"),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/views/admin/settings/cookies.php'), "\$privacyPage['visible']") && str_contains((string) file_get_contents(KALETA_SYSTEM . '/views/admin/settings/cookies.php'), "t('Page settings')"),
    str_contains($n320Stats, '$module->hint(t($empty))'), str_contains($n320Stats, 'MenuPaths::links'),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Modules/Stats.php'), "'modules' =>"),
], [true, true, true, false, false]);
$n320Dictionaries = [];
foreach (glob(KALETA_SYSTEM . '/jazyky/admin-*.php') as $file) {
    // a key written twice: the later line silently wins – the file has more "=>" than the array has keys
    $arrows = count(array_filter(token_get_all((string) file_get_contents($file)), fn ($t): bool => is_array($t) && $t[0] === T_DOUBLE_ARROW));
    if ($arrows !== count((array) require $file)) {
        $n320Dictionaries[] = basename($file);
    }
}
check('3.20 review (N320-12): no key is defined twice in an admin dictionary', [$n320Dictionaries], [[]]);

// 3.21 (U24): the Countdown target is a date-and-time field (typ termin): the picker's "YYYY-MM-DDTHH:MM" and the stored
// "YYYY-MM-DD HH:MM" both come out in the stored form, a whole day stays a day, anything else empties the field; the MCP schema
// names the type datetime and the element keeps its properties (tools/contracts)
[$u24Build] = Kaleta\Builder\Build::sanitize(['deti' => [['typ' => 'sekce', 'id' => 'u24s', 'deti' => [
    ['typ' => 'odpocet', 'id' => 'u24a', 'obsah' => ['cil' => '2031-05-06T07:08', 'konec' => 'Now']],
    ['typ' => 'odpocet', 'id' => 'u24b', 'obsah' => ['cil' => ' 2031-05-06 07:08 ', 'konec' => 'Now']],
    ['typ' => 'odpocet', 'id' => 'u24c', 'obsah' => ['cil' => '2031-05-06', 'konec' => 'Now']],
    ['typ' => 'odpocet', 'id' => 'u24d', 'obsah' => ['cil' => 'next friday', 'konec' => 'Now']],
]]]]);
$u24Schema = Kaleta\Builder\Build::schema(true, 'en');
$u24Countdown = array_values(array_filter($u24Schema['prvky'], fn (array $p): bool => $p['typ'] === 'odpocet'))[0] ?? [];
check('3.21 U24: the Countdown date-and-time field keeps the stored "YYYY-MM-DD HH:MM" form', [
    array_column(array_column($u24Build['deti'][0]['deti'], 'obsah'), 'cil'),
    Kaleta\Builder\Build::dateTime('2031-05-06T07:08'), Kaleta\Builder\Build::dateTime('2031-5-6 7:08'), Kaleta\Builder\Build::dateTime(''),
    $u24Countdown['vlastnosti']['cil']['typ'] ?? null, Kaleta\Mcp\Vocabulary::FIELD_TYPES['termin'] ?? null,
    str_contains(Kaleta\Builder\Build::overview($u24Schema)['prvky']['odpocet'] ?? '', 'cil:termin'),
    (bool) preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/D', (string) ($u24Countdown['vlastnosti']['cil']['vychozi'] ?? '')),
    str_contains(Kaleta\Builder\Elements\Countdown::render(['obsah' => ['cil' => '', 'konec' => 'Now']], ' id="u24e"', '', new Kaleta\Builder\Context($leadApp, true)), e(t('Choose the date and time in the Content panel.'))),
], [['2031-05-06 07:08', '2031-05-06 07:08', '2031-05-06', ''], '2031-05-06 07:08', '', '', 'termin', 'datetime', true, true, true]);

// 3.21 pre-release review (N321): the seams of the three 3.21 branches and MCP compatibility of the Countdown target
// N321-3: a language model sends ISO dates with seconds ("…T07:08:00"); before 3.21 the 16-character text field cut them to a valid
// value, the date-and-time field keeps that; an impossible date is refused, and a refused value is reported (in English over MCP)
[$n321Build, $n321Errors] = Kaleta\Builder\Build::sanitize(['deti' => [['typ' => 'sekce', 'id' => 'n321s', 'deti' => [
    ['typ' => 'odpocet', 'id' => 'n321a', 'obsah' => ['cil' => '2031-05-06T07:08:00', 'konec' => 'Now']],
    ['typ' => 'odpocet', 'id' => 'n321b', 'obsah' => ['cil' => '2031-05-06 07:08:59.5', 'konec' => 'Now']],
    ['typ' => 'odpocet', 'id' => 'n321c', 'obsah' => ['cil' => '2031-02-30 07:08', 'konec' => 'Now']],
    ['typ' => 'odpocet', 'id' => 'n321d', 'obsah' => ['cil' => '', 'konec' => 'Now']],
    ['typ' => 'odpocet', 'id' => 'n321e', 'obsah' => ['cil' => '2025-12-31 18:00', 'konec' => 'Now']],
]]]]);
check('3.21 review (N321-3): Countdown target – seconds dropped, an impossible date refused and reported, old values unchanged', [
    array_column(array_column($n321Build['deti'][0]['deti'], 'obsah'), 'cil'), array_keys($n321Errors),
    Kaleta\Mcp\Translator::message((string) ($n321Errors['deti[0].deti[2].obsah.cil'] ?? '')),
    Kaleta\Builder\Build::dateTime('2031-05-06 24:00'), Kaleta\Builder\Build::dateTime('2031-05-06'),
], [['2031-05-06 07:08', '2031-05-06 07:08', '', '', '2025-12-31 18:00'], ['deti[0].deti[2].obsah.cil'],
    'A date and time must be YYYY-MM-DD HH:MM – the field was left empty.', '', '2031-05-06']);
// N321-4: the MCP schema says the format of a datetime field (the label "Until" no longer does)
$n321Schema = Kaleta\Mcp\Vocabulary::schema(Kaleta\Builder\Build::schema(true, 'en'), ['countdown'], true);
check('3.21 review (N321-4): the MCP builder schema gives the datetime format', [
    str_contains(json_encode($n321Schema, JSON_UNESCAPED_UNICODE) ?: '', 'YYYY-MM-DD HH:MM'),
    str_contains(json_encode(Kaleta\Mcp\Vocabulary::schema(Kaleta\Builder\Build::schema(true, 'en')), JSON_UNESCAPED_UNICODE) ?: '', 'datetime(YYYY-MM-DD HH:MM)'),
], [true, true]);
// N321-2: an element hidden on a larger screen is shown again on a smaller one with its own display (revert-layer to the
// element's base CSS in the stavitel layer) – a forced "block" made a button full width and a form lose its grid
$n321VisErrors = [];
check('3.21 review (N321-2): shown again with the element\'s own display', [
    Kaleta\Builder\Style::css('#s-a', ['zaklad' => ['zobrazeni' => 'none'], 'tablet' => ['zobrazeni' => 'revert-layer']]),
    Kaleta\Builder\Style::sanitize(['zaklad' => ['zobrazeni' => 'none'], 'tablet' => ['zobrazeni' => 'revert-layer']], 's', $n321VisErrors), $n321VisErrors,
    str_contains($builderScript, "find((v) => v && v !== 'none') || 'revert-layer'"),
], ["#s-a { display: none; }\n@media (max-width: 1023px) { #s-a { display: revert-layer; } }\n", ['zaklad' => ['zobrazeni' => 'none'], 'tablet' => ['zobrazeni' => 'revert-layer']], [], true]);
// N321-1: instant canvas text never writes into the dashed strip of a hidden element (the strip carries the same data-ka-id)
check('3.21 review (N321-1): instant canvas text skips the strip of a hidden element', (bool) preg_match('~function fastCanvasText.+?querySelectorAll\(\'\[data-ka-id="\' \+ p\.id \+ \'"\]:not\(\.ka-st-skryty-prvek\)\'\)~s', $builderScript), true);

// 3.22 (C10): a reply to an enquiry – the stored history is cleaned, an anonymised enquiry keeps who and when without the
// address and the text, the subject is "Re: <form>", and nothing can be sent without SMTP or to a list of addresses
$c10Json = json_encode([['at' => '2026-11-01 10:00:00', 'by' => 'Alex', 'by_id' => 3, 'to' => 'hana@example.cz', 'reply_to' => 'alex@firma.cz', 'subject' => 'Re: Kontakt', 'text' => "Dobrý den,\nděkujeme.", 'mail' => 7, 'status' => 'sent', 'error' => '', 'via' => 'admin'],
    'junk', ['status' => 'hacked', 'via' => 'other']]);
$c10Anon = Kaleta\Core\EnquiryReply::decode(Kaleta\Core\EnquiryReply::anonymised($c10Json));
check('3.22 C10: EnquiryReply – history cleaned, anonymised without address and text, subject, one address only', [
    count(Kaleta\Core\EnquiryReply::decode($c10Json)), Kaleta\Core\EnquiryReply::decode($c10Json)[1]['status'], Kaleta\Core\EnquiryReply::decode($c10Json)[1]['via'],
    Kaleta\Core\EnquiryReply::decode('not json'), Kaleta\Core\EnquiryReply::decode(null), Kaleta\Core\EnquiryReply::anonymised(null),
    [$c10Anon[0]['by'], $c10Anon[0]['at'], $c10Anon[0]['status'], $c10Anon[0]['to'], $c10Anon[0]['reply_to'], $c10Anon[0]['subject'], $c10Anon[0]['text']],
    Kaleta\Core\EnquiryReply::subject(['formular' => 'Kontakt']), Kaleta\Core\EnquiryReply::subject(['formular' => ' ']) !== 'Re: ',
    Kaleta\Core\Mail::addresses("hana@example.cz\r\nBcc: x@evil.example"), Kaleta\Core\Mail::addresses('a@example.cz, b@example.cz'),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Core/EnquiryReply.php'), 'count($to) !== 1'),
], [2, 'failed', 'admin', [], [], null, ['Alex', '2026-11-01 10:00:00', 'sent', '', '', '', ''], 'Re: Kontakt', true, [], ['a@example.cz', 'b@example.cz'], true]);
// the message's own Reply-To (the visitor of a notification, the user who replies) wins over the site's reply address
check('3.22 C10: Mail keeps the Reply-To a message names; the site address only otherwise; the log row id is known', [
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Core/Mail.php'), "\$siteSettings->get('mail_reply_to') !== '' && !isset(\$h['Reply-To'])"),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Core/Mail.php'), "self::\$lastId = \$siteSettings->db()->insert('posta'"),
], [true, true]);
// MCP: a real e-mail – a destructive (full access) tool that needs confirm=true and sends only through EnquiryReply
$c10Def = array_values(array_filter(Kaleta\Mcp\Tools::definitions(), fn (array $d): bool => $d['name'] === 'reply_to_enquiry'))[0] ?? [];
check('3.22 C10: reply_to_enquiry – destructive, open-world, confirm and text required, described as a real e-mail', [
    Kaleta\Mcp\Catalog::TOOLS['reply_to_enquiry'] ?? null, in_array('reply_to_enquiry', Kaleta\Mcp\Catalog::OPEN_WORLD, true),
    $c10Def['inputSchema']['required'] ?? null, str_starts_with((string) ($c10Def['description'] ?? ''), 'SENDS A REAL E-MAIL'),
], [['destructive', 'poptavky'], true, ['id', 'text', 'confirm'], true]);
// 3.22 (C15): the Form's own confirmation e-mail text – empty = the thank-you with the next steps (as before), otherwise the
// author's text as written, cut to the limit; the builder shows the field only with the confirmation on, MCP calls it confirmation_text
$c15Form = Kaleta\Builder\Elements\Form::properties();
$c15Long = str_repeat('a', Kaleta\Builder\Elements\Form::CONFIRMATION_MAX + 50);
check('3.22 C15: per-form confirmation text', [
    Kaleta\Front\Forms::confirmationText($leadApp, ['dekujeme' => 'Thanks.', 'dalsi_kroky' => "Call\nOffer", 'potvrzeni_text' => '']),
    Kaleta\Front\Forms::confirmationText($leadApp, ['dekujeme' => 'Thanks.', 'potvrzeni_text' => "  Hello,\r\nwe reply within a day.\r\n\r\nJana  "]),
    mb_strlen(Kaleta\Front\Forms::confirmationText($leadApp, ['dekujeme' => 'x', 'potvrzeni_text' => $c15Long])),
    Kaleta\Front\Forms::confirmationText($leadApp, ['dekujeme' => 'Thanks.', 'potvrzeni_text' => ['not', 'text']]),
    $c15Form['potvrzeni_text']['typ'] ?? null, $c15Form['potvrzeni_text']['kdyz'] ?? null, Kaleta\Mcp\Vocabulary::CONTENT['potvrzeni_text'] ?? null,
    str_contains(Kaleta\Builder\Build::overview(Kaleta\Builder\Build::schema())['prvky']['formular'] ?? '', 'potvrzeni_text:radky'),
    str_contains($builderScript, 'if (def.kdyz && !Object.entries(def.kdyz).every(([pk, pv]) => p.obsah[pk] === pv)) { return; }'),
], [t('Thanks.') === 'Thanks.' ? "Thanks.\n\n" . t('What happens next') . ":\n1. Call\n2. Offer" : null, "Hello,\nwe reply within a day.\n\nJana", Kaleta\Builder\Elements\Form::CONFIRMATION_MAX, 'Thanks.',
    'radky', ['potvrzeni' => true], 'confirmation_text', true, true]);
[$c15Build] = Kaleta\Builder\Build::sanitize(['deti' => [['typ' => 'formular', 'id' => 'c15f', 'obsah' => ['potvrzeni' => true, 'potvrzeni_text' => '<b>Hi</b> there' . $c15Long]]]]);
check('3.22 C15: the confirmation text is plain text with a length limit in every saved build', [
    str_starts_with((string) $c15Build['deti'][0]['obsah']['potvrzeni_text'], 'Hi there'), mb_strlen((string) $c15Build['deti'][0]['obsah']['potvrzeni_text']),
], [true, Kaleta\Builder\Elements\Form::CONFIRMATION_MAX]);

