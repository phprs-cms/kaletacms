-- Komentáře pod účtem čtenáře, upozornění na odpověď e-mailem a nahlášení nevhodného komentáře.
ALTER TABLE rs_komentare
    ADD COLUMN idct INT UNSIGNED NULL,                     -- účet čtenáře (rs_ctenari), pokud komentoval přihlášený
    ADD COLUMN upozornit BOOL NOT NULL DEFAULT 0,          -- poslat autorovi e-mail, když mu někdo odpoví
    ADD COLUMN nahlaseno SMALLINT UNSIGNED NOT NULL DEFAULT 0;
