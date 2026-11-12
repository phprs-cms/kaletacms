echo "== 3.15 (N42): the encryption key out of the database"
# vault <read|legacy|key>: read = the connector secret and the case text as the site decrypts them; legacy = the site as
# before 3.15 (the data encrypted with secret_key again, no vault_key in config.php); key = the key in config.php
cat > "$WORK/vault-helper.php" <<'PHP'
<?php
chdir($argv[1]);
require 'system/bootstrap.php';
$config = require 'config.php';
$app = new Kaleta\Core\App($config);
$s = $app->settings();
$db = $app->db();
if ($argv[2] === 'read') {
    echo Kaleta\Core\Connectors::decrypt($s, $db->value("SELECT secret FROM {connectors} WHERE service = 'google'")) ?? '-', '|',
        mb_substr(Kaleta\Core\Whistleblowing::decrypt($s, $db->value('SELECT text FROM {whistleblowing_cases} WHERE id = ?', [(int) $argv[3]])) ?? '-', 0, 25);
} elseif ($argv[2] === 'key') {
    echo $config['vault_key'] ?? '';
} elseif ($argv[2] === 'legacy') {
    [$from, $to] = [(string) $config['vault_key'], (new Kaleta\Core\Antispam($db, $s))->key()];
    foreach (Kaleta\Core\Vault::SEALED as $table => [$purpose, $primary, $columns]) {
        foreach ($db->all('SELECT * FROM {' . $table . '}') as $row) {
            foreach ($columns as $c) {
                $new = Kaleta\Core\Vault::reseal(Kaleta\Core\Vault::derive($from, $purpose), Kaleta\Core\Vault::derive($to, $purpose), $row[$c]);
                if ($new !== null) { $db->update($table, [$c => $new], [$primary => $row[$primary]]); }
            }
        }
    }
    // 3.39 (N106): a site before 3.15 kept the secret settings and TOTP seeds in plain text
    $plain = array_map(fn (string $k): string => $s->get($k), array_combine(Kaleta\Core\Vault::SECRET_SETTINGS, Kaleta\Core\Vault::SECRET_SETTINGS));
    $seeds = array_map(fn (array $u): array => [$u['idu'], Kaleta\Core\Vault::openSecret($s, 'totp', (string) $u['totp_tajemstvi'])], $db->all("SELECT idu, totp_tajemstvi FROM {uzivatele} WHERE totp_tajemstvi <> ''"));
    $s->set('vault_check', '');
    foreach ($plain as $k => $v) { $s->set($k, $v); }
    foreach ($seeds as [$idu, $seed]) { $db->update('uzivatele', ['totp_tajemstvi' => $seed], ['idu' => $idu]); }
    file_put_contents('config.php', preg_replace("/\n\s*'vault_key' => '[0-9a-f]{64}',[^\n]*/", '', (string) file_get_contents('config.php')));
}
PHP
vault() { php "$WORK/vault-helper.php" "$WORK/web" "$@" "${WB_ID:-0}"; [ "$1" != legacy ] || config_changed; }
# the server's opcache looks at a changed file again after opcache.revalidate_freq (2 s): a change made from outside waits for it
config_changed() { sleep 3; }
vault_sealed() { sq "SELECT SHA2(CONCAT((SELECT secret FROM ka_connectors WHERE service = 'google'), (SELECT text FROM ka_whistleblowing_cases WHERE id = ${WB_ID:-0})), 256)"; }
vault_move() { curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=backups"; curl -s -b "$JAR" -c "$JAR" -L -o "$WORK/response" -X POST "$B/admin.php?module=settings&action=vault_move" -d "_csrf=$(csrf)"; }
VAULT_PLAIN=$(vault read)
expect "3.15 N42: a new site keeps the encryption key in config.php, the data is readable, the backups tab says so without a button" \
  "$(grep -c "'vault_key' => '[0-9a-f]\{64\}'" "$WORK/web/config.php")|$(sq "SELECT LENGTH(hodnota) FROM ka_nastaveni WHERE promenna = 'vault_check'")|$VAULT_PLAIN|$(curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=backups"; grep -c 'klíčem, který je v config.php' "$WORK/response")|$(grep -c 'action=vault_move' "$WORK/response" || true)" \
  "1|32|test-client-secret|Vedoucí skladu falšuje ev|1|0"
VAULT_FIRST=$(vault key)
vault legacy
expect "3.15 N42: a site from before 3.15 (key in the database) reads its data; the backups tab says so and offers the move" \
  "$(vault read)|$(grep -c vault_key "$WORK/web/config.php" || true)|$(curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=backups"; grep -c 'action=vault_move' "$WORK/response")|$(grep -c 'šifrované klíčem uloženým v databázi' "$WORK/response")" \
  "$VAULT_PLAIN|0|1|1"
