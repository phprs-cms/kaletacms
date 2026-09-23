-- Přístupové tokeny pro napojení na Claude (MCP). Ukládá se jen otisk tokenu.
CREATE TABLE rs_api_tokeny (
    idt       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    idu       INT UNSIGNED NOT NULL,
    nazev     VARCHAR(100) NOT NULL,
    otisk     CHAR(64) NOT NULL,                          -- sha256 tokenu
    vytvoren  DATETIME NOT NULL,
    pouzit    DATETIME NULL,
    PRIMARY KEY (idt),
    UNIQUE KEY uq_tokeny_otisk (otisk),
    CONSTRAINT fk_tokeny_user FOREIGN KEY (idu) REFERENCES rs_user (idu) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
