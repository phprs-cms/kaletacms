-- Kolekce ve více jazycích: položky různých jazykových verzí smí mít stejnou adresu (/compare/wordpress, /cs/compare/wordpress)
-- a šablona detailu má vlastní podobu pro každý další jazyk webu (výchozí jazyk zůstává v ka_kolekce).
ALTER TABLE ka_kolekce_polozky DROP INDEX ux_kolekce_polozky_seo, ADD UNIQUE KEY ux_kolekce_polozky_seo (idk, jazyk, seo_link);
CREATE TABLE IF NOT EXISTS ka_kolekce_sablony (
    idk            INT UNSIGNED NOT NULL,
    jazyk          CHAR(2) NOT NULL,
    stavba         MEDIUMTEXT NULL,
    stavba_koncept MEDIUMTEXT NULL,
    zmeneno        DATETIME NULL,
    PRIMARY KEY (idk, jazyk),
    CONSTRAINT fk_kolekce_sablony FOREIGN KEY (idk) REFERENCES ka_kolekce (idk) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
