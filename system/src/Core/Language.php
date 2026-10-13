<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Site language and translations of template texts.
 *
 * Texts in templates and code are wrapped in the function t('Read more'). The source text is English (since 1.4.1; texts
 * not switched yet are still Czech) and is looked up in the dictionary system/jazyky/<code>.php (source text => translation),
 * Czech included (cs.php). What is missing in the dictionary is taken from the English one, otherwise the source text is
 * shown as it is - the site never breaks.
 * The language of the whole site is set in Settings (site_language); the extension "jazyky" adds more language versions
 * at URLs /en/… - each has its own pages, categories and news. A new language = dictionaries system/jazyky/<code>.php
 * (site), admin-<code>.php and install-<code>.php + an entry in AVAILABLE.
 */
final class Language
{
    /**
     * code => [name in that language, locale (Open Graph, date format), numeric date format for date()]. Arabic is the
     * first right-to-left language: the site writes <html dir> by dir() and its CSS uses logical properties (docs/design/4.0-data-model.md §5).
     * Regional versions (3.10, docs/design/4.0-data-model.md §2 step 2) follow their language: en-gb, de-at, pt-br, zh-hant. They
     * use the dictionary of the language (base()) unless they have their own, and print their tag in the canonical case (tag()).
     */
    public const array AVAILABLE = [
        'cs' => ['Čeština', 'cs_CZ', 'j. n. Y'],
        'en' => ['English', 'en_US', 'j M Y'],
        'en-gb' => ['English (UK)', 'en_GB', 'j M Y'],
        'bg' => ['Български', 'bg_BG', 'd.m.Y'],
        'ca' => ['Català', 'ca_ES', 'd/m/Y'],
        'da' => ['Dansk', 'da_DK', 'd.m.Y'],
        'de' => ['Deutsch', 'de_DE', 'd.m.Y'],
        'de-at' => ['Deutsch (Österreich)', 'de_AT', 'd.m.Y'],
        'el' => ['Ελληνικά', 'el_GR', 'd/m/Y'],
        'es' => ['Español', 'es_ES', 'd/m/Y'],
        'et' => ['Eesti', 'et_EE', 'd.m.Y'],
        'fi' => ['Suomi', 'fi_FI', 'j.n.Y'],
        'fr' => ['Français', 'fr_FR', 'd/m/Y'],
        'ga' => ['Gaeilge', 'ga_IE', 'd/m/Y'],
        'hr' => ['Hrvatski', 'hr_HR', 'd.m.Y.'],
        'hu' => ['Magyar', 'hu_HU', 'Y. m. d.'],
        'is' => ['Íslenska', 'is_IS', 'j.n.Y'],
        'it' => ['Italiano', 'it_IT', 'd/m/Y'],
        'lt' => ['Lietuvių', 'lt_LT', 'Y-m-d'],
        'lv' => ['Latviešu', 'lv_LV', 'd.m.Y.'],
        'mt' => ['Malti', 'mt_MT', 'd/m/Y'],
        'nl' => ['Nederlands', 'nl_NL', 'd-m-Y'],
        'no' => ['Norsk', 'nb_NO', 'd.m.Y'],
        'pl' => ['Polski', 'pl_PL', 'd.m.Y'],
        'pt' => ['Português', 'pt_PT', 'd/m/Y'],
        'pt-br' => ['Português (Brasil)', 'pt_BR', 'd/m/Y'],
        'ro' => ['Română', 'ro_RO', 'd.m.Y'],
        'sk' => ['Slovenčina', 'sk_SK', 'j. n. Y'],
        'sl' => ['Slovenščina', 'sl_SI', 'j. n. Y'],
        'sq' => ['Shqip', 'sq_AL', 'd.m.Y'],
        'sr' => ['Srpski', 'sr_RS', 'd.m.Y.'],
        'bs' => ['Bosanski', 'bs_BA', 'd.m.Y.'],
        'mk' => ['Македонски', 'mk_MK', 'd.m.Y'],
        'sv' => ['Svenska', 'sv_SE', 'Y-m-d'],
        'tr' => ['Türkçe', 'tr_TR', 'd.m.Y'],
        'uk' => ['Українська', 'uk_UA', 'd.m.Y'],
        'ru' => ['Русский', 'ru_RU', 'd.m.Y'],
        'hi' => ['हिन्दी', 'hi_IN', 'd/m/Y'],
        'id' => ['Bahasa Indonesia', 'id_ID', 'd/m/Y'],
        'ja' => ['日本語', 'ja_JP', 'Y/m/d'],
        'ko' => ['한국어', 'ko_KR', 'Y. m. d.'],
        'vi' => ['Tiếng Việt', 'vi_VN', 'd/m/Y'],
        'zh' => ['中文', 'zh_CN', 'Y-m-d'],
        'zh-hant' => ['中文（繁體）', 'zh_TW', 'Y/m/d'],
        'ar' => ['العربية', 'ar_SA', 'd/m/Y'],
    ];

