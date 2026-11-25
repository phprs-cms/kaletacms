<?php

declare(strict_types=1);

namespace Kaleta\Builder;

use Kaleta\Core\App;
use Kaleta\Core\Db;
use Kaleta\Core\WpContent;

/**
 * Page build: a tree of elements {"v": 1, "deti": [{"id", "typ", "znacka", "obsah", "styl", "tridy", "kotva", "deti"}]}.
 *
 * The single validator for the editor, MCP and API (sanitize) and the single renderer in PHP (render) – what the editor shows is exactly the site.
 * An invalid part of the tree is fixed or discarded and reported during sanitizing; the public site never throws an exception.
 */
final class Build
{
    public const int VERSION = 1;
    public const int MAX_ELEMENTS = 800;
    public const int MAX_DEPTH = 12;

    /**
     * A class name an element or a shared class may take. Names starting with "ka-" are Kaleta's own (3.31.2, N99): the
     * site's scripts and styles hang on them (image/web.js follows the links inside .ka-kolekce-strany, the store locator
     * reads .ka-pobocky-seznam…), so a class from the editor, MCP or an import never starts with it – the same rule as
     * data-ka-… attributes and ka-… anchors. The renderer checks a stored build again (userClasses).
     */
    public const string CLASS_PATTERN = '/^(?!ka-)[a-z][a-z0-9-]{0,40}(__[a-z0-9-]{1,30})?(--[a-z0-9-]{1,30})?$/D';

    /**
     * Custom attributes of an element: only harmless ones – no on…, style, href or src, and none of the hooks the site's scripts read
     * (image/web.js, image/vitals.js, the cookie bar and accessibility toolbar scripts, image/editor.js on the page): a hook such as
     * data-leaflet, data-atribuce or data-kosik makes a script load a file or build a link from the value (3.3.2, N25). The list is
     * checked by tools/unit-tests.php against every data-* attribute those scripts mention; the renderer checks a stored build again.
     */
    public const string ATTRIBUTE_PATTERN = '/^(data-(?!ka-|(?:admin-url|adresa|atribuce|bez-skriptu|cast|casy|cekat|cena|cena-za|cetnost|cookies|den|dni|dny|editor|file-too-large|files-too-large|formular|gtm|hledat|hit|hit-key|hit-news|hit-page|hodnota|hotovo|kalendar|karusel|kategorie|kdyz|kdyz-hodnota|kolekce|koncept|koncept-url|konec|konverze|kopirovat|kosik|kosik-odeslan|kosik-pole|kosik-seznam|krok|kroky|lat|leaflet|lng|mapa|mapa-hned|max-bytes|max-post|mena|nahravani|nejblizsi|obnovit|obrazek|odeslano|odhad|odkud|odpocet|otevrit|pobocky|pocitadlo|popup|porovnani|porovnat|potvrzeni|prazdne|pred-po|pristupnost|pristupnost-volba|produkt|recaptcha|rezervace|rezervovat|samo|sdilet|sloty|sluzby|soubor|souhlas|spoustec|tema|tema-volba|tema-vychozi|text|text-chyba|text-na-mape|text-odmitnuto|text-serazeno|texty|titulek|utm|vitals|vlozit|volba|vybrano|vzdalenost|zadost|zaklad|zalozky|zapnuto|zarizeni|zavrit|znovu|zprava)$)[a-z0-9-]{1,30}|aria-[a-z]{2,20}|title|lang|role|rel)$/iD';

    /** Stands for the page content in a site-part wrapper until the build's own tokens are filled (html()). */
    private const string CONTENT_MARK = "\u{E000}ka-page-content\u{E000}";

    /** Ids used by the site layout (skip to content, navigation, cookie bar) – an element's anchor must not repeat them. */
    public const array RESERVED_ANCHORS = ['obsah', 'navigace', 'cookies-lista', 'cookies-nadpis', 'cookies-znovu'];

    /**
     * May an element take this anchor (id)? Not one of the layout's ids, not the style ids (s-…) or Kaleta's own namespace
     * (ka-…), and – 3.19.1 (N78) – none of the ids the site's scripts look up (Core\Html::isSafeId, the list texts have had
     * since 3.15, N21: gtag, grecaptcha, poptavka, l, datalayer…): an element with such an id would take over the hook.
     */
    public static function isFreeAnchor(string $anchor): bool
    {
        return !in_array($anchor, self::RESERVED_ANCHORS, true) && preg_match('/^(s|ka)-/', $anchor) !== 1 && \Kaleta\Core\Html::isSafeId($anchor);
    }

    /** Registry of element types (order = the order in the Add panel). @var list<class-string<Element>> */
    public const array ELEMENTS = [
        Elements\Section::class, Elements\Container::class, Elements\Grid::class,
        Elements\Heading::class, Elements\Text::class, Elements\Image::class, Elements\Button::class, Elements\BulletList::class,
        Elements\Quote::class, Elements\Faq::class, Elements\Video::class, Elements\Divider::class,
        Elements\Icon::class, Elements\Gallery::class, Elements\Tabs::class, Elements\Carousel::class, Elements\PricingTable::class, Elements\BeforeAfter::class, Elements\Hotspots::class, Elements\Timeline::class,
        Elements\Map::class, Elements\Embed::class, Elements\Breadcrumbs::class,
        Elements\Counter::class, Elements\Progress::class, Elements\Rating::class, Elements\Countdown::class, Elements\SocialLinks::class, Elements\Search::class,
        Elements\News::class, Elements\GoogleReviews::class, Elements\CollectionList::class, Elements\ItemNavigation::class, Elements\EnquiryButton::class, Elements\StoreLocator::class, Elements\Form::class, Elements\Booking::class, Elements\Newsletter::class, Elements\Component::class, Elements\Html::class, Elements\BackToTop::class,
        Elements\Logo::class, Elements\Navigation::class, Elements\LanguageSwitcher::class, Elements\CompanyDetails::class, Elements\PageContent::class,
    ];

    /** @return class-string<Element>|null */
    public static function className(string $type): ?string
    {
        foreach (self::ELEMENTS as $className) {
            if ($className::TYPE === $type) {
                return $className;
            }
        }

        return null;
    }

    public static function newId(): string
    {
        return substr(bin2hex(random_bytes(4)), 0, 7);
    }

    /** A new element of the given type with the default content and style (for the editor, the library and the HTML conversion). */
    public static function fresh(string $type, array $content = [], array $children = []): array
    {
        $className = self::className($type) ?? throw new \InvalidArgumentException('Neznámý typ prvku ' . $type);
        $defaults = array_map(fn (array $field): mixed => $field['vychozi'] ?? '', $className::properties());

        return ['id' => self::newId(), 'typ' => $type, 'znacka' => $className::HTML_TAGS[0], 'obsah' => $content + $defaults, 'styl' => $className::defaultStyle(), 'tridy' => [], 'deti' => $children];
    }

    /**
     * Sanitizes a tree from anyone (editor, AI, import). Unknown types, properties and values are discarded, missing ones filled
     * with defaults, duplicate or missing ids created anew.
     *
     * @param bool $admin can change ADMIN_ONLY elements (custom HTML); for others their content is taken over from $previous
     * @param array<string, mixed>|null $previous the existing build (because of ADMIN_ONLY elements)
     * @return array{0: array<string, mixed>, 1: array<string, string>} [build, errors path => text]
     */
    /**
     * 3.34 review low (f): may the person who saves change a Form's Purpose (job application or enquiry)? Only someone who
     * may open Applications – set per request by Admin\Kernel and Mcp\Server; null = no person (imports, the CLI). When
     * false, sanitize() keeps the Purpose a form had in $previous (a new form gets none) and the schema shows it read-only.
     */
    public static ?bool $purposeAllowed = null;

    /** @var array<string, string> the Purpose of each form of the previous build while sanitize() runs (purposeAllowed false) */
    private static array $previousPurposes = [];

    /** 3.50 N132: why a publish is refused (Builder\Publisher::guard) – Czech like the other builder notes, English in Mcp\Translator::MESSAGES. */
    public const string APPLICATION_FORM_REFUSED = 'Formulář žádostí o místo smí odebrat nebo nahradit jen uživatel se sekcí Žádosti o místo – nepublikováno, koncept zůstává uložený.';

    /** 3.50 review: a publish that would change the recipients of a published application form (e.g. a restored older version) – English in Mcp\Translator::MESSAGES. */
    public const string APPLICATION_RECIPIENTS_REFUSED = 'Příjemce formuláře žádostí o místo smí změnit jen uživatel se sekcí Žádosti o místo – nepublikováno, koncept zůstává uložený.';

    /**
     * 3.50.1 (SEC-l1): a publish that would hide a published application form (its style or an ancestor's, in any state) or
     * put another form with a file field beside it – English in Mcp\Translator::MESSAGES.
     */
    public const string APPLICATION_FORM_SHADOWED = 'Formulář žádostí o místo smí skrýt nebo k němu přidat jiný formulář se souborem jen uživatel se sekcí Žádosti o místo – nepublikováno, koncept zůstává uložený.';

    /** 3.50.1: the refusal of deleting a pop-up or component that holds an application form – English in Mcp\Translator::MESSAGES. */
    public const string APPLICATION_FORM_REMOVAL_REFUSED = 'Formulář žádostí o místo smí odebrat jen uživatel se sekcí Žádosti o místo – nic se nesmazalo.';

    /** 3.50.1 review: the refusal of switching off a pop-up, changing its rules or hiding a page that holds an application form – English in Mcp\Translator::MESSAGES. */
    public const string APPLICATION_FORM_OFF_SITE_REFUSED = 'Formulář žádostí o místo smí stáhnout z webu jen uživatel se sekcí Žádosti o místo – nic se nezměnilo.';

    /** The content fields of an application form that receive the applicants' data – locked with its Purpose (3.50 N132; the hidden copy since the 3.50 review). */
    private const array LOCKED_RECIPIENTS = ['prijemce', 'skryta_kopie'];

