-- Kaleta - struktura databáze
--
-- Názvy tabulek a sloupců jsou česky. Předpona "ka_" se při instalaci nahradí předponou z config.php.
-- Starší názvy sloupců zůstaly: idc = id novinky, tema/idt = kategorie, ido = médium, idu = uživatel.
-- InnoDB s cizími klíči, utf8mb4, hesla přes password_hash().
--
-- Tento soubor je vždy úplné aktuální schéma pro novou instalaci. Každá změna se zároveň zapisuje
-- jako migrace do system/sql/migrace/NNNN-popis.sql, aby se stávající weby aktualizovaly samy.

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- Uživatelé administrace
-- ---------------------------------------------------------------------------
CREATE TABLE ka_uzivatele (
    idu            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user           VARCHAR(40)  NOT NULL,                 -- přihlašovací jméno
    password       VARCHAR(255) NOT NULL,                 -- password_hash()
    jmeno          VARCHAR(100) NOT NULL DEFAULT '',
    email          VARCHAR(190) NOT NULL DEFAULT '',
    url            VARCHAR(255) NOT NULL DEFAULT '',
    admin          TINYINT UNSIGNED NOT NULL DEFAULT 0,   -- role: 0 autor, 1 editor, 2 správce
    role           INT UNSIGNED NULL,                     -- vlastní role (ka_role); NULL = jen úroveň z admin
    blokovat       BOOL NOT NULL DEFAULT 0,
    pocet_chyb     SMALLINT UNSIGNED NOT NULL DEFAULT 0,  -- neúspěšná přihlášení v řadě
    zamceno_do     DATETIME NULL,                         -- dočasný zámek po 10 chybných přihlášeních
    obnova_otisk   CHAR(64)     NOT NULL DEFAULT '',      -- sha256 jednorázového tokenu pro obnovu hesla e-mailem; prázdné = nic nečeká
    obnova_cas     DATETIME NULL,                         -- kdy byl odkaz pro obnovu hesla odeslán (platí hodinu)
    totp_tajemstvi VARCHAR(64)  NOT NULL DEFAULT '',      -- dvoufázové přihlášení (TOTP); prázdné = vypnuté
    totp_zalozni   TEXT NULL,                             -- JSON: otisky jednorázových záložních kódů
    posledni_login DATETIME NULL,
    jazyk          CHAR(2) NOT NULL DEFAULT '',            -- jazyk administrace; '' = čeština
    pozice         VARCHAR(100) NOT NULL DEFAULT '',      -- pozice ve firmě (medailonek autora novinek)
    foto           VARCHAR(255) NOT NULL DEFAULT '',
    bio            TEXT NULL,                             -- pár vět o autorovi
    PRIMARY KEY (idu),
    UNIQUE KEY uq_user (user)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- Vlastní role: pojmenovaná sada sekcí administrace a úroveň (0 = píše vlastní novinky, 1 = vydává a spravuje obsah všech).
CREATE TABLE ka_role (
    idr     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nazev   VARCHAR(60)  NOT NULL,
    popis   VARCHAR(200) NOT NULL DEFAULT '',
    uroven  TINYINT UNSIGNED NOT NULL DEFAULT 0,
    moduly  VARCHAR(1000) NOT NULL DEFAULT '',            -- identifikátory sekcí oddělené čárkou
    PRIMARY KEY (idr),
    UNIQUE KEY uq_role_nazev (nazev)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- Přístup uživatele k modulu administrace
CREATE TABLE ka_uzivatele_prava (
    fk_id_user   INT UNSIGNED NOT NULL,
    ident_modulu VARCHAR(30)  NOT NULL,
    PRIMARY KEY (fk_id_user, ident_modulu),
    CONSTRAINT fk_prava_user FOREIGN KEY (fk_id_user) REFERENCES ka_uzivatele (idu) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;


-- ---------------------------------------------------------------------------
-- Konfigurace
-- ---------------------------------------------------------------------------
CREATE TABLE ka_nastaveni (
    promenna VARCHAR(60) NOT NULL,
    hodnota  TEXT NOT NULL,
    PRIMARY KEY (promenna)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;



-- ---------------------------------------------------------------------------
-- Kategorie a novinky
-- ---------------------------------------------------------------------------
CREATE TABLE ka_kategorie (
    idt       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nazev     VARCHAR(100) NOT NULL,
    seo_link  VARCHAR(120) NOT NULL,
    popis     TEXT NOT NULL,
    hodnost   SMALLINT UNSIGNED NOT NULL DEFAULT 100,     -- pořadí, vyšší = výš
    jazyk     CHAR(2) NOT NULL DEFAULT '',                -- jazyková verze; '' = výchozí jazyk webu
    preklad_z INT UNSIGNED NULL,                          -- protějšek ve výchozím jazyce (hreflang, přepínač jazyků)
    PRIMARY KEY (idt),
    UNIQUE KEY uq_topic_seo (seo_link)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;




CREATE TABLE ka_novinky (
    idc            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    seo_link       VARCHAR(160) NOT NULL,
    titulek        VARCHAR(255) NOT NULL,
    uvod           MEDIUMTEXT NOT NULL,                   -- perex
    text           MEDIUMTEXT NOT NULL,
    obrazek        VARCHAR(255) NOT NULL DEFAULT '',      -- hlavní obrázek
    obrazek_popis  VARCHAR(300) NOT NULL DEFAULT '',      -- popisek hlavního obrázku (prázdné = popisek z knihovny médií)
    obrazek_autor  VARCHAR(120) NOT NULL DEFAULT '',      -- autor hlavního obrázku (prázdné = autor z knihovny médií)
    tema           INT UNSIGNED NOT NULL,                 -- kategorie
    autor          INT UNSIGNED NULL,
    datum          DATETIME NOT NULL,                     -- datum vydání (i budoucí)
    visible        BOOL NOT NULL DEFAULT 0,               -- vydaná novinka (jinak koncept)
    t_slova        VARCHAR(500) NOT NULL DEFAULT '',      -- klíčová slova
    seo_titulek    VARCHAR(255) NOT NULL DEFAULT '',      -- vlastní <title>, prázdné = titulek
    seo_popis      VARCHAR(320) NOT NULL DEFAULT '',      -- vlastní meta description, prázdné = z perexu
    noindex        BOOL NOT NULL DEFAULT 0,
    faq            TEXT NULL,                             -- otázky a odpovědi: otázka, pod ní odpověď, prázdný řádek
    visit          INT UNSIGNED NOT NULL DEFAULT 0,       -- počet zobrazení
    zmeneno        DATETIME NULL,
    aktualizovano  DATETIME NULL,                         -- kdy byla vydaná novinka podstatně doplněna
    oznameno       DATETIME NULL,                         -- kdy systém vydání oznámil (webhook, IndexNow); NULL = ještě ne
    jazyk          CHAR(2) NOT NULL DEFAULT '',           -- přebírá se z kategorie při uložení
    preklad_z      INT UNSIGNED NULL,                     -- idc novinky, jejíž je tato překladem
    hledani        MEDIUMTEXT NULL,                       -- text bez diakritiky pro hledání (Core\Hledani)
    odkazy_cas     DATETIME NULL,                         -- kdy se odkazy naposledy kontrolovaly
    smazano        DATETIME NULL,                         -- v koši od (po 30 dnech se smaže natrvalo); NULL = není v koši
    PRIMARY KEY (idc),
    UNIQUE KEY uq_clanky_seo (seo_link),
    KEY ix_clanky_jazyk (jazyk, visible, datum),
    KEY ix_clanky_oznameno (oznameno, visible, datum),
    KEY ix_clanky_datum (datum),
    KEY ix_clanky_smazano (smazano),
    KEY ix_clanky_tema (tema, visible, datum),
    KEY ix_clanky_autor (autor),
    FULLTEXT KEY ft_clanky (titulek, uvod, text, t_slova),
    FULLTEXT KEY ft_clanky_hledani (hledani),
    CONSTRAINT fk_clanky_tema  FOREIGN KEY (tema)  REFERENCES ka_kategorie (idt),
    CONSTRAINT fk_clanky_autor FOREIGN KEY (autor) REFERENCES ka_uzivatele (idu) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;


-- ---------------------------------------------------------------------------
-- Galerie obrázků
-- ---------------------------------------------------------------------------
CREATE TABLE ka_media_slozky (
    ids   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nazev VARCHAR(100) NOT NULL,
    PRIMARY KEY (ids)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE ka_media (
    ido         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    vlastnik    INT UNSIGNED NULL,
    sekce       INT UNSIGNED NULL,                         -- složka
    nazev       VARCHAR(150) NOT NULL DEFAULT '',          -- slouží i jako alternativní text (alt)
    popis       VARCHAR(500) NOT NULL DEFAULT '',          -- popisek pod obrázkem
    autor       VARCHAR(120) NOT NULL DEFAULT '',          -- autor fotografie (uvádí se u hlavní fotky článku)
    obr_poloha  VARCHAR(255) NOT NULL,                     -- cesta od kořene webu: media/2026/09/foto.jpg
    obr_width   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    obr_height  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    obr_vel     INT UNSIGNED NOT NULL DEFAULT 0,           -- velikost souboru v bajtech
    nahl_poloha VARCHAR(255) NOT NULL DEFAULT '',
    nahl_width  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    nahl_height SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    barva       CHAR(7) NOT NULL DEFAULT '',               -- převládající barva (#rrggbb) jako podklad před načtením; '' = nespočítáno, '-' = nejde zjistit
    ohnisko     VARCHAR(12) NOT NULL DEFAULT '',           -- střed ořezu (object-position), např. „50% 30%“; '' = střed
    datum       DATETIME NOT NULL,
    PRIMARY KEY (ido),
    KEY ix_imggal_datum (datum),
    KEY ix_imggal_poloha (obr_poloha),
    KEY ix_imggal_sekce (sekce),
    CONSTRAINT fk_imggal_sekce FOREIGN KEY (sekce) REFERENCES ka_media_slozky (ids) ON DELETE SET NULL,
    CONSTRAINT fk_imggal_vlastnik FOREIGN KEY (vlastnik) REFERENCES ka_uzivatele (idu) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;



-- Ochrana proti opakování akce ze stejné IP (přihlášení, hledání, formuláře)
CREATE TABLE ka_kontrola_ip (
    idk       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    ip_adresa VARCHAR(45) NOT NULL,
    typ       VARCHAR(20) NOT NULL,
    cil       INT UNSIGNED NOT NULL DEFAULT 0,
    cas       DATETIME NOT NULL,
    PRIMARY KEY (idk),
    KEY ix_kontrola (typ, cil, ip_adresa, cas)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- Ve kterých novinkách je obrázek použitý (přepočítá se při uložení)
CREATE TABLE ka_media_pouziti (
    ido INT UNSIGNED NOT NULL,
    idc INT UNSIGNED NOT NULL,
    PRIMARY KEY (ido, idc),
    KEY ix_pouziti_clanek (idc),
    CONSTRAINT fk_pouziti_obr FOREIGN KEY (ido) REFERENCES ka_media (ido) ON DELETE CASCADE,
    CONSTRAINT fk_pouziti_clanek FOREIGN KEY (idc) REFERENCES ka_novinky (idc) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- ---------------------------------------------------------------------------
-- Štítky novinek, historie verzí novinky a stránky webu.
CREATE TABLE ka_stitky (
    ids      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nazev    VARCHAR(80) NOT NULL,
    seo_link VARCHAR(100) NOT NULL,
    popis    TEXT NULL,                                   -- úvod stránky tématu (HTML od redakce)
    obrazek  VARCHAR(255) NOT NULL DEFAULT '',
    PRIMARY KEY (ids),
    UNIQUE KEY uq_stitky_seo (seo_link)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
CREATE TABLE ka_novinky_stitky (
    idc INT UNSIGNED NOT NULL,
    ids INT UNSIGNED NOT NULL,
    PRIMARY KEY (idc, ids),
    KEY ix_clanky_stitky_stitek (ids),
    CONSTRAINT fk_cs_clanek FOREIGN KEY (idc) REFERENCES ka_novinky (idc) ON DELETE CASCADE,
    CONSTRAINT fk_cs_stitek FOREIGN KEY (ids) REFERENCES ka_stitky (ids) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
CREATE TABLE ka_novinky_revize (
    idr     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    idc     INT UNSIGNED NOT NULL,
    datum   DATETIME NOT NULL,
    kdo     INT UNSIGNED NULL,
    titulek VARCHAR(255) NOT NULL,
    uvod    MEDIUMTEXT NOT NULL,
    text    MEDIUMTEXT NOT NULL,
    PRIMARY KEY (idr),
    KEY ix_revize_clanek (idc, datum),
    CONSTRAINT fk_revize_clanek FOREIGN KEY (idc) REFERENCES ka_novinky (idc) ON DELETE CASCADE,
    CONSTRAINT fk_revize_kdo FOREIGN KEY (kdo) REFERENCES ka_uzivatele (idu) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
CREATE TABLE ka_stranky (
    ids      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    seo_link VARCHAR(120) NOT NULL,
    titulek  VARCHAR(200) NOT NULL,
    popis    VARCHAR(300) NOT NULL DEFAULT '',           -- meta description
    seo_titulek VARCHAR(200) NOT NULL DEFAULT '',        -- vlastní <title>, prázdné = titulek
    obrazek  VARCHAR(255) NOT NULL DEFAULT '',           -- obrázek pro sdílení (og:image), prázdné = výchozí z Nastavení
    noindex  BOOL NOT NULL DEFAULT 0,
    text     MEDIUMTEXT NOT NULL,
    zobrazit BOOL NOT NULL DEFAULT 1,
    zverejnit_od DATETIME NULL,                          -- skrytá stránka se sama zveřejní v tuto chvíli
    v_menu   BOOL NOT NULL DEFAULT 1,                     -- odkaz v patičce / navigaci webu
    poradi   SMALLINT UNSIGNED NOT NULL DEFAULT 100,
    zmeneno  DATETIME NULL,
    jazyk          CHAR(2) NOT NULL DEFAULT '',            -- jazyková verze; '' = výchozí jazyk webu
    preklad_z      INT UNSIGNED NULL,                      -- protějšek ve výchozím jazyce (hreflang, přepínač jazyků)
    nadrazena      INT UNSIGNED NULL,                      -- nadřazená stránka: adresa je /nadrazena/stranka
    stavba         MEDIUMTEXT NULL,                        -- publikovaná stavba (JSON strom prvků builderu); NULL = textová stránka
    stavba_koncept MEDIUMTEXT NULL,                        -- rozpracovaná stavba z editoru; NULL = žádné neuložené změny
    smazano        DATETIME NULL,                          -- v koši od (po 30 dnech se smaže natrvalo); NULL = není v koši
    PRIMARY KEY (ids),
    UNIQUE KEY uq_stranky_seo (seo_link),
    KEY ix_stranky_smazano (smazano),
    KEY ix_stranky_zverejnit (zverejnit_od)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- Publikované verze staveb (posledních 20 na stránku)
-- Části webu z builderu: záhlaví a patička na všech stránkách, obálka detailu novinky, výpisu a stránky 404.
-- Bez řádku (nebo bez publikované stavby) platí část ze šablony (layout). Jazyk '' = výchozí jazyk webu.
CREATE TABLE ka_casti (
    typ            VARCHAR(20) NOT NULL,
    jazyk          CHAR(2) NOT NULL DEFAULT '',
    varianta       VARCHAR(40) NOT NULL DEFAULT '',   -- '' = výchozí; jinak podoba pro stránky v seznamu stranky (JSON čísel)
    nazev          VARCHAR(100) NOT NULL DEFAULT '',
    stranky        TEXT NULL,
    stavba         MEDIUMTEXT NULL,
    stavba_koncept MEDIUMTEXT NULL,
    zmeneno        DATETIME NULL,
    PRIMARY KEY (typ, jazyk, varianta)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- Publikované verze staveb: stránky (ids) i částí webu (cast = "typ:jazyk").
CREATE TABLE ka_stavba_revize (
    idr    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    ids    INT UNSIGNED NULL,
    cast   VARCHAR(80) NULL,
    datum  DATETIME NOT NULL,
    kdo    INT UNSIGNED NULL,
    stavba MEDIUMTEXT NOT NULL,
    PRIMARY KEY (idr),
    KEY ix_stavba_revize (ids, idr),
    KEY ix_stavba_revize_cast (cast, idr),
    CONSTRAINT fk_stavba_revize_stranka FOREIGN KEY (ids) REFERENCES ka_stranky (ids) ON DELETE CASCADE,
    CONSTRAINT fk_stavba_revize_kdo FOREIGN KEY (kdo) REFERENCES ka_uzivatele (idu) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- Sdílené třídy builderu: styl po breakpointech a stavech (JSON jako styl prvku) + volitelné vlastní CSS
CREATE TABLE ka_tridy (
    nazev  VARCHAR(60) NOT NULL,                          -- název třídy v HTML (malá písmena, číslice, pomlčky, __)
    styl   TEXT NOT NULL,                                 -- {"zaklad": {...}, "tablet": {...}, "mobil": {...}, "hover": {...}}
    css    TEXT NULL,                                     -- vlastní deklarace (jen bezpečné, viz Stavitel\Styl::vlastniCss)
    zmeneno DATETIME NULL,
    PRIMARY KEY (nazev)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- ---------------------------------------------------------------------------
-- Přesměrování a evidence souhlasů
CREATE TABLE ka_presmerovani (
    idp       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    z_adresy  VARCHAR(255) NOT NULL,                     -- cesta na webu bez úvodního lomítka: clanek/stara-adresa
    na_adresu VARCHAR(255) NOT NULL,                     -- cesta na webu, nebo celá adresa https://...
    typ       SMALLINT UNSIGNED NOT NULL DEFAULT 301,    -- 301 trvalé, 302 dočasné
    pocet     INT UNSIGNED NOT NULL DEFAULT 0,           -- kolikrát bylo přesměrování použito
    vytvoreno DATETIME NOT NULL,
    PRIMARY KEY (idp),
    UNIQUE KEY uq_presmerovani (z_adresy)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
CREATE TABLE ka_souhlasy (
    ids         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_souhlasu CHAR(32) NOT NULL,                       -- náhodný identifikátor uložený v cookie návštěvníka
    cas         DATETIME NOT NULL,
    kategorie   VARCHAR(60) NOT NULL,                    -- "analytika,marketing" nebo "nic"
    PRIMARY KEY (ids),
    KEY ix_souhlasy_cas (cas),
    KEY ix_souhlasy_id (id_souhlasu)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- ---------------------------------------------------------------------------
-- Statistika bez cookies
CREATE TABLE ka_stat_dny (
    den       DATE NOT NULL,
    navstevy  INT UNSIGNED NOT NULL DEFAULT 0,            -- unikátní návštěvníci dne
    zobrazeni INT UNSIGNED NOT NULL DEFAULT 0,            -- zobrazené stránky
    PRIMARY KEY (den)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
-- Otisk návštěvníka = hash(IP + prohlížeč + denní sůl). Druhý den už nejde spojit s předchozím; starší řádky se mažou.
CREATE TABLE ka_stat_navstevnici (
    den   DATE NOT NULL,
    otisk CHAR(32) NOT NULL,
    PRIMARY KEY (den, otisk)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
CREATE TABLE ka_stat_novinky (
    den   DATE NOT NULL,
    idc   INT UNSIGNED NOT NULL,
    pocet INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (den, idc),
    KEY ix_stat_clanky_idc (idc),
    CONSTRAINT fk_stat_clanek FOREIGN KEY (idc) REFERENCES ka_novinky (idc) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
CREATE TABLE ka_stat_stranky (
    den   DATE NOT NULL,
    cesta VARCHAR(255) NOT NULL,
    pocet INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (den, cesta)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE ka_stat_zdroje (
    den   DATE NOT NULL,
    zdroj VARCHAR(100) NOT NULL,                          -- doména, ze které návštěvník přišel
    pocet INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (den, zdroj)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;


-- ---------------------------------------------------------------------------
-- Protokol změn v administraci
CREATE TABLE ka_protokol (
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

-- ---------------------------------------------------------------------------
-- Přístupové tokeny pro napojení na Claude (MCP). Ukládá se jen otisk tokenu.
CREATE TABLE ka_api_tokeny (
    idt       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    idu       INT UNSIGNED NOT NULL,
    nazev     VARCHAR(100) NOT NULL,
    klient    CHAR(32) NULL,                              -- OAuth client_id; NULL = osobní token z Můj účet
    druh      VARCHAR(10) NOT NULL DEFAULT 'token',       -- token | pristup | obnova
    expirace  DATETIME NULL,
    otisk     CHAR(64) NOT NULL,                          -- sha256 tokenu
    vytvoren  DATETIME NOT NULL,
    pouzit    DATETIME NULL,
    PRIMARY KEY (idt),
    UNIQUE KEY uq_tokeny_otisk (otisk),
    KEY ix_tokeny_klient (klient),
    CONSTRAINT fk_tokeny_user FOREIGN KEY (idu) REFERENCES ka_uzivatele (idu) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- Přihlašovací klíče (passkeys / WebAuthn) jako druhý krok přihlášení. Ukládá se jen veřejný klíč zařízení.
CREATE TABLE ka_uzivatele_klice (
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
    CONSTRAINT fk_klice_user FOREIGN KEY (idu) REFERENCES ka_uzivatele (idu) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;






-- ---------------------------------------------------------------------------
-- Fronta a protokol e-mailů (Core\Posta)
-- ---------------------------------------------------------------------------
CREATE TABLE ka_posta (
    idp         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    komu        VARCHAR(190) NOT NULL,
    predmet     VARCHAR(255) NOT NULL,
    telo        MEDIUMTEXT NULL,                          -- JSON {text, html, hlavicky}; po odeslání se maže
    vytvoreno   DATETIME NOT NULL,
    odeslano    DATETIME NULL,
    pokusu      TINYINT UNSIGNED NOT NULL DEFAULT 0,
    dalsi_pokus DATETIME NULL,
    chyba       VARCHAR(255) NOT NULL DEFAULT '',
    PRIMARY KEY (idp),
    KEY ix_posta_fronta (odeslano, dalsi_pokus)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- Adresy, které skončily chybou 404 (podklad pro přesměrování)
CREATE TABLE ka_nenalezeno (
    cesta     VARCHAR(255) NOT NULL,
    pocet     INT UNSIGNED NOT NULL DEFAULT 1,
    naposledy DATETIME NOT NULL,
    PRIMARY KEY (cesta)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- Rozepsané novinky uložené na serveru (pokračování z jiného zařízení)
CREATE TABLE ka_novinky_koncepty (
    kdo  INT UNSIGNED NOT NULL,
    idc  INT UNSIGNED NOT NULL DEFAULT 0,
    cas  DATETIME NOT NULL,
    data MEDIUMTEXT NOT NULL,                             -- JSON {název pole formuláře: hodnota}
    PRIMARY KEY (kdo, idc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;


-- Nefunkční odkazy nalezené v novinkách (Core\Odkazy)
CREATE TABLE ka_odkazy_vadne (
    ido  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    idc  INT UNSIGNED NOT NULL,
    url  VARCHAR(500) NOT NULL,
    stav SMALLINT UNSIGNED NOT NULL DEFAULT 0,             -- kód odpovědi; 0 = server neodpověděl, 404 u vlastního článku = neexistuje
    cas  DATETIME NOT NULL,
    PRIMARY KEY (ido),
    KEY ix_odkazy_clanek (idc),
    CONSTRAINT fk_odkazy_clanek FOREIGN KEY (idc) REFERENCES ka_novinky (idc) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;



-- Import z jiných systémů (WordPress): co z cizího webu už bylo převedeno a na který náš záznam
CREATE TABLE ka_import_mapa (
    zdroj   VARCHAR(40) NOT NULL,                        -- odkud záznam pochází: wp:<doména starého webu>
    typ     VARCHAR(20) NOT NULL,                        -- clanek | stranka | rubrika | stitek | obrazek | komentar
    cizi_id VARCHAR(190) NOT NULL,                       -- identifikátor ve zdroji (číslo příspěvku, adresa rubriky, otisk adresy obrázku)
    nase_id INT UNSIGNED NOT NULL,                       -- číslo našeho záznamu; 0 u obrázku = stažení se nepovedlo
    PRIMARY KEY (zdroj, typ, cizi_id),
    KEY ix_import_mapa_nase (zdroj, typ, nase_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- stav: 0 = nová, 1 = přečtená, 2 = vyřízená
-- Poptávky a zprávy z formulářů webu (prvek Formulář v builderu). Data = JSON [[popisek, hodnota], …].
CREATE TABLE ka_poptavky (
    idp      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    datum    DATETIME NOT NULL,
    formular VARCHAR(120) NOT NULL DEFAULT '',
    zdroj    VARCHAR(40) NOT NULL DEFAULT '',
    prvek    VARCHAR(16) NOT NULL DEFAULT '',
    stranka  VARCHAR(255) NOT NULL DEFAULT '',
    kampan   VARCHAR(255) NOT NULL DEFAULT '',          -- parametry utm_* stránky s formulářem
    email    VARCHAR(190) NOT NULL DEFAULT '',
    data     MEDIUMTEXT NOT NULL,
    stav     TINYINT UNSIGNED NOT NULL DEFAULT 0,
    poznamka TEXT NULL,                               -- interní poznámka (návštěvník ji nevidí)
    prirazeno INT UNSIGNED NULL,                      -- kdo z uživatelů poptávku vyřizuje
    PRIMARY KEY (idp),
    KEY ix_poptavky_stav (stav, idp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- Kolekce: vlastní typy obsahu (reference, tým, produkty, pobočky…). pole = JSON [{klic, popisek, typ}], typ: text | radky | html | obrazek | odkaz | cislo | datum.
-- detail = položky mají vlastní stránku /<seo_link>/<seo položky> se šablonou z builderu (stavba, stavba_koncept).
CREATE TABLE ka_kolekce (
    idk            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nazev          VARCHAR(100) NOT NULL,
    seo_link       VARCHAR(110) NOT NULL,
    pole           TEXT NOT NULL,
    detail         TINYINT(1) NOT NULL DEFAULT 0,
    stavba         MEDIUMTEXT NULL,
    stavba_koncept MEDIUMTEXT NULL,
    zmeneno        DATETIME NULL,
    PRIMARY KEY (idk),
    UNIQUE KEY ux_kolekce_seo (seo_link)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE ka_kolekce_polozky (
    idp      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    idk      INT UNSIGNED NOT NULL,
    nazev    VARCHAR(200) NOT NULL,
    seo_link VARCHAR(160) NOT NULL,
    data     MEDIUMTEXT NOT NULL,
    poradi   INT NOT NULL DEFAULT 100,
    zobrazit TINYINT(1) NOT NULL DEFAULT 1,
    jazyk    CHAR(2) NOT NULL DEFAULT '',
    datum    DATETIME NOT NULL,
    zmeneno  DATETIME NULL,
    PRIMARY KEY (idp),
    UNIQUE KEY ux_kolekce_polozky_seo (idk, seo_link),
    KEY ix_kolekce_polozky (idk, zobrazit, poradi),
    CONSTRAINT fk_kolekce_polozky FOREIGN KEY (idk) REFERENCES ka_kolekce (idk) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- Menu webu (Vzhled → Menu): hlavní a v patičce, pro každou jazykovou verzi. Bez řádku se hlavní menu skládá ze stránek „v menu“.
CREATE TABLE ka_menu (
    umisteni VARCHAR(20) NOT NULL,                    -- hlavni | paticka
    jazyk    CHAR(2) NOT NULL DEFAULT '',             -- '' = výchozí jazyk webu
    polozky  MEDIUMTEXT NOT NULL,                     -- JSON [{typ: stranka|odkaz|novinky|skupina, ids, url, text, nove_okno, deti: […]}]
    zmeneno  DATETIME NULL,
    PRIMARY KEY (umisteni, jazyk)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE ka_stranky_revize (
    idr     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    ids     INT UNSIGNED NOT NULL,
    datum   DATETIME NOT NULL,
    kdo     INT UNSIGNED NULL,
    titulek VARCHAR(200) NOT NULL,
    text    MEDIUMTEXT NOT NULL,
    PRIMARY KEY (idr),
    KEY ix_stranky_revize (ids, idr),
    CONSTRAINT fk_stranky_revize_stranka FOREIGN KEY (ids) REFERENCES ka_stranky (ids) ON DELETE CASCADE,
    CONSTRAINT fk_stranky_revize_kdo FOREIGN KEY (kdo) REFERENCES ka_uzivatele (idu) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- Sekce, které si web uložil z builderu do vlastní knihovny (panel Přidat → Moje sekce).
CREATE TABLE ka_sekce (
    idx     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nazev   VARCHAR(100) NOT NULL,
    prvek   MEDIUMTEXT NOT NULL,                      -- JSON jednoho prvku (obvykle sekce) i s vnitřkem
    zmeneno DATETIME NULL,
    PRIMARY KEY (idx)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- Komponenty: znovupoužitelné bloky builderu. vlastnosti = JSON [{klic, popisek, typ, vychozi}] – v komponentě jako {{klic}},
-- každé použití (prvek „komponenta“) jim dává vlastní hodnoty. Změna komponenty se projeví všude, kde je použitá.
-- Pop-up okna jako části webu: vlastní stavba v builderu, spouštěč, pravidla zobrazení (JSON), četnost a počitadla bez cookies.
CREATE TABLE ka_popupy (
    idpp           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nazev          VARCHAR(100) NOT NULL,
    adresa         VARCHAR(60)  NOT NULL,
    typ            VARCHAR(20)  NOT NULL DEFAULT 'okno',
    spoustec       VARCHAR(20)  NOT NULL DEFAULT 'cas',
    hodnota        SMALLINT UNSIGNED NOT NULL DEFAULT 5,
    pravidla       TEXT NOT NULL,
    cetnost        VARCHAR(20)  NOT NULL DEFAULT 'relace',
    dni            SMALLINT UNSIGNED NOT NULL DEFAULT 7,
    aktivni        TINYINT(1) NOT NULL DEFAULT 0,
    poradi         SMALLINT NOT NULL DEFAULT 100,
    stavba         MEDIUMTEXT NULL,
    stavba_koncept MEDIUMTEXT NULL,
    zobrazeni      INT UNSIGNED NOT NULL DEFAULT 0,
    zavreni        INT UNSIGNED NOT NULL DEFAULT 0,
    konverze       INT UNSIGNED NOT NULL DEFAULT 0,
    zmeneno        DATETIME NULL,
    PRIMARY KEY (idpp),
    UNIQUE KEY ux_popupy_adresa (adresa)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE ka_komponenty (
    idm            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nazev          VARCHAR(100) NOT NULL,
    vlastnosti     TEXT NOT NULL,
    stavba         MEDIUMTEXT NULL,
    stavba_koncept MEDIUMTEXT NULL,
    zmeneno        DATETIME NULL,
    PRIMARY KEY (idm)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- Rozšíření Newsletter: odběratelé přihlášení prvkem Odběr novinek (double opt-in).
CREATE TABLE ka_odberatele (
    ido       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    email     VARCHAR(190) NOT NULL,
    stav      TINYINT UNSIGNED NOT NULL DEFAULT 0,       -- 0 čeká na potvrzení, 1 potvrzený
    token     CHAR(32)     NOT NULL,                     -- potvrzení a odhlášení odkazem
    zdroj     VARCHAR(255) NOT NULL DEFAULT '',          -- stránka, ze které se přihlásil
    datum     DATETIME     NOT NULL,
    potvrzeno DATETIME     NULL,
    PRIMARY KEY (ido),
    UNIQUE KEY uq_odberatel_email (email),
    UNIQUE KEY uq_odberatel_token (token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- OAuth 2.1 pro konektor Claude (MCP): registrovaní klienti a jednorázové autorizační kódy (tokeny jsou v ka_api_tokeny).
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
