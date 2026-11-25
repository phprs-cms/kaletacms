<?php

declare(strict_types=1);

/* ---------- 3.54 B: consent to news at the form – one property of the Form element, the proof of a sign-up, the unsubscribe link of a newsletter ---------- */
use Kaleta\Builder\Elements\Form as B5050Form;
use Kaleta\Front\Subscription as B5050Subscription;

// a site whose settings the test chooses – no database (as 4700)
$b5050Settings = function (string $extensions): Kaleta\Core\Settings {
    $s = new Kaleta\Core\Settings(new Kaleta\Core\Db('invalid-b5050:', '', ''));
    (new ReflectionProperty(Kaleta\Core\Settings::class, 'values'))->setValue($s, ['extensions' => $extensions, 'site_url' => 'https://firma.example']);
    (new ReflectionProperty(Kaleta\Core\Settings::class, 'lazyRead'))->setValue($s, array_fill_keys((new ReflectionMethod(Kaleta\Core\Settings::class, 'keysWith'))->invoke(null, 'lazy'), true));

    return $s;
};
$b5050App = function (string $extensions) use ($b5050Settings): Kaleta\Core\App {
    $app = new Kaleta\Core\App([], new Kaleta\Core\Request([], [], []));
    (new ReflectionProperty(Kaleta\Core\App::class, 'settings'))->setValue($app, $b5050Settings($extensions));

    return $app;
};

// rules 1–3: a property of its own (not a field: no Required switch, no condition), empty = not offered, never stored by default
$b5050Property = B5050Form::properties()['souhlas_novinky'];
check('3.54 B2: the news consent is a text property of the Form, empty by default (not offered until the author writes it), at most 500 characters, with a suggested wording that is only a hint',
    [$b5050Property['typ'], $b5050Property['vychozi'], $b5050Property['max'], $b5050Property['priklad'] !== '', isset(B5050Form::properties()['pole']['pole']['souhlas_novinky'])],
    ['text', '', 500, true, false]);
[$b5050Build] = Kaleta\Builder\Build::sanitize(['deti' => [['typ' => 'formular', 'id' => 'f5050', 'obsah' => ['souhlas_novinky' => str_repeat('a', 600)]], ['typ' => 'formular', 'id' => 'g5050']]]);
check('3.54 B2: the one validator keeps the wording cut to 500 and stores nothing for a form without it',
    [mb_strlen((string) $b5050Build['deti'][0]['obsah']['souhlas_novinky']), $b5050Build['deti'][1]['obsah']['souhlas_novinky'] ?? ''], [500, '']);

// offered only with the Newsletter feature on – the renderer and the server ask the same function
$b5050On = $b5050App('poptavky,newsletter');
$b5050Off = $b5050App('poptavky');
$b5050Text = 'I want news by e-mail.';
check('3.54 B2: newsConsentText – the wording with Newsletter on; nothing with it off, without a wording, or with only spaces',
    [B5050Form::newsConsentText($b5050On, ['souhlas_novinky' => '  ' . $b5050Text . ' '], 'stranka:1'), B5050Form::newsConsentText($b5050Off, ['souhlas_novinky' => $b5050Text], 'stranka:1'),
        B5050Form::newsConsentText($b5050On, [], 'stranka:1'), B5050Form::newsConsentText($b5050On, ['souhlas_novinky' => '   '], 'stranka:1')],
    [$b5050Text, '', '', '']);

// rendered: one unticked, never required checkbox ka_news above the button, outside the field list
$b5050Context = new Kaleta\Builder\Context($b5050On);
$b5050Render = (new ReflectionMethod(B5050Form::class, 'newsConsent'))->invoke(null, ['souhlas_novinky' => $b5050Text], 'f5050', $b5050Context);
check('3.54 B2 rules 1–2: the box is a plain checkbox named ka_news – never checked in advance, never required – with the wording as its label',
    [str_contains($b5050Render, 'type="checkbox" id="f-f5050-news" name="ka_news" value="1">'), str_contains($b5050Render, 'checked'), str_contains($b5050Render, 'required'), str_contains($b5050Render, '<span>' . $b5050Text . '</span>'),
        (new ReflectionMethod(B5050Form::class, 'newsConsent'))->invoke(null, ['souhlas_novinky' => $b5050Text], 'f5050', new Kaleta\Builder\Context($b5050Off))],
    [true, false, false, true, '']);

// the editor offers the property only with the Newsletter feature on
$b5050Schema = fn (array $extensions): bool => isset(array_column(Kaleta\Builder\Build::schema(true, 'cs', false, $extensions)['prvky'], 'vlastnosti', 'typ')['formular']['souhlas_novinky']);
check('3.54 B2: the builder schema offers the news consent with Newsletter on, not with it off', [$b5050Schema(['poptavky', 'newsletter']), $b5050Schema(['poptavky'])], [true, false]);

