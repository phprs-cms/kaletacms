-- Komponenty: znovupoužitelné bloky builderu. vlastnosti = JSON [{klic, popisek, typ, vychozi}] – v komponentě jako {{klic}},
-- každé použití (prvek „komponenta“) jim dává vlastní hodnoty. Změna komponenty se projeví všude, kde je použitá.
CREATE TABLE ka_komponenty (
    idm            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nazev          VARCHAR(100) NOT NULL,
    vlastnosti     TEXT NOT NULL,
    stavba         MEDIUMTEXT NULL,
    stavba_koncept MEDIUMTEXT NULL,
    zmeneno        DATETIME NULL,
    PRIMARY KEY (idm)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
