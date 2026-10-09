#!/usr/bin/env bash
# Kaleta – update test: installs an older release (FROM, default the latest tag) and updates it through the admin
# to a package built from the working tree, signed with a throwaway key. Checks that the update applies, migrates the
# database, removes files the new version no longer has (renamed classes must not linger) and that the site and the
# admin still work. Same env as tools/test.sh: DB_HOST DB_PORT DB_NAME DB_USER DB_PASS PORT.
# PACKAGE=dist/kaleta-X.Y.Z.zip tests a real release package instead (tools/release.php; the manifest next to it), signed
# with the publisher's key the old release already trusts – run it before uploading a release.
# MANIFEST=dist/aktualizace-stable.json takes the stable manifest instead (a patch of the stable line, docs/RELEASING.md).
# 3.9: the manifest's signatures v1 and v2 are checked first. v2 also covers the package address the test points to its
# own channel, so for an old release that checks v2 (3.9+) v2 is signed again with a throwaway key it trusts; v1 stays
# the publisher's. TRUST_KEY=<public key, base64> makes the old site trust the key of a dry run of tools/release.php.
# The database DB_NAME is DROPPED and created again.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DB_HOST="${DB_HOST:-127.0.0.1}"; DB_PORT="${DB_PORT:-3306}"; DB_NAME="${DB_NAME:-kaleta_test_upd}"; DB_USER="${DB_USER:-root}"; DB_PASS="${DB_PASS:-}"; PORT="${PORT:-8097}"
FROM="${FROM:-$(git -C "$ROOT" describe --tags --abbrev=0 HEAD^)}" # the release before HEAD (also when HEAD is a release tag)
WORK="$(mktemp -d)"; JAR="$WORK/cookies.txt"; B="http://127.0.0.1:$PORT"; CHANNEL_PORT=$((PORT + 1)); ERRORS=0
cleanup() { for pid in "${SERVER_PID:-}" "${CHANNEL_PID:-}"; do [ -z "$pid" ] || kill "$pid" 2>/dev/null || true; done
  # the server runs with several workers (the update checks itself over HTTP): its worker processes go too
  { lsof -nP -iTCP:"$PORT" -sTCP:LISTEN -t 2>/dev/null || true; } | xargs kill 2>/dev/null || true; rm -rf "$WORK"; }
trap cleanup EXIT

