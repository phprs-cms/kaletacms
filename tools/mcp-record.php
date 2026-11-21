<?php
/**
 * Kaleta – the MCP answers as a client sees them (3.49 P0). Stores the full tools/list answer of a running site for every
 * tool set (Mcp\Catalog::TOOL_SETS) × every connection access (full, drafts, read), the same with Customers (and so the Pipeline, Extensions::REQUIRES) and with only the
 * Pipeline switched off, and two find_tools answers – byte for byte as the site sends them over HTTP.
 *
 *   php tools/mcp-record.php --site=<checkout of the running site> --url=<its base address> --out=<directory>
 *
 * The record in tools/contracts/mcp-record/ was taken from the released v3.48.0 before the definitions moved next to their
 * handlers; smoke 4360 records the test site again and compares every file with cmp. The script uses the site's own code
 * (its system/bootstrap.php and config.php), switches every feature on for the recording, signs in through temporary
 * personal tokens of the first administrator and puts back what it changed (the feature list, claude_first_used, the tokens).
 */
declare(strict_types=1);

$options = getopt('', ['site:', 'url:', 'out:']);
$site = rtrim((string) ($options['site'] ?? ''), '/');
$url = rtrim((string) ($options['url'] ?? ''), '/');
$out = rtrim((string) ($options['out'] ?? ''), '/');
if ($site === '' || $url === '' || $out === '' || !is_file($site . '/config.php')) {
    fwrite(STDERR, "Usage: php tools/mcp-record.php --site=<site checkout> --url=<base address> --out=<directory>\n");
    exit(2);
}
require $site . '/system/bootstrap.php';
$app = new Kaleta\Core\App(require $site . '/config.php');
$app->applyTimezone();
$db = $app->db();
if (!is_dir($out) && !mkdir($out, 0o755, true)) {
    fwrite(STDERR, "Cannot create $out\n");
    exit(1);
}

$setting = fn (string $key): ?string => ($v = $db->value('SELECT hodnota FROM {nastaveni} WHERE promenna = ?', [$key])) === null ? null : (string) $v;
$put = function (string $key, ?string $value) use ($db): void {
    $value === null ? $db->run('DELETE FROM {nastaveni} WHERE promenna = ?', [$key]) : $db->run('REPLACE INTO {nastaveni} (promenna, hodnota) VALUES (?, ?)', [$key, $value]);
};
$admin = (int) $db->value('SELECT idu FROM {uzivatele} WHERE admin = ? AND blokovat = 0 ORDER BY idu LIMIT 1', [Kaleta\Core\Auth::ADMIN]);
if ($admin === 0) {
    fwrite(STDERR, "No administrator on the site\n");
    exit(1);
}
$saved = ['extensions' => $setting('extensions'), 'claude_first_used' => $setting('claude_first_used')];
$tokens = [];
$token = function (string $access, string $set) use ($db, $admin, &$tokens): string {
    $plain = 'kaleta_' . bin2hex(random_bytes(24));
    $db->insert('api_tokeny', ['idu' => $admin, 'nazev' => 'mcp-record', 'druh' => 'token', 'access' => $access, 'tool_set' => $set,
        'otisk' => hash('sha256', $plain), 'vytvoren' => date('Y-m-d H:i:s')]);
    $tokens[] = hash('sha256', $plain);

    return $plain;
};
$post = function (string $plain, array $message) use ($url): string {
    $body = @file_get_contents($url . '/mcp', false, stream_context_create(['http' => ['method' => 'POST', 'ignore_errors' => true, 'timeout' => 60,
        'header' => "Authorization: Bearer $plain\r\nContent-Type: application/json\r\n", 'content' => json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]]));
    if (!is_string($body) || !str_starts_with($body, '{"jsonrpc"')) {
        throw new RuntimeException('No MCP answer from ' . $url . '/mcp: ' . substr((string) $body, 0, 200));
    }

    return $body;
};
$features = array_keys(Kaleta\Core\Extensions::CATALOG);
$files = [];
try {
    foreach (['' => $features, '-customers-off' => array_diff($features, ['customers', 'pipeline']), '-pipeline-off' => array_diff($features, ['pipeline'])] as $suffix => $on) {
        $put('extensions', implode(',', $on));
        foreach ($suffix === '' ? Kaleta\Mcp\Catalog::TOOL_SETS : ['all'] as $set) {
            foreach ($suffix === '' ? ['full', 'drafts', 'read'] : ['full'] as $access) {
                $file = "tools-list-$set-$access$suffix.json";
                file_put_contents("$out/$file", $post($token($access, $set), ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']));
                $files[] = $file;
            }
        }
    }
    $put('extensions', implode(',', $features));
    $essentials = $token('full', 'essentials');
    // the index of the tools outside the essentials, and the full definitions of eight of them (the most find_tools gives)
    foreach (['find-tools-index.json' => new stdClass(), 'find-tools-names.json' => ['names' => ['list_news', 'create_news', 'save_redirect', 'list_bookings', 'save_quote', 'list_internal_files', 'fleet_inventory', 'delete_media']]] as $file => $arguments) {
        file_put_contents("$out/$file", $post($essentials, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'find_tools', 'arguments' => $arguments]]));
        $files[] = $file;
    }
} finally {
    foreach ($saved as $key => $value) {
        $put($key, $value);
    }
    foreach ($tokens as $hash) {
        $db->run('DELETE FROM {api_tokeny} WHERE otisk = ?', [$hash]);
    }
}
echo 'recorded ' . count($files) . " MCP answers in $out\n";
