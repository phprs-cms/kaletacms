<?php

declare(strict_types=1);

/*
 * The fake of every outside service for tools/test.sh (2.13, Core\Connectors): started on PORT+15, the site's
 * KALETA_CONNECTORS_FAKE points every connector call here (the path and the query stay). Each service has its own file
 * in tools/fake/ that answers its paths; the first one that returns true has handled the request.
 *
 * A fake file gets $method, $path, $query (array), $body (raw string), $json (decoded body or null), $headers (lower-case
 * names), $log (fn (string $name, array $entry) appends a JSON line to a log the test can read) and $fakeFile (fn (string
 * $name) the path of a log or flag file of this server).
 */
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
parse_str((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_QUERY), $query);
$body = (string) file_get_contents('php://input');
$json = json_decode($body, true);
$headers = array_change_key_case(function_exists('getallheaders') ? (getallheaders() ?: []) : []);
// a file of this fake server: logs and the flag files the test creates (the port keeps parallel runs apart) – in the folder
// KALETA_FAKE_DIR when tools/test.sh gives one (its work folder, removed by its exit trap – T1, Q4), else the temp folder
$fakeDir = (string) getenv('KALETA_FAKE_DIR');
$fakeDir = $fakeDir !== '' && is_dir($fakeDir) ? $fakeDir : sys_get_temp_dir();
// nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename
$fakeFile = fn (string $name): string => $fakeDir . '/kaleta-fake-' . (int) ($_SERVER['SERVER_PORT'] ?? 0) . '-' . preg_replace('/[^a-z0-9.\-]/', '', $name);
$log = function (string $name, array $entry) use ($fakeFile): void {
    file_put_contents($fakeFile($name . '.log'), json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
};
$reply = function (int $status, array $data): bool {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return true;
};
foreach (glob(__DIR__ . '/fake/*.php') ?: [] as $file) {
    if ((require $file) === true) {
        return true;
    }
}
$reply(404, ['error' => 'the fake has no ' . $method . ' ' . $path]);

return true;
