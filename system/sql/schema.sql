-- MiroCMS.0 - struktura databáze
--
-- Názvy tabulek a sloupců jsou česky a vycházejí z původního MiroCMS (rs_clanky.titulek, rs_topic.nazev...).
-- Jde o nový systém: data ze starého MiroCMS 2 se nepřevádějí.
-- Předpona "rs_" se při instalaci nahradí předponou z config.php.
-- InnoDB s cizími klíči, utf8mb4, hesla přes password_hash().
--
-- Tento soubor je vždy úplné aktuální schéma pro novou instalaci. Každá změna se zároveň zapisuje
-- jako migrace do system/sql/migrace/NNNN-popis.sql, aby se stávající weby aktualizovaly samy.

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- Autoři (uživatelé administrace)
-- ---------------------------------------------------------------------------
CREATE TABLE rs_user (
    idu            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user           VARCHAR(40)  NOT NULL,                 -- přihlašovací jméno
    password       VARCHAR(255) NOT NULL,                 -- password_hash()
    jmeno          VARCHAR(100) NOT NULL DEFAULT '',
    email          VARCHAR(190) NOT NULL DEFAULT '',
    url            VARCHAR(255) NOT NULL DEFAULT '',
    admin          TINYINT UNSIGNED NOT NULL DEFAULT 0,   -- 0 autor, 1 redaktor, 2 admin (jako v 2.8)
    pravo_vydavat  BOOL NOT NULL DEFAULT 0,
    blokovat       BOOL NOT NULL DEFAULT 0,
    pocet_chyb     SMALLINT UNSIGNED NOT NULL DEFAULT 0,  -- neúspěšná přihlášení v řadě
    zamceno_do     DATETIME NULL,                         -- dočasný zámek po 10 chybných přihlášeních
    obnova_otisk   CHAR(64)     NOT NULL DEFAULT '',      -- sha256 jednorázového tokenu pro obnovu hesla e-mailem; prázdné = nic nečeká
    obnova_cas     DATETIME NULL,                         -- kdy byl odkaz pro obnovu hesla odeslán (platí hodinu)
    totp_tajemstvi VARCHAR(64)  NOT NULL DEFAULT '',      -- dvoufázové přihlášení (TOTP); prázdné = vypnuté
    totp_zalozni   TEXT NULL,                             -- JSON: otisky jednorázových záložních kódů
    posledni_login DATETIME NULL,
    jazyk          CHAR(2) NOT NULL DEFAULT '',            -- jazyk administrace; '' = čeština
    upozorneni     BOOL NOT NULL DEFAULT 1,                -- redakční upozornění e-mailem (ke korektuře, vydáno, vráceno)
    pozice         VARCHAR(100) NOT NULL DEFAULT '',      -- pozice v redakci (medailonek autora)
    foto           VARCHAR(255) NOT NULL DEFAULT '',
    bio            TEXT NULL,                             -- pár vět o autorovi
    PRIMARY KEY (idu),
    UNIQUE KEY uq_user (user)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- Přístup uživatele k modulu administrace
CREATE TABLE rs_user_prava (
    fk_id_user   INT UNSIGNED NOT NULL,
    ident_modulu VARCHAR(30)  NOT NULL,
    PRIMARY KEY (fk_id_user, ident_modulu),
    CONSTRAINT fk_prava_user FOREIGN KEY (fk_id_user) REFERENCES rs_user (idu) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- Nadřízený vidí a edituje články podřízeného
CREATE TABLE rs_vazby_prava (
    fk_id_nadrizeny INT UNSIGNED NOT NULL,
    fk_id_podrizeny INT UNSIGNED NOT NULL,
    PRIMARY KEY (fk_id_nadrizeny, fk_id_podrizeny),
    CONSTRAINT fk_vazby_nad FOREIGN KEY (fk_id_nadrizeny) REFERENCES rs_user (idu) ON DELETE CASCADE,
    CONSTRAINT fk_vazby_pod FOREIGN KEY (fk_id_podrizeny) REFERENCES rs_user (idu) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- ---------------------------------------------------------------------------
-- Konfigurace
-- ---------------------------------------------------------------------------
CREATE TABLE rs_config (
    promenna VARCHAR(60) NOT NULL,
    hodnota  TEXT NOT NULL,
    PRIMARY KEY (promenna)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE rs_levely (
    idl          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nazev_levelu VARCHAR(60) NOT NULL,
    hodnota      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    zakladni     BOOL NOT NULL DEFAULT 0,                 -- základní level nejde smazat
    PRIMARY KEY (idl)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- Šablony článků: soubor layout/<layout>/cla_<soubor>.php
CREATE TABLE rs_cla_sab (
    ids            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nazev_cla_sab  VARCHAR(60) NOT NULL,
    soubor_cla_sab VARCHAR(60) NOT NULL,
    PRIMARY KEY (ids)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- ---------------------------------------------------------------------------
-- Rubriky a články
-- ---------------------------------------------------------------------------
CREATE TABLE rs_topic (
    idt       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nazev     VARCHAR(100) NOT NULL,
    seo_link  VARCHAR(120) NOT NULL,
    popis     TEXT NOT NULL,
    obrazek   VARCHAR(255) NOT NULL DEFAULT '',
    id_predka INT UNSIGNED NULL,                          -- nadřazená rubrika (strom)
    hodnost   SMALLINT UNSIGNED NOT NULL DEFAULT 100,     -- pořadí mezi sourozenci, vyšší = výš
    zobrazit  BOOL NOT NULL DEFAULT 1,
    jazyk          CHAR(2) NOT NULL DEFAULT '',            -- jazyková verze; '' = výchozí jazyk webu
    preklad_z      INT UNSIGNED NULL,                      -- protějšek ve výchozím jazyce (hreflang, přepínač jazyků)
    PRIMARY KEY (idt),
    UNIQUE KEY uq_topic_seo (seo_link),
    CONSTRAINT fk_topic_predek FOREIGN KEY (id_predka) REFERENCES rs_topic (idt) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- Skupiny souvisejících článků (seriály)
CREATE TABLE rs_skup_cl (
    ids        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nazev_skup VARCHAR(150) NOT NULL,
    PRIMARY KEY (ids)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE rs_ankety (
    ida       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    titulek   VARCHAR(150) NOT NULL,
    otazka    TEXT NOT NULL,
    datum     DATETIME NOT NULL,
    kdo       INT UNSIGNED NULL,
    zobrazit  BOOL NOT NULL DEFAULT 1,
    uzavrena  BOOL NOT NULL DEFAULT 0,
    jazyk     CHAR(2) NOT NULL DEFAULT '',                          -- jazyková verze; '' = výchozí jazyk webu
    PRIMARY KEY (ida),
    CONSTRAINT fk_ankety_kdo FOREIGN KEY (kdo) REFERENCES rs_user (idu) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE rs_odpovedi (
    ido       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    anketa    INT UNSIGNED NOT NULL,
    odpoved   VARCHAR(255) NOT NULL,
    pocitadlo INT UNSIGNED NOT NULL DEFAULT 0,
    poradi    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (ido),
    CONSTRAINT fk_odpovedi_anketa FOREIGN KEY (anketa) REFERENCES rs_ankety (ida) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE rs_clanky (
    idc            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    seo_link       VARCHAR(160) NOT NULL,
    titulek        VARCHAR(255) NOT NULL,
    uvod           MEDIUMTEXT NOT NULL,
    text           MEDIUMTEXT NOT NULL,
    obrazek        VARCHAR(255) NOT NULL DEFAULT '',      -- nové ve 3.0: hlavní obrázek článku
    obrazek_popis  VARCHAR(300) NOT NULL DEFAULT '',      -- popisek hlavní fotky (prázdné = popisek z knihovny médií)
    obrazek_autor  VARCHAR(120) NOT NULL DEFAULT '',      -- autor hlavní fotky (prázdné = autor z knihovny médií)
    tema           INT UNSIGNED NOT NULL,                 -- rubrika
    autor          INT UNSIGNED NULL,
    datum          DATETIME NOT NULL,                     -- datum vydání (i budoucí)
    datum_pl       DATETIME NULL,                         -- datum stažení z hlavní stránky
    visible        BOOL NOT NULL DEFAULT 0,               -- vydaný článek (jinak koncept)
    stav_redakce   VARCHAR(12) NOT NULL DEFAULT '',       -- '' rozepsáno | korektura | schvaleno (jen u nevydaných)
    poznamka       TEXT NULL,                             -- interní poznámka redakce
    zobr_na_indexu BOOL NOT NULL DEFAULT 1,
    priority       TINYINT UNSIGNED NOT NULL DEFAULT 0,
    typ_clanku     TINYINT UNSIGNED NOT NULL DEFAULT 1,   -- 1 dlouhý (náhled + celý), 2 krátký
    sablona        INT UNSIGNED NULL,
    level_clanku   INT UNSIGNED NULL,
    skupina_cl     INT UNSIGNED NULL,
    anketa_cl      INT UNSIGNED NULL,
    zdroj          VARCHAR(255) NOT NULL DEFAULT '',
    t_slova        VARCHAR(500) NOT NULL DEFAULT '',      -- klíčová slova
    seo_titulek    VARCHAR(255) NOT NULL DEFAULT '',      -- vlastní <title>, prázdné = titulek článku
    seo_popis      VARCHAR(320) NOT NULL DEFAULT '',      -- vlastní meta description, prázdné = z perexu
    noindex        BOOL NOT NULL DEFAULT 0,
    pristup        TINYINT UNSIGNED NOT NULL DEFAULT 0,   -- 0 všichni | 1 přihlášení čtenáři | 2 předplatitelé
    shrnuti        TEXT NULL,                             -- blok "Ve zkratce": jeden bod na řádek
    faq            TEXT NULL,                             -- otázky a odpovědi: otázka, pod ní odpověď, prázdný řádek
    povolit_kom    BOOL NOT NULL DEFAULT 1,
    kom            INT UNSIGNED NOT NULL DEFAULT 0,       -- počet komentářů
    visit          INT UNSIGNED NOT NULL DEFAULT 0,       -- počet přečtení
    hodnoceni      INT UNSIGNED NOT NULL DEFAULT 0,       -- součet známek
    mn_hodnoceni   INT UNSIGNED NOT NULL DEFAULT 0,       -- počet hlasů
    zmeneno        DATETIME NULL,
    aktualizovano  DATETIME NULL,                         -- kdy byl vydaný článek podstatně doplněn
    oznameno       DATETIME NULL,                         -- kdy systém vydání oznámil (webhook, IndexNow, Web Push); NULL = ještě ne
    zamek_kdo      INT UNSIGNED NULL,                     -- kdo má článek právě otevřený v editoru
    zamek_cas      DATETIME NULL,
    jazyk          CHAR(2) NOT NULL DEFAULT '',            -- přebírá se z rubriky při uložení článku
    preklad_z      INT UNSIGNED NULL,                      -- idc článku, jehož je tento překladem
    zive           TINYINT UNSIGNED NOT NULL DEFAULT 0,    -- 0 běžný článek | 1 živá reportáž běží | 2 reportáž skončila
    medium_url     VARCHAR(255) NOT NULL DEFAULT '',       -- zvuk nebo video: soubor z Médií, YouTube, Vimeo, Spotify
    recenze_predmet VARCHAR(160) NOT NULL DEFAULT '',      -- co se hodnotí
    recenze_hodnoceni TINYINT UNSIGNED NULL,               -- hodnocení v procentech, NULL = není recenze
    hledani        MEDIUMTEXT NULL,                       -- text bez diakritiky pro hledání (Core\Hledani)
    externi_autor  VARCHAR(120) NOT NULL DEFAULT '',      -- host nebo agentura bez účtu v administraci
    komercni       BOOL NOT NULL DEFAULT 0,               -- komerční sdělení (placený či partnerský obsah) – na webu se viditelně označí
    komercni_partner VARCHAR(120) NOT NULL DEFAULT '',    -- „ve spolupráci s …“
    odkazy_cas     DATETIME NULL,                         -- kdy se odkazy článku naposledy kontrolovaly
    smazano        DATETIME NULL,                         -- v koši od (po 30 dnech se smaže natrvalo); NULL = není v koši
    smazal         INT UNSIGNED NULL,                     -- kdo článek přesunul do koše
    smazano_vydany BOOL NOT NULL DEFAULT 0,               -- byl při přesunu do koše vydaný (natrvalo ho smaže jen ten, kdo smí vydávat)
    PRIMARY KEY (idc),
    KEY ix_clanky_jazyk (jazyk, visible, datum),
    UNIQUE KEY uq_clanky_seo (seo_link),
    KEY ix_clanky_index (jazyk, visible, zobr_na_indexu, priority, datum),
    KEY ix_clanky_oznameno (oznameno, visible, datum),
    KEY ix_clanky_datum (datum),
    KEY ix_clanky_smazano (smazano),
    KEY ix_clanky_tema (tema, visible, datum),
    KEY ix_clanky_autor (autor),
    FULLTEXT KEY ft_clanky (titulek, uvod, text, t_slova),
    FULLTEXT KEY ft_clanky_hledani (hledani),
    CONSTRAINT fk_clanky_tema    FOREIGN KEY (tema)         REFERENCES rs_topic (idt),
    CONSTRAINT fk_clanky_autor   FOREIGN KEY (autor)        REFERENCES rs_user (idu)    ON DELETE SET NULL,
    CONSTRAINT fk_clanky_sablona FOREIGN KEY (sablona)      REFERENCES rs_cla_sab (ids) ON DELETE SET NULL,
    CONSTRAINT fk_clanky_level   FOREIGN KEY (level_clanku) REFERENCES rs_levely (idl)  ON DELETE SET NULL,
    CONSTRAINT fk_clanky_skupina FOREIGN KEY (skupina_cl)   REFERENCES rs_skup_cl (ids) ON DELETE SET NULL,
    CONSTRAINT fk_clanky_anketa  FOREIGN KEY (anketa_cl)    REFERENCES rs_ankety (ida)  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE rs_komentare (
    idk        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    clanek     INT UNSIGNED NOT NULL,                     -- rs_clanky.idc (v 2.8 to byl link)
    reakce_na  INT UNSIGNED NULL,
    datum      DATETIME NOT NULL,
    titulek    VARCHAR(150) NOT NULL DEFAULT '',
    obsah      TEXT NOT NULL,
    od         VARCHAR(60) NOT NULL,
    od_mail    VARCHAR(190) NOT NULL DEFAULT '',
    od_ip      VARCHAR(45) NOT NULL DEFAULT '',            -- otisk adresy pisatele (Antispam::otisk), ne IP adresa
    zobrazit   BOOL NOT NULL DEFAULT 1,
    idct       INT UNSIGNED NULL,                         -- účet čtenáře, pokud komentoval přihlášený
    upozornit  BOOL NOT NULL DEFAULT 0,                   -- e-mail autorovi, když mu někdo odpoví
    nahlaseno  SMALLINT UNSIGNED NOT NULL DEFAULT 0,      -- kolikrát čtenáři komentář nahlásili
    PRIMARY KEY (idk),
    KEY ix_komentare_clanek (clanek, datum),
    KEY ix_komentare_stav (zobrazit, datum),
    CONSTRAINT fk_komentare_clanek FOREIGN KEY (clanek)    REFERENCES rs_clanky (idc)    ON DELETE CASCADE,
    CONSTRAINT fk_komentare_reakce FOREIGN KEY (reakce_na) REFERENCES rs_komentare (idk) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- ---------------------------------------------------------------------------
-- Galerie obrázků
-- ---------------------------------------------------------------------------
CREATE TABLE rs_imggal_sekce (
    ids   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nazev VARCHAR(100) NOT NULL,
    PRIMARY KEY (ids)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE rs_imggal_obr (
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
    datum       DATETIME NOT NULL,
    PRIMARY KEY (ido),
    KEY ix_imggal_datum (datum),
    KEY ix_imggal_poloha (obr_poloha),
    KEY ix_imggal_sekce (sekce),
    CONSTRAINT fk_imggal_sekce FOREIGN KEY (sekce) REFERENCES rs_imggal_sekce (ids) ON DELETE SET NULL,
    CONSTRAINT fk_imggal_vlastnik FOREIGN KEY (vlastnik) REFERENCES rs_user (idu) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- ---------------------------------------------------------------------------
-- Novinky a bloky
-- ---------------------------------------------------------------------------
CREATE TABLE rs_news (
    idn       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    titulek   VARCHAR(150) NOT NULL,
    informace TEXT NOT NULL,
    datum     DATETIME NOT NULL,
    jazyk     CHAR(2) NOT NULL DEFAULT '',                          -- jazyková verze; '' = výchozí jazyk webu
    PRIMARY KEY (idn),
    KEY ix_news_datum (datum),
    KEY ix_news_jazyk (jazyk, datum)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE rs_bloky (
    idb          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nazev        VARCHAR(100) NOT NULL,
    obsah        MEDIUMTEXT NOT NULL,                     -- HTML běžného bloku
    typ          TINYINT UNSIGNED NOT NULL DEFAULT 1,     -- vzhled: 1 běžný, 2 podbarvený, 3 zvýrazněný nadpis, 4 v rámečku, 5 bez nadpisu
    hodnost      SMALLINT UNSIGNED NOT NULL DEFAULT 100,  -- pořadí v zóně, vyšší = výš (nastavuje se přetažením)
    sys_funkce   VARCHAR(30) NOT NULL DEFAULT '',         -- '' běžný blok; ank, nov, rub, kal, hlb nebo zkratka plug-inu
    data_sys     VARCHAR(255) NOT NULL DEFAULT '',
    zobrazit     BOOL NOT NULL DEFAULT 1,
    zobrazit_kde TINYINT UNSIGNED NOT NULL DEFAULT 0,     -- 0 všude, 1 jen hlavní stránka, 2 všude mimo ni
    zona         VARCHAR(20) NOT NULL DEFAULT 'prava',    -- hlavicka, leva, nad, pod, prava, paticka
    jen_rubrika  INT UNSIGNED NULL,                       -- NULL = všude; jinak jen v rubrice a u jejích článků
    zarizeni     VARCHAR(10) NOT NULL DEFAULT 'vse',      -- vse | mobil | pocitac
    level_blok   INT UNSIGNED NULL,
    jen_jazyk      CHAR(2) NOT NULL DEFAULT '',            -- '' = ve všech jazycích, 'vy' = jen ve výchozím, jinak kód jazyka
    PRIMARY KEY (idb),
    KEY ix_bloky_zona (zona, hodnost),
    CONSTRAINT fk_bloky_rubrika FOREIGN KEY (jen_rubrika) REFERENCES rs_topic (idt) ON DELETE SET NULL,
    CONSTRAINT fk_bloky_level   FOREIGN KEY (level_blok) REFERENCES rs_levely (idl) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- Ochrana proti opakování akce ze stejné IP (přihlášení, hlasování v anketě, hodnocení, komentáře)
CREATE TABLE rs_kontrola_ip (
    idk       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    ip_adresa VARCHAR(45) NOT NULL,
    typ       VARCHAR(20) NOT NULL,
    cil       INT UNSIGNED NOT NULL DEFAULT 0,
    cas       DATETIME NOT NULL,
    PRIMARY KEY (idk),
    KEY ix_kontrola (typ, cil, ip_adresa, cas)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- Ve kterých článcích je obrázek použitý (přepočítá se při uložení článku)
CREATE TABLE rs_imggal_pouziti (
    ido INT UNSIGNED NOT NULL,
    idc INT UNSIGNED NOT NULL,
    PRIMARY KEY (ido, idc),
    KEY ix_pouziti_clanek (idc),
    CONSTRAINT fk_pouziti_obr FOREIGN KEY (ido) REFERENCES rs_imggal_obr (ido) ON DELETE CASCADE,
    CONSTRAINT fk_pouziti_clanek FOREIGN KEY (idc) REFERENCES rs_clanky (idc) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- ---------------------------------------------------------------------------
-- Štítky článků, historie verzí článku a statické stránky (O nás, Kontakt...).
CREATE TABLE rs_stitky (
    ids      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nazev    VARCHAR(80) NOT NULL,
    seo_link VARCHAR(100) NOT NULL,
    popis    TEXT NULL,                                   -- úvod stránky tématu (HTML od redakce)
    obrazek  VARCHAR(255) NOT NULL DEFAULT '',
    PRIMARY KEY (ids),
    UNIQUE KEY uq_stitky_seo (seo_link)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
CREATE TABLE rs_clanky_stitky (
    idc INT UNSIGNED NOT NULL,
    ids INT UNSIGNED NOT NULL,
    PRIMARY KEY (idc, ids),
    KEY ix_clanky_stitky_stitek (ids),
    CONSTRAINT fk_cs_clanek FOREIGN KEY (idc) REFERENCES rs_clanky (idc) ON DELETE CASCADE,
    CONSTRAINT fk_cs_stitek FOREIGN KEY (ids) REFERENCES rs_stitky (ids) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
CREATE TABLE rs_clanky_revize (
    idr     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    idc     INT UNSIGNED NOT NULL,
    datum   DATETIME NOT NULL,
    kdo     INT UNSIGNED NULL,
    titulek VARCHAR(255) NOT NULL,
    uvod    MEDIUMTEXT NOT NULL,
    text    MEDIUMTEXT NOT NULL,
    PRIMARY KEY (idr),
    KEY ix_revize_clanek (idc, datum),
    CONSTRAINT fk_revize_clanek FOREIGN KEY (idc) REFERENCES rs_clanky (idc) ON DELETE CASCADE,
    CONSTRAINT fk_revize_kdo FOREIGN KEY (kdo) REFERENCES rs_user (idu) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
CREATE TABLE rs_stranky (
    ids      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    seo_link VARCHAR(120) NOT NULL,
    titulek  VARCHAR(200) NOT NULL,
    popis    VARCHAR(300) NOT NULL DEFAULT '',           -- meta description
    text     MEDIUMTEXT NOT NULL,
    zobrazit BOOL NOT NULL DEFAULT 1,
    v_menu   BOOL NOT NULL DEFAULT 1,                     -- odkaz v patičce / navigaci webu
    poradi   SMALLINT UNSIGNED NOT NULL DEFAULT 100,
    zmeneno  DATETIME NULL,
    jazyk          CHAR(2) NOT NULL DEFAULT '',            -- jazyková verze; '' = výchozí jazyk webu
    preklad_z      INT UNSIGNED NULL,                      -- protějšek ve výchozím jazyce (hreflang, přepínač jazyků)
    PRIMARY KEY (ids),
    UNIQUE KEY uq_stranky_seo (seo_link)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- ---------------------------------------------------------------------------
-- Přesměrování a evidence souhlasů
CREATE TABLE rs_presmerovani (
    idp       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    z_adresy  VARCHAR(255) NOT NULL,                     -- cesta na webu bez úvodního lomítka: clanek/stara-adresa
    na_adresu VARCHAR(255) NOT NULL,                     -- cesta na webu, nebo celá adresa https://...
    pocet     INT UNSIGNED NOT NULL DEFAULT 0,           -- kolikrát bylo přesměrování použito
    vytvoreno DATETIME NOT NULL,
    PRIMARY KEY (idp),
    UNIQUE KEY uq_presmerovani (z_adresy)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
CREATE TABLE rs_souhlasy (
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
CREATE TABLE rs_stat_dny (
    den       DATE NOT NULL,
    navstevy  INT UNSIGNED NOT NULL DEFAULT 0,            -- unikátní návštěvníci dne
    zobrazeni INT UNSIGNED NOT NULL DEFAULT 0,            -- zobrazené stránky
    PRIMARY KEY (den)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
-- Otisk návštěvníka = hash(IP + prohlížeč + denní sůl). Druhý den už nejde spojit s předchozím; starší řádky se mažou.
CREATE TABLE rs_stat_navstevnici (
    den   DATE NOT NULL,
    otisk CHAR(32) NOT NULL,
    PRIMARY KEY (den, otisk)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
CREATE TABLE rs_stat_clanky (
    den   DATE NOT NULL,
    idc   INT UNSIGNED NOT NULL,
    pocet INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (den, idc),
    KEY ix_stat_clanky_idc (idc),
    CONSTRAINT fk_stat_clanek FOREIGN KEY (idc) REFERENCES rs_clanky (idc) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
CREATE TABLE rs_stat_zdroje (
    den   DATE NOT NULL,
    zdroj VARCHAR(100) NOT NULL,                          -- doména, ze které návštěvník přišel
    pocet INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (den, zdroj)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- ---------------------------------------------------------------------------
-- Reklamní systém: bannery a reklamní kódy přiřazené k pozicím na webu.
CREATE TABLE rs_reklama (
    idr           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nazev         VARCHAR(150) NOT NULL,                  -- interní název (klient, kampaň)
    pozice        VARCHAR(40) NOT NULL,                   -- sloupec | hlavicka | pod-clankem | paticka
    typ           VARCHAR(10) NOT NULL DEFAULT 'obrazek', -- obrazek | kod
    obrazek       VARCHAR(255) NOT NULL DEFAULT '',
    cil_url       VARCHAR(500) NOT NULL DEFAULT '',
    kod           MEDIUMTEXT NULL,                        -- HTML/JS reklamní sítě
    platna_od     DATETIME NULL,
    platna_do     DATETIME NULL,
    aktivni       BOOL NOT NULL DEFAULT 1,
    vaha          TINYINT UNSIGNED NOT NULL DEFAULT 1,    -- vyšší = zobrazuje se častěji
    max_zobrazeni INT UNSIGNED NULL,                      -- strop kampaně, NULL = bez omezení
    zobrazeni     INT UNSIGNED NOT NULL DEFAULT 0,
    kliky         INT UNSIGNED NOT NULL DEFAULT 0,
    jen_rubrika   INT UNSIGNED NULL,                      -- cílení: jen v této rubrice (a jejích článcích)
    zarizeni      VARCHAR(10) NOT NULL DEFAULT 'vse',     -- vse | mobil | pocitac
    PRIMARY KEY (idr),
    KEY ix_reklama_pozice (pozice, aktivni)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- ---------------------------------------------------------------------------
-- Protokol změn v administraci
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

-- ---------------------------------------------------------------------------
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

-- Přihlašovací klíče (passkeys / WebAuthn) jako druhý krok přihlášení. Ukládá se jen veřejný klíč zařízení.
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

-- ---------------------------------------------------------------------------
-- Newsletter
CREATE TABLE rs_odberatele (
    ido       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    email     VARCHAR(190) NOT NULL,
    token     CHAR(32) NOT NULL,                          -- pro potvrzení a odhlášení odkazem z e-mailu
    potvrzen  BOOL NOT NULL DEFAULT 0,
    prihlasen DATETIME NOT NULL,
    jazyk     CHAR(2) NOT NULL DEFAULT '',                -- jazyk webu, na kterém se přihlásil; dostává vydání v tomto jazyce
    PRIMARY KEY (ido),
    UNIQUE KEY uq_odberatele_email (email),
    KEY ix_odberatele_token (token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
CREATE TABLE rs_newsletter (
    idn       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    predmet   VARCHAR(200) NOT NULL,
    uvod      TEXT NOT NULL,
    clanky    VARCHAR(255) NOT NULL DEFAULT '',           -- idc článků oddělená čárkou
    vytvoreno DATETIME NOT NULL,
    odeslano  DATETIME NULL,                              -- NULL = rozesílka ještě nedoběhla
    posledni  INT UNSIGNED NOT NULL DEFAULT 0,            -- ido posledního obslouženého odběratele
    pocet     INT UNSIGNED NOT NULL DEFAULT 0,
    auto      BOOL NOT NULL DEFAULT 0,                    -- vydání vzniklo samo z nových článků
    odeslat_v DATETIME NULL,                              -- naplánovaná rozesílka na pozadí; NULL = ruční z administrace
    otevreno  INT UNSIGNED NOT NULL DEFAULT 0,            -- souhrnná statistika, nic o jednotlivcích
    prokliku  INT UNSIGNED NOT NULL DEFAULT 0,
    jazyk     CHAR(2) NOT NULL DEFAULT '',                -- vydání jde jen odběratelům tohoto jazyka
    PRIMARY KEY (idn)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- ---------------------------------------------------------------------------
-- Čtenáři (rozšíření Čtenáři a uzamčený obsah)
-- ---------------------------------------------------------------------------
CREATE TABLE rs_ctenari (
    idct         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    email        VARCHAR(190) NOT NULL,
    jmeno        VARCHAR(80) NOT NULL DEFAULT '',
    heslo        VARCHAR(255) NOT NULL,
    token        CHAR(32) NOT NULL,                       -- potvrzení e-mailu a obnova hesla
    token_cas    DATETIME NULL,                           -- kdy byl odeslán odkaz pro obnovu hesla (platí 2 hodiny)
    potvrzen     BOOL NOT NULL DEFAULT 0,
    predplatne_do DATE NULL,                              -- do kdy má čtenář předplatné; zapisuje administrátor ručně, nebo platba přes Stripe
    stripe_zakaznik VARCHAR(64) NULL,                     -- zákazník ve Stripe (cus_…); vzniká při první platbě
    stripe_predplatne VARCHAR(64) NULL,                   -- předplatné ve Stripe (sub_…)
    predplatne_stav VARCHAR(20) NOT NULL DEFAULT '',      -- '' = bez Stripe (ruční zápis) | aktivni | konci | nezaplaceno | zruseno
    vytvoren     DATETIME NOT NULL,
    naposledy    DATETIME NULL,
    PRIMARY KEY (idct),
    UNIQUE KEY uq_ctenari_email (email),
    KEY ix_ctenari_token (token),
    KEY ix_ctenari_stripe (stripe_zakaznik)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- Platby předplatného přijaté přes Stripe (zapisuje jen ověřený webhook). Žádné údaje o kartě ani adresy.
CREATE TABLE rs_platby (
    id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    udalost   VARCHAR(80) NOT NULL,                      -- číslo události Stripe (evt_…); unikátní = stejná událost se nezapíše dvakrát
    idct      INT UNSIGNED NULL,                         -- čtenář; po smazání účtu NULL (platba zůstává kvůli účetnictví anonymní)
    castka    INT NOT NULL,                              -- v nejmenších jednotkách měny (haléře, centy)
    mena      CHAR(3) NOT NULL,
    typ       VARCHAR(20) NOT NULL,                      -- predplatne
    vytvoreno DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_platby_udalost (udalost),
    KEY ix_platby_cas (vytvoreno),
    CONSTRAINT fk_platby_ctenar FOREIGN KEY (idct) REFERENCES rs_ctenari (idct) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- ---------------------------------------------------------------------------
-- Odběry oznámení Web Push (rozšíření Oznámení v prohlížeči)
-- ---------------------------------------------------------------------------
CREATE TABLE rs_push (
    idp       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    endpoint  VARCHAR(700) NOT NULL,                      -- adresa u služby prohlížeče (Google, Mozilla, Apple, Microsoft)
    otisk     CHAR(64) NOT NULL,                          -- sha256 adresy kvůli jedinečnosti
    p256dh    VARCHAR(120) NOT NULL DEFAULT '',
    auth      VARCHAR(40) NOT NULL DEFAULT '',
    vytvoreno DATETIME NOT NULL,
    PRIMARY KEY (idp),
    UNIQUE KEY uq_push_otisk (otisk)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- ---------------------------------------------------------------------------
-- Zápisy živé reportáže
-- ---------------------------------------------------------------------------
CREATE TABLE rs_zive (
    idz      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    idc      INT UNSIGNED NOT NULL,
    cas      DATETIME NOT NULL,
    text     TEXT NOT NULL,                               -- HTML zápisu (píše redakce)
    dulezite BOOL NOT NULL DEFAULT 0,
    autor    INT UNSIGNED NULL,
    PRIMARY KEY (idz),
    KEY ix_zive_clanek (idc, idz),
    CONSTRAINT fk_zive_clanek FOREIGN KEY (idc) REFERENCES rs_clanky (idc) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- ---------------------------------------------------------------------------
-- Fronta a protokol e-mailů (Core\Posta)
-- ---------------------------------------------------------------------------
CREATE TABLE rs_posta (
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
CREATE TABLE rs_nenalezeno (
    cesta     VARCHAR(255) NOT NULL,
    pocet     INT UNSIGNED NOT NULL DEFAULT 1,
    naposledy DATETIME NOT NULL,
    PRIMARY KEY (cesta)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- Rozepsané články uložené na serveru (pokračování z jiného zařízení)
CREATE TABLE rs_clanky_koncepty (
    kdo  INT UNSIGNED NOT NULL,
    idc  INT UNSIGNED NOT NULL DEFAULT 0,
    cas  DATETIME NOT NULL,
    data MEDIUMTEXT NOT NULL,                             -- JSON {název pole formuláře: hodnota}
    PRIMARY KEY (kdo, idc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- Spoluautoři článku (hlavní autor je rs_clanky.autor)
CREATE TABLE rs_clanky_autori (
    idc INT UNSIGNED NOT NULL,
    idu INT UNSIGNED NOT NULL,
    PRIMARY KEY (idc, idu),
    KEY ix_clanky_autori_idu (idu),
    CONSTRAINT fk_clanky_autori_clanek FOREIGN KEY (idc) REFERENCES rs_clanky (idc) ON DELETE CASCADE,
    CONSTRAINT fk_clanky_autori_autor FOREIGN KEY (idu) REFERENCES rs_user (idu) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- Nefunkční odkazy nalezené v článcích (Core\Odkazy)
CREATE TABLE rs_odkazy_vadne (
    ido  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    idc  INT UNSIGNED NOT NULL,
    url  VARCHAR(500) NOT NULL,
    stav SMALLINT UNSIGNED NOT NULL DEFAULT 0,             -- kód odpovědi; 0 = server neodpověděl, 404 u vlastního článku = neexistuje
    cas  DATETIME NOT NULL,
    PRIMARY KEY (ido),
    KEY ix_odkazy_clanek (idc),
    CONSTRAINT fk_odkazy_clanek FOREIGN KEY (idc) REFERENCES rs_clanky (idc) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- Uložené články přihlášených čtenářů
CREATE TABLE rs_ctenari_ulozene (
    idct INT UNSIGNED NOT NULL,
    idc  INT UNSIGNED NOT NULL,
    cas  DATETIME NOT NULL,
    PRIMARY KEY (idct, idc),
    CONSTRAINT fk_ulozene_ctenar FOREIGN KEY (idct) REFERENCES rs_ctenari (idct) ON DELETE CASCADE,
    CONSTRAINT fk_ulozene_clanek FOREIGN KEY (idc) REFERENCES rs_clanky (idc) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- Oprávnění podle rubriky (bez záznamu = všechny rubriky)
CREATE TABLE rs_user_rubriky (
    idu INT UNSIGNED NOT NULL,
    idt INT UNSIGNED NOT NULL,
    PRIMARY KEY (idu, idt),
    CONSTRAINT fk_user_rubriky_user FOREIGN KEY (idu) REFERENCES rs_user (idu) ON DELETE CASCADE,
    CONSTRAINT fk_user_rubriky_rubrika FOREIGN KEY (idt) REFERENCES rs_topic (idt) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- Import z jiných systémů (WordPress): co z cizího webu už bylo převedeno a na který náš záznam
CREATE TABLE rs_import_mapa (
    zdroj   VARCHAR(40) NOT NULL,                        -- odkud záznam pochází: wp:<doména starého webu>
    typ     VARCHAR(20) NOT NULL,                        -- clanek | stranka | rubrika | stitek | obrazek | komentar
    cizi_id VARCHAR(190) NOT NULL,                       -- identifikátor ve zdroji (číslo příspěvku, adresa rubriky, otisk adresy obrázku)
    nase_id INT UNSIGNED NOT NULL,                       -- číslo našeho záznamu; 0 u obrázku = stažení se nepovedlo
    PRIMARY KEY (zdroj, typ, cizi_id),
    KEY ix_import_mapa_nase (zdroj, typ, nase_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
