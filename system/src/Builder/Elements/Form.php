<?php

declare(strict_types=1);

namespace Kaleta\Builder\Elements;

use Kaleta\Core\Antispam;
use Kaleta\Builder\Context;
use Kaleta\Builder\Element;

/**
 * Enquiry / contact form. It is sent to /form (the older /formular answers too; Front\Forms): the server takes the fields from the published build
 * (not from the browser), verifies them, saves the enquiry („Administrace → Poptávky“, i.e. Admin → Enquiries) and sends a notification e-mail.
 * Protection without cookies and CAPTCHA (Core\Antispam), so the page with the form stays in the cache.
 */
final class Form extends Element
{
    public const string TYPE = 'formular';
    public const string EXTENSION = 'poptavky';
    public const string NAME = 'Form';
    public const string DESCRIPTION = 'An enquiry or question – submitted messages are in Enquiries and arrive by email.';
    public const string ICON = 'formular';
    public const string GROUP = 'Dynamic';
    public const array HTML_TAGS = ['form'];

    /** Form field types. */
    public const array FIELD_TYPES = ['text' => 'text', 'email' => 'e-mail', 'tel' => 'telefon', 'textarea' => 'longer text', 'vyber' => 'choice from a list',
        'volba' => 'single choice (radio buttons)', 'zaskrtnuti' => 'several choices (checkboxes)', 'datum' => 'datum', 'cislo' => 'číslo', 'soubor' => 'attachment (file)',
        'souhlas' => 'checkbox (consent)', 'skryte' => 'hidden value (e.g. the product the form is about)', 'kosik' => 'enquiry basket (products added with Add to enquiry)',
        'krok' => 'new step (a multi-step form – the label is the step title)', 'odhad' => 'price estimate (adds up the prices of the answers)'];

    /** An option with a price for the estimate (2.12): "Label | 1200" – the visitor sees and sends only the label. */
    private const string PRICED_OPTION = '/^(.*?)\s*\|\s*(-?\d[\d \x{a0}]*(?:[.,]\d+)?)\s*$/u';

    /** Form attachments: allowed types and the maximum size of one file. */
    /** Phone in the pattern attribute (the browser reads it with the v flag – parentheses, slash and hyphen in the class must be escaped). */
    public const string PHONE_PATTERN = '[+\\(\\)\\d\\s\\/.\\-]{6,30}';