    /** @var array<string, array<string, string>> the recipients (LOCKED_RECIPIENTS) of each application form of the previous build while sanitize() runs (purposeAllowed false, 3.50 N132) */
    private static array $previousRecipients = [];

    public static function sanitize(mixed $input, bool $admin = true, ?array $previous = null): array
    {
        $previousForms = self::$purposeAllowed === false && $previous !== null ? self::elementsOfType($previous, fn (string $className): bool => $className::TYPE === 'formular') : [];
        self::$previousPurposes = array_map(fn (array $p): string => (string) ($p['obsah']['ucel'] ?? ''), $previousForms);
        self::$previousRecipients = array_map(fn (array $p): array => array_map(fn (string $field): string => (string) ($p['obsah'][$field] ?? ''), array_combine(self::LOCKED_RECIPIENTS, self::LOCKED_RECIPIENTS)),
            array_filter($previousForms, fn (array $p): bool => ($p['obsah']['ucel'] ?? '') === \Kaleta\Core\Applications::PURPOSE));
        $errors = [];
        $used = [];
        $count = 0;
        $protected = $admin || $previous === null ? [] : self::elementsOfType($previous, fn (string $className): bool => $className::ADMIN_ONLY);
        $children = is_array($input) && is_array($input['deti'] ?? null) ? $input['deti'] : (is_array($input) && array_is_list($input) ? $input : []);
        if (!is_array($input)) {
            $errors['stavba'] = 'Stavba musí být objekt {"v": 1, "deti": [...]}.';
        }
        $build = ['v' => self::VERSION, 'deti' => self::sanitizeChildren($children, 'deti', 1, $errors, $used, $count, $admin, $protected)];

        return [$build, $errors];
    }

    /** @param array<string, array<string, mixed>> $protected */
    private static function sanitizeChildren(array $children, string $path, int $depth, array &$errors, array &$used, int &$count, bool $admin, array $protected): array
    {
        $output = [];
        foreach (array_values($children) as $i => $p) {
            $place = $path . '[' . $i . ']';
            if (!is_array($p) || ($className = self::className((string) ($p['typ'] ?? ''))) === null) {
                $errors[$place] = 'Neznámý typ prvku „' . mb_substr((string) (is_array($p) ? ($p['typ'] ?? '') : ''), 0, 30) . '“ – vynechán. Typy: ' . implode(', ', array_map(fn (string $t): string => $t::TYPE, self::ELEMENTS)) . '.';
                continue;
            }
            if (++$count > self::MAX_ELEMENTS) {
                $errors[$place] = 'Stavba má víc než ' . self::MAX_ELEMENTS . ' prvků – zbytek vynechán.';
                break;
            }
            $id = is_string($p['id'] ?? null) && preg_match('/^[a-z0-9]{3,16}$/D', $p['id']) && !isset($used[$p['id']]) ? $p['id'] : self::newId();
            $used[$id] = true;
            if ($className::ADMIN_ONLY && !$admin) {
                if (!isset($protected[$id])) {
                    $errors[$place] = 'Prvek „' . $className::NAME . '“ smí vložit jen správce webu – vynechán.';
                    continue;
                }
                $output[] = $protected[$id]; // the custom HTML content does not change, it can only stay in place
                continue;
            }
            $htmlTag = in_array($p['znacka'] ?? null, $className::HTML_TAGS, true) ? $p['znacka'] : $className::HTML_TAGS[0];
            $clean = ['id' => $id, 'typ' => $className::TYPE, 'znacka' => $htmlTag, 'obsah' => self::sanitizeContent($className::properties(), is_array($p['obsah'] ?? null) ? $p['obsah'] : [], $place . '.obsah', $errors)];
            if ($className::TYPE === 'formular' && self::$purposeAllowed === false && ($clean['obsah']['ucel'] ?? '') !== (self::$previousPurposes[$id] ?? '')) {
                $clean['obsah']['ucel'] = self::$previousPurposes[$id] ?? ''; // review low (f): the Purpose needs the section Applications
                if ($clean['obsah']['ucel'] === '') {
                    unset($clean['obsah']['ucel']);
                }
                $errors[$place . '.obsah.ucel'] = 'Účel formuláře (žádost o místo) smí měnit jen uživatel se sekcí Žádosti o místo – ponechán.';
            }
            // 3.50 N132: the recipients of an application form are locked the same way – they receive the applicants' CVs; the
            // hidden copy (Bcc) gets the same notification, so it is locked too (3.50 review)
            foreach ($className::TYPE === 'formular' && self::$purposeAllowed === false ? self::$previousRecipients[$id] ?? [] : [] as $field => $kept) {
                if ((string) ($clean['obsah'][$field] ?? '') === $kept) {
                    continue;
                }
                $clean['obsah'][$field] = $kept;
                if ($kept === '') {
                    unset($clean['obsah'][$field]);
                }
                $errors[$place . '.obsah.' . $field] = 'Příjemce formuláře žádostí o místo smí měnit jen uživatel se sekcí Žádosti o místo – ponecháni.';
            }
            $style = Style::sanitize($p['styl'] ?? [], $place . '.styl', $errors);
            if ($style !== []) {
                $clean['styl'] = $style;
            }
            $classes = self::userClasses($p['tridy'] ?? null);
            if (is_array($p['tridy'] ?? null) && array_filter($p['tridy'], fn (mixed $t): bool => is_string($t) && str_starts_with($t, 'ka-')) !== []) {
                $errors[$place . '.tridy'] = 'Třídy začínající „ka-“ jsou vyhrazené pro Kaletu – vynechány.';
            }
            if ($classes !== []) {
                $clean['tridy'] = array_slice($classes, 0, 8);
            }
            if (is_string($p['kotva'] ?? null) && preg_match('/^[a-z][a-z0-9-]{0,40}$/D', $p['kotva'])) {
                // anchor = id on the page: it must be unique and must not clash with a layout id or with another element's style (s-…)
                if (isset($used['kotva:' . $p['kotva']]) || !self::isFreeAnchor($p['kotva'])) {
                    $errors[$place . '.kotva'] = 'Kotvu „' . $p['kotva'] . '“ už na stránce používá jiný prvek nebo šablona – vynechána.';
                } else {
                    $clean['kotva'] = $p['kotva'];
                    $used['kotva:' . $p['kotva']] = true;
                }
            }
            if (is_string($p['popis'] ?? null) && trim($p['popis']) !== '') {
                $clean['popis'] = mb_substr(trim(strip_tags($p['popis'])), 0, 60); // element name in the editor's tree
            }
            // the element's custom CSS: only safe declarations (as with classes)
            if (is_string($p['css'] ?? null) && trim($p['css']) !== '') {
                $discarded = [];
                $css = Style::customCss(mb_substr($p['css'], 0, 2000), $discarded);
                if ($css !== '') {
                    $clean['css'] = $css;
                }
                foreach ($discarded as $d) {
                    $errors[$place . '.css'] = 'Nepovolená deklarace: ' . mb_substr($d, 0, 60);
                }
            }
            // custom attributes: data-*, aria-*, title, lang, role, rel – the values are escaped when rendering
            if (is_array($p['atributy'] ?? null)) {
                $attributes = [];
                foreach (array_slice($p['atributy'], 0, 10, true) as $name => $value) {
                    if (is_string($name) && is_scalar($value) && preg_match(self::ATTRIBUTE_PATTERN, $name)) {
                        $attributes[strtolower($name)] = mb_substr((string) $value, 0, 200); // Build::customAttributes() repeats the check when rendering
                    } else {
                        $errors[$place . '.atributy'] = 'Atribut může být jen data-…, aria-…, title, lang, role nebo rel.';
                    }
                }
                if ($attributes !== []) {
                    $clean['atributy'] = $attributes;
                }
            }
            // display conditions: signed-in / signed-out visitors, from and to a date (inclusive), language versions, a URL parameter
            if (is_array($p['podminky'] ?? null)) {
                $conditions = self::sanitizeConditions($p['podminky'], $place . '.podminky', $errors);
                if ($conditions !== []) {
                    $clean['podminky'] = $conditions;
                }
            }
            if (($p['zamek'] ?? false) === true) {
                $clean['zamek'] = true; // in the editor it cannot be selected or dragged on the canvas
            }
            if ($className::CONTAINER) {
                if ($depth >= self::MAX_DEPTH) {
                    $errors[$place . '.deti'] = 'Příliš hluboké vnoření – vnořené prvky vynechány.';
                    $clean['deti'] = [];
                } else {
                    $clean['deti'] = self::sanitizeChildren(is_array($p['deti'] ?? null) ? $p['deti'] : [], $place . '.deti', $depth + 1, $errors, $used, $count, $admin, $protected);
                }
            } elseif (!empty($p['deti'])) {
                $errors[$place . '.deti'] = 'Prvek „' . $className::NAME . '“ nemůže obsahovat další prvky – vynechány.';
            }
            $output[] = $clean;
        }

        return $output;
    }

    /** Name of a URL parameter a display condition may look at (short, letters, digits, _ and -). */
    public const string PARAMETER_NAME_PATTERN = '/^[a-z][a-z0-9_-]{0,39}$/iD';

    /** Its expected value: short and without characters that would need escaping anywhere. */
    public const string PARAMETER_VALUE_PATTERN = '/^[a-z0-9_.-]{1,80}$/iD';

