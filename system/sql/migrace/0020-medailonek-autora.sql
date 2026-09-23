-- Medailonek autora: fotka, pozice v redakci a pár vět o autorovi (rámeček pod článkem, stránka autora, strukturovaná data).
ALTER TABLE rs_user
    ADD COLUMN pozice VARCHAR(100) NOT NULL DEFAULT '',
    ADD COLUMN foto VARCHAR(255) NOT NULL DEFAULT '',
    ADD COLUMN bio TEXT NULL;