MYSQL=(mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" --init-command="SET time_zone = '+00:00'"); [ -n "$DB_PASS" ] && MYSQL+=(-p"$DB_PASS")
expect() { [ "$2" = "$3" ] && echo "  ok     $1" || { echo "  CHYBA  $1: got „$2“, expected „$3“"; ERRORS=$((ERRORS+1)); }; }
csrf() { grep -o 'name="_csrf" value="[a-f0-9]*"' "$WORK/response" | head -1 | sed 's/.*value="//;s/"//'; }
check() { # over <label> <expected code> <path>
  local code; code=$(curl -s -b "$JAR" -c "$JAR" -A "Mozilla/5.0 test" -o "$WORK/response" -w '%{http_code}' "$B$2")
  if [ "$code" != 200 ] || grep -qE 'Fatal error|Warning:|Deprecated:|Notice:' "$WORK/response"; then echo "  CHYBA  $1 ($2): code $code"; ERRORS=$((ERRORS+1)); else echo "  ok     $1"; fi
}

echo "== install $FROM"
"${MYSQL[@]}" -e "DROP DATABASE IF EXISTS \`$DB_NAME\`; CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_czech_ci"
mkdir "$WORK/web" "$WORK/kanal" && git -C "$ROOT" archive "$FROM" | tar -xf - -C "$WORK/web"
mkdir -p "$WORK/web/media" "$WORK/web/storage/log" "$WORK/web/storage/cache"
git -C "$ROOT" ls-tree -r --name-only "$FROM" > "$WORK/stare-soubory.txt"
(cd "$WORK/web" && PHP_CLI_SERVER_WORKERS=4 exec php -S "127.0.0.1:$PORT" -t "$WORK/web" "$WORK/web/system/dev-router.php" > "$WORK/server.log" 2>&1) & SERVER_PID=$!
for i in $(seq 1 30); do curl -s -o /dev/null "$B/install.php" && break; sleep 0.3; done
PASSWORD="Test-$(date +%s)-heslo"
curl -s -o "$WORK/response" -X POST "$B/install.php" --data-urlencode "db_host=$DB_HOST" -d "db_port=$DB_PORT" -d "db_name=$DB_NAME" -d "db_user=$DB_USER" --data-urlencode "db_password=$DB_PASS" -d db_prefix=ka_ \
  --data-urlencode "nazev_webu=Testovací firma" -d web=firemni -d user=admin -d jmeno=Tester -d email= --data-urlencode "password=$PASSWORD" --data-urlencode "password2=$PASSWORD" \
  -d 'rozsireni[]=novinky' -d 'rozsireni[]=poptavky' -d 'rozsireni[]=statistika' -d 'rozsireni[]=presmerovani'
grep -q "Hotovo, web běží" "$WORK/response" || { echo "  CHYBA  install of $FROM failed"; sed 's/<[^>]*>//g' "$WORK/response" | grep -v '^\s*$' | head -20; exit 1; }
FROM_VERSION=$(sed -n "s/^const KALETA_VERSION = '\(.*\)';/\1/p" "$WORK/web/system/bootstrap.php")
echo "  ok     $FROM installed ($FROM_VERSION)"
# the old site must never reach the real update channel (kaletacms.com): a security release there would be installed by its
# background maintenance before the test points it at its own channel – an unreachable local address until then
"${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO ka_nastaveni (promenna, hodnota) VALUES ('aktualizace_url', 'http://127.0.0.1:9/none.json'), ('update_url', 'http://127.0.0.1:9/none.json') ON DUPLICATE KEY UPDATE hodnota = VALUES(hodnota)"
# 1.x: the old admin URLs, old settings keys and the Modal element exist; from 2.0 on the update runs through the current admin
FROM_1X=0; [ "${FROM_VERSION%%.*}" = 1 ] && FROM_1X=1
curl -s -c "$JAR" -o "$WORK/response" "$B/admin.php"; TOKEN=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$TOKEN" -d user=admin --data-urlencode "password=$PASSWORD"
check "old version: home page" /
check "old version: admin" /admin.php

echo "== package of the working tree, signed with a throwaway key"
# same file selection as tools/release.php; the old site gets the file list of its release (system/soubory.json) as a real
# install from a release package would have, so the update knows which old files to remove
(cd "$ROOT" && git ls-files -z --cached --others --exclude-standard | while IFS= read -r -d '' s; do [ -e "$s" ] && printf '%s\n' "$s"; done) > "$WORK/nove-soubory.txt"
cat > "$WORK/balicek.php" <<'PHP'
<?php
[, $root, $site, $channel, $port] = $argv;
require $root . '/system/src/Core/Signature.php';
require $root . '/system/src/Core/Integrity.php';
$exclude = '#^(tools/|docs/|integrations/|\.github/|\.claude/|CLAUDE\.md$|\.gitignore$|\.gitleaks\.toml$|\.git-blame-ignore-revs$|phpstan\.neon\.dist$|phpstan-baseline\.neon$|docker/|Dockerfile$|compose\.yaml$|\.dockerignore$)#';
$unhashed = '#^(media|storage)/|^install\.php$#';
$pair = sodium_crypto_sign_keypair();
$sk = sodium_crypto_sign_secretkey($pair);
$pub = base64_encode(sodium_crypto_sign_publickey($pair)) . " test\n";
$fileList = fn (string $version, array $hashes): string => json_encode(['verze' => $version, 'soubory' => $hashes,
    'podpis' => base64_encode(sodium_crypto_sign_detached(Kaleta\Core\Integrity::stringToSign($version, $hashes), $sk))], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

// old site: trusts the throwaway key and knows the files of its release
file_put_contents($site . '/system/aktualizace.pub', $pub);
$hashes = [];
foreach (file($channel . '/../stare-soubory.txt', FILE_IGNORE_NEW_LINES) as $s) {
    if (!preg_match($exclude, $s) && !preg_match($unhashed, $s) && is_file($site . '/' . $s)) {
        $hashes[$s] = hash_file('sha256', $site . '/' . $s);
    }
}
ksort($hashes);
file_put_contents($site . '/system/soubory.json', $fileList('stara', $hashes));

// new package: the throwaway key stays trusted after the update, so the integrity check of the updated site can pass
$zip = new ZipArchive();
$zip->open($channel . '/kaleta.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE);
$hashes = [];
foreach (file($channel . '/../nove-soubory.txt', FILE_IGNORE_NEW_LINES) as $s) {
    if (preg_match($exclude, $s)) {
        continue;
    }
    $content = (string) file_get_contents($root . '/' . $s);
    if ($s === 'system/aktualizace.pub') {
        $content = $pub . $content;
    }
    if ($s === 'system/bootstrap.php') {
        // the package is a new release: its version is what the site shows after the update and what triggers the one-time cleanup
        $content = (string) preg_replace("/const KALETA_VERSION = '[^']*';/", "const KALETA_VERSION = '99.0.0';", $content);
    }
    $zip->addFromString($s, $content);
    if (!preg_match($unhashed, $s)) {
        $hashes[$s] = hash('sha256', $content);
    }
}
// like tools/release.php: classes of the old release that the new one no longer has ride along for the update request
$newFiles = array_flip(file($channel . '/../nove-soubory.txt', FILE_IGNORE_NEW_LINES));
$legacy = [];
foreach (file($channel . '/../stare-soubory.txt', FILE_IGNORE_NEW_LINES) as $s) {
    if ((str_starts_with($s, 'system/src/') || $s === 'system/class-aliases.php') && !isset($newFiles[$s]) && is_file($site . '/' . $s)) {
        $content = (string) file_get_contents($site . '/' . $s);
        $zip->addFromString($s, $content);
        $legacy[$s] = hash('sha256', $content);
    }
}
ksort($hashes);
$zip->addFromString('system/soubory.json', substr($fileList('99.0.0', $hashes), 0, -2) . ",\n    \"legacy\": " . json_encode((object) $legacy, JSON_UNESCAPED_SLASHES) . "\n}\n");
$zip->close();
$sha = hash_file('sha256', $channel . '/kaleta.zip');
// signed as tools/release.php signs (3.9): v1 for the old release up to 3.8, v2 over every field for 3.9 and later
file_put_contents($channel . '/aktualizace.json', json_encode(Kaleta\Core\Signature::signManifest(['verze' => '99.0.0', 'vydano' => date('Y-m-d'), 'url' => "http://127.0.0.1:$port/kaleta.zip", 'sha256' => $sha,
    'min_php' => '8.4', 'bezpecnostni' => false, 'zmeny' => ['test'], 'kanal' => 'latest'], $sk)));
echo '  ok     ' . count($hashes) . " files in the package\n";
PHP
# a class file of the old release that the new one no longer has: the update must delete it
echo '<?php // removed in the new version' > "$WORK/web/system/zrusene.php"; echo system/zrusene.php >> "$WORK/stare-soubory.txt"
if [ -n "${PACKAGE:-}" ]; then
  # the old site knows the files of its release (a real install from its package has this list); keys stay the real ones
  php -r '$h = []; foreach (file($argv[2], FILE_IGNORE_NEW_LINES) as $s) { if (!preg_match("#^(tools/|docs/|\.github/|\.claude/|CLAUDE\.md$|\.git|media/|storage/|install\.php$)#", $s) && is_file($argv[1] . "/" . $s)) { $h[$s] = hash_file("sha256", $argv[1] . "/" . $s); } }
    file_put_contents($argv[1] . "/system/soubory.json", json_encode(["verze" => "stara", "soubory" => $h]));' "$WORK/web" "$WORK/stare-soubory.txt"
  cp "$PACKAGE" "$WORK/kanal/kaleta.zip"
  # TRUST_KEY=<public key, base64>: a package signed with a throwaway key (a dry run of tools/release.php) – the old site trusts it too
  [ -z "${TRUST_KEY:-}" ] || printf '\n%s test-release\n' "$TRUST_KEY" >> "$WORK/web/system/aktualizace.pub"
  # does the old release check manifest signature v2 (3.9 and later)?
  FROM_V2=0; git -C "$ROOT" show "$FROM:system/src/Core/Signature.php" 2>/dev/null | grep -q 'function manifestMessage' && FROM_V2=1
  cat > "$WORK/manifest.php" <<'PHP'
<?php
// the real manifest: both signatures must hold for the keys the sites know; then its package address points to the local channel
[, $root, $source, $port, $target, $sitePub, $fromV2, $trustKey] = $argv;
require $root . '/system/src/Core/Signature.php';
$keys = tempnam(sys_get_temp_dir(), 'kaleta-pub');
file_put_contents($keys, file_get_contents($root . '/system/aktualizace.pub') . ($trustKey !== '' ? "\n" . $trustKey . " test-release\n" : ''));
$m = json_decode((string) file_get_contents($source), true);
$v1 = Kaleta\Core\Signature::isValid(Kaleta\Core\Signature::packageMessage((string) $m['verze'], (string) $m['sha256'], !empty($m['bezpecnostni'])), (string) $m['podpis'], $keys);
$v2 = isset($m['podpis2']) ? Kaleta\Core\Signature::manifestValid($m, $keys) : null;
unlink($keys);
if (!$v1 || $v2 === false) {
    fwrite(STDOUT, '  CHYBA  the manifest ' . $source . ': signature ' . (!$v1 ? 'v1' : 'v2') . " does not hold for system/aktualizace.pub\n");
    exit(1);
}
echo "  ok     the manifest holds signature v1" . ($v2 ? ' and v2' : ' (no v2: a release before 3.9)') . "\n";
$m['url'] = "http://127.0.0.1:$port/kaleta.zip";
if ($fromV2 === '1') {
    // v2 covers the package address, which the test has just changed: an old release that checks v2 gets it signed again
    // with a throwaway key it trusts (the publisher's v2 was checked above; v1 stays the publisher's and is checked by the site)
    $pair = sodium_crypto_sign_keypair();
    file_put_contents($sitePub, "\n" . base64_encode(sodium_crypto_sign_publickey($pair)) . " test-url\n", FILE_APPEND);
    $m['klic'] = Kaleta\Core\Signature::id(sodium_crypto_sign_publickey($pair));
    $m['podpis2'] = base64_encode(sodium_crypto_sign_detached((string) Kaleta\Core\Signature::manifestMessage($m), sodium_crypto_sign_secretkey($pair)));
    echo "  ok     v2 signed again for the local package address (the old release checks v2)\n";
}
file_put_contents($target, json_encode($m));
PHP
  php "$WORK/manifest.php" "$ROOT" "${MANIFEST:-$(dirname "$PACKAGE")/aktualizace.json}" "$CHANNEL_PORT" "$WORK/kanal/aktualizace.json" "$WORK/web/system/aktualizace.pub" "$FROM_V2" "${TRUST_KEY:-}"
  NEW_VERSION=$(php -r 'echo json_decode(file_get_contents($argv[1]), true)["verze"];' "$WORK/kanal/aktualizace.json")
  echo "  ok     release package $PACKAGE ($NEW_VERSION)"
else
  php "$WORK/balicek.php" "$ROOT" "$WORK/web" "$WORK/kanal" "$CHANNEL_PORT"
  NEW_VERSION=99.0.0
fi
(cd "$WORK/kanal" && exec php -S "127.0.0.1:$CHANNEL_PORT" > /dev/null 2>&1) & CHANNEL_PID=$!
for i in $(seq 1 30); do curl -s -o /dev/null "http://127.0.0.1:$CHANNEL_PORT/aktualizace.json" && break; sleep 0.2; done

# a page with the per-page Modal element of 1.x: 2.0 turns it into a site pop-up (migration 0034)
[ "$FROM_1X" = 1 ] && "${MYSQL[@]}" --default-character-set=utf8mb4 "$DB_NAME" -e "INSERT INTO ka_stranky (seo_link, titulek, text, zobrazit, v_menu, stavba) VALUES ('okno-test', 'Okno test', '', 1, 0, '{\"v\":1,\"deti\":[{\"id\":\"s1\",\"typ\":\"sekce\",\"deti\":[{\"id\":\"b1\",\"typ\":\"tlacitko\",\"obsah\":{\"text\":\"Open\",\"odkaz\":\"#akce\"}},{\"id\":\"o1\",\"typ\":\"okno\",\"kotva\":\"akce\",\"obsah\":{\"samo\":\"0\",\"znovu\":\"relace\"},\"deti\":[{\"id\":\"n1\",\"typ\":\"nadpis\",\"obsah\":{\"text\":\"Modal content\"}}]}]}]}')" 2>/dev/null && MODAL_PLANTED=1 || MODAL_PLANTED=0

# 3.2: a site that takes bookings (a booking service, 3.0 and later) – after the update Bookings must still be there (0073)
# only an update from before 3.2 runs 0073, which switches Bookings on for a site that has a booking service
FROM_DB=$(git -C "$ROOT" show "$FROM:system/bootstrap.php" | sed -n "s/.*KALETA_DB_VERSION = \([0-9]*\).*/\1/p")
BOOKINGS_PLANTED=0
[ "${FROM_DB:-0}" -lt 73 ] && "${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO ka_booking_services (name) VALUES ('Update test')" 2>/dev/null && BOOKINGS_PLANTED=1

echo "== update through the admin"
# the channel under the key of the old release (1.4.0 and older: aktualizace_url) and of the current one
[ "$FROM_1X" = 1 ] && OLD_KEY="('aktualizace_url','http://127.0.0.1:$CHANNEL_PORT/aktualizace.json'), " || OLD_KEY=""
"${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO ka_nastaveni VALUES $OLD_KEY('update_url','http://127.0.0.1:$CHANNEL_PORT/aktualizace.json') ON DUPLICATE KEY UPDATE hodnota=VALUES(hodnota); UPDATE ka_nastaveni SET hodnota = '' WHERE promenna IN ('aktualizace_cache', 'update_cache')"
# the old release answers to its own URLs (the admin of a 1.3 site clicks Update there)
if [ "$FROM_1X" = 1 ]; then
  curl -s -L -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?modul=config&zalozka=zalohy"; TOKEN=$(csrf) # 1.4+ redirects this to its own URL
  curl -s -L -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?modul=config&akce=aktualizuj" -d "_csrf=$TOKEN"
else
  curl -s -L -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=backups"; TOKEN=$(csrf)
  curl -s -L -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&action=update" -d "_csrf=$TOKEN" || { echo "  CHYBA  the update request got no answer; server log:"; tail -20 "$WORK/server.log"; exit 1; }
fi
if grep -qF "$NEW_VERSION" "$WORK/response"; then echo "  ok     update installed"; else
  echo "  CHYBA  update failed:"; sed 's/<[^>]*>//g' "$WORK/response" | grep -i -m3 'aktualiz'; exit 1; fi
cmp -s "$ROOT/system/src/helpers.php" "$WORK/web/system/src/helpers.php" && grep -qF "KALETA_VERSION = '$NEW_VERSION'" "$WORK/web/system/bootstrap.php" && echo "  ok     new core in place" || { echo "  CHYBA  the core is not the new one"; ERRORS=$((ERRORS+1)); }
LAST_MIGRATION=$(ls "$ROOT"/system/sql/migrace/[0-9]*-*.sql "$ROOT"/system/sql/migrace/[0-9]*-*.php 2>/dev/null | sed 's/.*\/\([0-9]*\)-.*/\1/' | sort -n | tail -1 | sed 's/^0*//')
LEFTOVERS=$(comm -23 <(sort "$WORK/stare-soubory.txt") <(sort "$WORK/nove-soubory.txt") | grep -vE '^(tools/|docs/|\.github/|\.claude/|CLAUDE\.md$|\.gitignore$|\.gitleaks\.toml$|phpstan\.neon\.dist$|phpstan-baseline\.neon$|docker/|Dockerfile$|compose\.yaml$|\.dockerignore$|install\.php$|media/|storage/|image/ukazka/)' \
  | while read -r s; do [ -e "$WORK/web/$s" ] && echo "$s"; done || true)
[ -z "$LEFTOVERS" ] && echo "  ok     files dropped since $FROM are gone" || { echo "  CHYBA  files of $FROM left behind:"; echo "$LEFTOVERS" | head -10; ERRORS=$((ERRORS+1)); }
INTEGRITY=$(cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $k = Kaleta\Core\Integrity::check(); echo $k["stav"], " ", $k["info"];')
expect "core files match the package (integrity check)" "${INTEGRITY%% *}" ok
[ "${INTEGRITY%% *}" = ok ] || echo "         $INTEGRITY"

echo "== updated site"
rm -f "$WORK"/web/storage/cache/stranky/*.html
for s in / /o-nas /sluzby /kontakt /novinky /sitemap.xml; do check "page $s" "$s"; done
grep -q "Testovací firma" <(curl -s "$B/") && echo "  ok     content kept" || { echo "  CHYBA  home page lost its content"; ERRORS=$((ERRORS+1)); }
# 3.2 (0073): features follow what the site uses – Bookings stay (it has a booking service), Whistleblowing is not switched on
if [ "$BOOKINGS_PLANTED" = 1 ]; then
  check "admin after the update (runs any migration still pending)" /admin.php
  grep -q 'module=bookings' "$WORK/response" && ! grep -q 'module=whistleblowing' "$WORK/response" && echo "  ok     a site that takes bookings still shows Bookings; Whistleblowing, never used, is hidden" || { echo "  CHYBA  the menu after the update: Bookings or Whistleblowing"; ERRORS=$((ERRORS+1)); }
  expect "the features after the update: Bookings on, Whistleblowing off" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(FIND_IN_SET('bookings', hodnota) > 0, '|', FIND_IN_SET('whistleblowing', hodnota) > 0) FROM ka_nastaveni WHERE promenna = 'extensions'")" "1|0"
  expect "the hidden module answers 403, the shown one 200" "$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' "$B/admin.php?module=whistleblowing")|$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' "$B/admin.php?module=bookings")" "403|200"
fi
# every admin module of the new version, with all extensions on
"${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO ka_nastaveni VALUES ('extensions','novinky,poptavky,newsletter,bookings,statistika,presmerovani,asistent,jazyky,whistleblowing,claude,fleet') ON DUPLICATE KEY UPDATE hodnota=VALUES(hodnota)"
check "admin dashboard" /admin.php
# releases before 1.1 migrate on the first admin load after the update, later ones during the update itself
expect "database migrated to $LAST_MIGRATION" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'db_version'")" "$LAST_MIGRATION"
expect "no settings row left under a key of 1.4.0" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_nastaveni WHERE promenna IN ('verze_db', 'nazev_webu', 'aktualizace_url', 'aktualizace_cache', 'uklizeno_verze')")" "0"
if [ "$LAST_MIGRATION" -ge 34 ] && [ "$MODAL_PLANTED" = 1 ]; then
  rm -f "$WORK"/web/storage/cache/stranky/*.html; curl -s -o "$WORK/response" "$B/okno-test"
  # the stored build (the planted one is not a validated 1.x build, so the page itself is checked in test.sh)
  expect "the Modal of the old release became a site pop-up opened by its button" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(spoustec, '|', aktivni) FROM ka_popupy WHERE adresa = 'akce'")|$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(stavba LIKE '%#popup-akce%', stavba LIKE '%\"typ\":\"okno\"%') FROM ka_stranky WHERE seo_link = 'okno-test'")" "klik|1|10"
fi
for m in $(cd "$WORK/web" && php -r 'require "system/bootstrap.php"; foreach (Kaleta\Admin\Kernel::MODULES as $m) { echo $m::IDENT, "\n"; }'); do check "admin $m" "/admin.php?module=$m"; done
for z in general seo analytics backups; do check "admin settings/$z" "/admin.php?module=settings&tab=$z"; done
grep -q 'name="password"' "$WORK/response" && { echo "  CHYBA  the update logged the admin out"; ERRORS=$((ERRORS+1)); } || echo "  ok     admin session survived"

if [ -s "$WORK/web/storage/log/chyby.log" ]; then echo "== application error log:"; cat "$WORK/web/storage/log/chyby.log"; ERRORS=$((ERRORS+1)); fi
if grep -qE 'Fatal|Warning|Deprecated' "$WORK/server.log"; then echo "== server log:"; grep -E 'Fatal|Warning|Deprecated' "$WORK/server.log" | head; ERRORS=$((ERRORS+1)); fi
"${MYSQL[@]}" -e "DROP DATABASE IF EXISTS \`$DB_NAME\`"
echo; [ "$ERRORS" -eq 0 ] && echo "UPDATE FROM $FROM OK" || { echo "ERRORS: $ERRORS"; exit 1; }