    public const array ATTACHMENT_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'heic', 'doc', 'docx', 'xls', 'xlsx', 'odt', 'ods', 'txt', 'zip', 'dwg', 'dxf'];
    /** The default size limit of one attachment (a form without its own limit, the whistleblowing channel). */
    public const int MAX_ATTACHMENT = 10 * 1024 * 1024;

    /** The highest limit a form may set for itself (3.7) – the server's upload_max_filesize and post_max_size still apply. */
    public const int HARD_MAX_ATTACHMENT_MB = 25;

    /**
     * The size limit of one attachment of a form, in bytes (3.7): its own limit in MB (1–25, default 10), never above what
     * the server accepts (the smaller of upload_max_filesize and post_max_size, Core\Files::limit).
     *
     * @param array<string, mixed> $content the form element's content
     */
    public static function attachmentLimit(array $content): int
    {
        $mb = (int) (is_numeric($content['max_priloha'] ?? null) ? $content['max_priloha'] : self::MAX_ATTACHMENT / 1048576);
        $own = max(1, min(self::HARD_MAX_ATTACHMENT_MB, $mb)) * 1048576;
        $server = \Kaleta\Core\Files::limit();

        return $server > 0 ? min($own, $server) : $own;
    }

    public static function properties(): array
    {
        return [
            'nazev' => ['typ' => 'text', 'popisek' => 'Form name (in Enquiries and in the email)', 'vychozi' => t('Enquiry'), 'max' => 120],
            'pole' => ['typ' => 'polozky', 'popisek' => 'Form fields', 'max' => 20, 'pole' => [
                'popisek' => ['typ' => 'text', 'popisek' => 'Label', 'vychozi' => '', 'max' => 200],
                'typ' => ['typ' => 'vyber', 'popisek' => 'Typ', 'vychozi' => 'text', 'moznosti' => self::FIELD_TYPES],
                'povinne' => ['typ' => 'prepinac', 'popisek' => 'Required', 'vychozi' => false],
                'moznosti' => ['typ' => 'radky', 'popisek' => 'Choice options (one per line)', 'vychozi' => '', 'max' => 2000, 'kdyz' => ['typ' => 'vyber']],
                'moznosti_volby' => ['typ' => 'radky', 'popisek' => 'Options (one per line)', 'vychozi' => '', 'max' => 2000, 'kdyz' => ['typ' => 'volba']],
                'moznosti_zaskrtnuti' => ['typ' => 'radky', 'popisek' => 'Options to tick (one per line)', 'vychozi' => '', 'max' => 2000, 'kdyz' => ['typ' => 'zaskrtnuti']],
                'hodnota' => ['typ' => 'text', 'popisek' => 'Value sent with the form (not shown to the visitor)', 'vychozi' => '', 'max' => 300, 'kdyz' => ['typ' => 'skryte']],
                // a quote calculator (2.12): options carry their price ("Label | 1200"), a number field a price per unit, the estimate a base
                'cena_za_jednotku' => ['typ' => 'text', 'popisek' => 'Price per unit for the estimate (the number × this price)', 'vychozi' => '', 'max' => 20, 'kdyz' => ['typ' => 'cislo']],
                'zaklad' => ['typ' => 'text', 'popisek' => 'Base price of the estimate', 'vychozi' => '', 'max' => 20, 'kdyz' => ['typ' => 'odhad']],
                'mena' => ['typ' => 'text', 'popisek' => 'Currency of the estimate (e.g. EUR, Kč)', 'vychozi' => '', 'max' => 10, 'kdyz' => ['typ' => 'odhad']],
                // conditions (2.12): the field shows only when another field has a value
                'kdyz_pole' => ['typ' => 'text', 'popisek' => 'Show only when the field labelled…', 'vychozi' => '', 'max' => 200],
                'kdyz_hodnota' => ['typ' => 'text', 'popisek' => '…has this value (for options to tick: this one is ticked)', 'vychozi' => '', 'max' => 200],
            ], 'vychozi' => [
                ['popisek' => t('Jméno'), 'typ' => 'text', 'povinne' => true, 'moznosti' => ''],
                ['popisek' => t('Email'), 'typ' => 'email', 'povinne' => true, 'moznosti' => ''],
                ['popisek' => t('Phone'), 'typ' => 'tel', 'povinne' => false, 'moznosti' => ''],
                ['popisek' => t('How can we help you?'), 'typ' => 'textarea', 'povinne' => true, 'moznosti' => ''],
                ['popisek' => t('I agree to the processing of my personal data for the purpose of handling this enquiry.'), 'typ' => 'souhlas', 'povinne' => true, 'moznosti' => ''],
            ]],
            'tlacitko' => ['typ' => 'text', 'popisek' => 'Button text', 'vychozi' => t('Send enquiry'), 'max' => 80],
            'dekujeme' => ['typ' => 'text', 'popisek' => 'Thank-you message', 'vychozi' => t('Thank you, we have received your message. We will get back to you as soon as possible.'), 'max' => 400],
            'prijemce' => ['typ' => 'text', 'popisek' => 'Notification email (empty = site email from Settings)', 'vychozi' => '', 'max' => 190],
            'dekovna' => ['typ' => 'odkaz', 'popisek' => 'After sending, go to a page (empty = thank-you message in place of the form)', 'vychozi' => ''],
            'potvrzeni' => ['typ' => 'prepinac', 'popisek' => 'Send the sender a confirmation e-mail (thank-you only, without the message content)', 'vychozi' => false],
            // a gated download (2.11, Core\Documents): the file goes out as a signed link that works for a week
            'poslat_soubor' => ['typ' => 'odkaz', 'popisek' => 'After sending, e-mail this file to the visitor (a file from Media; the form needs an e-mail field). A file in Media stays reachable by its own address – this stops casual sharing, not a determined person.', 'vychozi' => '', 'media' => 'soubor'],
            // 3.7: a form whose visitors send larger drawings or scans; the editor shows what this server accepts (BuilderActions)
            'max_priloha' => ['typ' => 'cislo', 'popisek' => 'Attachment size limit per file in MB (1–25)', 'vychozi' => 10, 'min' => 1, 'max' => self::HARD_MAX_ATTACHMENT_MB],
            'bez_captcha' => ['typ' => 'prepinac', 'popisek' => 'Without the extra spam check (CAPTCHA from Settings → Privacy and cookies)', 'vychozi' => false],
            // what happens next (2.12, Front\NextSteps): shown with the thank-you and sent in the confirmation e-mail
            'dalsi_kroky' => ['typ' => 'radky', 'popisek' => 'What happens next (one step per line, shown with the thank-you)', 'vychozi' => '', 'max' => 2000],
            'odpovime_do' => ['typ' => 'cislo', 'popisek' => 'We reply within (working hours by the opening hours in Business details; 0 = not shown)', 'vychozi' => 0, 'min' => 0, 'max' => \Kaleta\Front\NextSteps::MAX_HOURS],
            'odpovida' => ['typ' => 'text', 'popisek' => 'Who replies (e.g. “Jana from the office”)', 'vychozi' => '', 'max' => 120],
        ];
    }

    public static function baseCss(): string
    {
        // the anchor after sending points to the form: an offset so that the heading above it is visible too and the sticky header does not cover it
        return '.ka-formular { display: grid; gap: var(--ka-mezera-s); }
.ka-formular, .ka-formular-hotovo { scroll-margin-top: 6rem; }
.ka-pole { display: grid; gap: var(--ka-mezera-2xs); margin: 0; }
.ka-pole > label { font-weight: 600; }
.ka-pole input:not([type="checkbox"]):not([type="radio"]), .ka-pole select, .ka-pole textarea { box-sizing: border-box; width: 100%; padding: 0.7em 0.9em; border: 1px solid var(--ka-barva-linka); border-radius: var(--ka-zaobleni); background: var(--ka-barva-pozadi); color: var(--ka-barva-text); font: inherit; }
.ka-pole textarea { min-height: 8em; resize: vertical; }
.ka-pole :focus-visible { outline: 2px solid var(--ka-barva-primarni); outline-offset: 1px; }
.ka-pole-souhlas label { display: flex; gap: var(--ka-mezera-xs); align-items: flex-start; }
.ka-pole-souhlas input { margin-block-start: 0.3em; accent-color: var(--ka-barva-primarni); }
.ka-krok { display: grid; gap: var(--ka-mezera-s); margin: 0; padding: 0; border: 0; }
.ka-krok > legend { margin-bottom: var(--ka-mezera-xs); font-weight: 700; font-size: 1.1em; }
.ka-kroky-navigace { display: flex; flex-wrap: wrap; gap: var(--ka-mezera-xs); align-items: center; }
.ka-kroky-navigace span { margin-inline-end: auto; color: var(--ka-barva-tlumeny); font-size: 0.9em; }
.ka-odhad { display: flex; flex-wrap: wrap; align-items: baseline; gap: 0.5em; padding: 0.75em 1em; border-radius: var(--ka-zaobleni-m); background: var(--ka-barva-plocha); }
.ka-odhad output { font-size: 1.4em; font-weight: 700; }
.ka-odhad small { flex-basis: 100%; }
.ka-kosik { display: grid; gap: var(--ka-mezera-xs); margin: 0; padding: 0; list-style: none; }
.ka-kosik li { display: flex; flex-wrap: wrap; align-items: center; gap: var(--ka-mezera-xs); padding: 0.5em 0.75em; border: 1px solid var(--ka-barva-linka); border-radius: var(--ka-zaobleni-m); }
.ka-kosik li > span { flex: 1 1 12rem; }
.ka-kosik input { width: 5em; }
.ka-kosik button { background: none; border: 0; color: inherit; text-decoration: underline; cursor: pointer; font: inherit; }
.ka-kosik-prazdny { margin: 0; color: var(--ka-barva-tlumeny); }
.ka-pole-zasady { display: inline-block; margin-inline-start: 1.6em; font-size: var(--ka-krok--1); }
.ka-povinne { color: var(--ka-barva-primarni); }
.ka-pole fieldset { display: grid; gap: var(--ka-mezera-2xs); margin: 0; padding: 0; border: 0; }
.ka-pole legend { margin-block-end: var(--ka-mezera-2xs); padding: 0; font-weight: 600; }
.ka-pole fieldset label { display: flex; gap: var(--ka-mezera-xs); align-items: center; font-weight: 400; }
.ka-pole fieldset input { accent-color: var(--ka-barva-primarni); }
.ka-pole [aria-invalid="true"] { border-color: #c4281c !important; }
.ka-pole-napoveda { color: var(--ka-barva-tlumeny); font-size: var(--ka-krok--1); }
.ka-pole-chyba { color: color-mix(in oklch, #c4281c 80%, var(--ka-barva-text)); font-size: var(--ka-krok--1); }
.ka-formular-hotovo, .ka-formular-chyba { margin: 0; padding: var(--ka-mezera-m); border-radius: var(--ka-zaobleni); }
.ka-formular-hotovo { background: var(--ka-barva-primarni-jemna); color: var(--ka-barva-text); }
.ka-formular-chyba { background: color-mix(in oklch, #c4281c 12%, var(--ka-barva-pozadi)); color: color-mix(in oklch, #c4281c 80%, var(--ka-barva-text)); }
.ka-formular-hotovo p + p, .ka-formular-hotovo ol + p { margin-block-start: var(--ka-mezera-s); }
.ka-formular-hotovo .ka-kroky-nadpis { font-weight: 600; }
.ka-formular-hotovo ol { margin: var(--ka-mezera-2xs) 0 0; padding-inline-start: 1.5em; }
.ka-formular [hidden] { display: none !important; }'; // the display of steps, fields and buttons above would otherwise beat the hidden attribute
    }

    /** Form anchor (where the page returns after sending): the same as the id the form gets when rendered. */
    public static function anchor(array $p): string
    {
        return $p['kotva'] ?? (!empty($p['styl']) ? 's-' . $p['id'] : 'formular-' . $p['id']);
    }

    /** The CAPTCHA widget when the site has one (2.6); in the editor only a note, the provider's script does not load there. */
    public static function captcha(Context $k): string
    {
        if (\Kaleta\Core\Captcha::provider($k->app->settings()) === null) {
            return '';
        }

        return $k->editor ? '<p class="ka-pole ka-captcha"><small>' . e(t('CAPTCHA is checked here when the form is sent.')) . '</small></p>'
            : '<div class="ka-pole">' . \Kaleta\Core\Captcha::widget($k->app->settings()) . '</div>';
    }

    /** Message after sending by the code in the url (?form=<id>&result=<code>) – the text never comes from the url. */
    public static function messages(string $code): string
    {
        return match ($code) {
            'pole' => t('Please check the highlighted field.'),
            'limit' => t('Too many messages have come from your address in a short time. Please try again later.'),
            'rychle' => t('The form was sent before we could check that a person is sending it. Please wait a moment and send it again.'),
            'overeni' => t('The form could not be verified. Reload the page and try again.'),
            'captcha' => t('Please confirm that you are not a robot and send the form again.'),
            'plno' => t('This event is fully booked.'),
            'uzavreno' => t('Registration is closed.'),
            default => t('The message could not be sent. Please try again.'),
        };
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        $o = $p['obsah'];
        $r = $k->app->request;
        $result = $r->get('form') === $p['id'] ? $r->get('result') : '';
        $id = str_contains($a, ' id="') ? '' : ' id="' . e(self::anchor($p)) . '"';
        $hasBasket = in_array('kosik', array_column($o['pole'], 'typ'), true);
        if ($result === 'ok') {
            // data-odeslano: image/web.js reports the conversion (the kaleta:odeslano event and dataLayer, when the site has it);
            // data-kosik-odeslan: the enquiry basket was sent – the script empties it
            // after the thank-you text the next steps, the reply deadline and who replies (2.12, Front\NextSteps) when the form has them
            return '<div' . Text::withClass($a, 'ka-formular-hotovo') . $id . ' role="status" data-odeslano="' . e($o['nazev']) . '"' . ($hasBasket ? ' data-kosik-odeslan' : '') . '><p>' . e($o['dekujeme']) . '</p>'
                . \Kaleta\Front\NextSteps::html($k->app, $o) . '</div>';
        }
        // the registration form of an event (2.11, Core\Calendar): closed after the event or its deadline, or when it is full
        $registration = !$k->editor ? (string) ($k->item['_registration'][0] ?? '') : '';
        if ($registration === 'full' || $registration === 'closed') {
            return '<div' . Text::withClass($a, 'ka-formular-hotovo') . $id . ' role="status"><p>' . e(self::messages($registration === 'full' ? 'plno' : 'uzavreno')) . '</p></div>';
        }
        $k->types['tlacitko'] = true; // the form button looks like the Button element
        $html = $result !== '' ? '<p class="ka-formular-chyba" role="alert">' . e(self::messages($result)) . '</p>' : '';
        $invalid = $result === 'pole' ? $r->getInt('field', -1) : -1;
        [$steps, $stepTitle, $current] = [[], '', ''];
        foreach ($o['pole'] as $i => $field) {
            if ($field['typ'] === 'skryte') {
                // the value is the form's own (Front\Forms) and the visitor normally neither sees nor sends it; on a collection item
                // page it may have been filled from the item ({{nazev}} in a job's template, 2.11), so there it travels with the form –
                // the server takes it only when its own value is a placeholder, and only as short plain text
                $current .= $k->item !== null && (string) ($field['hodnota'] ?? '') !== '' ? '<input type="hidden" name="p' . $i . '" value="' . e((string) $field['hodnota']) . '">' : '';
                continue;
            }
            if ($field['typ'] === 'krok') {
                $steps[] = [$stepTitle, $current]; // a new step of a multi-step form (2.12)
                [$stepTitle, $current] = [(string) $field['popisek'], ''];
                continue;
            }
            $one = match ($field['typ']) {
                'kosik' => self::basketField($field, $i, $p['id'], $i === $invalid, $k),
                'odhad' => self::estimateField($field, $o['pole']),
                default => self::fields($field, $i, $p['id'], $i === $invalid, \Kaleta\Core\Privacy::policyUrl($k->app->settings()), self::attachmentLimit($o)),
            };
            // a condition (2.12): the script shows the field only for the answer; without the script it is always shown
            $when = mb_strtolower(trim((string) ($field['kdyz_pole'] ?? '')));
            $target = $when !== '' ? array_search($when, array_map(fn (array $f): string => mb_strtolower(trim((string) $f['popisek'])), $o['pole']), true) : false;
            $current .= $one !== '' && $target !== false ? '<div class="ka-podminka" data-kdyz="p' . (int) $target . '" data-kdyz-hodnota="' . e(trim((string) ($field['kdyz_hodnota'] ?? ''))) . '">' . $one . '</div>' : $one;
        }
        if ($steps === []) {
            $html .= $current;
        } else {
            // a multi-step form (2.12): one fieldset per step; the script shows one at a time with Back and Next, without it they are all shown
            $steps[] = [$stepTitle, $current];
            $html .= '<div class="ka-formular-kroky" data-kroky>' . implode('', array_map(fn (array $step, int $n): string => '<fieldset class="ka-krok"><legend>'
                . e($step[0] !== '' ? $step[0] : t('Step %d', $n + 1)) . '</legend>' . $step[1] . '</fieldset>', $steps, array_keys($steps))) . '</div>';
        }
        $antispam = new Antispam($k->app->db(), $k->app->settings());

        // data-formular: after an error image/web.js puts back into the fields what the visitor filled in (only their browser keeps it)
        $files = in_array('soubor', array_column($o['pole'], 'typ'), true) ? ' enctype="multipart/form-data"' : '';

        return '<form' . Text::withClass($a, 'ka-formular') . $id . ' method="post" action="' . e($k->url('formular')) . '"' . $files . ' data-formular="' . e($p['id']) . '"' . ($result !== '' ? ' data-obnovit' : '') . '>'
            . '<input type="hidden" name="zdroj" value="' . e($k->source) . '"><input type="hidden" name="prvek" value="' . e($p['id']) . '">'
            . '<input type="hidden" name="zpet" value="' . e($k->app->url($r->path())) . '">' . \Kaleta\Front\Forms::ATTRIBUTION_FIELDS
            . $antispam->fields('formular|' . $k->source . '|' . $p['id'])
            . $html
            . (empty($o['bez_captcha']) ? self::captcha($k) : '')
            . '<p class="ka-pole"><button class="ka-tlacitko ka-tlacitko--primarni" type="submit">' . e($o['tlacitko']) . '</button></p></form>';
    }

    /**
     * The enquiry basket field (2.11, Builder\Products): the products the visitor collected with Add to enquiry, which the
     * script keeps in the browser and writes into the hidden field as JSON. Without the script a product opened from Add to
     * enquiry (?product=collection/item&variant=…&quantity=…) is in it, checked like any basket line.
     */
    private static function basketField(array $field, int $i, string $element, bool $error, Context $k): string
    {
        $r = $k->app->request;
        $prefill = '[]';
        $list = '';
        if (preg_match('#^([a-z0-9-]{1,110})/([a-z0-9-]{1,160})$#D', $r->get('product'), $m) === 1) {
            $line = ['c' => $m[1], 'i' => $m[2], 'v' => mb_substr($r->get('variant'), 0, 100), 'q' => max(1, min(9999, $r->getInt('quantity', 1)))];
            $lines = \Kaleta\Builder\Products::basketLines($k->app->db(), (string) json_encode([$line]));
            if ($lines !== null && $lines !== []) {
                $line['n'] = (string) $k->app->db()->value('SELECT p.nazev FROM {kolekce_polozky} p JOIN {kolekce} k ON k.idk = p.idk WHERE k.seo_link = ? AND p.seo_link = ? AND p.zobrazit = 1 LIMIT 1', [$m[1], $m[2]]);
                $prefill = (string) json_encode([$line], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $list = '<li>' . e($lines[0]) . '</li>';
            }
        }
        $id = 'f-' . $element . '-' . $i;
        $star = $field['povinne'] ? ' <span class="ka-povinne" aria-hidden="true">*</span>' : '';
        $message = $error ? '<span class="ka-pole-chyba" id="' . $id . '-chyba">' . e(t('Add at least one product to the enquiry.')) . '</span>' : '';

        return '<div class="ka-pole ka-kosik-pole" id="poptavka"><span class="ka-popisek" id="' . $id . '">' . e($field['popisek']) . $star . '</span>'
            . '<ul class="ka-kosik" data-kosik-seznam aria-labelledby="' . $id . '">' . $list . '</ul>'
            . '<p class="ka-kosik-prazdny"' . ($list !== '' ? ' hidden' : '') . '>' . e(t('The enquiry is empty – add products with Add to enquiry.')) . '</p>'
            . '<input type="hidden" name="p' . $i . '" value="' . e($prefill) . '" data-kosik-pole' . ($field['povinne'] ? ' data-povinne' : '') . '>' . $message . '</div>';
    }

    private static function fields(array $field, int $i, string $element, bool $error = false, string $privacyPolicy = '', int $attachmentLimit = self::MAX_ATTACHMENT): string
    {
        $id = 'f-' . $element . '-' . $i;
        $displayName = 'p' . $i;
        $required = $field['povinne'] ? ' required' : '';
        $star = $field['povinne'] ? ' <span class="ka-povinne" aria-hidden="true">*</span>' : '';
        $labelText = e($field['popisek']);
        // a field the server rejected: marked and with a message that aria-describedby points to
        $marking = $error ? ' aria-invalid="true" aria-describedby="' . $id . '-chyba" autofocus' : '';
        $message = $error ? '<span class="ka-pole-chyba" id="' . $id . '-chyba">' . e($field['typ'] === 'email' ? t('Enter a valid e-mail address.') : t('Please fill in this field correctly.')) . '</span>' : '';
        if ($field['typ'] === 'souhlas') {
            $link = $privacyPolicy !== '' ? ' <a class="ka-pole-zasady" href="' . e($privacyPolicy) . '" target="_blank">' . e(t('Privacy policy')) . '</a>' : '';

            return '<p class="ka-pole ka-pole-souhlas"><label><input type="checkbox" name="' . $displayName . '" value="1"' . $required . $marking . '> <span>' . $labelText . $star . '</span></label>' . $link . $message . '</p>';
        }
        if ($field['typ'] === 'skryte') {
            return ''; // the value is the form's own (Front\Forms), the visitor neither sees nor sends it
        }
        if ($field['typ'] === 'zaskrtnuti') {
            $options = '';
            foreach (self::optionPrices($field) as $m => $price) {
                $options .= '<label><input type="checkbox" name="' . $displayName . '[]" value="' . e((string) $m) . '"' . self::priceAttribute($price) . $marking . '> ' . e((string) $m) . '</label>';
            }
            // required = at least one ticked; the browser cannot say that about a group, the server does (Front\Forms)
            return '<div class="ka-pole ka-pole-zaskrtnuti"><fieldset' . ($field['povinne'] ? ' aria-required="true"' : '') . '><legend>' . $labelText . $star . '</legend>' . $options . '</fieldset>' . $message . '</div>';
        }
        if ($field['typ'] === 'volba') {
            $options = '';
            $j = 0;
            foreach (self::optionPrices($field) as $m => $price) {
                $options .= '<label><input type="radio" name="' . $displayName . '" value="' . e((string) $m) . '"' . self::priceAttribute($price) . ($j++ === 0 ? $required . $marking : '') . '> ' . e((string) $m) . '</label>';
            }

            return '<div class="ka-pole"><fieldset><legend>' . $labelText . $star . '</legend>' . $options . '</fieldset>' . $message . '</div>';
        }
        $label = '<label for="' . $id . '">' . $labelText . $star . '</label>';
        $input = match ($field['typ']) {
            'textarea' => '<textarea id="' . $id . '" name="' . $displayName . '" maxlength="5000"' . $required . $marking . '></textarea>',
            'vyber' => '<select id="' . $id . '" name="' . $displayName . '"' . $required . $marking . '><option value="">' . e(t('— choose —')) . '</option>'
                . implode('', array_map(fn (string|int $m, float $price): string => '<option' . self::priceAttribute($price) . '>' . e((string) $m) . '</option>', array_keys($prices = self::optionPrices($field)), $prices)) . '</select>',
            'datum' => '<input id="' . $id . '" name="' . $displayName . '" type="date"' . $required . $marking . '>',
            'cislo' => '<input id="' . $id . '" name="' . $displayName . '" type="number" step="any" inputmode="decimal"'
                . (self::price($field['cena_za_jednotku'] ?? '') != 0.0 ? ' data-cena-za="' . self::price($field['cena_za_jednotku']) . '"' : '') . $required . $marking . '>',
            'soubor' => '<input id="' . $id . '" name="' . $displayName . '" type="file" accept=".' . implode(',.', self::ATTACHMENT_EXTENSIONS) . '"' . $required . $marking . '>'
                . '<small class="ka-pole-napoveda">' . e(t('Up to %d MB: PDF, image, document or ZIP.', intdiv($attachmentLimit, 1048576))) . '</small>',
            // phone: the same rule as on the server (Front\Forms), the browser checks it right away; the pattern is valid with the v flag too
            'tel' => '<input id="' . $id . '" name="' . $displayName . '" type="tel" autocomplete="tel" maxlength="30" pattern="' . self::PHONE_PATTERN . '" title="' . e(t('Phone number, for example +44 20 7946 0958.')) . '"' . $required . $marking . '>',
            default => '<input id="' . $id . '" name="' . $displayName . '" type="' . ($field['typ'] === 'email' ? 'email" autocomplete="email' : 'text' . self::autocomplete($field['popisek'])) . '" maxlength="300"' . $required . $marking . '>',
        };

        return '<p class="ka-pole">' . $label . $input . $message . '</p>';
    }

    /**
     * Autocomplete of a text field by its label (WCAG 1.3.5): name and company. The field type stays "text",
     * so that forms built earlier keep working.
     */
    private static function autocomplete(string $labelText): string
    {
        return match (true) {
            (bool) preg_match('/^(vaše |celé |your |full )?(jméno|name)\b/iu', trim($labelText)) => '" autocomplete="name',
            (bool) preg_match('/^(firma|společnost|název firmy|company|organi[sz]ation)\b/iu', trim($labelText)) => '" autocomplete="organization',
            default => '',
        };
    }

    /** @return list<string> options of a select or radio buttons */
    public static function options(array $field): array
    {
        return array_keys(self::optionPrices($field));
    }

    /**
     * The options of a choice field with their prices for the estimate (2.12): label => price (0 without one).
     *
     * @return array<string, float>
     */
    public static function optionPrices(array $field): array
    {
        $text = match ($field['typ'] ?? '') {
            'volba' => (string) ($field['moznosti_volby'] ?? ''),
            'zaskrtnuti' => (string) ($field['moznosti_zaskrtnuti'] ?? ''),
            default => (string) ($field['moznosti'] ?? ''),
        };
        $out = [];
        foreach (explode("\n", $text) as $line) {
            $line = trim($line);
            $price = preg_match(self::PRICED_OPTION, $line, $m) === 1 ? self::price($m[2]) : null;
            $label = $price !== null ? trim($m[1]) : $line;
            if ($label !== '') {
                $out[$label] = $price ?? 0.0;
            }
        }

        return $out;
    }

    /**
     * The price estimate field (2.12): the label and the amount, which the script recalculates as the visitor answers;
     * without the script it shows the base price and the server computes the real estimate when the form is sent.
     *
     * @param list<array<string, mixed>> $fields
     */
    private static function estimateField(array $field, array $fields): string
    {
        $base = self::price($field['zaklad'] ?? '');
        $currency = mb_substr(trim((string) ($field['mena'] ?? '')), 0, 10);

        return '<p class="ka-pole ka-odhad" data-odhad data-zaklad="' . $base . '" data-mena="' . e($currency) . '"><span>' . e((string) $field['popisek']) . '</span> <output aria-live="polite">'
            . e(self::money($base, $currency)) . '</output><small class="ka-pole-napoveda">' . e(t('An estimate from your answers – the final price follows in our reply.')) . '</small></p>';
    }

    /** The price of an option for the estimate script; nothing without one. */
    private static function priceAttribute(float $price): string
    {
        return $price != 0.0 ? ' data-cena="' . $price . '"' : '';
    }

    /** A price written by the administrator ("1 200", "12,50") as a number; 0 when empty or not a number. */
    public static function price(mixed $value): float
    {
        $v = str_replace([' ', "\u{a0}", ','], ['', '', '.'], is_scalar($value) ? (string) $value : '');

        return is_numeric($v) ? (float) $v : 0.0;
    }

    /**
     * Which fields are shown for the given answers (2.12): a field with a condition only when the field with that label has
     * that value (a choice: equals it; options to tick: it is ticked; a "*" value: anything filled in). A condition on a
     * field that does not exist or is itself hidden hides the field. Pure – the server and the tests use it.
     *
     * @param list<array<string, mixed>> $fields
     * @param array<int, string|list<string>> $answers index => value (a list for options to tick)
     * @return array<int, bool>
     */
    public static function visible(array $fields, array $answers): array
    {
        $byLabel = [];
        foreach ($fields as $i => $f) {
            $byLabel[mb_strtolower(trim((string) ($f['popisek'] ?? '')))] ??= $i;
        }
        $visible = [];
        $resolve = function (int $i, int $depth) use (&$resolve, &$visible, $fields, $answers, $byLabel): bool {
            if (isset($visible[$i])) {
                return $visible[$i];
            }
            $when = mb_strtolower(trim((string) ($fields[$i]['kdyz_pole'] ?? '')));
            if ($when === '') {
                return $visible[$i] = true;
            }
            $target = $byLabel[$when] ?? null;
            if ($target === null || $target === $i || $depth > 10 || !$resolve($target, $depth + 1)) {
                return $visible[$i] = false;
            }
            $expected = trim((string) ($fields[$i]['kdyz_hodnota'] ?? ''));
            $answer = $answers[$target] ?? '';

            return $visible[$i] = is_array($answer) ? ($expected === '*' ? $answer !== [] : in_array($expected, $answer, true))
                : ($expected === '*' ? trim($answer) !== '' : trim($answer) === $expected);
        };
        foreach (array_keys($fields) as $i) {
            $resolve($i, 0);
        }

        return $visible;
    }

    /**
     * The price estimate (2.12): the base price, plus the price of every chosen or ticked option, plus every number × its
     * price per unit – only of the fields that are shown. Pure; the server computes it again, never trusting the browser.
     *
     * @param list<array<string, mixed>> $fields
     * @param array<int, string|list<string>> $answers
     * @param array<int, bool> $visible
     */
    public static function estimate(array $fields, array $answers, array $visible, float $base): float
    {
        $total = $base;
        foreach ($fields as $i => $f) {
            if (!($visible[$i] ?? true)) {
                continue;
            }
            $answer = $answers[$i] ?? '';
            $type = (string) ($f['typ'] ?? '');
            if (in_array($type, ['vyber', 'volba', 'zaskrtnuti'], true)) {
                $prices = self::optionPrices($f);
                foreach ((array) $answer as $chosen) {
                    $total += $prices[(string) $chosen] ?? 0.0;
                }
            } elseif ($type === 'cislo' && is_string($answer) && is_numeric(str_replace(',', '.', $answer))) {
                $total += (float) str_replace(',', '.', $answer) * self::price($f['cena_za_jednotku'] ?? '');
            }
        }

        return round($total, 2);
    }

    /** An amount for visitors: whole numbers with the thousands separator of the language, the currency after it. */
    public static function money(float $amount, string $currency): string
    {
        $number = format_count($amount, abs($amount - round($amount)) < 0.005 ? 0 : 2);

        return trim($number . ($currency !== '' ? "\u{a0}" . $currency : ''));
    }
}
