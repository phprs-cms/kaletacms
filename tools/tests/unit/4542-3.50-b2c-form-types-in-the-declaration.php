<?php

declare(strict_types=1);

/* ---------- 3.50 B2c (Q9): the tab and the form type of a setting are part of its declaration; Admin\Modules\Settings::FIELDS is gone ---------- */
use Kaleta\Admin\Modules\Settings as B2cModule;
use Kaleta\Core\Settings as B2cSettings;

// the rows: a tab goes with a type, the tab is one of FORM_TABS, and fields() keeps every tab (an empty one too) in order
$b2cBad = [];
foreach (B2cSettings::DECLARATIONS as $b2cKey => $b2cRow) {
    if (isset($b2cRow['tab']) !== isset($b2cRow['type']) || (isset($b2cRow['tab']) && !in_array($b2cRow['tab'], B2cSettings::FORM_TABS, true))
        || (isset($b2cRow['type']) && (!is_string($b2cRow['type']) || $b2cRow['type'] === ''))) {
        $b2cBad[] = $b2cKey;
    }
}
check('3.50 B2c: a row of Settings::DECLARATIONS has a tab and a type together, the tab is one of FORM_TABS and the type is a text', $b2cBad, []);
check('3.50 B2c: Settings::fields() has every tab of FORM_TABS in its order (sections and console are empty), 148 typed keys, each in one tab', [
    array_keys(B2cSettings::fields()), B2cSettings::fields()['sections'], B2cSettings::fields()['console'], count(array_merge(...array_values(B2cSettings::fields()))),
    count(array_unique(array_merge(...array_map('array_keys', array_values(B2cSettings::fields())))))],
    [B2cSettings::FORM_TABS, [], [], 148, 148]);

// the types that are built from constants of other classes are what those constants say
check('3.50 B2c: the types built from shared lists – languages, social networks, auto-suspension, mail services, AI providers, the screen bounds', [
    B2cSettings::fields()['languages']['site_language'], B2cSettings::fields()['general']['social_networks'], B2cSettings::fields()['security']['auto_suspend'],
    B2cSettings::fields()['mail']['smtp_provider'], B2cSettings::fields()['extensions']['ai_provider'], B2cSettings::fields()['general']['screen_seconds'],
    [Kaleta\Front\Screen::MIN_SECONDS, Kaleta\Front\Screen::MAX_SECONDS]], [
    'vyber:' . Kaleta\Core\Language::CODES, 'seznam:' . Kaleta\Core\SocialDrafts::NETWORK_KEYS,
    'seznam:' . Kaleta\Core\SecurityHygiene::SUSPEND_ACCOUNTS . '|' . Kaleta\Core\SecurityHygiene::SUSPEND_CONNECTIONS,
    'vyber:' . Kaleta\Core\MailServices::CHOICES, 'vyber:' . Kaleta\Core\Assistant::PROVIDER_KEYS, 'cislo:5:60', [5, 60]]);

// the module keeps no list of its own and still cleans, links and moves the fields as before
$b2cModule = new ReflectionClass(B2cModule::class);
check('3.50 B2c: Admin\Modules\Settings has no FIELDS, COMPANY_TYPES or CURRENCY_CHOICES any more', [
    $b2cModule->hasConstant('FIELDS'), $b2cModule->hasConstant('COMPANY_TYPES'), $b2cModule->hasConstant('CURRENCY_CHOICES')], [false, false, false]);
check('3.50 B2c: verifyValue reads the declared types – a company type, a currency, the screen bounds, a language variant, a secret and an unknown key', [
    B2cModule::verifyValue('company_type', 'Store'), B2cModule::verifyValue('company_type', 'Shop'), B2cModule::verifyValue('default_currency', 'CZK'), B2cModule::verifyValue('screen_seconds', '99'),
    B2cModule::verifyValue('cookies_text_de', ' Hallo '), B2cModule::verifyValue('smtp_password', 'x'), B2cModule::verifyValue('no_such_setting', 'x')],
    ['Store', null, 'CZK', '60', 'Hallo', null, null]);
check('3.50 B2c: fieldUrl and movedFields find a key\'s tab in the declaration', [
    B2cModule::fieldUrl('agency_logo'), B2cModule::fieldUrl('company_name'), B2cModule::fieldUrl('cookies_text_en'), B2cModule::fieldUrl('dark_mode'), B2cModule::fieldUrl('look_draft')],
    ['module=settings&tab=agency#agency_logo', 'module=business#company_name', 'module=settings&tab=cookies#cookies_text_en', 'module=settings', 'module=settings']);

// 3.50 integration: DECLARATIONS is read on every request, so the four lists of classes the request may never need are written
// as literals (a class constant in a constant expression autoloads its class) – each literal is held equal to its constant here
$b2cDecl = B2cSettings::DECLARATIONS;
check('3.50 integration: the literal types of DECLARATIONS equal the constants they mirror (SocialDrafts, SecurityHygiene, MailServices, Assistant)', [
    $b2cDecl['social_networks']['type'], $b2cDecl['auto_suspend']['type'], $b2cDecl['smtp_provider']['type'], $b2cDecl['ai_provider']['type']], [
    'seznam:' . Kaleta\Core\SocialDrafts::NETWORK_KEYS, 'seznam:' . Kaleta\Core\SecurityHygiene::SUSPEND_ACCOUNTS . '|' . Kaleta\Core\SecurityHygiene::SUSPEND_CONNECTIONS,
    'vyber:' . Kaleta\Core\MailServices::CHOICES, 'vyber:' . Kaleta\Core\Assistant::PROVIDER_KEYS]);
// … and reading the declaration, as every request does, loads none of those four classes (a child process, plain bootstrap)
$b2cChild = json_decode((string) shell_exec(escapeshellarg(PHP_BINARY) . ' -n -r ' . escapeshellarg('require ' . var_export(KALETA_SYSTEM . '/bootstrap.php', true)
    . '; $d = Kaleta\Core\Settings::DECLARATIONS; Kaleta\Core\Settings::keysWith("export"); echo json_encode(array_map(fn ($c) => class_exists("Kaleta\\\\Core\\\\" . $c, false), ["SocialDrafts", "SecurityHygiene", "MailServices", "Assistant", "Settings"]));') . ' 2>&1'), true);
check('3.50 integration: reading Settings::DECLARATIONS autoloads none of SocialDrafts, SecurityHygiene, MailServices, Assistant (Settings itself is loaded)',
    $b2cChild, [false, false, false, false, true]);
