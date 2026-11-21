echo "== 3.50 B1 (Q14): the public addresses answer as the routes record of 3.49 (tools/contracts/front-routes.txt) after the route table"
# b350_replay <scenario>: the requests of that scenario, signed out; prints one line per answer that differs from the contract
cat > "$WORK/b350-replay.php" <<'PHP'
<?php
[, $file, $scenario, $base] = $argv;
$values = ['{z32}' => str_repeat('0', 32), '{z64}' => str_repeat('0', 64), '{w32}' => str_repeat('ab', 16), '{c32}' => str_repeat('c', 32)];
foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
    if ($line === '' || $line[0] === '#') {
        continue;
    }
    $cells = array_map('trim', explode(' | ', $line));
    $request = explode(' ', strtr($cells[0], $values), 4);
    if ($request[0] !== $scenario) {
        continue;
    }
    [, $method, $path] = $request;
    $body = $request[3] ?? null;
    $ch = curl_init($base . $path);
    $headers = ['User-Agent: Mozilla/5.0 (B1 replay)', 'Accept: text/html,*/*'];
    if ($body !== null && $body[0] === '{') {
        $headers[] = 'Content-Type: application/json';
    }
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => $headers]);
    if ($method === 'POST') {
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => (string) $body]);
    }
    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $got = [];
    foreach (preg_split('/\r?\n/', substr($raw, 0, (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE))) ?: [] as $header) {
        if (str_contains($header, ':')) {
            [$name, $value] = explode(':', $header, 2);
            $got[strtolower(trim($name))] = trim($value);
        }
    }
    // no curl_close(): a no-op since PHP 8.0 and deprecated in 8.5 (its notice would land in the compared output)
    $location = $got['location'] ?? '-';
    $location = str_starts_with($location, $base) ? (string) substr($location, strlen($base)) : $location;
    $answer = implode(' | ', [$status, $got['content-type'] ?? '-', $location, $got['cache-control'] ?? '-', $got['x-robots-tag'] ?? '-', $got['retry-after'] ?? '-']);
    if ($answer !== implode(' | ', array_slice($cells, 1, 6))) {
        echo $method . ' ' . $path . ': ' . $answer . "\n";
    }
}
PHP
b350_replay() { command php "$WORK/b350-replay.php" "$ROOT/tools/contracts/front-routes.txt" "$1" "$B"; }
B350_EXT=$(sq "SELECT IFNULL(MAX(hodnota), '') FROM ka_nastaveni WHERE promenna = 'extensions'")
B350_KEPT=$(sq "SELECT IFNULL(GROUP_CONCAT(CONCAT('(', QUOTE(promenna), ', ', QUOTE(hodnota), ')') SEPARATOR ', '), '') FROM ka_nastaveni WHERE promenna IN ('maintenance', 'whistleblowing_enabled')")
features --exact novinky poptavky customers pipeline newsletter bookings statistika presmerovani jazyky applications quotes whistleblowing fleet claude
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('maintenance', '0'), ('whistleblowing_enabled', '1')"
rm -f "$WORK"/web/storage/cache/stranky/*.html
expect "3.50 B1: every feature on, signed out – the system routes, files, beacons, tokens and bearer links answer with the status and headers of 3.49" "$(b350_replay anon)" ""
features -novinky -bookings -fleet -newsletter
rm -f "$WORK"/web/storage/cache/stranky/*.html
expect "3.50 B1: News, Bookings, Fleet and Newsletter off – their routes are the not-found page, the unsubscribe and confirm links keep working" "$(b350_replay off)" ""
features --exact novinky poptavky customers pipeline newsletter bookings statistika presmerovani jazyky applications quotes whistleblowing fleet claude
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('maintenance', '1')"
rm -f "$WORK"/web/storage/cache/stranky/*.html
expect "3.50 B1: maintenance mode – the visitor gets 503 with Retry-After, while MCP, OAuth and /ulohy still answer for themselves" "$(b350_replay maint)" ""
sq "DELETE FROM ka_nastaveni WHERE promenna IN ('maintenance', 'whistleblowing_enabled')"
[ -z "$B350_KEPT" ] || sq "INSERT INTO ka_nastaveni (promenna, hodnota) VALUES $B350_KEPT"
features --exact "$B350_EXT"
rm -f "$WORK"/web/storage/cache/stranky/*.html "$WORK/b350-replay.php"