# config.php cannot be written: the exact line comes back once, nothing changes, the key is kept nowhere
VAULT_BEFORE=$(vault_sealed); chmod 444 "$WORK/web/config.php"; vault_move; chmod 644 "$WORK/web/config.php"
VAULT_NEW=$(grep -o 'id="vault-radek">[^<]*' "$WORK/response" | grep -o '[0-9a-f]\{64\}' | head -1 || true); VAULT_LINE="'vault_key' => '$VAULT_NEW'"
expect "3.15 N42: with config.php not writable the line to add is shown, nothing changes and the key is in no table" \
  "$([ -n "$VAULT_NEW" ] && echo line)|$(grep -c 'Kaleta nemůže klíč do config.php bezpečně zapsat' "$WORK/response")|$(grep -c vault_key "$WORK/web/config.php" || true)|$(vault_sealed)|$(sq "SELECT COUNT(*) FROM ka_nastaveni WHERE hodnota LIKE '%${VAULT_NEW:-none}%'")|$(sq "SELECT COUNT(*) FROM ka_protokol WHERE popis LIKE '%${VAULT_NEW:-none}%' OR akce = 'encryption key moved'")|$(vault read)" \
  "line|1|0|$VAULT_BEFORE|0|0|$VAULT_PLAIN"
# the administrator adds the line by hand; a failure while encrypting again (a table gone) rolls everything back
php -r '$f = $argv[1]; file_put_contents($f, preg_replace("/return\s*(\[|array\s*\()/", "$0\n  " . $argv[2], (string) file_get_contents($f), 1));' -- "$WORK/web/config.php" "$VAULT_LINE,"; config_changed
sq "RENAME TABLE ka_whistleblowing_messages TO ka_wm_315" > /dev/null; vault_move; sq "RENAME TABLE ka_wm_315 TO ka_whistleblowing_messages" > /dev/null
expect "3.15 N42: a failed re-encryption rolls back: the old key stays in use, the data reads, the backups tab says the move is unfinished" \
  "$(grep -c 'Přesun se nezdařil' "$WORK/response")|$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'vault_check'")|$(vault_sealed)|$(vault read)|$(curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=backups"; grep -c 'Dokončete přesun' "$WORK/response")" \
  "1||$VAULT_BEFORE|$VAULT_PLAIN|1"
vault_move
expect "3.15 N42: the move encrypts the data again under the key in config.php, logs it and never writes the key into the database" \
  "$(grep -c 'Šifrovací klíč je teď v config.php' "$WORK/response")|$([ "$(vault_sealed)" != "$VAULT_BEFORE" ] && echo resealed)|$(vault read)|$(sq "SELECT COUNT(*) FROM ka_protokol WHERE akce = 'encryption key moved'")|$(sq "SELECT COUNT(*) FROM ka_nastaveni WHERE hodnota LIKE '%$VAULT_NEW%'")|$(sq "SELECT COUNT(*) FROM ka_protokol WHERE popis LIKE '%$VAULT_NEW%'")|$(curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=backups"; grep -c "$VAULT_NEW" "$WORK/response" || true)" \
  "1|resealed|$VAULT_PLAIN|1|0|0|0"
# config.php without the key the data belongs to (a database restored elsewhere): nothing reads, System status says what to put back
cp "$WORK/web/config.php" "$WORK/config-315.php"; php -r '$f = $argv[1]; file_put_contents($f, preg_replace("/\n\s*\x27vault_key\x27 => \x27[0-9a-f]{64}\x27,[^\n]*/", "", (string) file_get_contents($f)));' -- "$WORK/web/config.php"; config_changed
expect "3.15 N42: without the key in config.php the data cannot be read and System status says to put the line back" \
  "$(vault read)|$(curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=status"; grep -c 'Vraťte do něj řádek s vault_key' "$WORK/response")" "-|-|1"
cp "$WORK/config-315.php" "$WORK/web/config.php"; config_changed
# a writable config.php: one press writes the key and encrypts everything again
vault legacy; vault_move
expect "3.15 N42: with config.php writable one press writes a new key into it and moves the data; other lines of config.php stay" \
  "$(grep -c 'Šifrovací klíč je teď v config.php' "$WORK/response")|$(grep -c "'vault_key' => '[0-9a-f]\{64\}'" "$WORK/web/config.php")|$([ "$(vault key)" != "$VAULT_NEW" ] && [ "$(vault key)" != "$VAULT_FIRST" ] && echo new)|$(grep -c "'prefix' =>" "$WORK/web/config.php")|$(vault read)" \
  "1|1|new|1|$VAULT_PLAIN"
# 3.2: the feature switched off closes the channel even with its own switch on; the cases stay
sq "UPDATE ka_nastaveni SET hodnota = '$WB_EXT' WHERE promenna = 'extensions'" > /dev/null
check "3.2 whistleblowing off: the public address is a 404 although the channel is set up" 404 "/_report"
check "3.2 whistleblowing off: the admin module is gone" 403 "/admin.php?module=whistleblowing"
mcp site_info '{}' > "$WORK/response"; expect "3.2 whistleblowing off: MCP site_info no longer says the channel is on; the cases stay" "$([ "$(mcp_value whistleblowing)" = 1 ] && echo on || echo off)|$(sq "SELECT COUNT(*) > 0 FROM ka_whistleblowing_cases")" "off|1"
kill "$SMTP_PID" 2>/dev/null || true
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('mail_mode', 'mail'), ('smtp_host', '')" > /dev/null
