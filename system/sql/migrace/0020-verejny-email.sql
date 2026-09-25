-- Veřejný e-mail firmy (Nastavení → Firma) je nově samostatný: e-mail webu (upozornění, poptávky) se na webu sám neukazuje.
-- Stávající weby ho dosud zveřejňovaly v patičce, v prvku Údaje firmy a ve strukturovaných datech – aby o kontakt nepřišly, převezme se.
-- Zdroj je odvozená tabulka: odkaz na tutéž tabulku v INSERT … SELECT … ON DUPLICATE KEY UPDATE MySQL odmítne (1.0.8).
INSERT IGNORE INTO ka_nastaveni (promenna, hodnota)
SELECT 'firma_email', e.hodnota FROM (SELECT hodnota FROM ka_nastaveni WHERE promenna = 'email_webu' AND hodnota <> '') AS e;