    /** Codes from AVAILABLE for Settings field types (vyber:… / seznam:…). */
    public const string CODES = 'cs|en|en-gb|bg|ca|da|de|de-at|el|es|et|fi|fr|ga|hr|hu|is|it|lt|lv|mt|nl|no|pl|pt|pt-br|ro|sk|sl|sq|sr|bs|mk|sv|tr|uk|ru|hi|id|ja|ko|vi|zh|zh-hant|ar';

    /**
     * A language code as Kaleta stores it (3.9, docs/design/4.0-data-model.md §2): a lowercase BCP 47 tag – the language,
     * then optional script, region or variant subtags (cs, en, pt-br, zh-hant, sr-latn) – in a VARCHAR(35) ASCII column.
     * Every place that reads a code from a URL, a settings key, an import or a tool goes through the helpers below (a unit
     * test refuses a two-letter pattern of its own); which codes are accepted is still only what AVAILABLE offers.
     */
    public const string TAG = '[a-z]{2,3}(?:-[a-z0-9]{2,8}){0,3}';

    /**
     * Languages written right to left: Arabic is offered; Hebrew, Persian, Urdu and the others are ready for the day a
     * dictionary comes. A script subtag decides first – az-arab is right to left, a Latin transliteration of an RTL language is not.
     */
    public const array RTL = ['ar', 'he', 'fa', 'ur', 'ps', 'sd', 'ug', 'yi', 'dv', 'ckb'];

    /** Scripts written right to left (ISO 15924, lowercase as a stored tag has them). */
    private const array RTL_SCRIPTS = ['arab', 'hebr', 'thaa', 'syrc', 'nkoo', 'adlm', 'rohg', 'mand', 'samr'];

    /** The longest tag a language column holds (RFC 5646 recommends supporting 35 characters). */
    public const int TAG_MAX = 35;

    /** Is it a code in the stored form (a lowercase tag, at most TAG_MAX characters)? Says nothing about whether Kaleta offers it. */
    public static function isTag(string $code): bool
    {
        return strlen($code) <= self::TAG_MAX && preg_match('/^' . self::TAG . '$/D', $code) === 1;
    }

    /** Is it a code Kaleta offers (AVAILABLE)? The only codes accepted from requests, imports, exports and tools. */
    public static function isOffered(string $code): bool
    {
        return self::isTag($code) && isset(self::AVAILABLE[$code]);
    }

    /** The value when it is a code Kaleta offers, otherwise '' – the default language (a stored language column). */
    public static function offeredOrDefault(mixed $value): string
    {
        return is_string($value) && self::isOffered($value) ? $value : '';
    }

    /**
     * A tag from another system (Joomla's pt-BR, a Drupal langcode, a locale pt_BR) in the stored form: the longest part of the
     * tag Kaleta offers – zh-Hant-TW → zh-hant, pt-BR → pt-br, en-US → en (§2 step 2) – otherwise ''.
     */
    public static function fromForeign(string $tag): string
    {
        $tag = strtolower(str_replace('_', '-', trim($tag)));
        if (!self::isTag($tag)) {
            return '';
        }
        for ($parts = explode('-', $tag); $parts !== []; array_pop($parts)) {
            if (self::isOffered($code = implode('-', $parts))) {
                return $code;
            }
        }

        return '';
    }

    /**
     * A code for a site with the languages $site: a regional version the site does not have, whose language it has, becomes
     * that language (en-gb into a site with en); anything else stays. Imports use it so a regional source lands in the
     * version the site already has.
     *
     * @param list<string> $site
     */
    public static function forSite(string $code, array $site): string
    {
        return !in_array($code, $site, true) && in_array(self::base($code), $site, true) ? self::base($code) : $code;
    }

