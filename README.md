<p><picture><source media="(prefers-color-scheme: dark)" srcset="image/kaleta-logo-tmavy.svg"><img src="image/kaleta-logo.svg" alt="Kaleta" height="48"></picture></p>

# Kaleta

**Open-source CMS pro firemní weby** – prezentace, služby, reference, tým, kariéra, kontakt a novinky. Vizuální builder
stránek s výstupem na úrovni ručně psaného HTML, AI asistent a napojení Clauda přes MCP, import z WordPressu.
[English](README.en.md)

> **Stav: před vydáním 1.0.** Funkce níže jsou hotové a pokryté testy; zatím nepoužívejte na produkčních webech.

## Co umí

- **Builder stránek** – plátno je skutečná stránka webu. Prvky a hotové sekce přetáhnete na místo, styl nastavíte zvlášť
  pro počítač, tablet, mobil i najetí myší. Koncept se ukládá průběžně, na web jde až tlačítkem Publikovat; starší verze
  se dají obnovit.
- **Knihovna 39 hotových sekcí** (úvody, služby, ceník, reference, tým, galerie, kontakt s formulářem…) a **tři ukázkové
  weby** k instalaci. Texty česky i anglicky, podle jazyka stránky.
- **Design systém** – předvolby vzhledu jedním klikem, barvy s kontrolou čitelnosti (WCAG), písma, plynulé velikosti
  a mezery. Všechno jsou tokeny, takže změna barvy přebarví celý web.
- **Části webu** – záhlaví, patička a obálky detailu novinky, výpisu a stránky 404 v builderu, včetně variant pro
  vybrané stránky (landing page bez navigace).
- **Komponenty** – znovupoužitelné bloky s vlastnostmi; úprava komponenty se projeví všude, kde je použitá.
- **Kolekce** – vlastní typy obsahu (reference, tým, produkty, pobočky…) s vlastními poli, výpis v builderu se
  značkami `{{pole}}`, filtry, řazením a stránkováním, stránky položek se šablonou z builderu.
- **Formuláře a poptávky** – poptávkový formulář bez CAPTCHA a cookies, poptávky v administraci, upozornění e-mailem,
  export CSV a automatické mazání osobních údajů.
- **Firma** – adresa, IČO, otevírací doba a mapa jednou v Nastavení; web je vypíše a vyhledávače dostanou strukturovaná
  data LocalBusiness.
- **AI** – asistent navrhne novou sekci podle popisu, přepíše text prvku, navrhne titulky, SEO popisy, korekturu i překlad
  (Claude, OpenAI, Google Gemini nebo Mistral). Claude navíc může web stavět přes **MCP**: HTML převede na stavbu,
  upraví části webu, kolekce i vzhled – vždy jako koncept ke schválení.
- **Novinky** (blog), vícejazyčné weby, SEO a llms.txt, vlastní měření návštěvnosti bez cookies, přesměrování,
  zálohy a podepsané aktualizace, **import z WordPressu** (i rovnou do builderu).

## Zásady

- **Bez technologického dluhu:** čisté PHP 8.4+, žádný framework, Composer ani build krok; žádné cizí pluginy.
- **Čistý výstup:** jeden prvek builderu = jedna HTML značka, CSS jen toho, co stránka používá, ve vrstvách kaskády
  (`@layer`); JavaScript jen tam, kde je opravdu potřeba. Hlídají to testy.
- **Web 2026:** fluidní typografie a mezery, container queries, barvy v OKLCH (`color-mix`), Popover API, přechody mezi stránkami.
- **AI jako rovnocenný uživatel:** co jde v editoru, jde i přes MCP – stejné schéma, validace i oprávnění.
- **Soukromí a přístupnost jako výchozí stav:** žádné cizí skripty ani písma, kontrola kontrastu barev.
- **Instalace přes FTP**, podepsané aktualizace.

## Instalace

1. Nahrajte obsah repozitáře na hosting s PHP 8.4+ a MySQL 8 / MariaDB 10.6+.
2. Založte prázdnou databázi.
3. Otevřete `https://vas-web.cz/install.php`, vyplňte formulář a vyberte ukázkový web.

Nginx nečte `.htaccess` – použijte ukázku `system/nginx.priklad.conf`. Návod k používání je v [příručce](docs/prirucka.md).

## Vývoj

```bash
php -S localhost:8080 system/dev-router.php
```

Testy: `php tools/testy.php` (jednotkové, bez databáze) a `tools/test.sh` (čistá instalace a průchod webem, administrací,
builderem i MCP; potřebuje MySQL; `WEB=remeslo tools/test.sh` otestuje jiný ukázkový web). Pravidla pro přispěvatele
a architektura jsou v [`CLAUDE.md`](CLAUDE.md).

## Licence

GNU GPL verze 2 nebo novější. Text licence je v souboru [`LICENSE`](LICENSE).
