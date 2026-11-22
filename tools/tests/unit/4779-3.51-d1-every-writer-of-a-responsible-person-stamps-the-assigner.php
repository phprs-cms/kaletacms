<?php

declare(strict_types=1);

/* ---------- 3.51 D1 (SECURITY-3): every writer of a responsible person on enquiries, tasks, deals and quotes names assigned_by ---------- */
// A rule over the whole of system/src, table-aware: a method that writes ka_poptavky.prirazeno, ka_tasks.assignee_id,
// ka_deals.owner_id or ka_quotes.owner_id – in SQL (UPDATE {t} SET … col = / INSERT INTO {t} (… col …)), or through
// $db->update('t', …) / $db->insert('t', …) / a writer helper of its class (self::write(…) over update('t', $data)) with the
// column as an array key – must name assigned_by (stamped, or stamped only when the person changes). A missed writer would
// leave a stale assigned_by, and the team summary would count a planted task, enquiry, deal or quote again. owner_id of pages,
// collection items, customers and companies (Core/Handover.php, Core/Contacts.php, Admin/Modules/Pages.php) is not in the rule.
const D779_GUARDED = ['poptavky' => 'prirazeno', 'tasks' => 'assignee_id', 'deals' => 'owner_id', 'quotes' => 'owner_id'];

$d779Methods = function (string $code): array {
    $tokens = token_get_all($code);
    $out = [];
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
            continue;
        }
        $name = '';
        for ($j = $i + 1; $j < $count && $tokens[$j] !== '{' && $tokens[$j] !== ';'; $j++) {
            if ($name === '' && is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                $name = $tokens[$j][1];
            }
        }
        if ($name === '' || $j >= $count || $tokens[$j] !== '{') {
            continue; // a closure (no name) belongs to the method around it; an abstract method has no body
        }
        $depth = 0;
        $body = '';
        for ($k = $j; $k < $count; $k++) {
            $t = $tokens[$k];
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
        $out[$name] = $body;
        $i = $k;
    }

    return $out;
};

/** @return list<string> "method → table.column" for each writer that never names assigned_by */
$d779Unstamped = function (string $code) use ($d779Methods): array {
    $methods = $d779Methods($code);
    // helpers of the class that write a guarded table with a payload passed in and stamp nothing themselves (Tasks::write:
    // $db->update('tasks', $data, …)) – their callers must stamp; a helper that stamps (Deals::save) covers its callers
    $helpers = [];
    foreach ($methods as $name => $body) {
        foreach (array_keys(D779_GUARDED) as $table) {
            if (!str_contains($body, 'assigned_by') && preg_match('/->(?:update|insert)\(\s*\'' . $table . '\'\s*,\s*\$/', $body) === 1) {
                $helpers[$table][] = $name;
            }
        }
    }
    $out = [];
    foreach ($methods as $name => $body) {
        if (str_contains($body, 'assigned_by')) {
            continue;
        }
        foreach (D779_GUARDED as $table => $column) {
            $sql = preg_match('/UPDATE \{' . $table . '\}\s+SET\s[^\'"]*\b' . $column . '\s*=/', $body) === 1
                || preg_match('/INSERT INTO \{' . $table . '\}\s*\([^\'"]*\b' . $column . '\b/', $body) === 1;
            $calls = preg_match('/->(?:update|insert)\(\s*\'' . $table . '\'/', $body) === 1;
            foreach ($helpers[$table] ?? [] as $helper) {
                $calls = $calls || ($helper !== $name && str_contains($body, 'self::' . $helper . '('));
            }
            // the column as a key being written ('col' => …, $data['col'] = …) – not a read like $row['col'] or $a['col'] === …
            if ($sql || ($calls && preg_match('/\'' . $column . '\'\s*=>|\[\'' . $column . '\'\]\s*=(?!=)/', $body) === 1)) {
                $out[] = $name . ' → ' . $table . '.' . $column;
            }
        }
    }

    return $out;
};

$d779Src = KALETA_SYSTEM . '/src';
$d779Found = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d779Src, FilesystemIterator::SKIP_DOTS)) as $d779File) {
    if ($d779File instanceof SplFileInfo && $d779File->getExtension() === 'php') {
        foreach ($d779Unstamped((string) file_get_contents($d779File->getPathname())) as $d779Where) {
            $d779Found[] = substr($d779File->getPathname(), strlen($d779Src) + 1) . ' ' . $d779Where;
        }
    }
}
sort($d779Found);
check('3.51 D1 (SECURITY-3): no method under system/src writes the person of an enquiry, task, deal or quote without naming assigned_by (stamp who changed it, NULL for a rule of the site)', $d779Found, []);

// the guard itself, on a probe: each way of writing is found without assigned_by and passes with it; owner_id of another table,
// a reader of the column, a write of other columns and a call of a helper that stamps are not writers
$d779Probe = <<<'PHP'
<?php
final class Probe {
    public static function sqlUpdate(Db $db): void { $db->run('UPDATE {tasks} SET assignee_id = ?, updated_at = ? WHERE id = ?', [1, 2, 3]); }
    public static function sqlInsert(Db $db): void { $db->run('INSERT INTO {poptavky} (email, prirazeno) VALUES (?, ?)', ['a', 1]); }
    public static function helperUpdate(Db $db): void { $db->update('deals', ['owner_id' => 2, 'title' => 'x'], ['id' => 1]); }
    public static function arrayKey(Db $db): void { $data = []; $data['owner_id'] = 2; $db->update('quotes', $data, ['id' => 1]); }
    public static function viaHelper(Db $db, array $task): void { $data = ['assignee_id' => 4]; self::write($db, $task, $data); }
    private static function write(Db $db, array $task, array $data): void { $db->update('tasks', $data, ['id' => $task['id']]); }
    public static function stamped(Db $db): void { $db->run('UPDATE {tasks} SET assignee_id = ?, assigned_by = ? WHERE id = ?', [1, 5, 3]); }
    public static function stampedOnChange(Db $db, bool $changed): void { $db->update('deals', ['owner_id' => 2] + ($changed ? ['assigned_by' => 5] : []), ['id' => 1]); }
    public static function pages(Db $db): void { $db->run('UPDATE {stranky} SET owner_id = ? WHERE owner_id = ?', [1, 2]); $db->update('contacts', ['owner_id' => 1], ['id' => 1]); }
    public static function reader(Db $db): array { return $db->all('SELECT owner_id FROM {deals} WHERE owner_id = ?', [1]); }
    public static function otherColumns(Db $db, array $p): void { $db->update('poptavky', ['stav' => 1], ['idp' => $p['prirazeno'] === $p['x'] ? 1 : 2]); $db->run('UPDATE {quotes} SET contact_id = ? WHERE contact_id = ?', [1, 2]); }
    public static function save(Db $db, array $data): void { $db->insert('deals', $data + ['assigned_by' => 1]); }
    public static function throughStampingHelper(Db $db): void { self::save($db, ['owner_id' => 3]); }
}
PHP;
check('3.51 D1: the rule finds exactly the unstamped writers of a probe – SQL UPDATE and INSERT, update() with the column, a payload built by key, a writer helper of the class – and none of the stamped ones, other tables, readers, other columns or callers of a stamping helper',
    $d779Unstamped($d779Probe), ['sqlUpdate → tasks.assignee_id', 'sqlInsert → poptavky.prirazeno', 'helperUpdate → deals.owner_id', 'arrayKey → quotes.owner_id', 'viaHelper → tasks.assignee_id']);