    /** Is the language written right to left? By the script subtag when the code has one, otherwise by the language (RTL). */
    public static function isRtl(string $code): bool
    {
        $parts = explode('-', strtolower($code));
        foreach (array_slice($parts, 1) as $part) {
            if (strlen($part) === 4 && ctype_alpha($part)) {
                return in_array($part, self::RTL_SCRIPTS, true);
            }
        }

        return in_array($parts[0], self::RTL, true);
    }

    /** The value of the HTML dir attribute for a language: rtl or ltr. */
    public static function dir(string $code): string
    {
        return self::isRtl($code) ? 'rtl' : 'ltr';
    }

    /**
     * ' dir="rtl"' for a right-to-left language, otherwise '' – a left-to-right page keeps its markup as it was (ltr is the
     * default of HTML). For <html>, e-mails and content areas of the administration.
     */
    public static function dirAttribute(string $code): string
    {
        return self::isRtl($code) ? ' dir="rtl"' : '';
    }

    /**
     * Does the language have texts for visitors (system/jazyky/<code>.php, or its language's – pt-br uses pt.php; Czech and
     * English always)? Without them a version shows Search, Read more… in English (3.12: Settings warns).
     */
    public static function hasVisitorTexts(string $code): bool
    {
        return self::isOffered($code) && (in_array(self::base($code), ['cs', 'en'], true) || is_file(KALETA_SYSTEM . '/jazyky/' . $code . '.php')
            || is_file(KALETA_SYSTEM . '/jazyky/' . self::base($code) . '.php'));
    }

    /** The language subtag of a stored code: pt-br → pt, zh-hant → zh, cs → cs. What a regional version shares with its language. */
    public static function base(string $code): string
    {
        return explode('-', $code, 2)[0];
    }

    /**
     * A stored code in the canonical case of BCP 47 (RFC 5646 §2.1.1) for <html lang>, hreflang, feeds and structured data:
     * the language lowercase, a script titlecase, a region uppercase – pt-br → pt-BR, zh-hant → zh-Hant, sr-latn-rs → sr-Latn-RS.
     * A two-letter code stays as it is. URLs, settings keys and the database keep the lowercase form.
     */
    public static function tag(string $code): string
    {
        $parts = explode('-', strtolower($code));
        foreach ($parts as $i => $part) {
            if ($i > 0 && strlen($part) === 1) {
                break; // an extension or private use (x-…) stays lowercase
            }
            if ($i > 0) {
                $parts[$i] = match (true) {
                    strlen($part) === 4 && ctype_alpha($part) => ucfirst($part),
                    strlen($part) === 2 && ctype_alpha($part), strlen($part) === 3 && ctype_digit($part) => strtoupper($part),
                    default => $part,
                };
            }
        }

        return implode('-', $parts);
    }

    /**
     * The language prefix of a site path: "/en/kontakt" → ["en", "/kontakt"], "/en" → ["en", "/"]; null when the first
     * segment is not a code Kaleta offers. Whether that version is switched on is the caller's question (Front\Kernel asks
     * additional(), App::url() only keeps a prefix a link already has).
     *
     * @return array{0: string, 1: string}|null
     */
    public static function splitPrefix(string $path): ?array
    {
        if (preg_match('#^/(' . self::TAG . ')(/.*)?$#Ds', $path, $m) !== 1 || !self::isOffered($m[1])) {
            return null;
        }

        return [$m[1], ($m[2] ?? '') !== '' ? $m[2] : '/'];
    }

    /**
     * A settings key with a language suffix (site_name_en, popis_webu_de): [base, code] when the base is one of $bases and
     * the code is one Kaleta offers, otherwise null.
     *
     * @param list<string> $bases
     * @return array{0: string, 1: string}|null
     */
    public static function settingKey(string $key, array $bases): ?array
    {
        foreach ($bases as $base) {
            if (str_starts_with($key, $base . '_') && self::isOffered($code = substr($key, strlen($base) + 1))) {
                return [$base, $code];
            }
        }

        return null;
    }

    private static string $code = 'cs';

    private static bool $loaded = false;
    private static string $column = '';

    /** @var array<string, string> */
    private static array $dictionary = [];

    /** Languages the administration is translated into (dictionary system/jazyky/admin-<code>.php). */
    public const array ADMIN_LANGUAGES = ['cs' => 'Čeština', 'en' => 'English', 'de' => 'Deutsch', 'es' => 'Español'];

