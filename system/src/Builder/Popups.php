<?php

declare(strict_types=1);

namespace Kaleta\Builder;

use Kaleta\Core\Db;

/**
 * Popups as site parts: the content is built in the builder (target „popup:<id>“, versions in ka_stavba_revize), a popup has a type,
 * a trigger, display rules and a frequency.
 *
 * The rules the server knows (pages, collections, news, language, period) decide whether the popup is inserted into the page
 * at all. The rest (device, campaign, where the visitor came from, number of pages in the visit, frequency) is evaluated by
 * image/web.js in the browser – without cookies, only the visitor's sessionStorage and localStorage. The counters of views,
 * closes and conversions are incremented by POST /popup (Front\Kernel).
 */
final class Popups
{
    /** type => [name, popover] – the window and the full screen close with a click outside (auto), the panel and bars do not (manual) */
    public const array TYPES = [
        'okno' => ['Window in the middle', 'auto'],
        'panel' => ['Slide-in panel in the corner', 'manual'],
        'lista-nahore' => ['Bar at the top', 'manual'],
        'lista-dole' => ['Bar at the bottom', 'manual'],
        'cela' => ['Full screen', 'auto'],
    ];

    /** trigger => [name, value unit ('' = without a value)] */
    public const array TRIGGERS = [
        'cas' => ['After a number of seconds', 's'],
        'posun' => ['After scrolling part of the page', '%'],
        'odchod' => ['When the visitor is about to leave', ''],
        'necinnost' => ['After a number of seconds without activity', 's'],
        'stranky' => ['After a number of pages in the visit', 'stránek'],
        'klik' => ['Only by clicking a link or button', ''],
    ];

    public const array FREQUENCIES = [
        'relace' => 'Once per visit',
        'dni' => 'Once every number of days',
        'zavreni' => 'Until the visitor closes it',
        'odeslani' => 'Until the visitor sends the form in it',
        'vzdy' => 'Every time the trigger is met',
    ];

    public const array DEVICES = ['vse' => 'All devices', 'pocitac' => 'Computer and tablet only', 'telefon' => 'Phone only'];

    public const string ADDRESS_PATTERN = '/^[a-z0-9][a-z0-9-]{0,59}$/D';

    /** Rules of a new popup: the whole site, all languages, no restrictions. */
    public static function defaultRules(): array
    {
        return ['kde' => 'vse', 'stranky' => [], 'kolekce' => [], 'novinky' => false, 'jazyk' => '', 'od' => '', 'do' => '',
            'zarizeni' => 'vse', 'utm' => '', 'odkud' => ''];
    }

    /** @param array<string, mixed> $p */
    public static function sanitizeRules(array $p): array
    {
        $date = fn (mixed $d): string => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $d) && checkdate((int) substr($d, 5, 2), (int) substr($d, 8, 2), (int) substr($d, 0, 4)) ? $d : '';
        $text = fn (mixed $t): string => mb_substr(trim(preg_replace('/[^\p{L}\p{N} ._\/-]/u', '', is_string($t) ? $t : '') ?? ''), 0, 80);