    /**
     * Display conditions of an element: prihlaseni (ano|ne), od/do (YYYY-MM-DD, inclusive), jazyky (language versions, '' = the
     * default language) and parametr {nazev, hodnota?} (the page address has a query parameter, optionally with an exact value).
     *
     * @param array<string, mixed> $input
     * @param array<string, string> $errors
     * @return array<string, mixed>
     */
    public static function sanitizeConditions(array $input, string $place, array &$errors): array
    {
        $conditions = [];
        if (in_array($input['prihlaseni'] ?? '', ['ano', 'ne'], true)) {
            $conditions['prihlaseni'] = $input['prihlaseni'];
        }
        foreach (['od', 'do'] as $bound) {
            $date = (string) ($input[$bound] ?? '');
            if ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) && strtotime($date) !== false) {
                $conditions[$bound] = $date;
            } elseif ($date !== '') {
                $errors[$place] = 'Datum podmínky zobrazení musí být ve tvaru RRRR-MM-DD.';
            }
        }
        if (is_array($input['jazyky'] ?? null)) {
            $languages = [];
            foreach ($input['jazyky'] as $code) {
                if (is_string($code) && ($code === '' || isset(\Kaleta\Core\Language::AVAILABLE[$code]))) {
                    $languages[$code] = true;
                } else {
                    $errors[$place] = 'Jazyk podmínky zobrazení musí být kód jazykové verze webu („“ = výchozí jazyk).';
                }
            }
            if ($languages !== []) {
                $conditions['jazyky'] = array_keys($languages);
            }
        }
        if (is_array($input['parametr'] ?? null) || is_string($input['parametr'] ?? null)) {
            // a bare string is the parameter name (shorthand for {nazev})
            $parameter = is_array($input['parametr']) ? $input['parametr'] : ['nazev' => $input['parametr']];
            $name = is_string($parameter['nazev'] ?? null) ? trim($parameter['nazev']) : '';
            $value = is_scalar($parameter['hodnota'] ?? null) ? trim((string) $parameter['hodnota']) : '';
            if ($name === '' || !preg_match(self::PARAMETER_NAME_PATTERN, $name)) {
                if ($name !== '' || $value !== '') {
                    $errors[$place] = 'Název parametru adresy: 1–40 znaků, písmena bez diakritiky, číslice, _ a -.';
                }
            } elseif ($value !== '' && !preg_match(self::PARAMETER_VALUE_PATTERN, $value)) {
                $errors[$place] = 'Hodnota parametru adresy: 1–80 znaků, písmena bez diakritiky, číslice, _ . a -.';
            } else {
                $conditions['parametr'] = ['nazev' => $name] + ($value !== '' ? ['hodnota' => $value] : []);
            }
        }

        return $conditions;
    }

    /**
     * Whether a page with these conditions must be assembled anew for every visit: a language version has its own address
     * (and its own entry in the page cache), everything else (date, sign-in, URL parameter) can differ between two visits
     * of the same address.
     *
     * @param array<string, mixed> $conditions
     */
    public static function conditionsBypassCache(array $conditions): bool
    {
        return array_diff(array_keys($conditions), ['jazyky']) !== [];
    }

    /** @param array<string, array<string, mixed>> $field */
    private static function sanitizeContent(array $field, array $content, string $path, array &$errors): array
    {
        $clean = [];
        foreach ($field as $key => $def) {
            $value = $content[$key] ?? $def['vychozi'] ?? '';
            $max = (int) ($def['max'] ?? 5000);
            $clean[$key] = match ($def['typ']) {
                'text' => mb_substr(trim(strip_tags(is_scalar($value) ? (string) $value : '')), 0, $max),
                'radky' => mb_substr(strip_tags(is_scalar($value) ? (string) $value : ''), 0, $max),
                'inline' => self::withinLimits(fn (): string => self::inline(is_scalar($value) ? (string) $value : '', $max), $path . '.' . $key, $errors),
                'html' => self::withinLimits(fn (): string => WpContent::safeHtml(is_scalar($value) ? mb_substr((string) $value, 0, 200000) : ''), $path . '.' . $key, $errors),
                'kod' => self::withinLimits(fn (): string => self::code(is_scalar($value) ? mb_substr((string) $value, 0, $max) : ''), $path . '.' . $key, $errors),
                'odkaz' => self::link(is_scalar($value) ? (string) $value : '', $path . '.' . $key, $errors),
                'obrazek' => is_string($value) && preg_match('#^(https://[^\s"\'<>]{1,500}|/?([A-Za-z0-9_.-]+/){0,3}media/[A-Za-z0-9/_.-]{1,300}|\{\{[a-z][a-z0-9_]{0,30}\}\})$#D', $value) ? $value : '',
                'vyber' => is_scalar($value) && isset($def['moznosti'][(string) $value]) ? (string) $value : (string) $def['vychozi'],
                'cislo' => is_numeric($value) ? max((int) ($def['min'] ?? 0), min((int) ($def['max'] ?? 100), (int) $value)) : (int) $def['vychozi'],
                'prepinac' => (bool) $value,
                'termin' => self::dateTime(is_scalar($value) ? (string) $value : ''),
                // component property values: only key => text; they are checked by the property type when rendering
                'hodnoty' => array_slice(array_filter(
                    array_map(fn (mixed $v): ?string => is_scalar($v) ? mb_substr((string) $v, 0, 20000) : null, is_array($value) ? $value : []),
                    fn (?string $v, int|string $k): bool => $v !== null && is_string($k) && preg_match('/^[a-z][a-z0-9_]{0,30}$/D', $k) === 1,
                    ARRAY_FILTER_USE_BOTH,
                ), 0, 30, true),
                // a closure, not an arrow function: an arrow function copies $errors, so a rejected link or image inside an item would go unreported
                'polozky' => array_slice(array_values(array_map(
                    function (mixed $item) use ($def, $path, $key, &$errors): array { return self::sanitizeContent($def['pole'], is_array($item) ? $item : [], $path . '.' . $key, $errors); },
                    is_array($value) ? $value : [],
                )), 0, (int) ($def['max'] ?? 30)),
                default => '',
            };
            if ($def['typ'] === 'obrazek' && $value !== '' && $clean[$key] === '') {
                $errors[$path . '.' . $key] = 'Obrázek musí být z Médií (media/…) nebo na adrese https://.';
            }
            if ($def['typ'] === 'termin' && is_scalar($value) && trim((string) $value) !== '' && $clean[$key] === '') {
                $errors[$path . '.' . $key] = 'Datum a čas musí být ve tvaru RRRR-MM-DD HH:MM – pole zůstalo prázdné.'; // MCP learns why, not an empty field in silence
            }
        }

        return $clean;
    }

    /**
     * A date-and-time field (typ termin, 3.21 U24): stored as "YYYY-MM-DD HH:MM" or a whole day "YYYY-MM-DD" – the format pages
     * held before the picker. The picker's "YYYY-MM-DDTHH:MM" is taken too, and seconds are dropped ("…T07:08:00" from a language
     * model; the text field before 3.21 cut the value to 16 characters, so such a value worked); an impossible date or anything
     * else leaves the field empty.
     */
    public static function dateTime(string $value): string
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::\d{2}(?:\.\d{1,6})?)?)?$/D', trim($value), $m)
            || !checkdate((int) $m[2], (int) $m[3], (int) $m[1]) || (isset($m[4]) && ((int) $m[4] > 23 || (int) $m[5] > 59))) {
            return '';
        }

        return $m[1] . '-' . $m[2] . '-' . $m[3] . (isset($m[4]) ? ' ' . $m[4] . ':' . $m[5] : '');
    }

    /**
     * A sanitized field; HTML over a limit of Core\HtmlLimits leaves the field empty with a note (never the input as it was).
     *
     * @param callable(): string $sanitize
     * @param array<string, string> $errors
     */
    private static function withinLimits(callable $sanitize, string $place, array &$errors): string
    {
        try {
            return \Kaleta\Core\HtmlLimits::guard($sanitize);
        } catch (\Kaleta\Core\HtmlTooLarge $e) {
            $errors[$place] = self::limitNote($e->violation);

            return '';
        }
    }

    /**
     * The note for a field over a limit of Core\HtmlLimits (a Czech source text like every builder note; Mcp\Translator::NOTICES
     * has the English).
     *
     * @param array{limit: string, value: int, max: int} $v
     */
    public static function limitNote(array $v): string
    {
        return sprintf(match ($v['limit']) {
            'bytes' => 'Kód má %d bajtů, nejvýš smí mít %d – obsah pole vynechán.',
            'depth' => 'Kód je vnořený do %d úrovní, nejvýš smí do %d – obsah pole vynechán.',
            'attributes' => 'Prvek v kódu má %d atributů, nejvýš smí mít %d – obsah pole vynechán.',
            default => 'Kód má %d prvků, nejvýš smí mít %d – obsah pole vynechán.',
        }, $v['value'], $v['max']);
    }

    /** A note of limitNote(). */
    public static function isLimitNote(string $note): bool
    {
        return str_ends_with($note, ' – obsah pole vynechán.');
    }

    /** Short text with bold, italic, highlight (mark = the accent color, e.g. a dot after a title), line break and link – nothing else. */
    private static function inline(string $html, int $max): string
    {
        $clean = WpContent::safeHtml('<p>' . mb_substr($html, 0, $max * 2) . '</p>');
        $clean = (string) preg_replace('#^<p>|</p>$#', '', trim($clean));
        $clean = strip_tags($clean, '<strong><b><em><i><mark><br><a><s><sub><sup>');

        return trim(str_replace(['</p>', '<p>'], ['<br>', ''], $clean));
    }

    /** Elements the administrator's custom HTML never keeps: they run code, change the page's base or reload it, or hide markup from the parser. */
    private const array CODE_DISCARD = ['script', 'object', 'embed', 'applet', 'base', 'meta', 'link', 'frame', 'frameset', 'portal', 'template', 'noscript',
        'noembed', 'noframes', 'xmp', 'plaintext', 'math', 'animate', 'animatemotion', 'animatetransform', 'set', 'handler', 'foreignobject',
        // parsers disagree on what a <select> may hold (the relaxed content model of newer ones): markup the filter saw as inert
        // could be read as live elsewhere, so none of it is kept
        'select', 'option', 'optgroup', 'datalist', 'selectedcontent'];

    /** Elements whose text the parser does not read as markup: a < inside them is never kept (another parser or context could). */
    private const array CODE_RAWTEXT = ['style', 'iframe', 'script', 'xmp', 'noembed', 'noframes', 'plaintext', 'noscript'];

    /** Attributes holding an address: only http(s), mailto, tel or a relative one (checked after the parser decoded entities). */
    private const array CODE_URLS = ['href', 'src', 'action', 'formaction', 'poster', 'cite', 'data', 'background', 'lowsrc', 'ping', 'longdesc', 'codebase', 'manifest', 'xlink:href', 'dynsrc'];

    /**
     * The administrator's custom HTML (an administrator-only element). Embedded maps and service forms are <iframe>s and <form>s, those
     * stay; scripts, event handlers, srcdoc, script-like URLs (also entity-encoded or unquoted) and SVG animation of attributes are removed.
     * A DOM pass, not a regular expression – regular expressions over HTML can be bypassed. It repeats until the output parses back to
     * itself, so markup that a browser would read differently after serialization (mutation XSS) is cleaned in the form the browser sees.
     * Runs on save and again when rendering.
     */
    public static function code(string $html): string
    {
        for ($i = 0; $i < 4; $i++) {
            $clean = self::codePass($html);
            if ($clean === $html) {
                return $clean;
            }
            $html = $clean;
        }

        return ''; // never settles – such markup is not an embed code
    }

    private static function codePass(string $html): string
    {
        if (trim($html) === '' || !preg_match('/<|&/', $html)) {
            return \Kaleta\Core\HtmlLimits::refuse(\Kaleta\Core\HtmlLimits::check($html)) ? '' : $html;
        }
        $doc = \Kaleta\Core\HtmlLimits::fragment($html); // over a limit: '' (an error when saving, nothing when rendering)
        $body = $doc?->body;
        if ($body === null) {
            return '';
        }
        self::codeNode($body);

        return \Kaleta\Core\Html::inner($body); // attribute values without a raw < or >
    }

    /**
     * CSS as the browser reads it, for the checks of codeNode() (3.15, N48): escapes decoded (@\69mport is @import,
     * java\script is javascript) and comments dropped. Only checked, never written – the code stays as typed.
     */
    private static function cssPlain(string $css): string
    {
        $css = (string) preg_replace('#/\*.*?(\*/|$)#s', '', $css);
        $css = (string) preg_replace_callback('/\\\\([0-9a-fA-F]{1,6})[ \t\r\n\f]?/', fn (array $m): string => mb_chr(max(1, min(0x10FFFF, (int) hexdec($m[1])))) ?: '', $css);

        return (string) preg_replace('/\\\\(.)/su', '$1', $css);
    }

    private static function codeNode(\Dom\Node $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $n) {
            if ($n instanceof \Dom\Comment || ($n instanceof \Dom\Element && (in_array(strtolower($n->localName), self::CODE_DISCARD, true)
                || !preg_match('/^[a-z][a-z0-9-]*$/iD', $n->localName)))) {
                $n->remove();
                continue;
            }
            if (!$n instanceof \Dom\Element) {
                continue;
            }
            if (strtolower($n->localName) === 'style' && preg_match('/javascript:|expression\s*\(|@import|behavior\s*:|-moz-binding/i', self::cssPlain((string) $n->textContent))) {
                $n->remove();
                continue;
            }
            foreach (iterator_to_array($n->attributes, false) as $a) { // a list: the keys are local names, and xlink:href would hide href (or onload x:onload)
                $name = strtolower($a->nodeName); // the qualified name: xlink:href, xml:onerror
                $ok = !str_starts_with($name, 'on') && $name !== 'srcdoc' && $name !== 'http-equiv'
                    && (!in_array($name, self::CODE_URLS, true) && !str_ends_with($name, ':href') || WpContent::isSafeUrl($a->value) || trim($a->value) === '')
                    && ($name !== 'srcset' || !preg_match('/(javascript|data|vbscript):/i', $a->value))
                    && ($name !== 'style' || !preg_match('/javascript:|expression\s*\(|behavior\s*:|-moz-binding|url\s*\(\s*["\']?\s*(javascript|data|vbscript):/i', self::cssPlain($a->value)))
                    && ($name !== 'attributename' && $name !== 'formtarget');
                if (!$ok) {
                    $n->removeAttributeNode($a);
                }
            }
            // only HTML's own elements are raw text – an SVG <style> holds parsed markup and is cleaned like any other element
            if ($n->namespaceURI === 'http://www.w3.org/1999/xhtml' && in_array(strtolower($n->localName), self::CODE_RAWTEXT, true) && str_contains((string) $n->textContent, '<')) {
                // CSS keeps its meaning with the escape \3c; the text of a frame is never shown, so it goes
                $n->textContent = strtolower($n->localName) === 'style' ? str_replace('<', '\3c ', (string) $n->textContent) : '';
            }
            self::codeNode($n);
        }
    }

    private static function link(string $url, string $path, array &$errors): string
    {
        $url = trim($url);
        if ($url === '' || $url === '#' || preg_match('/^\{\{[a-z][a-z0-9_]{0,30}\}\}$/D', $url)) {
            return $url; // {{url}} and other collection fields: filled in and checked when rendering
        }
        if (preg_match('/^(tel:|mailto:)?\{\{\s*fact\.[a-z][a-z0-9_]{1,39}\s*\}\}$/D', $url)) {
            return $url; // a business fact (2.10): tel:{{fact.company_phone}}, mailto:{{fact.company_email}}, {{fact.booking_url}}
        }
        if (WpContent::isSafeUrl($url) && !preg_match('/[\s"<>]/', $url)) {
            return mb_substr($url, 0, 500);
        }
        $errors[$path] = 'Odkaz může být jen https://…, mailto:, tel:, #kotva nebo adresa na webu (/…).';

        return '';
    }

    /**
     * 3.50 review: the ids of the application forms of $published that $new still has but with other recipients
     * (LOCKED_RECIPIENTS) – e.g. an older version restored into the draft. A dropped form is droppedApplicationForms(). Pure.
     *
     * @return list<string>
     */
    public static function changedApplicationRecipients(?string $published, ?string $new): array
    {
        $forms = function (?string $json): array {
            $build = $json === null ? null : self::fromJson($json);

            return $build === null ? [] : self::elementsOfType($build, fn (string $className): bool => $className::TYPE === 'formular');
        };
        $recipients = fn (array $p): array => array_map(fn (string $field): string => (string) ($p['obsah'][$field] ?? ''), self::LOCKED_RECIPIENTS);
        $newForms = $forms($new);
        $changed = [];
        foreach ($forms($published) as $id => $p) {
            if (($p['obsah']['ucel'] ?? '') === \Kaleta\Core\Applications::PURPOSE && isset($newForms[$id]) && $recipients($p) !== $recipients($newForms[$id])) {
                $changed[] = (string) $id;
            }
        }

        return $changed;
    }

    /** @return array<string, array<string, mixed>> id => element for all elements whose class meets the condition */
    /**
     * 3.50 N132: the ids of the application forms (Purpose = job application) of $published that $new no longer has as an
     * application form – removed, or replaced by a form without the Purpose. Pure; a build that does not decode has none.
     *
     * @return list<string>
     */
    public static function droppedApplicationForms(?string $published, ?string $new): array
    {
        $forms = function (?string $json): array {
            $build = $json === null ? null : self::fromJson($json);

            return $build === null ? [] : array_keys(array_filter(self::elementsOfType($build, fn (string $className): bool => $className::TYPE === 'formular'),
                fn (array $p): bool => ($p['obsah']['ucel'] ?? '') === \Kaleta\Core\Applications::PURPOSE));
        };

        return array_values(array_map('strval', array_diff($forms($published), $forms($new))));
    }

    /**
     * 3.50.1 (SEC-l1, CODE-l3): the ids of forms in $new that would take the applications of a published application form
     * away from it without removing it: (a) an application form of $published that $new hides – its own style or an
     * ancestor's has display none in some state where $published did not, or (3.50.1 review) it or an ancestor has display
     * conditions (podminky) it did not have in $published, or carries a class it did not carry there; (b) a form in $new that is not an application
     * form, has a file field and whose id had no such form in $published (also an id swapped with the application form).
     * Only when $published has an application form. Pure; a build that does not decode has none.
     * 3.50.1 release review: display conditions of an element that had conditions in $published count only when they are
     * narrower (conditionsNarrower – a later "do" date or one more language passes); with $hidingClasses (the shared classes
     * whose style hides, Publisher::guard) a new class counts only when it is one of them – null keeps every new class (no
     * database at hand). A class restyled to hide later is refused by Publisher::guardClasses.
     *
     * @param list<string>|null $hidingClasses
     * @return list<string>
     */
    public static function shadowedApplicationForms(?string $published, ?string $new, ?array $hidingClasses = null): array
    {
        $old = $published === null ? null : self::fromJson($published);
        $next = $new === null ? null : self::fromJson($new);
        if ($old === null || $next === null) {
            return [];
        }
        $isForm = fn (string $className): bool => $className::TYPE === 'formular';
        $isApplication = fn (array $p): bool => ($p['obsah']['ucel'] ?? '') === \Kaleta\Core\Applications::PURPOSE;
        $hasFile = fn (array $p): bool => in_array('soubor', array_map(fn (mixed $f): string => is_array($f) ? (string) ($f['typ'] ?? '') : '', is_array($p['obsah']['pole'] ?? null) ? $p['obsah']['pole'] : []), true);
        $oldForms = self::elementsOfType($old, $isForm);
        $applications = array_keys(array_filter($oldForms, $isApplication));
        if ($applications === []) {
            return [];
        }
        $oldHidden = self::hiddenElements($old);
        $newHidden = self::hiddenElements($next);
        $out = [];
        foreach ($applications as $id) {
            $before = $oldHidden[(string) $id] ?? [];
            foreach ($newHidden[(string) $id] ?? [] as $marker) {
                if (in_array($marker, $before, true)) {
                    continue;
                }
                if (preg_match('/^[a-z0-9]+\.(.+)$/Ds', $marker, $m) === 1 && $hidingClasses !== null && !in_array($m[1], $hidingClasses, true)) {
                    continue; // a class whose style does not hide
                }
                // conditions of an element that had conditions hiding it at least as often
                if (preg_match('/^([a-z0-9]+)\?(.*)$/Ds', $marker, $m) === 1 && array_filter($before, fn (string $old): bool => str_starts_with($old, $m[1] . '?')
                    && !self::conditionsNarrower((array) json_decode(substr($old, strlen($m[1]) + 1), true), (array) json_decode($m[2], true))) !== []) {
                    continue;
                }
                $out[] = (string) $id; // (a) the form or an ancestor of it is hidden now
                break;
            }
        }
        foreach (self::elementsOfType($next, $isForm) as $id => $p) {
            $before = $oldForms[$id] ?? null;
            if (!$isApplication($p) && $hasFile($p) && !($before !== null && !$isApplication($before) && $hasFile($before))) {
                $out[] = (string) $id; // (b) a new form with a file field beside a published application form
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * 3.50.1 release review: whether the display conditions $new hide an element anywhere the conditions $old did not – a
     * condition $old did not have, a later "od", an earlier "do", a language fewer, another login rule or another address
     * parameter (the same parameter without its value is wider). Unknown keys must stay equal. Pure.
     *
     * @param array<mixed> $old
     * @param array<mixed> $new
     */
    public static function conditionsNarrower(array $old, array $new): bool
    {
        foreach ($new as $key => $value) {
            if (!array_key_exists($key, $old)) {
                return true;
            }
            $was = $old[$key];
            if (!match ($key) {
                'od' => is_string($value) && is_string($was) && $value <= $was,
                'do' => is_string($value) && is_string($was) && $value >= $was,
                'jazyky' => is_array($value) && is_array($was) && array_diff($was, $value) === [],
                // the same address parameter, with the value dropped or kept: shown at least as often
                'parametr' => is_array($value) && is_array($was) && ($value['nazev'] ?? null) === ($was['nazev'] ?? null)
                    && (!array_key_exists('hodnota', $value) || ($value['hodnota'] ?? null) === ($was['hodnota'] ?? null)),
                default => $value === $was,
            }) {
                return true;
            }
        }

        return false;
    }

    /**
     * 3.50.1 review: the components a "komponenta" element of $new points to where that element did not point to the same
     * component in $published – only when $published has an application form (Publisher::guard then checks whether such a
     * component holds a form with a file field, rule (b)). Pure; a build that does not decode has none.
     *
     * @return list<int>
     */
    public static function newComponentReferences(?string $published, ?string $new): array
    {
        $old = $published === null ? null : self::fromJson($published);
        $next = $new === null ? null : self::fromJson($new);
        if ($old === null || $next === null || self::droppedApplicationForms($published, null) === []) {
            return [];
        }
        $isComponent = fn (string $className): bool => $className::TYPE === 'komponenta';
        $before = array_map(fn (array $p): int => (int) ($p['obsah']['komponenta'] ?? 0), self::elementsOfType($old, $isComponent));
        $out = [];
        foreach (self::elementsOfType($next, $isComponent) as $id => $p) {
            $idm = (int) ($p['obsah']['komponenta'] ?? 0);
            if ($idm > 0 && ($before[$id] ?? null) !== $idm) {
                $out[] = $idm;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * 3.50.1 review: whether a build has a form with a file field that is not an application form (rule (b) of
     * shadowedApplicationForms), and the components its "komponenta" elements point to. Pure.
     *
     * @return array{0: bool, 1: list<int>}
     */
    public static function fileFormsAndComponents(?string $json): array
    {
        $build = $json === null ? null : self::fromJson($json);
        if ($build === null) {
            return [false, []];
        }
        $file = false;
        foreach (self::elementsOfType($build, fn (string $className): bool => $className::TYPE === 'formular') as $p) {
            $fields = is_array($p['obsah']['pole'] ?? null) ? $p['obsah']['pole'] : [];
            if (($p['obsah']['ucel'] ?? '') !== \Kaleta\Core\Applications::PURPOSE && in_array('soubor', array_map(fn (mixed $f): string => is_array($f) ? (string) ($f['typ'] ?? '') : '', $fields), true)) {
                $file = true;
                break;
            }
        }
        $components = array_map(fn (array $p): int => (int) ($p['obsah']['komponenta'] ?? 0), self::elementsOfType($build, fn (string $className): bool => $className::TYPE === 'komponenta'));

        return [$file, array_values(array_unique(array_filter($components, fn (int $idm): bool => $idm > 0)))];
    }

    /**
     * 3.50.1 review: the shared classes a build puts on an application form or on one of its ancestors – a class restyled to
     * display none would hide the form on the site (Publisher::guardClasses). A "komponenta" element pointing to one of
     * $formComponents (components holding an application form) counts as an application form too. Pure.
     *
     * @param list<int> $formComponents
     * @return list<string>
     */
    public static function applicationFormClasses(?string $json, array $formComponents = []): array
    {
        $build = $json === null ? null : self::fromJson($json);
        if ($build === null) {
            return [];
        }
        $out = [];
        $walk = function (array $children, array $carried) use (&$walk, &$out, $formComponents): void {
            foreach ($children as $p) {
                if (!is_array($p)) {
                    continue;
                }
                $own = $carried;
                foreach (is_array($p['tridy'] ?? null) ? $p['tridy'] : [] as $class) {
                    if (is_string($class) && $class !== '') {
                        $own[] = $class;
                    }
                }
                $type = (string) ($p['typ'] ?? '');
                if (($type === 'formular' && ($p['obsah']['ucel'] ?? '') === \Kaleta\Core\Applications::PURPOSE)
                    || ($type === 'komponenta' && in_array((int) ($p['obsah']['komponenta'] ?? 0), $formComponents, true))) {
                    array_push($out, ...$own);
                }
                if (is_array($p['deti'] ?? null)) {
                    $walk($p['deti'], $own);
                }
            }
        };
        $walk($build['deti'] ?? [], []);

        return array_values(array_unique($out));
    }

    /**
     * 3.50.1 review: whether a shared class hides what carries it – display none in some state of its style, or in its own
     * CSS declarations. Pure.
     *
     * @param array<string, mixed>|null $class ['styl' => states, 'css' => declarations]
     */
    public static function classHides(?array $class): bool
    {
        if ($class === null) {
            return false;
        }
        foreach (is_array($class['styl'] ?? null) ? $class['styl'] : [] as $state) {
            if (is_array($state) && ($state['zobrazeni'] ?? null) === 'none') {
                return true;
            }
        }

        return preg_match('/(^|[\s;])display\s*:\s*none\b/i', (string) ($class['css'] ?? '')) === 1;
    }

    /**
     * 3.50.1 (the hourly application form watch, Builder\ApplicationFormWatch): whether a published build shows an application
     * form – a "formular" with the Purpose job application, or a "komponenta" element pointing to one of $formComponents – that
     * neither itself nor an ancestor hides: display none in some state of its style, display conditions (podminky), or a class
     * in $hidingClasses (classes whose published style hides, classHides()). Pure; a build that does not decode shows none.
     *
     * @param list<string> $hidingClasses
     * @param list<int> $formComponents
     */
    public static function showsApplicationForm(?string $json, array $hidingClasses, array $formComponents): bool
    {
        $build = $json === null ? null : self::fromJson($json);

        return $build !== null && self::showsIn($build, $hidingClasses, $formComponents);
    }

    /**
     * 3.50.1 release review: the hourly watch decodes each build once – whether it holds an application form (directly or
     * through one of $holdingComponents) and whether it shows one (showsApplicationForm with $showingComponents). Pure. 3.51:
     * with $everyForm any Form counts (a jobs item template, as in Front\Forms) and the components are those holding any Form.
     *
     * @param list<string> $hidingClasses
     * @param list<int> $holdingComponents
     * @param list<int> $showingComponents
     * @return array{0: bool, 1: bool}
     */
    public static function applicationFormPresence(?string $json, array $hidingClasses, array $holdingComponents, array $showingComponents, bool $everyForm = false): array
    {
        $build = $json === null ? null : self::fromJson($json);
        if ($build === null) {
            return [false, false];
        }
        $forms = self::elementsOfType($build, fn (string $className): bool => in_array($className::TYPE, ['formular', 'komponenta'], true));
        $holds = array_filter($forms, fn (array $p): bool => self::isWatchedForm($p, $holdingComponents, $everyForm)) !== [];

        return [$holds, $holds && self::showsIn($build, $hidingClasses, $showingComponents, $everyForm)];
    }

    /** The watched form: a Form with the Purpose job application (any Form with $everyForm) or a component of $formComponents. */
    private static function isWatchedForm(array $p, array $formComponents, bool $everyForm): bool
    {
        return (($p['typ'] ?? '') === 'formular' && ($everyForm || ($p['obsah']['ucel'] ?? '') === \Kaleta\Core\Applications::PURPOSE))
            || (($p['typ'] ?? '') === 'komponenta' && in_array((int) ($p['obsah']['komponenta'] ?? 0), $formComponents, true));
    }

    /**
     * @param list<string> $hidingClasses
     * @param list<int> $formComponents
     */
    private static function showsIn(array $build, array $hidingClasses, array $formComponents, bool $everyForm = false): bool
    {
        $hidden = self::hiddenElements($build);
        foreach (self::elementsOfType($build, fn (string $className): bool => in_array($className::TYPE, ['formular', 'komponenta'], true)) as $id => $p) {
            if (!self::isWatchedForm($p, $formComponents, $everyForm)) {
                continue;
            }
            $shown = true;
            foreach ($hidden[(string) $id] ?? [] as $marker) {
                // "<id>" display none, "<id>?<json>" display conditions, "<id>.<class>" a class (ids are [a-z0-9])
                if (preg_match('/^[a-z0-9]+\.(.+)$/Ds', $marker, $m) !== 1 || in_array($m[1], $hidingClasses, true)) {
                    $shown = false;
                    break;
                }
            }
            if ($shown) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every element of a build => what on its path (itself and its ancestors) can hide it on the site: "<id>" when the
     * element's style has display none in any state, "<id>?<conditions as JSON>" for its display conditions (podminky) and
     * "<id>.<class>" for each class it carries (3.50.1 review – a class's own style can hide it too). A marker in $new that was not
     * in $published means a new way to hide the element. Pure.
     *
     * @return array<string, list<string>>
     */
    private static function hiddenElements(array $build): array
    {
        $out = [];
        $walk = function (array $children, array $hidden) use (&$walk, &$out): void {
            foreach ($children as $p) {
                if (!is_array($p) || !isset($p['id'])) {
                    continue;
                }
                $id = (string) $p['id'];
                $own = $hidden;
                foreach (is_array($p['styl'] ?? null) ? $p['styl'] : [] as $state) {
                    if (is_array($state) && ($state['zobrazeni'] ?? null) === 'none') {
                        $own[] = $id;
                        break;
                    }
                }
                if (is_array($p['podminky'] ?? null) && $p['podminky'] !== []) {
                    $own[] = $id . '?' . json_encode($p['podminky']);
                }
                foreach (is_array($p['tridy'] ?? null) ? $p['tridy'] : [] as $class) {
                    if (is_string($class) && $class !== '') {
                        $own[] = $id . '.' . $class;
                    }
                }
                $out[$id] = $own;
                if (is_array($p['deti'] ?? null)) {
                    $walk($p['deti'], $own);
                }
            }
        };
        $walk($build['deti'] ?? [], []);

        return $out;
    }

    private static function elementsOfType(array $build, callable $condition): array
    {
        $found = [];
        $walk = function (array $children) use (&$walk, &$found, $condition): void {
            foreach ($children as $p) {
                $className = is_array($p) ? self::className((string) ($p['typ'] ?? '')) : null;
                if ($className !== null && $condition($className) && isset($p['id'])) {
                    $found[(string) $p['id']] = $p;
                }
                if (is_array($p['deti'] ?? null)) {
                    $walk($p['deti']);
                }
            }
        };
        $walk($build['deti'] ?? []);

        return $found;
    }

    /**
     * Renders a build: HTML and CSS of only what the page uses (type bases, used classes, element styles) in cascade layers.
     * In editor mode every element gets data-ka-id so that it can be selected on the canvas.
     *
     * @return array{html:string, css:string, faq:list<array{0:string, 1:string}>}
     */
    public static function render(App $app, array $build, bool $editor = false): array
    {
        $k = new Context($app, $editor);
        $html = self::html($build, $k);

        return ['html' => $html, 'css' => self::css($app->db(), $k), 'faq' => $k->faq];
    }

    /** HTML of a build in the page's shared context (this is how the site assembles the page, header and footer and outputs the CSS once via css()). */
    public static function html(array $build, Context $k): string
    {
        // the first image of a page's (or a collection item page's) first section loads at once – usually the hero, the LCP (3.5)
        $k->leadImage = preg_match('/^(stranka|kolekce):/', $k->source) === 1 ? (string) self::leadImage($build['deti'][0] ?? null) : '';
        try {
            return self::buildHtml($build, $k);
        } finally {
            $k->leadImage = '';
        }
    }

    /**
     * Id of the first image in an element's subtree when its loading is left automatic; '' when that first image has its own
     * setting (or none is found); null while no image has been found yet. Collection lists and components are skipped: what
     * is inside them repeats or comes from elsewhere.
     */
    private static function leadImage(mixed $element, bool $root = true): ?string
    {
        if (!is_array($element)) {
            return $root ? '' : null;
        }
        if (($element['typ'] ?? '') === 'obrazek') {
            $content = is_array($element['obsah'] ?? null) ? $element['obsah'] : [];
            $id = $element['id'] ?? null;

            return in_array($content['priorita'] ?? '', ['', false], true) && ($content['src'] ?? '') !== '' && is_string($id) ? $id : '';
        }
        if (!in_array($element['typ'] ?? '', ['kolekce', 'komponenta'], true)) {
            foreach (is_array($element['deti'] ?? null) ? $element['deti'] : [] as $child) {
                $id = self::leadImage($child, false);
                if ($id !== null) {
                    return $id; // the first image decides, even when its editor chose lazy loading
                }
            }
        }

        return $root ? '' : null;
    }

    private static function buildHtml(array $build, Context $k): string
    {
        if ($k->editor) {
            return self::renderChildren($build['deti'] ?? [], $k); // the builder keeps every token, so it stays in the build
        }
        // the content of a site-part wrapper (a news item, a list with the visitor's search query) was filled by Front\Kernel
        // already: it goes in only after the tokens of the build, so nothing a visitor sent is read as a token (3.3.2, N38)
        $content = $k->content;
        $k->content = $content === '' ? '' : self::CONTENT_MARK;
        try {
            $html = self::renderChildren($build['deti'] ?? [], $k);
        } finally {
            $k->content = $content;
        }
        // business facts (2.10) and add-on tokens (3.0) filled for visitors
        $html = \Kaleta\Extension\Registry::fillTokens(\Kaleta\Core\Facts::fill($html, $k->app));

        return $content === '' ? $html : str_replace(self::CONTENT_MARK, $content, $html);
    }

    private static function renderChildren(array $children, Context $k): string
    {
        $html = '';
        foreach ($children as $p) {
            try {
                $html .= self::renderElement($p, $k);
            } catch (\Throwable $e) {
                // "doctor": a broken element is left out on the site, the editor shows a message
                error_log('Builder: prvek ' . ($p['id'] ?? '?') . ' – ' . $e->getMessage());
                $html .= $k->editor ? '<div data-ka-id="' . e((string) ($p['id'] ?? '')) . '" style="padding:1rem;border:2px dashed #b3261e;color:#b3261e">' . e(t('Prvek se nepodařilo vykreslit.')) . '</div>' : '';
            }
        }

        return $html;
    }

    /**
     * Whether an element with the (sanitized) display conditions is shown to this visitor: every condition must hold.
     *
     * @param array{prihlaseni?: string, od?: string, do?: string, jazyky?: list<string>, parametr?: array{nazev: string, hodnota?: string}} $conditions
     */
    public static function meetsConditions(array $conditions, Context $k): bool
    {
        $today = date('Y-m-d');
        if (isset($conditions['od']) && $today < $conditions['od'] || isset($conditions['do']) && $today > $conditions['do']) {
            return false;
        }
        // the language version being displayed, as the "jazyk" column has it: '' = the site's default language
        if (isset($conditions['jazyky']) && !in_array($k->app->languagePrefix, $conditions['jazyky'], true)) {
            return false;
        }
        if (isset($conditions['parametr']['nazev'])) {
            $r = $k->app->request;
            if (!$r->has($conditions['parametr']['nazev']) || (isset($conditions['parametr']['hodnota']) && $r->get($conditions['parametr']['nazev']) !== $conditions['parametr']['hodnota'])) {
                return false;
            }
        }
        if (isset($conditions['prihlaseni'])) {
            $signedIn = $k->app->auth()->user() !== null;

            return $conditions['prihlaseni'] === 'ano' ? $signedIn : !$signedIn;
        }

        return true;
    }

    private static function renderElement(array $p, Context $k): string
    {
        $className = self::className((string) $p['typ']);
        if ($className === null) {
            return '';
        }
        if (($p['podminky'] ?? []) !== [] && !$k->editor) {
            // a page whose content depends on the visit (date, sign-in, URL parameter) is not cached – the page cache
            // ignores tracking parameters such as utm_campaign, so a cached copy could not tell the visits apart
            $k->withoutCache = $k->withoutCache || self::conditionsBypassCache($p['podminky']);
            if (!self::meetsConditions($p['podminky'], $k)) {
                return '';
            }
        }
        if ($className::EXTENSION !== '' && !\Kaleta\Core\Extensions::isEnabled($k->app->settings(), $className::EXTENSION)) {
            // an element of a disabled extension (news, form): nothing on the site, a notice in the editor – the build stays, it comes back once enabled
            return $k->editor ? '<div data-ka-id="' . e((string) ($p['id'] ?? '')) . '" data-ka-typ="' . e($className::TYPE) . '" style="padding:1rem;border:2px dashed currentColor;opacity:.6">'
                . e(t('%s – the feature is switched off and will not appear on the website.', t($className::NAME))) . '</div>' : '';
        }
        // a build saved by an older version may lack properties the element got later – they are filled with the default value
        $p['obsah'] = (is_array($p['obsah'] ?? null) ? $p['obsah'] : []) + array_map(fn (array $field): mixed => $field['vychozi'] ?? '', $className::properties());
        $k->types[$className::TYPE] = true;
        if ($k->item !== null) {
            $p['obsah'] = self::fillItem($className::properties(), $p['obsah'] ?? [], $k->item);
        }
        $children = match (true) {
            $className === Elements\CollectionList::class => Elements\CollectionList::repeat($p, $k, fn (): string => self::renderChildren($p['deti'] ?? [], $k)),
            $className === Elements\Component::class => Elements\Component::inner($p, $k, fn (array $build): string => self::renderChildren($build['deti'] ?? [], $k)),
            $className::CONTAINER => self::renderChildren($p['deti'] ?? [], $k),
            default => '',
        };
        $style = $p['styl'] ?? [];
        // behaviour of a content property (a header transparent at the top): after the element's own style, so that the style cannot undo it
        $customCss = trim((string) ($p['css'] ?? '') . ' ' . $className::behaviourCss($p, $k));
        $hasStyle = $style !== [] || $customCss !== '';
        // inside a Collection list the element repeats: style through the class s-<id>, not through the id (an id must be on the page only once)
        $isRepeated = $k->inLoop > 0;
        // 3.19.1 (N78): an anchor stored before the check (gtag, poptavka…) is not written – the element keeps its style id
        $anchor = is_string($p['kotva'] ?? null) && self::isFreeAnchor($p['kotva']) ? $p['kotva'] : null;
        $id = $isRepeated ? null : ($anchor ?? ($hasStyle ? 's-' . $p['id'] : null));
        // 3.31.2 (N99): a build saved before "ka-" names were reserved loses them here – the element renders without the hook
        $own = self::userClasses($p['tridy'] ?? null);
        $classes = array_merge($isRepeated && $hasStyle ? ['s-' . $p['id']] : [], $own);
        if ($hasStyle && !isset($k->styles[$p['id']])) {
            $k->styles[$p['id']] = true;
            $k->css .= Style::css($isRepeated ? '.s-' . $p['id'] : '#' . $id, $style, $customCss, $k->app->request->basePath());
        }
        foreach ($own as $t) {
            $k->classes[$t] = true;
        }
        $custom = self::customAttributes($p['atributy'] ?? null);
        $a = ($id !== null ? ' id="' . e($id) . '"' : '')
            . ($classes !== [] ? ' class="' . e(implode(' ', $classes)) . '"' : '')
            . $custom
            . ($k->editor || $k->markIds ? ' data-ka-id="' . e((string) $p['id']) . '"' : '')
            . ($k->editor ? ' data-ka-typ="' . e($className::TYPE) . '"' . (!empty($p['zamek']) ? ' data-ka-zamek' : '') : '');

        $html = $className::render($p, $a, $children, $k);

        return $custom === '' ? $html : self::customAttributesLast($html, $custom);
    }

    /**
     * The classes of an element that may be written to the page: valid names only, never one of Kaleta's own ("ka-…",
     * CLASS_PATTERN). Used when a build is saved and again when it is rendered.
     *
     * @return list<string>
     */
    public static function userClasses(mixed $classes): array
    {
        return array_values(array_unique(array_filter(is_array($classes) ? $classes : [], fn (mixed $t): bool => is_string($t) && preg_match(self::CLASS_PATTERN, $t) === 1)));
    }

    /**
     * The custom attributes of an element as HTML. A stored build is checked again: a build saved before a hook was reserved
     * (data-leaflet, data-kosik… – 3.3.2) loses that attribute quietly, the rest of the element renders as before.
     */
    public static function customAttributes(mixed $attributes): string
    {
        $html = '';
        foreach (is_array($attributes) ? array_slice($attributes, 0, 10, true) : [] as $name => $value) {
            if (is_string($name) && is_scalar($value) && preg_match(self::ATTRIBUTE_PATTERN, $name)) {
                $html .= ' ' . strtolower($name) . '="' . e(mb_substr((string) $value, 0, 200)) . '"';
            }
        }

        return $html;
    }

    /**
     * Moves the custom attributes to the end of the tag they are in: the element's own attributes come first, and HTML keeps
     * the first of two equal attributes – so a custom attribute can never override what the element itself sets (3.3.2, N25).
     * Values are escaped, so the only ">" outside quotes ends the tag.
     */
    private static function customAttributesLast(string $html, string $custom): string
    {
        $inside = '(?:[^>"\']|"[^"]*"|\'[^\']*\')*?';

        return (string) (preg_replace_callback('/(<[a-zA-Z][^\s\/>]*' . $inside . ')' . preg_quote($custom, '/') . '(' . $inside . ')(\s*\/?>)/',
            fn (array $m): string => $m[1] . $m[2] . $custom . $m[3], $html, 1) ?? $html);
    }

    /**
     * Collection item values into content fields by their type ({{nazev}} in a heading, {{foto}} in an image, {{url}} in a link…).
     *
     * @param array<string, array<string, mixed>> $properties
     * @param array<string, array{0: string, 1: string}> $values
     */
    private static function fillItem(array $properties, array $content, array $values): array
    {
        foreach ($properties as $key => $def) {
            if (is_string($content[$key] ?? null)) {
                $content[$key] = Collections::fill($content[$key], $def['typ'], $values);
            } elseif ($def['typ'] === 'hodnoty' && is_array($content[$key] ?? null)) {
                // a component in a collection list: the item's {{field}} in property values (checked only later by the property type)
                $content[$key] = array_map(fn (mixed $v): mixed => is_string($v) ? Collections::fill($v, 'text', $values) : $v, $content[$key]);
            } elseif ($def['typ'] === 'polozky' && is_array($content[$key] ?? null)) {
                $content[$key] = array_map(fn (array $item): array => self::fillItem($def['pole'], $item, $values), $content[$key]);
            }
        }

        return $content;
    }

    /** Page CSS: the base of the used types, the used classes (from ka_tridy) and the style of individual elements – each in its own layer. */
    public static function css(Db $db, Context $k): string
    {
        // in a build, spacing is controlled by the containers' gap, not by the layout's margins of headings and paragraphs; text inside a Text element keeps them
        // and so does the system content inside the Page content element of a wrapper (.obsah: a news item's text looks the same as without the wrapper)
        $base = ':where(.stavba) :where(h1, h2, h3, h4, h5, h6, p, ul, ol, blockquote, figure, hr):not(:where(.obsah *)) { margin-block: 0; }' . "\n"
            . ':where(.stavba) :where(.ka-text) > * + * { margin-block-start: 1em; }' . "\n";
        $rtl = \Kaleta\Core\Language::isRtl(\Kaleta\Core\Language::code());
        foreach (self::ELEMENTS as $className) {
            if (isset($k->types[$className::TYPE]) && $className::baseCss() !== '') {
                $base .= $className::baseCss() . "\n" . ($rtl && $className::rtlCss() !== '' ? $className::rtlCss() . "\n" : '');
            }
        }
        $classes = '';
        if ($k->classes !== []) {
            $names = array_keys($k->classes);
            foreach (\Kaleta\Core\Look::classesForCss($db, $names) as $r) { // the draft classes in a preview of the draft look
                $classes .= Style::css('.' . $r['nazev'], json_decode((string) $r['styl'], true) ?: [], Style::customCss((string) $r['css']), $k->app->request->basePath());
            }
        }
        $used = array_filter(Style::KEYFRAMES, fn (string $name): bool => str_contains($k->css . $classes, $name . ' linear both'), ARRAY_FILTER_USE_KEY);
        if ($used !== []) {
            // the scroll animations (reveal and motion while scrolling) the page uses; whoever does not want motion (system setting) sees the elements right away
            foreach ($used as $name => [$frames]) {
                $base .= '@keyframes ' . $name . ' { ' . $frames . " }\n";
            }
            $base .= '@media (prefers-reduced-motion: reduce) { :where(.stavba) * { animation: none !important; } }' . "\n";
        }
        if (str_contains($k->css, 'animation: ka-hlavicka-')) {
            $base .= Elements\Section::scrollCss() . "\n"; // the header that is transparent at the top or shrinks after scrolling – only when a header uses it
        }
        $css = DesignSystem::LAYERS . "\n";
        foreach (['stavitel' => $base, 'tridy' => $classes, 'prvky' => $k->css] as $layer => $content) {
            if (trim($content) !== '') {
                $css .= '@layer ' . $layer . " {\n" . $content . "}\n";
            }
        }

        return $css;
    }

    /** A build from a page: the published one, or the draft (editor, preview). Invalid JSON = null. */
    public static function fromJson(?string $json): ?array
    {
        $data = $json === null ? null : json_decode($json, true);

        return is_array($data) && isset($data['deti']) ? $data : null;
    }

    public static function toJson(array $build): string
    {
        return (string) json_encode($build, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Build content as plain semantic HTML without layout and style (headings, paragraphs, lists, links, images).
     * On publish it is saved into the text column – search, llms.txt, the .md version, API, MCP and export draw on it, and it is
     * also the page content if the page went back to text. 3.53 (A3): the walk lives in Core\BuildText, so the search index
     * (Core\SearchIndex) makes it without reaching up into Builder.
     */
    public static function asText(array $build): string
    {
        return \Kaleta\Core\BuildText::of($build);
    }

    /** A text page converted to a build: one narrow section with a heading and text (going back works through versions). */
    public static function fromText(string $title, string $html): array
    {
        return ['v' => self::VERSION, 'deti' => [self::fresh('sekce', ['sirka' => 'uzka'], [
            ['znacka' => 'h1'] + self::fresh('nadpis', ['text' => e($title)]),
            self::fresh('text', ['html' => $html !== '' ? $html : '<p></p>']),
        ])]];
    }

    /**
     * Schema description for the editor and for language models (MCP builder_schema): element types with fields, style properties and tokens.
     *
     * @return array<string, mixed>
     */
    /** @param list<string>|null $extensions enabled extensions (null = all) – elements of disabled ones are not offered */
    public static function schema(bool $admin = true, string $language = 'cs', bool $parts = false, ?array $extensions = null): array
    {
        // the default content of new elements is in the page language, the editor translates field labels into the admin language
        return \Kaleta\Core\Language::runWith($language, fn (): array => self::buildSchema($admin, $parts, $extensions));
    }

    /**
     * The schema in brief for the language model (MCP): each element and style property on one line. Enumerations have the default
     * value marked with an asterisk, items (polozky) list their fields in square brackets. Full definitions of chosen elements
     * (labels, default children) are returned by the schema with the elements (prvky) parameter.
     *
     * @param array<string, mixed> $schema output of schema()
     * @return array<string, mixed>
     */
    public static function overview(array $schema): array
    {
        $field = function (array $properties) use (&$field): string {
            $parts = [];
            foreach ($properties as $key => $v) {
                $description = $key . ':' . $v['typ'];
                if (isset($v['moznosti']) && is_array($v['moznosti'])) {
                    $description .= '(' . implode('|', array_map(fn (string|int $m): string => (string) $m . ((string) $m === (string) ($v['vychozi'] ?? '') ? '*' : ''), array_keys($v['moznosti']))) . ')';
                } elseif (isset($v['pole']) && is_array($v['pole'])) {
                    $description .= '[' . $field($v['pole']) . ']';
                } elseif (in_array($v['typ'], ['prepinac', 'cislo'], true) && isset($v['vychozi'])) {
                    $description .= '=' . var_export($v['vychozi'], true);
                } elseif ($v['typ'] === 'termin') {
                    $description .= '(YYYY-MM-DD HH:MM)'; // the label no longer carries the format (the editor has a picker)
                }
                $parts[] = $description;
            }

            return implode('; ', $parts);
        };
        $elements = [];
        foreach ($schema['prvky'] as $p) {
            $style = [];
            foreach ((array) $p['vychozi_styl'] as $state => $properties) {
                foreach ((array) $properties as $k => $h) {
                    $style[] = ($state === 'zaklad' ? '' : $state . '.') . $k . '=' . $h;
                }
            }
            $elements[$p['typ']] = $p['nazev'] . ' – ' . $p['popis'] . ($p['kontejner'] ? ' [KONTEJNER]' : '') . ' | značky: ' . implode(',', $p['znacky'])
                . (is_array($p['vlastnosti']) && $p['vlastnosti'] !== [] ? ' | obsah: ' . $field($p['vlastnosti']) : '')
                . ($style !== [] ? ' | styl nového prvku v builderu: ' . implode(', ', $style) . ' (v JSON ho uveď sám, jinak ho prvek nemá)' : '');
        }
        $style = [];
        foreach ($schema['styl'] as $key => $v) {
            $style[$key] = $v['css'] . ': ' . (isset($v['moznosti']) ? implode('|', array_keys($v['moznosti'])) : $v['typ']);
        }

        return ['verze' => $schema['verze'], 'prvky' => $elements, 'styl' => $style, 'stavy' => $schema['stavy'], 'tokeny' => $schema['tokeny'],
            'typy_hodnot' => [
                'mezera' => 'token ' . implode('|', $schema['tokeny']['mezery']) . ' nebo délka (1.5rem)', 'krok' => 'velikost písma: token ' . implode('|', $schema['tokeny']['kroky']) . ' nebo délka',
                'barva' => 'token (' . implode('|', array_keys($schema['tokeny']['barvy'])) . ') nebo #hex', 'zaobleni' => 'token ' . implode('|', array_map('strval', $schema['tokeny']['zaobleni'])) . ' nebo délka',
                'stin' => 'token s|m|l, none nebo „x y rozostření barva“', 'ramecek' => 'výčet nebo „2px solid barva“', 'delka' => 'px, rem, %, vw, fr, auto, min()/max()/clamp()/calc()',
                'sloupce' => 'číslo 1–12, „auto:16rem“ (kolik se vejde) nebo „2fr 1fr“', 'radky' => 'číslo nebo „auto 1fr“', 'oblasti' => 'řádky oddělené /, např. „a a / b c“',
            ],
            'uzel' => '{"id":"(nepovinné, zachovej při úpravách)","typ":"…","znacka":"(jedna ze značek; výchozí první)","obsah":{…},"styl":{"zaklad":{…},"tablet":{…},"mobil":{…},"hover":{…}},"tridy":["…"],"kotva":"id-pro-odkaz","deti":[…]}; prázdná pole a výchozí hodnoty vynech',
            'podminky' => 'nepovinné "podminky" prvku: {"prihlaseni":"ano|ne","od":"RRRR-MM-DD","do":"RRRR-MM-DD","jazyky":["","de"] ("" = výchozí jazyk),"parametr":{"nazev":"utm_campaign","hodnota":"jaro"}} – prvek se na webu vykreslí, jen když platí všechny',
            'pravidla' => $schema['pravidla']];
    }

    /**
     * A build without what sanitize() fills in: default content, default tag, empty style, classes and children. The style stays whole –
     * only a new element from the builder gets the type's default style (container flex), a saved element without a style would lose it. For output to MCP –
     * the model reads and sends only what is set (the same build is roughly a third of the length).
     *
     * @param array<string, mixed> $build
     * @return array<string, mixed>
     */
    public static function compact(array $build): array
    {
        $node = function (array $p) use (&$node): array {
            $className = self::className((string) ($p['typ'] ?? ''));
            if ($className !== null) {
                foreach ($className::properties() as $key => $v) {
                    if (array_key_exists($key, $p['obsah'] ?? []) && $p['obsah'][$key] === ($v['vychozi'] ?? null)) {
                        unset($p['obsah'][$key]);
                    }
                }
                if (($p['znacka'] ?? null) === $className::HTML_TAGS[0]) {
                    unset($p['znacka']);
                }
            }
            foreach (['obsah', 'styl', 'tridy', 'deti', 'podminky', 'atributy'] as $key) {
                if (array_key_exists($key, $p) && ($p[$key] === [] || $p[$key] === null || $p[$key] === '')) {
                    unset($p[$key]);
                }
            }
            if (isset($p['deti'])) {
                $p['deti'] = array_map($node, $p['deti']);
            }

            return $p;
        };

        return ['v' => $build['v'] ?? self::VERSION, 'deti' => array_map($node, $build['deti'] ?? [])];
    }

    /** Element property types that carry text for the visitor or a link (build translation). */
    private const array TEXT_PROPERTIES = ['text', 'odkaz', 'radky', 'html', 'inline', 'textarea', 'polozky', 'souhlas'];

    /** Text properties that are settings, not text for the visitor (collection and field keys, e-mail, date, rating number). */
    private const array TECHNICAL_PROPERTIES = ['kolekce', 'razeni_pole', 'filtr_pole', 'pole_poloha', 'komponenta', 'kategorie', 'prijemce', 'skryta_kopie', 'cil', 'hodnota'];

    /**
     * Build texts for translation: elements with an id and only those content properties that carry text or a link (without styles
     * and structure), plus labels for screen readers in the attributes. Changes come back through the „uprav“ operation by id (Edits).
     *
     * @param array<string, mixed> $build
     * @return list<array{id: string, typ: string, obsah?: array<string, mixed>, atributy?: array<string, string>}>
     */
    public static function texts(array $build): array
    {
        $result = [];
        $walk = function (array $elements) use (&$walk, &$result): void {
            foreach ($elements as $p) {
                $className = self::className((string) ($p['typ'] ?? ''));
                $content = [];
                foreach ($className !== null ? $className::properties() : [] as $key => $v) {
                    $value = $p['obsah'][$key] ?? null;
                    if (in_array($v['typ'] ?? '', self::TEXT_PROPERTIES, true) && !in_array($key, self::TECHNICAL_PROPERTIES, true) && $value !== null && $value !== '' && $value !== []) {
                        $content[$key] = $value;
                    }
                }
                $attributes = array_intersect_key(is_array($p['atributy'] ?? null) ? $p['atributy'] : [], ['aria-label' => 1, 'title' => 1]);
                if (($content !== [] || $attributes !== []) && isset($p['id'])) {
                    $result[] = ['id' => (string) $p['id'], 'typ' => (string) $p['typ']] + ($content !== [] ? ['obsah' => $content] : []) + ($attributes !== [] ? ['atributy' => $attributes] : []);
                }
                $walk(is_array($p['deti'] ?? null) ? $p['deti'] : []);
            }
        };
        $walk($build['deti'] ?? []);

        return $result;
    }

    /** @param list<string>|null $extensions */
    public static function disabledTypes(?array $extensions): array
    {
        $types = [];
        foreach (self::ELEMENTS as $className) {
            if ($extensions !== null && $className::EXTENSION !== '' && !in_array($className::EXTENSION, $extensions, true)) {
                $types[] = $className::TYPE;
            }
        }

        return $types;
    }

    /** @return array<string, mixed> */
    private static function buildSchema(bool $admin, bool $parts, ?array $extensions): array
    {
        $elements = [];
        $disabled = self::disabledTypes($extensions);
        foreach (self::ELEMENTS as $className) {
            if (($className::ADMIN_ONLY && !$admin) || ($className::PARTS_ONLY && !$parts) || in_array($className::TYPE, $disabled, true)) {
                continue;
            }
            $properties = $className::properties();
            if ($className::TYPE === 'formular' && $extensions !== null && !in_array(\Kaleta\Core\Applications::EXTENSION, $extensions, true)) {
                unset($properties['ucel'], $properties['souhlas_uchovani']); // 3.34 B: Purpose and the pool consent only while job applications are on
            }
            if ($className::TYPE === 'formular' && $extensions !== null && !in_array('newsletter', $extensions, true)) {
                unset($properties['souhlas_novinky']); // 3.54: the news consent only while the Newsletter feature is on
            }
            if ($className::TYPE === 'formular' && isset($properties['ucel']) && self::$purposeAllowed === false) {
                $properties['ucel']['jen_cteni'] = true; // review low (f): shown, not changed, without the section Applications
            }
            $elements[] = [
                'typ' => $className::TYPE, 'nazev' => $className::NAME, 'popis' => $className::DESCRIPTION, 'ikona' => $className::ICON, 'skupina' => $className::GROUP,
                'kontejner' => $className::CONTAINER, 'znacky' => $className::HTML_TAGS, 'vlastnosti' => $properties, 'vychozi_styl' => $className::defaultStyle() ?: new \stdClass(),
                'vychozi_deti' => $className::defaultChildren(),
            ];
        }
        $style = [];
        foreach (Style::PROPERTIES as $key => [$css, $type, $group, $labelText, $options]) {
            $style[$key] = ['css' => $css, 'typ' => $type, 'skupina' => $group, 'popisek' => $labelText] + ($options !== null ? ['moznosti' => $options] : []);
        }

        return [
            'verze' => self::VERSION, 'prvky' => $elements, 'styl' => $style, 'skupiny_stylu' => Style::GROUPS, 'stavy' => array_keys(Style::STATUSES),
            'tokeny' => ['barvy' => DesignSystem::COLOR_TOKENS, 'mezery' => array_keys(DesignSystem::SPACES), 'kroky' => DesignSystem::STEPS,
                'zaobleni' => array_keys(DesignSystem::RADII), 'stiny' => array_keys(DesignSystem::SHADOWS)],
            'pravidla' => [
                'Jeden prvek = jedna HTML značka; sekce má nejvýš jeden vnitřní obal. Obsah stránky skládej ze sekcí.',
                'Styl ber z tokenů (mezera „l“, barva „primarni“, krok „2“); volnou hodnotu jen když token nestačí.',
                'Styl má stavy zaklad, tablet (do 1023 px), mobil (do 767 px), hover; úprava jednoho stavu nemění ostatní.',
                'Opakovaný vzhled (karty, štítky) dej do třídy, ne do stylu každého prvku.',
                'Sloupce mřížky: číslo („3“), „auto:16rem“ (kolik se vejde) nebo poměr („2fr 1fr“).',
            ],
        ];
    }
}
