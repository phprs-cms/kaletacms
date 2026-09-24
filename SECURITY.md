# Bezpečnost Kalety

## Nahlášení chyby

Bezpečnostní chybu prosím **nehlaste veřejně** v Issues. Použijte soukromé hlášení na GitHubu
(záložka *Security → Report a vulnerability*) nebo e-mail uvedený na webu projektu. Popište verzi,
postup a dopad. Ozveme se do 3 pracovních dnů; opravu běžně vydáváme do 14 dnů, u kritických chyb co nejdříve.

## Jak se oprava dostane k uživatelům

1. Oprava vyjde jako nová verze označená **bezpečnostní**.
2. Každá instalace se po novinkách dívá dvakrát denně. Bezpečnostní verzi si (pokud to správce nevypnul)
   **nainstaluje sama**: zazálohuje databázi, ověří kontrolní součet a podpis vydavatele a přepíše soubory systému.
3. Správce dostane e-mail a v administraci vidí upozornění. Kdo má automatiku vypnutou, aktualizuje jedním tlačítkem.
4. Po vydání opravy zveřejníme bezpečnostní oznámení (GitHub Security Advisory) s popisem a poděkováním nálezci.

Podporovaná je vždy poslední vydaná verze.

## Co systém chrání

Připravené dotazy všude, hesla `password_hash`, CSRF u každé akce v administraci, výstup přes escapování,
nahrané obrázky se překódovávají, složky `system/` a `storage/` nejsou z webu přístupné, aktualizace jen
s podpisem Ed25519. Systém za běhu nepoužívá žádné knihovny třetích stran.
