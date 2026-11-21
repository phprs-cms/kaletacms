<?php

declare(strict_types=1);

/* ---------- 3.50 B2b (Q9): the code reads Settings::DECLARATIONS; the four public lists stay as mirrors for older code during an update ---------- */
use Kaleta\Core\Settings as B2bSettings;

// a mirror that drifted from the declaration: the message prints the line to paste (in the order of the declaration)
$b2bMirrors = [
    'Kaleta\Core\Vault::SECRET_SETTINGS' => ['secret', Kaleta\Core\Vault::SECRET_SETTINGS],
    'Kaleta\Core\SiteExport::SETTINGS' => ['export', Kaleta\Core\SiteExport::SETTINGS],
    'Kaleta\Core\Settings::PER_LANGUAGE' => ['per_language', B2bSettings::PER_LANGUAGE],
    'Kaleta\Core\Settings::LAZY' => ['lazy', B2bSettings::LAZY],
];
$b2bDrift = [];
foreach ($b2bMirrors as $b2bName => [$b2bFlag, $b2bList]) {
    $b2bDeclared = B2bSettings::keysWith($b2bFlag);
    if (array_diff($b2bDeclared, $b2bList) !== [] || array_diff($b2bList, $b2bDeclared) !== [] || count($b2bList) !== count(array_unique($b2bList))) {
        $b2bDrift[$b2bName] = 'public const array ' . substr($b2bName, strrpos($b2bName, '::') + 2) . " = ['" . implode("', '", $b2bDeclared) . "'];";
    }
}
check('3.50 B2b: the mirrors Vault::SECRET_SETTINGS, SiteExport::SETTINGS, Settings::PER_LANGUAGE and LAZY hold the keys of their flag (a drifted one prints the line to paste)', $b2bDrift, []);

// nothing new reads a mirror: a rule over the tree (code only, comments aside) – the mirrors are for the classes of an older
// release that are in memory during an update, never for new code
$b2bReads = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(KALETA_SYSTEM, FilesystemIterator::SKIP_DOTS)) as $b2bFile) {
    if ($b2bFile->getExtension() !== 'php') {
        continue;
    }
    $b2bCode = implode('', array_map(fn ($t): string => is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $t[1]) : $t, token_get_all((string) file_get_contents($b2bFile->getPathname()))));
    $b2bName = substr($b2bFile->getPathname(), strlen(KALETA_SYSTEM) + 1);
    preg_match_all('/\b(?:Vault::SECRET_SETTINGS|self::SECRET_SETTINGS|SiteExport::SETTINGS|Settings::PER_LANGUAGE|self::PER_LANGUAGE|Settings::LAZY|self::LAZY|MCP_SETTINGS'
        . ($b2bName === 'src/Core/SiteExport.php' ? '|self::SETTINGS' : '') . ')\b/', $b2bCode, $b2bM);
    if ($b2bM[0] !== []) {
        $b2bReads[$b2bName] = array_values(array_unique($b2bM[0]));
    }
}
check('3.50 B2b: no code under system/ reads the mirrors or an MCP_SETTINGS pattern – it asks Settings::keysWith() and Settings::claudeMaySet()', $b2bReads, []);
check('3.50 B2b: Mcp\Tools has no MCP_SETTINGS any more; tools/settings.php reads Claude\'s access from Settings::claudeMaySet', [
    (new ReflectionClass(Kaleta\Mcp\Tools::class))->hasConstant('MCP_SETTINGS'), str_contains((string) file_get_contents(KALETA_ROOT . '/tools/settings.php'), 'Settings::claudeMaySet($key)')], [false, true]);

// the readers answer as before: an older plain secret stays readable and is never "unreadable", a lazy key read is remembered
$b2bSettings = (new ReflectionClass(B2bSettings::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(B2bSettings::class, 'values'))->setValue($b2bSettings, ['site_name' => 'Web', 'smtp_password' => 'plain-old', 'domain_watch' => '{"a":1}']);
(new ReflectionProperty(B2bSettings::class, 'lazyRead'))->setValue($b2bSettings, ['domain_watch' => true, 'look_draft' => true]);
check('3.50 B2b: Settings::get() of an older plain secret, a lazy key already read and a plain key; secretUnreadable() only for secrets', [
    $b2bSettings->get('smtp_password'), $b2bSettings->get('domain_watch'), $b2bSettings->get('look_draft'), $b2bSettings->get('site_name'), $b2bSettings->secretUnreadable('smtp_password'), $b2bSettings->secretUnreadable('site_name')],
    ['plain-old', '{"a":1}', '', 'Web', false, false]);
