<?php

declare(strict_types=1);

/* ---------- 3.50.1 B1 (CODE-c2): no unused `use` import anywhere in system/src ---------- */
// A rule over the whole tree, not a pin of one file: every top-level class import has its short name somewhere else in the
// same file – as a name token, as the first segment of a qualified name, or in a doc comment (an import kept for a docblock).
// 3.50.0 carried 105 dead imports (26 of them frozen layer references in unit 3100); an unused import hides a real layer
// reference among dead ones and keeps a frozen row alive for nothing.
$u4630Unused = function (string $code): array {
    $tokens = token_get_all($code);
    $count = count($tokens);
    $imports = [];
    $depth = 0;
    for ($i = 0; $i < $count; $i++) {
        $t = $tokens[$i];
        if ($t === '{' || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
            $depth++;
        } elseif ($t === '}') {
            $depth--;
        }
        if ($depth !== 0 || !is_array($t) || $t[0] !== T_USE) {
            continue;
        }
        $names = [];
        $function = false;
        for ($j = $i + 1; $j < $count && $tokens[$j] !== ';'; $j++) {
            $tk = $tokens[$j];
            if (is_array($tk) && in_array($tk[0], [T_FUNCTION, T_CONST], true)) {
                $function = true;
            } elseif (is_array($tk) && in_array($tk[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                $names[] = $tk[1];
            }
        }
        if (!$function && $names !== []) {
            $short = count($names) > 1 ? $names[1] : substr((string) strrchr('\\' . $names[0], '\\'), 1);
            $imports[] = [$short, $names[0], $i, $j];
        }
        $i = $j;
    }
    $unused = [];
    foreach ($imports as [$short, $name, $from, $to]) {
        $used = false;
        foreach ($tokens as $k => $tk) {
            if (($k >= $from && $k <= $to) || !is_array($tk)) {
                continue;
            }
            if (($tk[0] === T_STRING && $tk[1] === $short)
                || ($tk[0] === T_NAME_QUALIFIED && explode('\\', $tk[1])[0] === $short)
                || ($tk[0] === T_DOC_COMMENT && preg_match('/\b' . preg_quote($short, '/') . '\b/', $tk[1]) === 1)) {
                $used = true;
                break;
            }
        }
        if (!$used) {
            $unused[] = $name;
        }
    }
    return $unused;
};

$u4630Src = KALETA_SYSTEM . '/src';
$u4630Found = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($u4630Src, FilesystemIterator::SKIP_DOTS)) as $u4630File) {
    if ($u4630File instanceof SplFileInfo && $u4630File->getExtension() === 'php') {
        foreach ($u4630Unused((string) file_get_contents($u4630File->getPathname())) as $u4630Name) {
            $u4630Found[] = substr($u4630File->getPathname(), strlen($u4630Src) + 1) . ' → ' . $u4630Name;
        }
    }
}
sort($u4630Found);
check('3.50.1 B1 (CODE-c2): no file under system/src imports a class it never names (delete the use line; shorten its row in unit 3100 when it was a frozen layer reference)', $u4630Found, []);

// the guard itself: a dead import is found; one used by name, as the head of a qualified name, aliased, in a docblock,
// in a closure's `use (...)` or as a function import is not
$u4630Probe = <<<'PHP'
<?php
namespace Kaleta\Core;
use Kaleta\Admin\Modules\Pages;
use Kaleta\Builder\Build;
use Kaleta\Builder\Elements;
use Kaleta\Front\Stats as Counter;
use Kaleta\Connectors\Connector;
use Kaleta\Fleet\Kit;
use function strlen;
/** @param Connector $c */
final class Probe {
    public function a(): void { Build::render([]); new Elements\Form(); Counter::isOn(); $x = 1; $f = function () use ($x): int { return strlen('a') + $x; }; }
}
PHP;
check('3.50.1 B1: the rule reports exactly the dead imports of a probe (Pages and Kit) and none of the used, aliased, qualified or docblock ones',
    $u4630Unused($u4630Probe), ['Kaleta\Admin\Modules\Pages', 'Kaleta\Fleet\Kit']);
