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
     * code => [name in that language, locale (Open Graph, date format), numeric date format for date()]. Right-to-left languages
     * (Arabic, Hebrew) are not available yet – templates and the builder do not account for them.
     */
    public const array AVAILABLE = [
        'cs' => ['Čeština', 'cs_CZ', 'j. n. Y'],
        'en' => ['English', 'en_US', 'j M Y'],
        'bg' => ['Български', 'bg_BG', 'd.m.Y'],
        'ca' => ['Català', 'ca_ES', 'd/m/Y'],
        'da' => ['Dansk', 'da_DK', 'd.m.Y'],
        'de' => ['Deutsch', 'de_DE', 'd.m.Y'],
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
    ];

    /** Codes from AVAILABLE for Settings field types (vyber:… / seznam:…). */
    public const string CODES = 'cs|en|bg|ca|da|de|el|es|et|fi|fr|ga|hr|hu|is|it|lt|lv|mt|nl|no|pl|pt|ro|sk|sl|sq|sr|bs|mk|sv|tr|uk|ru|hi|id|ja|ko|vi|zh';

    /**
     * A language code as Kaleta stores it (3.9, docs/design/4.0-data-model.md §2): a lowercase BCP 47 tag – the language,
     * then optional script, region or variant subtags (cs, en, pt-br, zh-hant, sr-latn) – in a VARCHAR(35) ASCII column.
     * Every place that reads a code from a URL, a settings key, an import or a tool goes through the helpers below (a unit
     * test refuses a two-letter pattern of its own); which codes are accepted is still only what AVAILABLE offers.
     */
    public const string TAG = '[a-z]{2,3}(?:-[a-z0-9]{2,8}){0,3}';

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
     * A tag from another system (Joomla's pt-BR, a Drupal langcode, a locale pt_BR) in the stored form: the whole tag when
     * Kaleta offers it, otherwise its language subtag when that is offered (pt-BR → pt until regional versions exist,
     * §2 step 2), otherwise ''.
     */
    public static function fromForeign(string $tag): string
    {
        $tag = strtolower(str_replace('_', '-', trim($tag)));
        if (!self::isTag($tag)) {
            return '';
        }

        return self::isOffered($tag) ? $tag : self::offeredOrDefault(explode('-', $tag)[0]);
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
    public const array ADMIN_LANGUAGES = ['cs' => 'Čeština', 'en' => 'English', 'de' => 'Deutsch'];

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
        $file = KALETA_SYSTEM . '/jazyky/' . $dictionarySet . self::$code . '.php';
        $custom = is_file($file) ? require $file : [];
        $overlay = KALETA_SYSTEM . '/jazyky/' . $dictionarySet . self::$code . '-du.php';
        if (self::$register === 'informal' && is_file($overlay)) {
            $custom = (require $overlay) + $custom;
        }
        $baseDictionary = self::$code !== 'en' && is_file(KALETA_SYSTEM . '/jazyky/' . $dictionarySet . 'en.php') ? require KALETA_SYSTEM . '/jazyky/' . $dictionarySet . 'en.php' : [];
        self::$dictionary = $custom + $baseDictionary;
        if (self::$code !== 'en') {
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
        return in_array('de', array_merge([self::defaults($s)], self::additional($s)), true) ? self::normalizeRegister($s->get('german_register')) : null;
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
