echo "== instalace aktualizace (testovací klíč a kanál)"
cat > "$WORK/vydani-test.php" <<'PHP'
<?php
[$site, $port] = [$argv[1], $argv[2]];
require $site . '/system/src/Core/Signature.php';
$pair = sodium_crypto_sign_keypair();
$sk = sodium_crypto_sign_secretkey($pair);
file_put_contents($site . '/system/aktualizace.pub', base64_encode(sodium_crypto_sign_publickey($pair)) . " test\n");
// seznam souborů „nainstalované verze“: podle otisku .htaccess aktualizace pozná, že ho správce upravil
file_put_contents($site . '/system/soubory.json', json_encode(['verze' => '1.0.0-dev', 'soubory' => ['.htaccess' => hash_file('sha256', $site . '/.htaccess')]]));
@mkdir(dirname($site) . '/kanal');
$zip = new ZipArchive();
$zip->open(dirname($site) . '/kanal/k.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE);
$zip->addFromString('image/test-aktualizace.txt', "nova verze\n");
$zip->addFromString('.htaccess', "# htaccess nove verze\n");
// the core of the package says it is 3.99.9 – the check after the update (2.8) asks the site which version runs
$bootstrap = (string) preg_replace("/const KALETA_VERSION = '[^']*';/", "const KALETA_VERSION = '3.99.9';", (string) file_get_contents($site . '/system/bootstrap.php'));
$zip->addFromString('system/bootstrap.php', $bootstrap); // balíček musí nést jádro
$zip->addFromString('index.php', (string) file_get_contents($site . '/index.php'));
$zip->addFromString('system/aktualizace.pub', (string) file_get_contents($site . '/system/aktualizace.pub')); // the same test key: tools/check-channel.php checks the keys of the package
$zip->close();
$sha = hash_file('sha256', dirname($site) . '/kanal/k.zip');
// signed as tools/release.php signs: v1 ("podpis") and v2 over every field ("podpis2", 3.9)
$m = Kaleta\Core\Signature::signManifest(['verze' => '3.99.9', 'vydano' => date('Y-m-d'), 'url' => "http://127.0.0.1:$port/k.zip", 'sha256' => $sha, 'min_php' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION, 'zmeny' => ['test']], $sk);
file_put_contents(dirname($site) . '/kanal/ok.json', json_encode($m));
// 3.3.2 (N40): the same release, genuinely signed as a security release (the source changes its answer between check and installation)
file_put_contents(dirname($site) . '/kanal/ok-bezpecnostni.json', json_encode(Kaleta\Core\Signature::signManifest(['bezpecnostni' => true] + $m, $sk)));
// 3.9: the security flag turned on after signing – signature v2 no longer holds, the check refuses the manifest at once
file_put_contents(dirname($site) . '/kanal/prepnuty.json', json_encode(['bezpecnostni' => true] + $m));
// 3.9: signature v2 removed from a 3.9+ manifest (to get around it) – refused too
file_put_contents(dirname($site) . '/kanal/bezv2.json', json_encode(array_diff_key($m, ['podpis2' => 1])));
// 3.9 (N37-4): a package whose own bootstrap needs a newer PHP than its (here signed) manifest says – refused before a file is written
$newPhp = new ZipArchive();
$newPhp->open(dirname($site) . '/kanal/p.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE);
$newPhp->addFromString('image/test-novephp.txt', "nove php\n");
$newPhp->addFromString('system/bootstrap.php', (string) preg_replace("/const KALETA_MIN_PHP = '[^']*';/", "const KALETA_MIN_PHP = '99.0';", $bootstrap));
$newPhp->addFromString('index.php', (string) file_get_contents($site . '/index.php'));
$newPhp->close();
$shaP = hash_file('sha256', dirname($site) . '/kanal/p.zip');
file_put_contents(dirname($site) . '/kanal/balicekphp.json', json_encode(Kaleta\Core\Signature::signManifest(['url' => "http://127.0.0.1:$port/p.zip", 'sha256' => $shaP] + $m, $sk)));
// 2.8: a package that installs fine but breaks the home page – the update must undo itself
$broken = new ZipArchive();
$broken->open(dirname($site) . '/kanal/b.zip', ZipArchive::CREATE);
$broken->addFromString('image/test-rozbita.txt', "rozbita verze\n");
$broken->addFromString('system/bootstrap.php', $bootstrap);
$broken->addFromString('index.php', "<?php\nif (str_starts_with((string) parse_url((string) (\$_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), '/ulohy')) { require __DIR__ . '/system/bootstrap.php'; \$app = Kaleta\\Core\\App::boot(); (new Kaleta\\Front\\Kernel(\$app))->handle()->send(); exit; }\nhttp_response_code(500);\necho 'broken';\n");
$broken->close();
$shaB = hash_file('sha256', dirname($site) . '/kanal/b.zip');
file_put_contents(dirname($site) . '/kanal/rozbity.json', json_encode(Kaleta\Core\Signature::signManifest(['url' => "http://127.0.0.1:$port/b.zip", 'sha256' => $shaB] + $m, $sk)));
// 3.31.2 (N103): a security release 3.99.11 whose package is not on the server yet (the download fails) – the test puts
// it there later, so a later try of the same version succeeds
$retry = new ZipArchive();
$retry->open(dirname($site) . '/kanal/retry-package.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE);
$retry->addFromString('image/test-retry.txt', "retried\n");
$retry->addFromString('system/bootstrap.php', str_replace("const KALETA_VERSION = '3.99.9';", "const KALETA_VERSION = '3.99.11';", $bootstrap));
$retry->addFromString('index.php', (string) file_get_contents($site . '/index.php'));
$retry->close();
file_put_contents(dirname($site) . '/kanal/retry-bezpecnostni.json', json_encode(Kaleta\Core\Signature::signManifest(['verze' => '3.99.11', 'bezpecnostni' => true,
    'url' => "http://127.0.0.1:$port/retry.zip", 'sha256' => hash_file('sha256', dirname($site) . '/kanal/retry-package.zip')] + $m, $sk)));
// a foreign v1 signature (v2 holds): the installation checks v1 as before and refuses
file_put_contents(dirname($site) . '/kanal/zly.json', json_encode(['podpis' => base64_encode(random_bytes(64))] + $m));
// 3.7: a correctly signed release for a PHP newer than the server runs
file_put_contents(dirname($site) . '/kanal/novephp.json', json_encode(Kaleta\Core\Signature::signManifest(['min_php' => '99.0'] + $m, $sk)));
// 3.8 (D3): release channels – folders with aktualizace.json (latest) and aktualizace-stable.json next to it, one key
$signed = fn (string $version, string $channel): array => Kaleta\Core\Signature::signManifest(['verze' => $version, 'kanal' => $channel] + $m, $sk);
$channels = [
    'kanaly' => [$signed('3.99.10', 'latest'), $signed('3.99.9', 'stable')],   // latest 3.99.10, stable 3.99.9
    'pozadu' => [$signed('3.99.10', 'latest'), $signed('1.0.0', 'stable')],   // the stable line is behind the site
    'spatne' => [$signed('3.99.10', 'latest'), $signed('3.99.10', 'latest')],  // a wrong redirect: the latest manifest at the stable address
    'bez' => [$signed('3.99.10', 'latest'), null],                            // no stable channel published yet
    // 3.9 (N38-3): a genuine latest release relabelled "stable" at the stable address – the channel is signed, v2 fails
    'podvrh' => [$signed('3.99.10', 'latest'), ['kanal' => 'stable'] + $signed('3.99.10', 'latest')],
    // the manifest published today (3.8.0, written before v2 existed): the daily check keeps passing on its v1 signature
    'stary' => [array_diff_key($signed('3.8.0', 'latest'), ['podpis2' => 1]), null],
    // 3.10 (N39-7): a manifest signed 50 days ago (sites warn at 60, the check two weeks earlier) and one without a signed date
    'zastaraly' => [Kaleta\Core\Signature::signManifest(['verze' => '3.99.10', 'kanal' => 'latest', 'vydano' => date('Y-m-d', time() - 50 * 86400)] + $m, $sk), null],
    'nedatovany' => [Kaleta\Core\Signature::signManifest(['verze' => '3.99.10', 'kanal' => 'latest', 'vydano' => ''] + $m, $sk), null],
    'budouci' => [Kaleta\Core\Signature::signManifest(['verze' => '3.99.10', 'kanal' => 'latest', 'vydano' => '2099-12-31'] + $m, $sk), null],
];
// 3.10 (N39-7): one source answering with a newer, then an older signed manifest (a replay), a v1-only one, and one
// published 70 days ago (System status warns after 60)
$replay = [
    'novy' => $signed('3.99.10', 'latest'),
    'starsi' => Kaleta\Core\Signature::signManifest(['verze' => '3.99.9', 'kanal' => 'latest', 'vydano' => date('Y-m-d', time() - 7 * 86400)] + $m, $sk),
    'v1' => array_diff_key($signed('3.8.0', 'latest'), ['podpis2' => 1]),
    'stary-datum' => Kaleta\Core\Signature::signManifest(['verze' => '3.99.10', 'kanal' => 'latest', 'vydano' => date('Y-m-d', strtotime('-70 days'))] + $m, $sk),
];
foreach ($replay as $name => $manifest) {
    file_put_contents(dirname($site) . '/kanal/replay-' . $name . '.json', json_encode($manifest));
}
// 3.10 (N310-1): genuine manifests of both channels to serve at the wrong address; (N310-3) one dated far in the future
$dated = fn (string $version, string $channel, int $daysAgo): array => Kaleta\Core\Signature::signManifest(['verze' => $version, 'kanal' => $channel, 'vydano' => date('Y-m-d', time() - $daysAgo * 86400)] + $m, $sk);
@mkdir(dirname($site) . '/kanal/krizem');
foreach (['latest-10' => $dated('3.99.10', 'latest', 10), 'latest-0' => $dated('3.99.10', 'latest', 0), 'stable-20' => $dated('3.99.9', 'stable', 20), 'stable-5' => $dated('3.99.11', 'stable', 5),
    'budouci' => Kaleta\Core\Signature::signManifest(['verze' => '3.99.10', 'kanal' => 'latest', 'vydano' => '2099-12-31'] + $m, $sk)] as $name => $manifest) {
    file_put_contents(dirname($site) . '/kanal/krizem-' . $name . '.json', json_encode($manifest));
}
foreach ($channels as $folder => [$latest, $stable]) {
    @mkdir(dirname($site) . '/kanal/' . $folder);
    file_put_contents(dirname($site) . '/kanal/' . $folder . '/aktualizace.json', json_encode($latest));
    if ($stable !== null) {
        file_put_contents(dirname($site) . '/kanal/' . $folder . '/aktualizace-stable.json', json_encode($stable));
    }
}// 3.15: a major version has its own manifest next to aktualizace.json – a 4.0.0 package (its bootstrap says 4.0.0)
$next = new ZipArchive();
$next->open(dirname($site) . '/kanal/k4.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE);
$next->addFromString('image/test-aktualizace4.txt', "kaleta 4\n");
$next->addFromString('system/bootstrap.php', (string) preg_replace("/const KALETA_VERSION = '[^']*';/", "const KALETA_VERSION = '4.0.0';", (string) file_get_contents($site . '/system/bootstrap.php')));
$next->addFromString('index.php', (string) file_get_contents($site . '/index.php'));
$next->addFromString('system/aktualizace.pub', (string) file_get_contents($site . '/system/aktualizace.pub'));
$next->close();
$m4 = Kaleta\Core\Signature::signManifest(['verze' => '4.0.0', 'kanal' => 'latest', 'url' => "http://127.0.0.1:$port/k4.zip", 'sha256' => hash_file('sha256', dirname($site) . '/kanal/k4.zip'),
    'zmeny' => ['Tables renamed to their English names']] + $m, $sk);
foreach ([
    'velka' => [$signed('3.99.10', 'latest'), Kaleta\Core\Signature::signManifest(['bezpecnostni' => true] + $m4, $sk)], // the line has a newer release; 4.0.0 even marked security
    'velka2' => [$signed('3.99.9', 'latest'), $m4],                                                                      // the site runs the newest 3.x
    'velka3' => [$m4, null],                                                                                             // 4.0.0 at the regular address by mistake
    'velka4' => [null, $m4],                                                                                             // N315-3: the line's own source is broken
] as $folder => [$regular, $major]) {
    @mkdir(dirname($site) . '/kanal/' . $folder);
    file_put_contents(dirname($site) . '/kanal/' . $folder . '/aktualizace.json', $regular === null ? '{' : json_encode($regular));
    if ($major !== null) {
        file_put_contents(dirname($site) . '/kanal/' . $folder . '/aktualizace-4.json', json_encode($major));
    }
}
// N315-8: a 4.x release in the stable file of the 3.x line (read by stable sites 3.8–3.14, which have no major guard) and a
// 3.x release in the stable file of the 4.x line – tools/check-channel.php must refuse both
@mkdir(dirname($site) . '/kanal/velka5');
file_put_contents(dirname($site) . '/kanal/velka5/aktualizace.json', json_encode($signed('3.99.9', 'latest')));
file_put_contents(dirname($site) . '/kanal/velka5/aktualizace-stable.json', json_encode(Kaleta\Core\Signature::signManifest(['kanal' => 'stable'] + $m4, $sk)));
file_put_contents(dirname($site) . '/kanal/velka5/aktualizace-4.json', json_encode($m4));
file_put_contents(dirname($site) . '/kanal/velka5/aktualizace-4-stable.json', json_encode($signed('3.99.9', 'stable')));
PHP
# the channel on its own server: the built-in PHP server handles only one request at a time, it could not download from itself
CHANNEL_PORT=$((PORT + 1))
php "$WORK/vydani-test.php" "$WORK/web" "$CHANNEL_PORT"
(cd "$WORK/kanal" && exec php -S "127.0.0.1:$CHANNEL_PORT" > /dev/null 2>&1) & CHANNEL_PID=$!
for i in $(seq 1 30); do curl -s -o /dev/null "http://127.0.0.1:$CHANNEL_PORT/ok.json" && break; sleep 0.2; done
echo "# vlastni uprava spravce" >> "$WORK/web/.htaccess"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=backups"; TOKEN=$(csrf)
update_from() { "${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO ka_nastaveni VALUES ('update_url','http://127.0.0.1:$CHANNEL_PORT/$1') ON DUPLICATE KEY UPDATE hodnota=VALUES(hodnota); UPDATE ka_nastaveni SET hodnota = '' WHERE promenna = 'update_cache'"
  curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=update" -d "_csrf=$TOKEN"; }
update_from zly.json
[ ! -f "$WORK/web/image/test-aktualizace.txt" ] && echo "  ok     balíček s cizím podpisem se nenainstaluje" || { echo "  CHYBA  nainstalován balíček s neplatným podpisem"; ERRORS=$((ERRORS+1)); }
# 3.7: a site on an older PHP than the release needs is not offered it (it says which PHP it needs) and cannot install it
"${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO ka_nastaveni VALUES ('update_url','http://127.0.0.1:$CHANNEL_PORT/novephp.json') ON DUPLICATE KEY UPDATE hodnota=VALUES(hodnota); UPDATE ka_nastaveni SET hodnota = '' WHERE promenna = 'update_cache'"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=backups"
contains -F 'PHP 99.0' "$WORK/response" && ! contains -F 'value="3.99.9"' "$WORK/response" && echo "  ok     3.7: a release for a newer PHP is not offered, the page names the PHP it needs" || { echo "  CHYBA  3.7: release for a newer PHP offered"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=status"
contains -F '99.0' "$WORK/response" && echo "  ok     3.7: system health names the PHP the new version needs" || { echo "  CHYBA  3.7: health does not say which PHP the update needs"; ERRORS=$((ERRORS+1)); }
update_from novephp.json
[ ! -f "$WORK/web/image/test-aktualizace.txt" ] && echo "  ok     3.7: a release for a newer PHP does not install" || { echo "  CHYBA  3.7: release for a newer PHP installed"; ERRORS=$((ERRORS+1)); }
# 3.9 (N38-3, N37-4): manifest signature v2 – a field changed after signing, or v2 removed from a 3.9+ manifest, is refused
# as soon as the site reads the manifest: nothing is offered, nothing installs
for f in prepnuty bezv2; do
  "${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO ka_nastaveni VALUES ('update_url','http://127.0.0.1:$CHANNEL_PORT/$f.json') ON DUPLICATE KEY UPDATE hodnota=VALUES(hodnota); UPDATE ka_nastaveni SET hodnota = '' WHERE promenna = 'update_cache'"
  curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=backups"
  contains -F 'Informace o aktualizaci není podepsaná vydavatelem Kalety' "$WORK/response" && ! contains -F 'value="3.99.9"' "$WORK/response" && echo "  ok     3.9 manifest v2 ($f): the manifest is refused, nothing is offered" || { echo "  CHYBA  3.9 manifest v2 ($f): offered"; ERRORS=$((ERRORS+1)); }
  update_from "$f.json"
  [ ! -f "$WORK/web/image/test-aktualizace.txt" ] && echo "  ok     3.9 manifest v2 ($f): nothing installs" || { echo "  CHYBA  3.9 manifest v2 ($f): installed"; ERRORS=$((ERRORS+1)); }
done
# 3.9 (N37-4): the package's own KALETA_MIN_PHP decides before a file is written, whatever the manifest says
"${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO ka_nastaveni VALUES ('update_url','http://127.0.0.1:$CHANNEL_PORT/balicekphp.json') ON DUPLICATE KEY UPDATE hodnota=VALUES(hodnota); UPDATE ka_nastaveni SET hodnota = '' WHERE promenna = 'update_cache'"
curl -s -L -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&action=update" -d "_csrf=$TOKEN"
[ ! -f "$WORK/web/image/test-novephp.txt" ] && contains -F 'vyžaduje PHP 99.0' "$WORK/response" && echo "  ok     3.9: a package that needs a newer PHP is refused before a file is written" || { echo "  CHYBA  3.9: package PHP requirement"; ERRORS=$((ERRORS+1)); }
# 2.8: the check after an update asks the site itself – a second server on the same files, since this one is busy installing
PROBE_PORT=$((PORT + 11)); (cd "$WORK/web" && exec php -S "127.0.0.1:$PROBE_PORT" system/dev-router.php > /dev/null 2>&1) & PROBE_PID=$!
for i in $(seq 1 30); do curl -s -o /dev/null "http://127.0.0.1:$PROBE_PORT/" && break; sleep 0.2; done
SITE_URL_BEFORE=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'site_url'")
sq "INSERT INTO ka_nastaveni VALUES ('site_url','http://127.0.0.1:$PROBE_PORT') ON DUPLICATE KEY UPDATE hodnota = VALUES(hodnota)" > /dev/null
update_from rozbity.json
[ ! -f "$WORK/web/image/test-rozbita.txt" ] && ! grep -q "echo 'broken'" "$WORK/web/index.php" && [ "$(sq "SELECT COUNT(*) FROM ka_events WHERE type = 'update.rolled_back'")" = 1 ] \
  && sq "SELECT message FROM ka_events WHERE type = 'update.rolled_back'" | contains '500' && echo "  ok     2.8: an update that breaks the site undoes itself (event update.rolled_back)" || { echo "  CHYBA  rozbitá aktualizace se nevrátila"; sq "SELECT message, data FROM ka_events WHERE type LIKE 'update.%'"; ERRORS=$((ERRORS+1)); }
expect "3.31.2 N103: an administrator's update that was rolled back does not mark the version as done for the background job" "$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'update_attempt'")" ""
sq "UPDATE ka_nastaveni SET hodnota = '' WHERE promenna = 'update_attempt'" > /dev/null
# 3.3.2 (N40): the version the administrator saw must be the one the source offers when installing
"${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO ka_nastaveni VALUES ('update_url','http://127.0.0.1:$CHANNEL_PORT/ok.json') ON DUPLICATE KEY UPDATE hodnota=VALUES(hodnota); UPDATE ka_nastaveni SET hodnota = '' WHERE promenna = 'update_cache'"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=update" -d "_csrf=$TOKEN" -d verze=3.99.8
[ ! -f "$WORK/web/image/test-aktualizace.txt" ] && echo "  ok     3.3.2: an update offering another version than the one shown is not installed" || { echo "  CHYBA  installed a version the administrator did not choose"; ERRORS=$((ERRORS+1)); }
# 3.14: … and the screen then shows the version the source offers, so the next click installs it (kaletacms.com kept
# offering 3.12.1 for 12 hours while the source had 3.13.1, and every click failed the same way)
expect "3.14: after a refused update the screen shows the version the source offers now" "$(sq "SELECT JSON_UNQUOTE(JSON_EXTRACT(hodnota, '\$.manifest.verze')) FROM ka_nastaveni WHERE promenna = 'update_cache'")" "3.99.9"
# the automatic installation decided on a manifest that called 3.99.9 a security release; when installing, the source answers
# with the genuine regular release (signed without the flag) – nothing may be installed
cat > "$WORK/kanal/flip.php" <<'PHP'
<?php
$n = (int) @file_get_contents(__DIR__ . '/flip.n');
file_put_contents(__DIR__ . '/flip.n', (string) ($n + 1));
// both answers are genuinely signed (3.9: a flag changed after signing is refused at the check already, see prepnuty.json)
header('Content-Type: application/json');
echo file_get_contents(__DIR__ . ($n === 0 ? '/ok-bezpecnostni.json' : '/ok.json'));
PHP
sq "INSERT INTO ka_nastaveni VALUES ('auto_updates', '1') ON DUPLICATE KEY UPDATE hodnota = '1';
  INSERT INTO ka_nastaveni VALUES ('update_url', 'http://127.0.0.1:$CHANNEL_PORT/flip.php') ON DUPLICATE KEY UPDATE hodnota = VALUES(hodnota);
  UPDATE ka_nastaveni SET hodnota = '' WHERE promenna IN ('update_cache', 'update_attempt');
  UPDATE ka_jobs SET last_run = NULL WHERE name = 'updates'" > /dev/null
curl -s -o "$WORK/tasks.txt" "$B/ulohy?token=testtoken123"
[ ! -f "$WORK/web/image/test-aktualizace.txt" ] && grep -q 'updates: failed 3.99.9' "$WORK/tasks.txt" && echo "  ok     3.3.2: a security flag the signed package does not carry stops the automatic installation" || { echo "  CHYBA  automatic installation on an unverified security flag"; grep updates "$WORK/tasks.txt"; ERRORS=$((ERRORS+1)); }
# 3.31.2 (N103): that failure was try 1 of 3 – the version is not done with, it waits an hour for the next try
s3312_attempt() { sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'update_attempt'"; }
s3312_updates() { sq "UPDATE ka_jobs SET last_run = NULL WHERE name = 'updates'" > /dev/null; curl -s -o "$WORK/tasks.txt" "$B/ulohy?token=testtoken123"; grep -o 'updates: [^,]*' "$WORK/tasks.txt" | head -1; }
s3312_due() { sq "UPDATE ka_nastaveni SET hodnota = CONCAT(SUBSTRING_INDEX(hodnota, '|', 2), '|', UNIX_TIMESTAMP() - 5) WHERE promenna = 'update_attempt' AND hodnota LIKE '%|%|%'" > /dev/null; }
s3312_shape() { s3312_attempt | php -r '$p = explode("|", trim((string) stream_get_contents(STDIN))); echo $p[0], "|", $p[1] ?? "-", "|", isset($p[2]) ? (int) round(((int) $p[2] - time()) / 3600) . "h" : "-";'; }
expect "3.31.2 N103: a failed automatic installation says which try it was and waits an hour for the next" "$(grep -o 'updates: [^,]*' "$WORK/tasks.txt" | head -1)|$(s3312_shape)" "updates: failed 3.99.9 (try 1 of 3)|3.99.9|1|1h"
# 3.31.2 (pre-release review): the next try is due, but another installation holds the update lock – no pre-update backup,
# and the try is postponed by 10 minutes (the tries so far stay), so the requests in between neither back up nor try again
# the source offers the security release again (flip.php answers it to the first request) and the manifest is looked at anew
s3312_due; echo 0 > "$WORK/kanal/flip.n"; sq "UPDATE ka_nastaveni SET hodnota = '' WHERE promenna = 'update_cache'" > /dev/null
(cd "$WORK/web" && exec php -r '$f = fopen("storage/cache/aktualizace.zamek", "c"); flock($f, LOCK_EX); sleep(60);') & S3312_HOLDER=$!
sleep 1
s3312_backups() { find "$WORK/web/storage/zalohy" -name '*predaktualizaci*' 2>/dev/null | wc -l | tr -d ' '; }
S3312_BACKUPS=$(s3312_backups)
S3312_BUSY=$(s3312_updates)
S3312_SHAPE=$(s3312_attempt | php -r '$p = explode("|", trim((string) stream_get_contents(STDIN))); echo $p[0], "|", $p[1] ?? "-", "|", isset($p[2]) ? (int) round(((int) $p[2] - time()) / 60) . "min" : "-";')
expect "3.31.2 review: a due try that finds another installation running makes no backup and waits 10 minutes, keeping its count; the next request waits too" \
  "$S3312_BUSY|$S3312_SHAPE|$(s3312_backups)|$(s3312_updates | grep -c 'busy\|failed\|installed')|$(s3312_backups)" "updates: busy 3.99.9|3.99.9|1|10min|$S3312_BACKUPS|0|$S3312_BACKUPS"
kill "$S3312_HOLDER" 2>/dev/null || true; wait "$S3312_HOLDER" 2>/dev/null || true
sq "UPDATE ka_nastaveni SET hodnota = '' WHERE promenna IN ('update_attempt', 'update_cache'); UPDATE ka_nastaveni SET hodnota = '0' WHERE promenna = 'auto_updates'" > /dev/null
# 3.8 (D3): release channels – Latest (default) reads aktualizace.json, Stable the signed aktualizace-stable.json next to it
channel_source() { sq "INSERT INTO ka_nastaveni VALUES ('update_url','http://127.0.0.1:$CHANNEL_PORT/$1') ON DUPLICATE KEY UPDATE hodnota=VALUES(hodnota); UPDATE ka_nastaveni SET hodnota = '' WHERE promenna = 'update_cache'" > /dev/null; }
backups_page() { curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=backups"; }
channel_source kanaly/aktualizace.json; backups_page
expect "3.8 channels: an existing site stays on Latest" "$(sq "SELECT COALESCE((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'update_channel'), 'latest')")|$(contains -F 'name="update_channel" value="latest" checked' "$WORK/response" && echo checked)" "latest|checked"
contains -F 'value="3.99.10"' "$WORK/response" && echo "  ok     3.8 channels: Latest offers the newest version (3.99.10)" || { echo "  CHYBA  3.8 channels: Latest does not offer 3.99.10"; ERRORS=$((ERRORS+1)); }
TOKEN=$(csrf)
# the channel is chosen on the Backups and updates tab; the checkboxes of the tab are sent as they are
backups_save() { curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=save" -d "_csrf=$TOKEN" -d tab=backups -d "update_channel=$1" \
  $( [ "$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'auto_backups'")" = 0 ] || echo "-d auto_backups=1" ) $( [ "$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'backup_media'")" = 0 ] || echo "-d backup_media=1" ); }
backups_save beta; backups_page # the refused value is shown back once for correction
expect "3.8 channels: an unknown channel is not saved" "$(sq "SELECT COUNT(*) FROM ka_nastaveni WHERE promenna = 'update_channel' AND hodnota = 'beta'")" "0"
backups_save stable; sq "UPDATE ka_nastaveni SET hodnota = '' WHERE promenna = 'update_cache'" > /dev/null; backups_page
expect "3.8 channels: the site switched to Stable" "$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'update_channel'")" "stable"
contains -F 'value="3.99.9"' "$WORK/response" && ! contains -F 'value="3.99.10"' "$WORK/response" && contains -F 'name="update_channel" value="stable" checked' "$WORK/response" \
  && echo "  ok     3.8 channels: Stable offers the stable manifest (3.99.9), never the newer latest one" || { echo "  CHYBA  3.8 channels: Stable offers the wrong version"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=status"
contains -F 'Kanál aktualizací' "$WORK/response" && contains -F 'Stabilní – jen bezpečnostní opravy' "$WORK/response" && echo "  ok     3.8 channels: System status names the channel" || { echo "  CHYBA  3.8 channels: System status does not name the channel"; ERRORS=$((ERRORS+1)); }
mcp get_health '{}' > "$WORK/response"; GH="$(mcp_value update channel)|$(mcp_value update available)"
mcp site_info '{}' > "$WORK/response"; expect "3.8 channels: MCP get_health and site_info say the channel and the offered version" "$GH|$(mcp_value update_channel)" "stable|3.99.9|stable"
# a site that switched to Stable while it runs a newer version than the stable line: no downgrade, it waits and says so
channel_source pozadu/aktualizace.json; backups_page
contains -F 'novější než stabilní kanál (1.0.0)' "$WORK/response" && ! contains -F 'value="1.0.0"' "$WORK/response" && echo "  ok     3.8 channels: a site ahead of the stable line is offered no downgrade and told why" || { echo "  CHYBA  3.8 channels: ahead of the stable line"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=update" -d "_csrf=$TOKEN" -d verze=1.0.0
mcp get_health '{}' > "$WORK/response"
expect "3.8 channels: no downgrade is installed; get_health says the site is ahead of the stable line" "$([ -f "$WORK/web/image/test-aktualizace.txt" ] && echo installed || echo kept)|$(mcp_value update ahead_of_stable)" "kept|1.0.0"
# the latest manifest at the stable address (a wrong redirect): nothing is offered nor installed
channel_source spatne/aktualizace.json; backups_page
contains -F 'nenabízí vydání stabilního kanálu' "$WORK/response" && ! contains -F 'value="3.99.10"' "$WORK/response" && echo "  ok     3.8 channels: a latest manifest at the stable address offers nothing" || { echo "  CHYBA  3.8 channels: wrong manifest on the stable channel"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=update" -d "_csrf=$TOKEN" -d verze=3.99.10
[ ! -f "$WORK/web/image/test-aktualizace.txt" ] && echo "  ok     3.8 channels: a latest manifest at the stable address does not install" || { echo "  CHYBA  3.8 channels: installed a latest release on the stable channel"; ERRORS=$((ERRORS+1)); }
# 3.9 (N38-3): a genuine latest release relabelled "stable" at the stable address – the channel is signed (v2), so it is refused
channel_source podvrh/aktualizace.json; backups_page
contains -F 'není podepsaná vydavatelem Kalety' "$WORK/response" && ! contains -F 'value="3.99.10"' "$WORK/response" && echo "  ok     3.9 channels: a latest release relabelled stable is refused (the channel is signed)" || { echo "  CHYBA  3.9 channels: relabelled stable manifest offered"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=update" -d "_csrf=$TOKEN" -d verze=3.99.10
[ ! -f "$WORK/web/image/test-aktualizace.txt" ] && echo "  ok     3.9 channels: a latest release relabelled stable does not install" || { echo "  CHYBA  3.9 channels: installed a relabelled release on the stable channel"; ERRORS=$((ERRORS+1)); }
# a custom source with another file name has no stable twin: the site follows it as before, whatever the channel
channel_source ok.json; backups_page
contains -F 'nemá proto stabilní protějšek' "$WORK/response" && contains -F 'value="3.99.9"' "$WORK/response" && echo "  ok     3.8 channels: a custom source without a stable twin keeps working on Stable" || { echo "  CHYBA  3.8 channels: custom source on Stable"; ERRORS=$((ERRORS+1)); }
# tools/check-channel.php checks both manifests (signature, package, keys, the channel mark) and skips a stable one not yet published
for c in kanaly spatne bez podvrh stary; do
  if php "$WORK/web/tools/check-channel.php" "http://127.0.0.1:$CHANNEL_PORT/$c/aktualizace.json" > "$WORK/channel-$c.txt" 2>&1; then echo ok >> "$WORK/channel-$c.txt"; else echo failed >> "$WORK/channel-$c.txt"; fi
done
expect "3.8 check-channel: both manifests pass, a latest one at the stable address fails, a missing stable one is skipped" \
  "$(tail -1 "$WORK/channel-kanaly.txt")|$(contains -F 'aktualizace-stable.json – nabízená verze: 3.99.9' "$WORK/channel-kanaly.txt" && echo stable)|$(tail -1 "$WORK/channel-spatne.txt")|$(contains -F '"kanal": "stable"' "$WORK/channel-spatne.txt" && echo why)|$(tail -1 "$WORK/channel-bez.txt")|$(contains -F '404' "$WORK/channel-bez.txt" && echo skipped)" \
  "ok|stable|failed|why|ok|skipped"
[ "$(tail -1 "$WORK/channel-kanaly.txt")" = ok ] || cat "$WORK/channel-kanaly.txt"
expect "3.9 check-channel: a relabelled stable manifest fails on signature v2; a pre-3.9 manifest without v2 passes on v1" \
  "$(tail -1 "$WORK/channel-podvrh.txt")|$(contains -F 'podpis v2 (podpis2) NEPLATÍ' "$WORK/channel-podvrh.txt" && echo why)|$(tail -1 "$WORK/channel-stary.txt")|$(contains -F 'bez podpisu v2' "$WORK/channel-stary.txt" && echo noted)" \
  "failed|why|ok|noted"
if php "$WORK/web/tools/check-channel.php" "http://127.0.0.1:$CHANNEL_PORT/velka2/aktualizace.json" > "$WORK/channel-velka2.txt" 2>&1; then echo ok >> "$WORK/channel-velka2.txt"; else echo failed >> "$WORK/channel-velka2.txt"; fi
if php "$WORK/web/tools/check-channel.php" "http://127.0.0.1:$CHANNEL_PORT/velka5/aktualizace.json" > "$WORK/channel-velka5.txt" 2>&1; then echo ok >> "$WORK/channel-velka5.txt"; else echo failed >> "$WORK/channel-velka5.txt"; fi
expect "3.15 check-channel (N315-8): a 3.x line with its own 4.x line passes; a 4.x release in aktualizace-stable.json and a 3.x one in aktualizace-4-stable.json fail" \
  "$(tail -1 "$WORK/channel-velka2.txt")|$(contains -F 'aktualizace-4-stable.json: the stable channel of Kaleta 4 is not published yet' "$WORK/channel-velka2.txt" && echo skipped)|$(tail -1 "$WORK/channel-velka5.txt")|$(contains -F 'must never be in aktualizace-stable.json' "$WORK/channel-velka5.txt" && echo stable3)|$(contains -F 'aktualizace-4-stable.json: offers 3.99.9' "$WORK/channel-velka5.txt" && echo stable4)" \
  "ok|skipped|failed|stable3|stable4"
[ "$(tail -1 "$WORK/channel-velka2.txt")" = ok ] || cat "$WORK/channel-velka2.txt"
for c in zastaraly nedatovany budouci; do
  if php "$WORK/web/tools/check-channel.php" "http://127.0.0.1:$CHANNEL_PORT/$c/aktualizace.json" > "$WORK/channel-$c.txt" 2>&1; then echo ok >> "$WORK/channel-$c.txt"; else echo failed >> "$WORK/channel-$c.txt"; fi
done
# (the fresh manifest is dated in the site's zone, its age counted in UTC – -1, 0 or 1 day around midnight, never the shell's date)
expect "3.10 check-channel: a manifest signed 50 days ago fails (re-sign two weeks before sites warn), one without a signed date fails, a fresh one says its date" \
  "$(tail -1 "$WORK/channel-zastaraly.txt")|$(contains -F 'sign the manifest again' "$WORK/channel-zastaraly.txt" && echo why)|$(tail -1 "$WORK/channel-nedatovany.txt")|$(contains -F 'release date (vydano' "$WORK/channel-nedatovany.txt" && echo why)|$(contains -E "published [0-9]{4}-[0-9]{2}-[0-9]{2} \(-?[01] days ago\)" "$WORK/channel-kanaly.txt" && echo dated)" \
  "failed|why|failed|why|dated"
expect "3.10 check-channel (N310-3): a signed date in the future fails" "$(tail -1 "$WORK/channel-budouci.txt")|$(contains -F 'lies in the future' "$WORK/channel-budouci.txt" && echo why)" "failed|why"
sq "UPDATE ka_nastaveni SET hodnota = 'latest' WHERE promenna = 'update_channel'" > /dev/null
# 3.10 (N39-7): manifest freshness – the source first gives 3.99.10, then replays an older, validly signed 3.99.9 (and a
# v1-only 3.8.0): refused as a stale manifest, said so, nothing offered or installed; the newer one is accepted again
replay_serve() { cp "$WORK/kanal/replay-$1.json" "$WORK/kanal/replay.json"; sq "UPDATE ka_nastaveni SET hodnota = '' WHERE promenna = 'update_cache'" > /dev/null; }
replay_serve novy; channel_source replay.json; backups_page
! contains -F 'Obnovit historii aktualizací' "$WORK/response" && echo "  ok     3.11 UX310-10: a healthy update state offers no reset of the update history" || { echo "  CHYBA  3.11: the reset button shows in the normal state"; ERRORS=$((ERRORS+1)); }
expect "3.10 freshness: a new signed manifest is offered and remembered for its source" "$(contains -F 'value="3.99.10"' "$WORK/response" && echo offered)|$(sq "SELECT JSON_UNQUOTE(JSON_EXTRACT(hodnota, '\$.\"http://127.0.0.1:$CHANNEL_PORT/replay.json#latest\".verze')) FROM ka_nastaveni WHERE promenna = 'update_seen'")" "offered|3.99.10"
replay_serve starsi; backups_page
contains -F 'starší informací o aktualizaci (verze 3.99.9' "$WORK/response" && ! contains -F 'value="3.99.9"' "$WORK/response" && echo "  ok     3.10 freshness: an older signed manifest of the same source is refused as stale and nothing is offered" || { echo "  CHYBA  3.10 freshness: a replayed older manifest was not refused"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=status"
contains -F 'starší informací o aktualizaci' "$WORK/response" && ! contains -F 'zdroj aktualizací neodpovídá' "$WORK/response" && echo "  ok     3.10 freshness: System status names the stale manifest (not an unreachable source)" || { echo "  CHYBA  3.10 freshness: System status"; ERRORS=$((ERRORS+1)); }
replay_serve starsi; curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=update" -d "_csrf=$TOKEN" -d verze=3.99.9
replay_serve v1; backups_page
expect "3.10 freshness: the stale one does not install; a v1-only manifest after a v2 one is stale too; the remembered one stays 3.99.10" \
  "$([ -f "$WORK/web/image/test-aktualizace.txt" ] && echo installed || echo kept)|$(contains -F 'starší informací o aktualizaci (verze 3.8.0' "$WORK/response" && echo stale)|$(sq "SELECT JSON_UNQUOTE(JSON_EXTRACT(hodnota, '\$.\"http://127.0.0.1:$CHANNEL_PORT/replay.json#latest\".verze')) FROM ka_nastaveni WHERE promenna = 'update_seen'")" "kept|stale|3.99.10"
replay_serve novy; backups_page
contains -F 'value="3.99.10"' "$WORK/response" && ! contains -F 'starší informací' "$WORK/response" && echo "  ok     3.10 freshness: once the source is current again, the update is offered" || { echo "  CHYBA  3.10 freshness: current source after a stale answer"; ERRORS=$((ERRORS+1)); }
# the newest update information is 70 days old: System status warns (only a warning – it is still offered); 3.11 (UX310-3):
# the Updates row itself says so – never a green "up to date" next to it – and Backups and updates shows the same words,
# with Reset update history under Troubleshooting (UX310-10)
replay_serve stary-datum; channel_source replay-stary-datum.json; backups_page; cp "$WORK/response" "$WORK/backups-stale.html"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=status"
contains -F 'Kaleta nemůže potvrdit, že jde o nejnovější verzi – informace o aktualizaci je 70 dní stará' "$WORK/response" && ! contains -F 'systém je aktuální' "$WORK/response" \
  && contains -F 'Kaleta nemůže potvrdit, že jde o nejnovější verzi – informace o aktualizaci je 70 dní stará' "$WORK/backups-stale.html" && contains -F 'Obnovit historii aktualizací' "$WORK/backups-stale.html" \
  && ! contains -F 'aktualizace-stable.json' "$WORK/backups-stale.html" && echo "  ok     3.10 freshness, 3.11 UX310-3: System status and Backups and updates both say the update information is 70 days old, with the reset under Troubleshooting" || { echo "  CHYBA  3.10/3.11 freshness: the warning about old update information"; ERRORS=$((ERRORS+1)); }
# checks failing for days: System status says how long
sq "UPDATE ka_nastaveni SET hodnota = JSON_OBJECT('url', 'http://127.0.0.1:$CHANNEL_PORT/replay-stary-datum.json', 'overeno', UNIX_TIMESTAMP(), 'manifest', NULL, 'chyba', 'test', 'stale', false, 'failing_since', UNIX_TIMESTAMP() - 3 * 86400 - 60) WHERE promenna = 'update_cache'" > /dev/null
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=status"
contains -F 'Kontroly selhávají už 3 dní' "$WORK/response" && echo "  ok     3.10: System status says how long the update checks have been failing" || { echo "  CHYBA  3.10: failing checks without their duration"; ERRORS=$((ERRORS+1)); }
# 3.10 (N310-1): the other channel's genuine manifest at the wrong address is refused before it is remembered – it neither
# blocks the right one later nor passes as "up to date"
kriz() { cp "$WORK/kanal/krizem-$1.json" "$WORK/kanal/krizem/$2"; sq "UPDATE ka_nastaveni SET hodnota = '' WHERE promenna = 'update_cache'" > /dev/null; }
seen_kriz() { sq "SELECT CONCAT_WS('|', JSON_UNQUOTE(JSON_EXTRACT(hodnota, '\$.\"http://127.0.0.1:$CHANNEL_PORT/krizem/$1#$2\".verze')), JSON_LENGTH(hodnota)) FROM ka_nastaveni WHERE promenna = 'update_seen'"; }
sq "UPDATE ka_nastaveni SET hodnota = '' WHERE promenna = 'update_seen'; UPDATE ka_nastaveni SET hodnota = 'stable' WHERE promenna = 'update_channel'" > /dev/null
kriz stable-20 aktualizace-stable.json; channel_source krizem/aktualizace.json; backups_page
B1="$(contains -F 'value="3.99.9"' "$WORK/response" && echo offered)"
kriz latest-0 aktualizace-stable.json; backups_page
B2="$(contains -F 'nenabízí vydání stabilního kanálu' "$WORK/response" && echo refused)|$(seen_kriz aktualizace-stable.json stable)"
kriz stable-5 aktualizace-stable.json; backups_page
expect "3.10 channels (N310-1): Stable – the latest manifest served at the stable address is refused and not remembered; a new stable release after it is offered" \
  "$B1|$B2|$(contains -F 'value="3.99.11"' "$WORK/response" && ! contains -F 'starší informací' "$WORK/response" && echo offered)" "offered|refused|3.99.9|1|offered"
sq "UPDATE ka_nastaveni SET hodnota = 'latest' WHERE promenna = 'update_channel'" > /dev/null
kriz latest-10 aktualizace.json; backups_page
A1="$(contains -F 'value="3.99.10"' "$WORK/response" && echo offered)"
kriz stable-5 aktualizace.json; backups_page
A2="$(contains -F 'odpověděl vydáním stabilního kanálu (verze 3.99.11' "$WORK/response" && ! contains -F 'Máte aktuální verzi' "$WORK/response" && echo refused)|$(seen_kriz aktualizace.json latest)"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=status"
A2="$A2|$(contains -F 'odpověděl vydáním stabilního kanálu' "$WORK/response" && contains -F 'obnovte historii aktualizací' "$WORK/response" && echo status)"
kriz latest-10 aktualizace.json; backups_page
expect "3.10 channels (N310-1): Latest – the stable manifest (newer date) at the latest address is an error, not \"up to date\", and not remembered; the latest one is offered again" \
  "$A1|$A2|$(contains -F 'value="3.99.10"' "$WORK/response" && echo offered)" "offered|refused|3.99.10|2|status|offered"
# 3.10 (N310-3): a manifest dated far in the future is offered but not remembered, and System status says so
channel_source krizem-budouci.json; backups_page
B3="$(contains -F 'value="3.99.10"' "$WORK/response" && echo offered)|$(sq "SELECT JSON_CONTAINS_PATH(hodnota, 'one', '\$.\"http://127.0.0.1:$CHANNEL_PORT/krizem-budouci.json#latest\"') FROM ka_nastaveni WHERE promenna = 'update_seen'")"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=status"
expect "3.10 freshness (N310-3): a date in the future is offered, not remembered, and System status names it" "$B3|$(contains -F 'datem v budoucnosti (2099-12-31)' "$WORK/response" && echo warned)" "offered|0|warned"
# the way out: Forget remembered update information (admin, CSRF, change log) – then a source that is right again is accepted
replay_serve novy; channel_source replay.json; backups_page; replay_serve starsi; backups_page
contains -F 'Obnovit historii aktualizací' "$WORK/response" && contains -F 'Kaleta odmítla informaci o aktualizaci' "$WORK/response" && echo "  ok     3.10 (N310-3), 3.11: the stale message, in plain words, comes with the reset button" || { echo "  CHYBA  3.10: no reset button next to the stale message"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=forget_updates" -d "_csrf=bad"
FORGOT_BAD="$(sq "SELECT hodnota <> '' FROM ka_nastaveni WHERE promenna = 'update_seen'")"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=forget_updates" -d "_csrf=$TOKEN"; backups_page
expect "3.10 (N310-3): Forget needs the CSRF token, clears what was remembered, is in the change log, and the source is accepted again" \
  "$FORGOT_BAD|$(sq "SELECT JSON_LENGTH(hodnota) FROM ka_nastaveni WHERE promenna = 'update_seen'")|$(sq "SELECT COUNT(*) FROM ka_protokol WHERE modul = 'settings' AND akce = 'forget update information'")|$(contains -F 'value="3.99.9"' "$WORK/response" && echo offered)" "1|1|1|offered"
sq "UPDATE ka_nastaveni SET hodnota = '' WHERE promenna IN ('update_cache', 'update_seen')" > /dev/null
# 3.31.2 (N102): the site is in maintenance mode while it updates – visitors get 503, the check after the update still passes
# (/ulohy answers, the 503 of the home page is not an error) and nothing is rolled back
S3312_BEFORE=$(sq "SELECT COUNT(*) FROM ka_events WHERE type IN ('update.rolled_back', 'update.failed')")
setting maintenance 1
S3312_VISITOR=$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:$PROBE_PORT/")
update_from ok.json
setting maintenance 0
expect "3.31.2 N102: an update of a site in maintenance mode is installed, not rolled back (visitors saw 503 meanwhile)" \
  "$S3312_VISITOR|$([ -f "$WORK/web/image/test-aktualizace.txt" ] && echo installed)|$(( $(sq "SELECT COUNT(*) FROM ka_events WHERE type IN ('update.rolled_back', 'update.failed')") - S3312_BEFORE ))|$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'update_probe'")" "503|installed|0|"
[ -f "$WORK/web/image/test-aktualizace.txt" ] && echo "  ok     podepsaná aktualizace se nainstaluje" || { echo "  CHYBA  aktualizace se nenainstalovala"; sq "SELECT message, data FROM ka_events WHERE type LIKE 'update.%'"; ERRORS=$((ERRORS+1)); }
grep -q "vlastni uprava spravce" "$WORK/web/.htaccess" && [ -f "$WORK/web/.htaccess.kaleta-nova" ] && echo "  ok     vlastní .htaccess zůstal, nová verze leží vedle" || { echo "  CHYBA  aktualizace přepsala vlastní .htaccess"; ERRORS=$((ERRORS+1)); }
expect "2.10.2: after an update the site knows it runs the newest version (no new check, no error)" "$(sq "SELECT CONCAT(JSON_UNQUOTE(JSON_EXTRACT(hodnota, '\$.manifest.verze')), '|', JSON_TYPE(JSON_EXTRACT(hodnota, '\$.chyba'))) FROM ka_nastaveni WHERE promenna = 'update_cache'")" "3.99.9|NULL"
expect "2.8: a working update is checked and recorded (update.applied)" "$(sq "SELECT COUNT(*) FROM ka_events WHERE type = 'update.applied'")" "1"

