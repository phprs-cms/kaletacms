<?php

declare(strict_types=1);

// 3.35 re-review N3: when the compare-and-swap of Auth::replaceBackupCodes loses every round nothing was stored – My account
// then shows an error instead of codes and logs neither two_factor_on nor backup_codes_new
use Kaleta\Admin\Account;

$r363Account = (string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Account.php');
$r363On = substr($r363Account, (int) strpos($r363Account, '[$codes, $json] = Totp::backupCodes();'), 900);
$r363New = substr($r363Account, (int) strrpos($r363Account, '[$codes, $json] = Totp::backupCodes();'), 700);
check('3.35 re-review N3: every caller checks the result; both places that show codes check it the result before logging and before showing the codes', [
    substr_count($r363Account, '$app->auth()->replaceBackupCodes('), substr_count($r363Account, '!$app->auth()->replaceBackupCodes('),
    strpos($r363On, 'self::CODES_NOT_SAVED') < strpos($r363On, "'two_factor_on'"), strpos($r363On, 'self::CODES_NOT_SAVED') < strpos($r363On, "'backupCodes' => \$codes"),
    strpos($r363New, 'self::CODES_NOT_SAVED') < strpos($r363New, "'backup_codes_new'"), strpos($r363New, 'self::CODES_NOT_SAVED') < strpos($r363New, "'backupCodes' => \$codes"),
], [3, 3, true, true, true, true]);
check('3.35 re-review N3: the error is translated (cs, de, es, de-du)', [
    array_map(fn (string $code): bool => Kaleta\Core\Language::runWith($code, fn (): bool => t(Account::CODES_NOT_SAVED) !== Account::CODES_NOT_SAVED, 'admin-'), ['cs', 'de', 'es']),
    isset((require KALETA_SYSTEM . '/jazyky/admin-de-du.php')[Account::CODES_NOT_SAVED]),
], [[true, true, true], true]);