    /**
     * German registers (issue #20): formal (Sie, the base dictionaries) and informal (du, an overlay system/jazyky/<set>de-du.php with only the
     * strings that contain a form of address). The register is a setting, not a language: the code stays "de".
     */
    public const array REGISTERS = ['formal', 'informal'];

    private static string $register = 'formal';

    /** Register of the administration texts (the user's choice) and of the site texts (setting german_register), for code that switches language. */
    private static string $adminRegister = 'formal';

    private static string $siteRegister = 'formal';

    /** The language has no dictionary of its own, texts come from the English one (the date in words then comes from the intl extension, if the server has it). */
    private static bool $baseOnly = false;

    /**
     * The language's dictionary: its own (system/jazyky/<set><code>.php), and what is missing there, from the English one – a language
     * without a dictionary thus has template texts in English and the date in its own numeric format. Czech needs no dictionary (texts in the code are Czech).
     *
     * @param string $dictionarySet "" = site texts, "admin-" = administration texts, "install-" = installer texts
     * @param string|null $register formal | informal (only German has it); null = the register of the administration (admin-, install-) or of the site
     */
    public static function set(string $code, string $dictionarySet = '', ?string $register = null): void
    {
        self::$code = isset(self::AVAILABLE[$code]) ? $code : 'cs';
        self::$register = in_array($register, self::REGISTERS, true) ? $register : ($dictionarySet === '' ? self::$siteRegister : self::$adminRegister);
        self::$loaded = true;
        self::$dictionary = [];
        self::$baseOnly = false;
        if (self::$code === 'cs') {
            // the source texts are English since 1.4.1; Czech is a dictionary like any other (texts still Czech pass through)
            $file = KALETA_SYSTEM . '/jazyky/' . $dictionarySet . 'cs.php';
            self::$dictionary = is_file($file) ? require $file : [];

            return;
        }
        // a regional version without its own dictionary uses the one of its language (de-at → de.php, en-gb → en.php)
        $file = KALETA_SYSTEM . '/jazyky/' . $dictionarySet . self::$code . '.php';
        $file = is_file($file) ? $file : KALETA_SYSTEM . '/jazyky/' . $dictionarySet . self::base(self::$code) . '.php';
        $custom = is_file($file) ? require $file : [];
        $overlay = KALETA_SYSTEM . '/jazyky/' . $dictionarySet . self::base(self::$code) . '-du.php';
        if (self::$register === 'informal' && is_file($overlay)) {
            $custom = (require $overlay) + $custom;
        }
        $baseDictionary = self::base(self::$code) !== 'en' && is_file(KALETA_SYSTEM . '/jazyky/' . $dictionarySet . 'en.php') ? require KALETA_SYSTEM . '/jazyky/' . $dictionarySet . 'en.php' : [];
        self::$dictionary = $custom + $baseDictionary;
        if (self::base(self::$code) !== 'en') {
            // the date format from the English dictionary is not taken over: its own, otherwise the language's numeric format and the date in words from the Czech keys of days and months
            if (!isset($custom['datum_format'])) {
                self::$dictionary['datum_format'] = self::AVAILABLE[self::$code][2];
            }
            if (!isset($custom['datum_slovy'])) {
                unset(self::$dictionary['datum_slovy']);
            }
            self::$baseOnly = $custom === [];
        }
    }

    /** Locale for the date in words through the intl extension – only for a language without its own dictionary; otherwise null. */
    public static function intlLocale(): ?string
    {
        return self::$baseOnly && class_exists(\IntlDateFormatter::class) ? self::AVAILABLE[self::$code][1] : null;
    }

    /**
     * Language of the currently shown version of the site; also remembers the value of the "jazyk" column for queries.
     */
    public static function setSite(Settings $s, string $code): void
    {
        self::setSiteRegister($s->get('german_register'));
        self::set($code);
        self::$column = self::column($s, self::$code);
        Collation::setLanguage(self::$code); // lists of the version sort in its alphabet (3.12)
    }

