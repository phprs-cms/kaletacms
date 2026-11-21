<?php

declare(strict_types=1);

use Kaleta\Core\QuoteIntake;
use Kaleta\Core\QuoteLines;
use Kaleta\Core\QuoteNumbers;
use Kaleta\Core\Quotes;

/* ---------- 3.49 pre-step P2b: Core\Quotes split into QuoteLines, QuoteNumbers and QuoteIntake ---------- */
// Every public member that moved keeps a forward on Core\Quotes (13 callers of prepareDraft, index.php, migrations, the
// tests). The forward must take the same parameters (name, type, default, by reference) and return the same type as the
// method it calls, and an alias constant must hold its target's value – compared through reflection, no source text is read.

/** @var array<class-string, list<string>> target class => the methods Quotes forwards to it */
$p349Methods = [
    QuoteLines::class => ['computeTotals', 'impliedDiscount', 'combinedDiscount'],
    QuoteNumbers::class => ['formatRefusal', 'formatNumber', 'numberFormat', 'assignNumber', 'nextNumber', 'raiseCounter'],
    QuoteIntake::class => ['prepareLater', 'afterResponse', 'preparing', 'prepareDraft', 'dropPreparedDraft', 'parseBasket', 'linesFromEnquiry'],
];
/** @var array<class-string, list<string>> target class => the constants Quotes keeps as aliases */
$p349Constants = [
    QuoteLines::class => ['NAME_MAX', 'DESCRIPTION_MAX'],
    QuoteNumbers::class => ['NUMBER_MAX', 'NUMBER_FORMAT'],
    QuoteIntake::class => ['LOOKUPS_MAX', 'PARTS_MAX', 'AUTO_DRAFT_SETTING', 'AUTO_DRAFT_DAILY_MAX'],
];

$p349Signature = static function (ReflectionMethod $m): string {
    $params = array_map(static fn (ReflectionParameter $p): string => ($p->getType() ?? 'mixed') . ($p->isPassedByReference() ? ' &' : ' ') . '$' . $p->getName()
        . ($p->isDefaultValueAvailable() ? ' = ' . var_export($p->getDefaultValue(), true) : ''), $m->getParameters());

    return ($m->isStatic() ? 'static ' : '') . ($m->isPublic() ? 'public ' : 'private ') . $m->getName() . '(' . implode(', ', $params) . '): ' . ($m->getReturnType() ?? 'mixed');
};
$p349Mismatch = [];
$p349Count = 0;
foreach ($p349Methods as $p349Class => $p349Names) {
    foreach ($p349Names as $p349Name) {
        $p349Count++;
        if (!method_exists($p349Class, $p349Name) || !method_exists(Quotes::class, $p349Name)) {
            $p349Mismatch[] = $p349Class . '::' . $p349Name . ' missing';
            continue;
        }
        $p349Forward = $p349Signature(new ReflectionMethod(Quotes::class, $p349Name));
        $p349Target = $p349Signature(new ReflectionMethod($p349Class, $p349Name));
        if ($p349Forward !== $p349Target) {
            $p349Mismatch[] = $p349Forward . ' ≠ ' . $p349Class . ': ' . $p349Target;
        }
    }
}
check('3.49 P2b: each forward on Core\\Quotes has the signature of the method it calls (parameters, defaults, return type)', [$p349Mismatch, $p349Count], [[], 16]);

// a public method of a new class is either forwarded or a helper Core\Quotes itself calls (marked @internal)
$p349Left = [];
$p349Internal = [];
foreach (array_keys($p349Methods) as $p349Class) {
    foreach ((new ReflectionClass($p349Class))->getMethods(ReflectionMethod::IS_PUBLIC) as $p349Method) {
        if (in_array($p349Method->getName(), $p349Methods[$p349Class], true)) {
            continue;
        }
        if (str_contains((string) $p349Method->getDocComment(), '@internal')) {
            $p349Internal[] = $p349Method->getName();
        } else {
            $p349Left[] = $p349Class . '::' . $p349Method->getName();
        }
    }
}
sort($p349Internal);
check('3.49 P2b: every public method of the three new classes is forwarded, or an @internal helper of Core\\Quotes (nothing a caller used under the old name is lost)',
    [$p349Left, $p349Internal], [[], ['checkDiscount', 'isManual', 'line', 'prepareLine', 'text']]);

$p349Wrong = [];
foreach ($p349Constants as $p349Class => $p349Names) {
    foreach ($p349Names as $p349Name) {
        $p349Alias = new ReflectionClassConstant(Quotes::class, $p349Name);
        if (!$p349Alias->isPublic() || $p349Alias->getValue() !== constant($p349Class . '::' . $p349Name)) {
            $p349Wrong[] = $p349Name;
        }
    }
}
check('3.49 P2b: the alias constants of Core\\Quotes hold the values of the moved ones', $p349Wrong, []);

// the arithmetic and the numbering answer the same through the forward and the new class (pure methods)
$p349Lines = [['unit_price' => '10.10', 'quantity' => '3', 'discount_percent' => '7.5', 'vat_rate' => '21'], ['unit_price' => null, 'quantity' => '1', 'discount_percent' => '0', 'vat_rate' => '12']];
check('3.49 P2b: the moved code answers the same through the forward and the new class (pure methods)', [
    Quotes::computeTotals($p349Lines, '2', 'standard') === QuoteLines::computeTotals($p349Lines, '2', 'standard'),
    Quotes::combinedDiscount(2000, 2000) === QuoteLines::combinedDiscount(2000, 2000),
    Quotes::impliedDiscount(7999, 10000) === QuoteLines::impliedDiscount(7999, 10000),
    Quotes::formatNumber('Q-{YY}/{NNN}', 2031, 7) === QuoteNumbers::formatNumber('Q-{YY}/{NNN}', 2031, 7),
    Quotes::formatRefusal('{NNNN}') === QuoteNumbers::formatRefusal('{NNNN}'),
    Quotes::parseBasket("3 × Laser – Modrý (L-M)\nhello") === QuoteIntake::parseBasket("3 × Laser – Modrý (L-M)\nhello"),
    QuoteLines::computeTotals($p349Lines, '2', 'standard'), QuoteLines::combinedDiscount(2000, 2000), QuoteLines::impliedDiscount(7999, 10000),
    QuoteNumbers::formatNumber('Q-{YY}/{NNN}', 2031, 7), QuoteIntake::parseBasket("3 × Laser – Modrý (L-M)\nhello")],
    [true, true, true, true, true, true,
        ['net' => '27.47', 'vat' => '5.77', 'gross' => '33.24', 'rates' => [['rate' => '21.00', 'net' => '27.47', 'vat' => '5.77']], 'unpriced' => 1], 3600, 2001,
        'Q-31/007', [['quantity' => 3, 'name' => 'Laser', 'variant' => 'Modrý', 'code' => 'L-M']]]);
