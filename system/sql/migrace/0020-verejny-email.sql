-- Veřejný e-mail firmy (Nastavení → Firma) je nově samostatný: e-mail webu (upozornění, poptávky) se na webu sám neukazuje.
-- Stávající weby ho dosud zveřejňovaly v patičce, v prvku Údaje firmy a ve strukturovaných datech – aby o kontakt nepřišly, převezme se.
INSERT INTO ka_nastaveni (promenna, hodnota)
SELECT 'firma_email', hodnota FROM ka_nastaveni WHERE promenna = 'email_webu' AND hodnota <> ''
ON DUPLICATE KEY UPDATE hodnota = IF(ka_nastaveni.hodnota = '', VALUES(hodnota), ka_nastaveni.hodnota);