    /**
     * Runs a function with the site texts in another language and switches the language back. For content whose language does not
     * depend on who is creating it – typically an e-mail to a visitor (triggered by an administrator in the administration or by a background task).
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public static function runWith(string $code, callable $callback, string $dictionarySet = '', ?string $register = null): mixed
    {
        [$previousCode, $previousDictionary, $previousBase, $previousRegister] = [self::$code, self::$dictionary, self::$baseOnly, self::$register];
        self::set($code, $dictionarySet, $register); // set "admin-" = an e-mail to an administration user in the language of their administration
        try {
            return $callback();
        } finally {
            [self::$code, self::$dictionary, self::$baseOnly, self::$register] = [$previousCode, $previousDictionary, $previousBase, $previousRegister];
        }
    }

    /** Value of the "jazyk" column for the currently shown version of the site ('' = default language). Only '' or a code from AVAILABLE. */
    public static function siteColumn(): string
    {
        return self::$column;
    }

    /** Any value to formal | informal (anything unknown, empty included, is formal). */
    public static function normalizeRegister(string $register): string
    {
        return in_array($register, self::REGISTERS, true) ? $register : 'formal';
    }

    /** Register of the administration of the person using it (My account); texts of the administration, installer and e-mails to staff follow it. */
    public static function setAdminRegister(string $register): void
    {
        self::$adminRegister = self::normalizeRegister($register);
    }

    /** Register of the site texts (setting german_register): the texts for visitors and e-mails to them. */
    public static function setSiteRegister(string $register): void
    {
        self::$siteRegister = self::normalizeRegister($register);
    }

    /**
     * Form of address of the German texts for visitors, or null when no version of the site is German – it is what Claude needs to know
     * when it writes pages, news or replies to enquiries for visitors (the administration may use the other form).
     */
    public static function visitorAddress(Settings $s): ?string
    {
        return in_array('de', array_map(self::base(...), array_merge([self::defaults($s)], self::additional($s))), true) ? self::normalizeRegister($s->get('german_register')) : null;
    }

    /** Register of the currently loaded dictionary: only German has an informal one. */
    public static function register(): string
    {
        return self::$register;
    }

    public static function code(): string
    {
        return self::$code;
    }

    public static function t(string $text, string|int ...$values): string
    {
        if (!self::$loaded) {
            self::set(self::$code); // texts before the first set() (early errors, tools) use the default language too
        }
        $translation = self::$dictionary[$text] ?? $text;
        if ($values !== [] && self::isRtl(self::$code)) {
            // a name, an address or a date inside a right-to-left sentence is its own direction island (first strong isolate),
            // so "(5 cm)" of a Latin product name does not lose its bracket to the Arabic around it
            $values = array_map(fn (string|int $v): string|int => is_string($v) && $v !== '' ? "\u{2068}" . $v . "\u{2069}" : $v, $values);
        }

        return $values === [] ? $translation : sprintf($translation, ...$values);
    }

    /** Default language of the site. */
    public static function defaults(Settings $s): string
    {
        return isset(self::AVAILABLE[$s->get('site_language')]) ? $s->get('site_language') : 'cs';
    }

    /**
     * Additional language versions of the site (without the default language); empty when the extension is disabled.
     *
     * @return list<string>
     */
    public static function additional(Settings $s): array
    {
        if (!Extensions::isEnabled($s, 'jazyky')) {
            return [];
        }

        return array_values(array_diff(array_intersect(explode(',', $s->get('additional_languages')), array_keys(self::AVAILABLE)), [self::defaults($s)]));
    }

    /**
     * Additional languages the site offers to visitors and search engines (language switcher, hreflang, sitemap). When the home
     * of the site is a page, only languages with its published translation – a language version in progress is not shown yet.
     *
     * @return list<string>
     */
    public static function published(Settings $s, Db $db): array
    {
        $additional = self::additional($s);
        $home = $s->int('home_page');
        if ($additional === [] || $home === 0) {
            return $additional;
        }
        $done = array_column($db->all('SELECT DISTINCT jazyk FROM {stranky} WHERE preklad_z = ? AND zobrazit = 1 AND smazano IS NULL', [$home]), 'jazyk');

        return array_values(array_intersect($additional, $done));
    }

    /** Language of content by the "jazyk" column (page, category, news item): empty = the site's default language. */
    public static function ofContent(Settings $s, string $column): string
    {
        return isset(self::AVAILABLE[$column]) ? $column : self::defaults($s);
    }

    /** Value of the "jazyk" column for the given language: the site's default language is stored as an empty string. */
    public static function column(Settings $s, string $code): string
    {
        return $code === self::defaults($s) || !in_array($code, self::additional($s), true) ? '' : $code;
    }
}
