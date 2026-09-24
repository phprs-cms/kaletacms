-- OAuth 2.1 pro konektor Claude (MCP): registrovaní klienti, jednorázové autorizační kódy a tokeny aplikací v ka_api_tokeny.
CREATE TABLE ka_oauth_klienti (
    idk          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    client_id    CHAR(32)     NOT NULL,
    tajemstvi    CHAR(64)     NOT NULL DEFAULT '',   -- sha256 client_secret; prázdné = veřejný klient (jen PKCE)
    nazev        VARCHAR(100) NOT NULL DEFAULT '',
    presmerovani TEXT         NOT NULL,              -- JSON seznam povolených redirect_uri
    vytvoren     DATETIME     NOT NULL,
    PRIMARY KEY (idk),
    UNIQUE KEY uq_oauth_klient (client_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
CREATE TABLE ka_oauth_kody (
    otisk        CHAR(64)     NOT NULL,              -- sha256 kódu
    client_id    CHAR(32)     NOT NULL,
    idu          INT UNSIGNED NOT NULL,
    presmerovani VARCHAR(500) NOT NULL,
    vyzva        VARCHAR(128) NOT NULL,              -- code_challenge (PKCE, S256)
    expirace     DATETIME     NOT NULL,
    PRIMARY KEY (otisk)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
ALTER TABLE ka_api_tokeny
    ADD COLUMN klient CHAR(32) NULL AFTER nazev,                         -- OAuth client_id; NULL = osobní token z Můj účet
    ADD COLUMN druh VARCHAR(10) NOT NULL DEFAULT 'token' AFTER klient,    -- token | pristup | obnova
    ADD COLUMN expirace DATETIME NULL AFTER druh,
    ADD KEY ix_tokeny_klient (klient);