        return [
            'kde' => ($p['kde'] ?? '') === 'vybrane' ? 'vybrane' : 'vse',
            'stranky' => array_values(array_unique(array_filter(array_map('intval', is_array($p['stranky'] ?? null) ? $p['stranky'] : []), fn (int $i): bool => $i > 0))),
            'kolekce' => array_values(array_unique(array_filter(is_array($p['kolekce'] ?? null) ? $p['kolekce'] : [], fn (mixed $k): bool => is_string($k) && preg_match('/^[a-z0-9-]{1,110}$/D', $k) === 1))),
            'novinky' => !empty($p['novinky']),
            'jazyk' => \Kaleta\Core\Language::offeredOrDefault($p['jazyk'] ?? null),
            'od' => $date($p['od'] ?? ''),
            'do' => $date($p['do'] ?? ''),
            'zarizeni' => isset(self::DEVICES[$p['zarizeni'] ?? '']) ? $p['zarizeni'] : 'vse',
            'utm' => $text($p['utm'] ?? ''),
            'odkud' => $text($p['odkud'] ?? ''),
        ];
    }

    /**
     * Decides the rules the server knows: language and period always apply, the choice of places only with „vybrane“.
     *
     * @param array<string, mixed> $rules sanitized rules
     * @param array{ids: ?int, kolekce: ?string, novinky: bool, jazyk: string, dnes: string} $whereParts the displayed page
     */
    public static function matches(array $rules, array $whereParts): bool
    {
        if ($rules['jazyk'] !== '' && $rules['jazyk'] !== $whereParts['jazyk']) {
            return false;
        }
        if (($rules['od'] !== '' && $whereParts['dnes'] < $rules['od']) || ($rules['do'] !== '' && $whereParts['dnes'] > $rules['do'])) {
            return false;
        }
        if ($rules['kde'] === 'vse') {
            return true;
        }

        return ($whereParts['ids'] !== null && in_array($whereParts['ids'], $rules['stranky'], true))
            || ($whereParts['kolekce'] !== null && in_array($whereParts['kolekce'], $rules['kolekce'], true))
            || ($whereParts['novinky'] && $rules['novinky']);
    }

    /** A database row with the rules decoded. */
    public static function prepare(array $r): array
    {
        $r['pravidla'] = self::sanitizeRules(json_decode((string) $r['pravidla'], true) ?: []);
        foreach (['idpp', 'hodnota', 'dni', 'aktivni', 'poradi', 'zobrazeni', 'zavreni', 'konverze'] as $number) {
            $r[$number] = (int) $r[$number];
        }

        return $r;
    }

    public static function byId(Db $db, int $id): ?array
    {
        $r = $db->one('SELECT * FROM {popupy} WHERE idpp = ?', [$id]);

        return $r === null ? null : self::prepare($r);
    }

    /** @return list<array<string, mixed>> */
    public static function all(Db $db): array
    {
        return array_map(self::prepare(...), $db->all('SELECT * FROM {popupy} ORDER BY poradi, nazev'));
    }

    /**
     * Published and enabled popups for the displayed page.
     *
     * @param array{ids: ?int, kolekce: ?string, novinky: bool, jazyk: string, dnes: string} $whereParts
     * @return list<array<string, mixed>>
     */
    public static function forPage(Db $db, array $whereParts): array
    {
        return array_values(array_filter(
            array_map(self::prepare(...), $db->all('SELECT * FROM {popupy} WHERE aktivni = 1 AND stavba IS NOT NULL ORDER BY poradi, nazev')),
            fn (array $p): bool => self::matches($p['pravidla'], $whereParts),
        ));
    }

    /** A free popup slug (#popup-<slug>) derived from the text. */
    public static function address(Db $db, string $z, int $idpp = 0): string
    {
        return \Kaleta\Core\Slug::makeUnique(slugify($z, 50) ?: 'popup', fn (string $a): bool => $db->value('SELECT idpp FROM {popupy} WHERE adresa = ? AND idpp <> ?', [$a, $idpp]) !== null, 60);
    }

    /**
     * Popup wrapper on the site. The content is an already rendered build; image/web.js reads data-* (trigger, frequency, browser rules).
     * $open: preview – the popup opens right after loading, regardless of trigger and frequency.
     */
    public static function wrapper(array $p, string $content, string $counterUrl, bool $open = false): string
    {
        $type = isset(self::TYPES[$p['typ']]) ? $p['typ'] : 'okno';
        $id = 'popup-' . $p['adresa'];
        $dialog = in_array($type, ['okno', 'cela'], true);
        $data = ['popup' => (string) $p['idpp'], 'spoustec' => $p['spoustec'], 'hodnota' => (string) $p['hodnota'], 'cetnost' => $p['cetnost'],
            'dni' => (string) $p['dni'], 'zarizeni' => $p['pravidla']['zarizeni'], 'utm' => $p['pravidla']['utm'], 'odkud' => $p['pravidla']['odkud'],
            'pocitadlo' => $counterUrl] + ($open ? ['otevrit' => '1'] : []);

        return '<div id="' . e($id) . '" class="ka-popup ka-popup--' . e($type) . '" popover="' . self::TYPES[$type][1] . '" role="' . ($dialog ? 'dialog' : 'region') . '"'
            . ' aria-label="' . e($p['nazev']) . '"' . implode('', array_map(fn (string $k, string $v): string => ' data-' . $k . '="' . e($v) . '"', array_keys($data), $data)) . '>'
            . '<button type="button" class="ka-popup-zavrit" popovertarget="' . e($id) . '" popovertargetaction="hide" aria-label="' . e(t('Close')) . '">×</button>'
            . '<div class="ka-popup-obsah stavba">' . $content . '</div></div>';
    }

    /** Wrapper in the builder editor: the popup stands on the canvas so that it can be edited (without popover and trigger). */
    public static function editorWrapper(array $p, string $content): string
    {
        $type = isset(self::TYPES[$p['typ']]) ? $p['typ'] : 'okno';

        return '<div class="ka-popup ka-popup--' . e($type) . ' ka-popup--editor"><div class="ka-popup-obsah stavba">' . $content . '</div></div>';
    }

    /** Ready-made popups for a new popup: key => [name, description, type, trigger, value]. */
    public const array LIBRARY = [
        'newsletter' => ['Přihlášení k newsletteru', 'A heading, a short text and an e-mail field with confirmed sign-up.', 'okno', 'posun', 50],
        'magnet' => ['Download for an e-mail', 'A guide or price list in exchange for contact details – the form goes to Enquiries.', 'okno', 'odchod', 0],
        'lista' => ['Announcement bar', 'A slim bar at the top with a short message and a link.', 'lista-nahore', 'cas', 1],
        'sleva' => ['Discount or offer', 'A bold offer with a code and a button.', 'okno', 'cas', 15],
        'udalost' => ['Event invitation', 'A corner panel with the date, the place and a registration link.', 'panel', 'cas', 8],
        'prazdny' => ['Blank pop-up', 'A heading and a text – build the rest in the builder.', 'okno', 'klik', 0],
    ];

    /** Build of a ready-made popup in the content language. */
    public static function libraryBuild(string $key, string $language = 'cs'): array
    {
        return \Kaleta\Core\Language::runWith($language, function () use ($key): array {
            $n = Build::fresh(...);
            $h = fn (string $text, string $htmlTag = 'h2'): array => ['znacka' => $htmlTag] + $n('nadpis', ['text' => $text]);
            $children = match ($key) {
                'newsletter' => [$h(t('News once a month')), $n('text', ['html' => '<p>' . e(t('Tips and news from our field. No spam – unsubscribe with one click.')) . '</p>']), $n('newsletter')],
                'magnet' => [$h(t('Download the free guide')), $n('text', ['html' => '<p>' . e(t('We will send it by e-mail. We use your contact only to reply.')) . '</p>']),
                    $n('formular', ['nazev' => t('Guide download'), 'dekujeme' => t('Thank you! We will send you the guide by e-mail.'), 'tlacitko' => t('Send me the guide'),
                        'pole' => [['popisek' => t('Jméno'), 'typ' => 'text', 'povinne' => false, 'moznosti' => ''], ['popisek' => t('Email'), 'typ' => 'email', 'povinne' => true, 'moznosti' => ''],
                            ['popisek' => t('I agree to the processing of my personal data for the purpose of handling this enquiry.'), 'typ' => 'souhlas', 'povinne' => true, 'moznosti' => '']]])],
                'lista' => [$n('kontejner', [], [$h(t('We are now also open on Saturday mornings.'), 'p'), $n('tlacitko', ['text' => t('More information'), 'odkaz' => '#', 'varianta' => 'odkaz'])])],
                'sleva' => [$h(t('10% off your first order')), $n('text', ['html' => '<p>' . e(t('Enter the code')) . ' <strong>' . e(t('WELCOME10')) . '</strong>.</p>']), $n('tlacitko', ['text' => t('Get the discount'), 'odkaz' => '#'])],
                'udalost' => [$h(t('Open day'), 'h3'), $n('text', ['html' => '<p>' . e(t('Saturday 12 October, 10 am – 4 pm. Come and see how we work.')) . '</p>']), $n('tlacitko', ['text' => t('I want to come'), 'odkaz' => '#'])],
                default => [$h(t('Window heading')), $n('text', ['html' => '<p>' . e(t('A short text for the window.')) . '</p>'])],
            };
            if ($key === 'lista') {
                $children[0]['styl'] = ['zaklad' => ['zobrazeni' => 'flex', 'smer' => 'row', 'zarovnani' => 'center', 'rozmisteni' => 'center', 'mezera' => 's']];
            }

            return Build::sanitize(['v' => Build::VERSION, 'deti' => [$n('kontejner', [], $children)]], true)[0];
        });
    }
}
