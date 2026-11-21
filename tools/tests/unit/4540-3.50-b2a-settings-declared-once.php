<?php

declare(strict_types=1);

/* ---------- 3.50 B2a (Q9): every setting is declared once – Settings::DECLARATIONS answers what the lists of 3.49 answered ---------- */
use Kaleta\Core\Settings as B2aSettings;

// the rows: keys of DEFAULTS only, known flags only, each flag written as true
$b2aFlags = ['secret', 'export', 'mcp', 'per_language', 'lazy'];
$b2aBad = [];
foreach (B2aSettings::DECLARATIONS as $b2aKey => $b2aRow) {
    if (!array_key_exists($b2aKey, B2aSettings::DEFAULTS)) {
        $b2aBad[$b2aKey] = 'not in DEFAULTS';
    }
    foreach ($b2aRow as $b2aName => $b2aValue) {
        if (in_array($b2aName, $b2aFlags, true) && $b2aValue !== true) {
            $b2aBad[$b2aKey] = $b2aName . ' is not true';
        }
    }
}
check('3.50 B2a: every row of Settings::DECLARATIONS is a key of DEFAULTS, and a flag is only ever written as true', $b2aBad, []);

// the projections are the four lists of 3.49 (kept as their mirrors, unit 4541)
$b2aSorted = function (array $list): array {
    sort($list);

    return $list;
};
check('3.50 B2a: keysWith() gives the secret, exported, per-language and lazy settings of 3.49', [
    $b2aSorted(B2aSettings::keysWith('secret')), $b2aSorted(B2aSettings::keysWith('export')), $b2aSorted(B2aSettings::keysWith('per_language')), $b2aSorted(B2aSettings::keysWith('lazy')),
], [
    $b2aSorted(Kaleta\Core\Vault::SECRET_SETTINGS), $b2aSorted(Kaleta\Core\SiteExport::SETTINGS), $b2aSorted(B2aSettings::PER_LANGUAGE), $b2aSorted(B2aSettings::LAZY),
]);
check('3.50 B2a: keysWith() of an unknown flag is empty, and the answer is the same the second time (memoised)', [
    B2aSettings::keysWith('no_such_flag'), B2aSettings::keysWith('lazy') === B2aSettings::keysWith('lazy')], [[], true]);

// Claude's access: the rule of 3.49 (Mcp\Tools::MCP_SETTINGS) frozen here, asked for every key, every language variant and near misses
$b2aRule349 = '/^(site_name|site_description|footer_text|home_page|news_slug|news_title|social_(facebook|instagram|x|youtube|linkedin)|news_per_page|share_buttons|article_outline|related_news_auto|company_[a-z_]+|security_contact|claude_instructions|lead_attribution|agency_(name|url|email|phone|logo)|captcha_(provider|site_key|fail_open)|dark_mode|theme_switcher|german_register|site_(name|description)_' . Kaleta\Core\Language::TAG . '|indexing|schema_org|llms_txt|markdown_news|indexnow|ai_crawlers|url_slash|robots_extra|verification_(google|bing)|cookies_(mode|text|policy_url|log|log_months)|cookies_(text|policy_url)_' . Kaleta\Core\Language::TAG . '|stats|ga4_id|plausible_domain|screen_(mode|seconds|news|hours|clock)|redirect_auto(_threshold)?|booking_(lead_hours|horizon_days|cancel_hours|reminder_hours|hold_hours|pending_thanks|pending_mail|declined_mail))$/D';
$b2aAsked = array_keys(B2aSettings::DEFAULTS);
foreach (array_keys(B2aSettings::DEFAULTS) as $b2aKey) {
    foreach ([...array_keys(Kaleta\Core\Language::AVAILABLE), 'en-gb-x', 'xx', 'abcd', 'EN', '1a'] as $b2aCode) {
        $b2aAsked[] = $b2aKey . '_' . $b2aCode;
    }
}
array_push($b2aAsked, 'company_', 'company', 'company_x', 'company_X', 'company_1', 'company_a-b', 'companyname', 'xcompany_name', 'company_name2', 'company_address', 'company_vat',
    'site_name_', 'site_name ', ' site_name', "site_name\n", "site_name_en\n", 'SITE_NAME', 'site_name_en-gb-x-y-z-w', 'site_name_en-gb-1234', 'site_description_1a', 'cookies_text_', 'cookies_policy_url_xx1',
    'head_code', 'marketing_code', 'cookies_external_code', 'gtm_id', 'matomo_url', 'matomo_id', 'redirect_auto_', 'redirect_automatic', 'booking_', 'booking_pending', '', '_', 'stats_');
$b2aDiffer = array_values(array_filter($b2aAsked, fn (string $k): bool => B2aSettings::claudeMaySet($k) !== (preg_match($b2aRule349, $k) === 1)));
check('3.50 B2a: Settings::claudeMaySet() answers as the rule of 3.49 for ' . count($b2aAsked) . ' keys, language variants and near misses', $b2aDiffer, []);
check('3.50 B2a: an undeclared company_ key may be asked for (and then gets "invalid value"); code, GTM and Matomo never', [
    B2aSettings::claudeMaySet('company_x'), B2aSettings::claudeMaySet('company_address'), B2aSettings::claudeMaySet('site_name_de'), B2aSettings::claudeMaySet('cookies_policy_url_en-gb'),
    B2aSettings::claudeMaySet('head_code'), B2aSettings::claudeMaySet('gtm_id'), B2aSettings::claudeMaySet('footer_text_de'), B2aSettings::claudeMaySet('smtp_password')],
    [true, true, true, true, false, false, false, false]);

// declaration() reads the declaration (its shape is the one of 3.42)
check('3.50 B2a: Settings::declaration() of a secret, an exported per-language and a lazy key is what 3.49 gave', [
    B2aSettings::declaration('ai_key'), B2aSettings::declaration('cookies_text')['per_language'] ?? null, B2aSettings::declaration('cookies_text')['export'] ?? null, B2aSettings::declaration('domain_watch')['lazy'] ?? null],
    [['default' => '', 'secret' => true, 'export' => false, 'per_language' => false, 'lazy' => false, 'old_names' => ['ai_klic']], true, true, true]);
