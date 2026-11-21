<?php

declare(strict_types=1);

/* ---------- 3.50 A2: System status no longer claims that a key in config.php protects every CV – old headers keep the old wrapping (N131) ---------- */
$a508Config = Kaleta\Core\Language::runWith('en', fn (): string => Kaleta\Core\Vault::describe('config'), 'admin-');
check('3.50 N131: the key-in-config.php text names the exception of CVs sealed before the move or a new signing key, in every admin language', [
    str_contains($a508Config, 'a stolen database or local backup alone does not reveal them.'), str_contains($a508Config, 'still opens with the old key'),
    ...array_map(fn (string $code): bool => Kaleta\Core\Language::runWith($code, fn (): string => Kaleta\Core\Vault::describe('config'), 'admin-') !== $a508Config, ['cs', 'de', 'es'])],
    [true, true, true, true, true]);
