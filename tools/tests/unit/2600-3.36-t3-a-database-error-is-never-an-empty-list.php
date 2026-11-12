<?php

declare(strict_types=1);

// 3.36 T3 (Q2): only a missing table or column (SQLSTATE 42S02 / 42S22, MySQL 1146 / 1054) reads as "before the migration";
// any other database error – a deadlock, a lost connection, a mistake in the SQL – is thrown on, logged and shown as an error
use Kaleta\Core\Db;

$t3Error = function (string $state, int $code): PDOException {
    $e = new PDOException("SQLSTATE[$state]: test");
    $e->errorInfo = [$state, $code, 'test'];

    return $e;
};
$t3Rethrows = function (PDOException $e): bool {
    try {
        Db::rethrowUnlessMissing($e);

        return false;
    } catch (PDOException $thrown) {
        return $thrown === $e;
    }
};
check('3.36 T3: Db::tableMissing – a missing table or column only, never a deadlock, a lost connection, a syntax error, a duplicate or a full disk', [
    Db::tableMissing($t3Error('42S02', 1146)), Db::tableMissing($t3Error('42S22', 1054)),
    Db::tableMissing($t3Error('40001', 1213)), Db::tableMissing($t3Error('HY000', 2006)), Db::tableMissing($t3Error('42000', 1064)),
    Db::tableMissing($t3Error('23000', 1062)), Db::tableMissing($t3Error('HY000', 1021)), Db::tableMissing(new PDOException('no error info')),
], [true, true, false, false, false, false, false, false]);
check('3.36 T3: Db::rethrowUnlessMissing returns for a missing table or column and throws the same error on otherwise', [
    $t3Rethrows($t3Error('42S02', 1146)), $t3Rethrows($t3Error('42S22', 1054)), $t3Rethrows($t3Error('40001', 1213)), $t3Rethrows($t3Error('HY000', 2006)),
], [false, false, true, true]);

/**
 * Every catch of a PDOException in the product code (system/, the entry files and the built-in add-ons): which do not
 * look at the error. A catch block passes when it calls Db::rethrowUnlessMissing / Db::tableMissing, uses the caught
 * error (rethrows, inspects its code, logs it, shows its message), or says why any database error is fine there with
 * the words "any database error" in a comment.
 *
 * @return list<string> file:line of the blocks that swallow every database error
 */
function t3_swallowing_catches(string $root): array
{
    $files = [$root . '/admin.php', $root . '/index.php', $root . '/install.php'];
    foreach (['system', 'extensions'] as $dir) {
        if (!is_dir($root . '/' . $dir)) {
            continue;
        }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
    }
    $out = [];
    foreach ($files as $file) {
        $source = is_file($file) ? (string) file_get_contents($file) : '';
        if (!str_contains($source, 'PDOException')) {
            continue;
        }
        $tokens = token_get_all($source);
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_CATCH) {
                continue;
            }
            $line = $tokens[$i][2];
            $head = '';
            $variable = null;
            for ($j = $i + 1; $j < $count && $tokens[$j] !== ')'; $j++) {
                $head .= is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_VARIABLE) {
                    $variable = $tokens[$j][1];
                }
            }
            if (!preg_match('/(^|[\\\\(|\s])PDOException\b/', $head)) {
                continue;
            }
            for (; $j < $count && $tokens[$j] !== '{'; $j++);
            $depth = 0;
            $body = '';
            for (; $j < $count; $j++) {
                $t = $tokens[$j];
                $text = is_array($t) ? $t[1] : $t;
                if ($t === '{' || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                    $depth++;
                } elseif ($t === '}') {
                    $depth--;
                }
                $body .= $text;
                if ($depth === 0) {
                    break;
                }
            }
            $looks = str_contains($body, 'rethrowUnlessMissing(') || str_contains($body, 'tableMissing(')
                || ($variable !== null && preg_match('/' . preg_quote($variable, '/') . '\b/', $body) === 1)
                || stripos($body, 'any database error') !== false;
            if (!$looks) {
                $out[] = substr($file, strlen($root) + 1) . ':' . $line;
            }
        }
    }

    return $out;
}
check('3.36 T3: no catch of a PDOException swallows every database error (Db::rethrowUnlessMissing, use the error, or say "any database error" and why)',
    t3_swallowing_catches(KALETA_ROOT), []);
check('3.36 T3: … and the guard sees a bare catch, and accepts the three ways out', t3_swallowing_catches_probe(), [['probe.php:3'], []]);

/** @return array{0: list<string>, 1: list<string>} the guard over a bad and a good temporary copy */
function t3_swallowing_catches_probe(): array
{
    $results = [];
    foreach ([
        "<?php\ntry { f(); }\ncatch (\\PDOException) { return []; }\n",
        "<?php\ntry { f(); } catch (\\PDOException \$e) { Db::rethrowUnlessMissing(\$e); return []; }\n"
        . "try { f(); } catch (\\PDOException \$e) { log(\$e); }\ntry { f(); } catch (\\PDOException) { // any database error: why\n}\n"
        . "try { f(); } catch (\\RuntimeException) { return []; }\n",
    ] as $code) {
        $dir = sys_get_temp_dir() . '/kaleta-t3-' . bin2hex(random_bytes(4));
        mkdir($dir . '/system', 0o777, true);
        file_put_contents($dir . '/system/probe.php', $code);
        $results[] = array_map(fn (string $f): string => basename($f), t3_swallowing_catches($dir));
        unlink($dir . '/system/probe.php');
        rmdir($dir . '/system');
        rmdir($dir);
    }

    return $results;
}
check('3.36 T3 (Q12): a site without KALETA_TEST_SEAMS in config.php sends no X-Kaleta-Queries and TestSeams is off; with it the header counts the statements',
    shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg('require ' . var_export(KALETA_SYSTEM . '/bootstrap.php', true) . '; $db = new Kaleta\Core\Db("mysql:host=127.0.0.1", "", ""); $db->queryCount = 7;'
        . ' echo json_encode([Kaleta\Core\TestSeams::enabled(), Kaleta\Core\TestSeams::headers($db)]); define("KALETA_TEST_SEAMS", true); echo json_encode([Kaleta\Core\TestSeams::enabled(), Kaleta\Core\TestSeams::headers($db)]);')),
    '[false,[]][true,{"X-Kaleta-Queries":"7"}]');