// MCP: news_consent both ways, the build round trip keeps it
$b5050En = Kaleta\Mcp\Vocabulary::contentToEnglish('formular', ['souhlas_novinky' => $b5050Text, 'souhlas_uchovani' => '']);
check('3.54 B2: Vocabulary – souhlas_novinky is news_consent over MCP and back', [$b5050En['news_consent'] ?? null, Kaleta\Mcp\Vocabulary::contentToCzech(['news_consent' => $b5050Text])['souhlas_novinky'] ?? null], [$b5050Text, $b5050Text]);

// build_from_html never guesses a news consent: every HTML checkbox becomes a consent field of the form
$b5050Html = Kaleta\Builder\HtmlConverter::convert('<form><label>E-mail <input type="email" name="e"></label><label><input type="checkbox" name="news"> Send me news and offers by e-mail</label><button>Send</button></form>');
$b5050Find = function (array $nodes) use (&$b5050Find): array {
    foreach ($nodes as $n) {
        if (($n['typ'] ?? '') === 'formular') {
            return $n;
        }
        if (($found = $b5050Find($n['deti'] ?? [])) !== []) {
            return $found;
        }
    }

    return [];
};
$b5050Form = $b5050Find($b5050Html['stavba']['deti'] ?? []);
check('3.54 B2: build_from_html – a newsletter checkbox stays a consent field (souhlas); the form gets no news consent',
    [$b5050Form['typ'] ?? null, array_column($b5050Form['obsah']['pole'] ?? [], 'typ'), $b5050Form['obsah']['souhlas_novinky'] ?? ''], ['formular', ['email', 'souhlas'], '']);

// the pre-publish check: a news consent without an e-mail field subscribes nobody
$b5050Findings = fn (array $fields): int => count(Kaleta\Builder\Check::builds(['deti' => [['typ' => 'formular', 'id' => 'c5050', 'obsah' => ['souhlas_novinky' => $b5050Text, 'pole' => $fields]]]], false));
check('3.54 B2: Check::builds warns about a news consent on a form without an e-mail field, not with one, not without a consent',
    [$b5050Findings([['popisek' => 'Name', 'typ' => 'text']]), $b5050Findings([['popisek' => 'E-mail', 'typ' => 'email']]),
        count(Kaleta\Builder\Check::builds(['deti' => [['typ' => 'formular', 'id' => 'c5050', 'obsah' => ['pole' => [['popisek' => 'Name', 'typ' => 'text']]]]]], false))],
    [1, 0, 0]);

// the proof columns of a sign-up
check('3.54 B1: proofColumns – the wording cut to 500, the source to 64, the element to 16; a missing proof is empty, never an error',
    [array_map('mb_strlen', B5050Subscription::proofColumns(['text' => str_repeat('ž', 520), 'source' => 'cast:paticka:en:' . str_repeat('v', 60), 'element' => str_repeat('e', 20)])), B5050Subscription::proofColumns([])],
    [['consent_text' => 500, 'consent_source' => 64, 'consent_element' => 16], ['consent_text' => '', 'consent_source' => '', 'consent_element' => '']]);

// part C: a newsletter's unsubscribe link names it; the export's link (no newsletter) stays as before
check('3.54 C: unsubscribeLink – &newsletter=<id> for a newsletter, the bare link without one (the CSV export, 0)', [
    B5050Subscription::unsubscribeLink($b5050On, str_repeat('a', 32), 7), B5050Subscription::unsubscribeLink($b5050On, str_repeat('a', 32)), B5050Subscription::unsubscribeLink($b5050On, str_repeat('a', 32), -3)], [
    'https://firma.example/odber?unsubscribe=' . str_repeat('a', 32) . '&newsletter=7', 'https://firma.example/odber?unsubscribe=' . str_repeat('a', 32), 'https://firma.example/odber?unsubscribe=' . str_repeat('a', 32)]);
$b5050Id = new ReflectionMethod(B5050Subscription::class, 'newsletterId');
check('3.54 C: the newsletter id a link carries – a positive integer, otherwise 0 (an old link, a forged or overlong value)',
    array_map(fn (string $v): int => $b5050Id->invoke(null, new Kaleta\Core\Request(['newsletter' => $v], [], [])), ['12', '', '0', '-4', 'x1', '99999999999']), [12, 0, 0, 0, 0, 0]);

// the Subscribers screen and CSV: where the consent was given
check('3.54 B3: consentForm – the source and the element, empty for a sign-up without a stored proof',
    [Kaleta\Admin\Modules\Subscribers::consentForm(['consent_source' => 'stranka:12', 'consent_element' => 'a1b2']), Kaleta\Admin\Modules\Subscribers::consentForm(['consent_source' => '', 'consent_element' => ''])],
    ['stranka:12 · a1b2', '']);

// unit 4700 stays: the Newsletter element's own guard is unchanged (a register address one e-mail a week, any other at once)
check('3.54 B1: the Newsletter element keeps the 3.51 guard – mayConfirmAgain unchanged', [B5050Subscription::mayConfirmAgain(Kaleta\Core\Clock::now(), false, Kaleta\Core\Clock::now()), B5050Subscription::REGISTER_CONFIRM_DAYS], [true, 7]);
