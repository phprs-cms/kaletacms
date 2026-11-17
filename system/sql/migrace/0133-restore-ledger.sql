-- 3.46 (N156): the restore ledger (Core\RestoreLedger) – kept outside every backup, so a restore never brings back revoked
-- sign-ins, erased people or unsubscribed addresses. The site's current signing key is recorded by its one-way id, so a
-- backup of this site is recognised after the key changes. The code of 3.45 reads none of it.
CREATE TABLE IF NOT EXISTS ka_restore_ledger (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    kind       VARCHAR(20) CHARACTER SET ascii NOT NULL,
    hash       CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY ux_restore_ledger (kind, hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
INSERT IGNORE INTO ka_restore_ledger (kind, hash, created_at)
    SELECT 'site', SHA2(hodnota, 256), NOW() FROM ka_nastaveni WHERE promenna = 'secret_key' AND hodnota <> '';
