-- Dvoufázové přihlášení, protokol změn v administraci a zámek proti souběžné úpravě článku.
ALTER TABLE rs_user
    ADD COLUMN totp_tajemstvi VARCHAR(64) NOT NULL DEFAULT '' AFTER prostredi,   -- prázdné = dvoufázové přihlášení vypnuté
    ADD COLUMN totp_zalozni TEXT NULL AFTER totp_tajemstvi;                      -- JSON: otisky jednorázových záložních kódů
ALTER TABLE rs_clanky
    ADD COLUMN zamek_kdo INT UNSIGNED NULL AFTER zmeneno,
    ADD COLUMN zamek_cas DATETIME NULL AFTER zamek_kdo;
CREATE TABLE rs_protokol (
    idp   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    cas   DATETIME NOT NULL,
    kdo   INT UNSIGNED NULL,
    jmeno VARCHAR(100) NOT NULL DEFAULT '',               -- jméno v okamžiku akce (účet může později zaniknout)
    modul VARCHAR(30) NOT NULL,
    akce  VARCHAR(40) NOT NULL,
    popis VARCHAR(255) NOT NULL DEFAULT '',
    PRIMARY KEY (idp),
    KEY ix_protokol_cas (cas),
    KEY ix_protokol_kdo (kdo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
