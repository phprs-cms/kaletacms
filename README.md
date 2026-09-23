<p><picture><source media="(prefers-color-scheme: dark)" srcset="image/mirocms-logo-tmavy.svg"><img src="image/mirocms-logo.svg" alt="MiroCMS" height="48"></picture></p>

# MiroCMS

**Open-source CMS pro firemní weby** – prezentace, služby, reference, kariéra, kontakt a novinky. Vizuální stavitel
stránek s výstupem na úrovni ručně psaného HTML, napojení na jazykové modely (Claude přes MCP i další) a import z WordPressu.

> **Stav: raný vývoj.** Jádro (správa, bezpečnost, média, SEO, import z WordPressu, MCP) je hotové; stavitel stránek,
> design systém a moduly pro firemní web vznikají. Zatím nepoužívejte na produkčních webech.

## Zásady

- **Bez technologického dluhu:** čisté PHP 8.4+, žádný framework, Composer ani build krok; žádné cizí pluginy.
- **Čistý výstup:** jeden prvek stavitele = jedna HTML značka, CSS jen toho, co stránka používá, nulový JavaScript,
  pokud ho stránka nepotřebuje. Hlídají to testy.
- **Web 2026:** fluidní typografie a mezery, container queries, barvy v OKLCH, design tokeny (W3C DTCG),
  přechody mezi stránkami bez SPA.
- **AI jako rovnocenný uživatel:** co jde v editoru, jde i přes MCP – stejné schéma, validace i oprávnění; změny od AI jsou návrh ke schválení.
- **Soukromí a přístupnost jako výchozí stav:** žádné cizí skripty ani písma, kontrola WCAG 2.2 AA v editoru.
- **Instalace přes FTP**, podepsané aktualizace.

## Instalace (vývojová verze)

1. Nahrajte obsah repozitáře na hosting s PHP 8.4+ a MySQL 8 / MariaDB 10.6+.
2. Založte prázdnou databázi.
3. Otevřete `https://vas-web.cz/install.php` a vyplňte formulář.

Nginx nečte `.htaccess` – použijte ukázku `system/nginx.priklad.conf`.

## Vývoj

```bash
php -S localhost:8080 system/dev-router.php
```

Test (čistá instalace + průchod webem a administrací, potřebuje MySQL): `tools/test.sh`.

## Licence

GNU GPL verze 2 nebo novější. Text licence je v souboru [`LICENSE`](LICENSE).
