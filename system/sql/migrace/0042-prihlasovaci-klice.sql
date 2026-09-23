-- Přihlašovací klíče (passkeys / WebAuthn): otisk prstu, Face ID nebo bezpečnostní klíč jako druhý krok přihlášení místo kódu z aplikace.
-- Ukládá se jen veřejný klíč zařízení; soukromý zařízení nikdy neopustí.
CREATE TABLE rs_user_klice (
    idk        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    idu        INT UNSIGNED NOT NULL,
    nazev      VARCHAR(80)  NOT NULL DEFAULT '',          -- pojmenování zařízení uživatelem („MacBook“, „telefon“)
    otisk_id   CHAR(64)     NOT NULL,                     -- sha256 identifikátoru klíče (identifikátor může mít až 1023 bajtů)
    id_klice   TEXT         NOT NULL,                     -- identifikátor klíče, base64url
    verejny    TEXT         NOT NULL,                     -- veřejný klíč zařízení (PEM)
    alg        SMALLINT     NOT NULL,                     -- algoritmus podpisu podle COSE: -7 ES256, -257 RS256
    pocitadlo  INT UNSIGNED NOT NULL DEFAULT 0,           -- počitadlo podpisů; pokud ho zařízení vede, musí růst
    vytvoreno  DATETIME     NOT NULL,
    pouzito    DATETIME     NULL,
    PRIMARY KEY (idk),
    UNIQUE KEY uq_klice_otisk (otisk_id),
    KEY ix_klice_user (idu),
    CONSTRAINT fk_klice_user FOREIGN KEY (idu) REFERENCES rs_user (idu) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
