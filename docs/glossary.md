# Glossary: Czech names in the code → English

Kaleta's code started with Czech identifiers. From 1.4 they were renamed to English, one area per release, by a tool
that renamed PHP tokens with a map per step (removed in 3.39, once the work was done). This page is the source of truth for the translations, so
that one Czech term becomes the same English word everywhere. The English wording follows what users already see: the
English admin and the MCP tools (`get_build`, `list_site_parts`, `save_collection_item`…).

## What does not change

These are contracts with data that already exists, with users' own CSS and scripts, and with Claude and other API
clients. They keep their Czech names; the 1.4 renames never touched string literals. Tables and columns are not renamed
in any later version either (owner decision, docs/ROADMAP.md "Path to 4.0").

| Contract | Examples | Why |
|---|---|---|
| Database tables and columns, stored values | `ka_stranky.seo_link`, `ka_casti.typ = 'hlavicka'`, extension keys (`novinky`) | data on every installed site |
| Build JSON: keys, element types, style keys | `{"typ":"nadpis","obsah":{…},"styl":{"mobil":{"mezera":"s"}},"deti":[…]}` | stored builds; over MCP they are English since 1.6 (`Mcp\Vocabulary`), the Czech form is still accepted |
| Design system keys and CSS custom properties | `barvy.primarni`, `--ka-barva-text`, `--ka-mezera-l` | stored design, shared classes |
| Public HTML hooks | classes `ka-*`, `data-ka-*`, `#popup-<slug>`, localStorage `ka-jazyk` | custom CSS and scripts of sites |
| Release channel | manifest keys `verze`, `sha256`, `podpis`…, `system/soubory.json` | older installs parse them |
| MCP and REST | tool and parameter names (English names exist, Czech aliases stay) | connected clients |
| `config.php` | `db_host`, `db_name`… | written by the installer |

Renamed later, each with a migration or an alias so old links and data keep working:

- UI source strings `t('…')` / `T('…')`: English since 1.4.1 (step 6); Czech is a dictionary like
  any other language (`system/jazyky/cs.php`, `admin-cs.php`, `install-cs.php`, `image/jazyky/admin-cs.js`). About 160 texts
  stay Czech for now (one Czech text with two English translations, or two Czech texts sharing one), and the other
  dictionaries keep their Czech keys, so a Czech text passed from data still translates.

Since 2.0 the old names are gone: old class names and template helpers (1.3), old admin URLs (`?modul=…&akce=…`) and
settings under old keys. Settings keys are English since 1.4.1 (`site_name`, `company_id`…); the map of old keys lives on
in `Core\OldSettingsKeys` only at the edges – updates from 1.4.0 and older, MCP clients that still send `nazev_webu`, and
imports of old exports. The empty `system/class-aliases.php` left the code base in 2.0.1; release packages still carry it
for sites updating from 1.4–2.0, and the new version deletes it after the update.

## Conventions

- Classes are nouns in PascalCase, methods start with a verb, booleans with `is`/`has`/`can`: `jeAktivni` → `isActive`,
  `maModul` → `hasModule`, `smiPublikovat` → `canPublish`.
- Admin actions keep the `action` prefix: `akceUloz` → `actionSave`, URL `?action=save`.
- No new abbreviations. Kept: `id`, `url`, `html`, `css`, `db`, `ai`, `mcp`, `seo`, `utm`, `sha`, `max`, `min`.
- One Czech word with two meanings gets two English words; the table says which is which (`adresa`, `stav`, `značka`).
- A Czech inflected form maps like its base word: `stranky`, `stranku`, `strance` → `page`/`pages`.

## Terms

### Content

| Czech | English | Note |
|---|---|---|
| stránka | page | |
| novinka, novinky | news item, news | `Novinky` (module) → `News` |
| titulek | title | |
| nadpis | heading | the element and `<h1>`–`<h6>` |
| úvod | intro | news intro |
| text (stránky) | content | MCP `content` |
| kategorie, rubrika | category | |
| štítek | tag | news tags; an HTML tag is `htmlTag` |
| kolekce | collection | |
| položka | item | collection item, menu item |
| pole (kolekce) | field | |
| popisek | label | |
| hodnota | value | |
| adresa (v URL) | slug | `seo_link` column stays |
| adresa (celá) | url | |
| adresa (poštovní) | address | company details |
| odkaz | link | |
| obrázek | image | |
| média | media | |
| soubor | file | |
| složka | folder | |
| galerie | gallery | admin module `intergal` → `Media` |
| poptávka | enquiry | |
| formulář | form | |
| odběratel, odběr | subscriber, subscription | |
| okno, pop-up | popup | the per-page element `Okno` / `Modal` became site pop-ups in 2.0 (`Builder\ModalConversion`) |
| spouštěč | trigger | |
| četnost | frequency | |
| pravidla | rules | |
| přesměrování | redirect | |
| menu, umístění | menu, location | |
| překlad | translation | |
| jazyk, jazyky | language, languages | |
| firma, údaje firmy | company, company details | |
| hledání | search | |
| koš | trash | |

### Builder

| Czech | English | Note |
|---|---|---|
| stavitel | builder | namespace `Kaleta\Stavitel` → `Kaleta\Builder` |
| stavba | build | page or part tree; MCP `*_build` |
| prvek, prvky | element, elements | `Prvky\` → `Elements\` |
| typ | type | |
| značka (HTML) | htmlTag | build key `znacka` stays |
| obsah | content | |
| styl | style | |
| třída, třídy | class, classes | shared style classes; PHP class names are not in the way |
| děti | children | |
| kotva | anchor | |
| vlastnosti | properties | element property definitions |
| výchozí | default | |
| sekce | section | |
| knihovna | library | |
| komponenta | component | |
| část webu | site part | `Casti` → `SiteParts` |
| hlavička, patička | header, footer | |
| varianta | variant | |
| šablona detailu | item template | collection item page |
| koncept | draft | |
| publikovat, zveřejnit | publish | |
| revize, verze | version | MCP `list_build_versions` |
| náhled | preview | |
| úpravy, operace | edits, operations | `Upravy` → `Edits` |
| kontrola (před zveřejněním) | check | |
| kontext | context | |
| vykreslit | render | |
| předvolba | preset | design system preset |
| startovací web | starter site | |
| vzhled | appearance | |
| šablona (layout) | layout | the `layout/` folder, removed in 1.6 (themeless) |
| téma, tmavý režim | color scheme, dark mode | the light/dark switcher |
| přepínač | switcher | |
| mobil, tablet, počítač | mobile, tablet, desktop | build keys `mobil`/`tablet` stay |
| zařízení | device | |
| mřížka, kontejner | grid, container | |
| tlačítko | button | |
| oddělovač | divider | |
| počítadlo, odpočet, průběh | counter, countdown, progress | |
| hodnocení, citát | rating, quote | |
| drobečky | breadcrumbs | |
| záložky | tabs | |
| karusel | carousel | |
| údaje | details | company details element |
| výpis kolekce | collection list | |
| nahoru | back to top | element |

### Administration and system

| Czech | English | Note |
|---|---|---|
| modul | module | |
| akce | action | |
| správce | administrator | `admin` in short names |
| redaktor, autor, uživatel | editor, author, user | |
| role, oprávnění, práva | role, permission, permissions | |
| rozšíření | extension | |
| nastavení | settings | |
| záloha, obnova | backup, restore | |
| aktualizace | update | |
| migrace | migration | |
| protokol změn | change log | |
| přenos | import/export | `Prenos` → `Transfer` |
| statistika | stats | |
| souhlas (cookies) | consent | |
| antispam | spam protection | class stays `Antispam` |
| heslo, obnova hesla | password, password reset | |
| přihlášení, relace | sign-in, session | |
| klíč, podpis, otisk | key, signature, hash | |
| integrita | integrity | |
| stav (záznamu) | status | published, draft, new… |
| stav (systému) | health | the Status tab of settings |
| stav (za běhu) | state | UI and runtime state |
| oznámení | notification | |
| pošta | mail | |
| úloha na pozadí | background task | |
| mezipaměť | cache | |
| chyba, chyby | error, errors | |
| cesty | routes | |

### Verbs

| Czech | English | | Czech | English |
|---|---|---|---|---|
| ulož | save | | načti | load |
| vytvoř, založ | create | | smaž | delete |
| uprav | update (data), edit (UI) | | zahoď | discard |
| přidej | add | | odeber | remove |
| obnov | restore | | nahraď | replace |
| zobraz | show | | skryj | hide |
| vykresli | render | | vypiš | list |
| najdi | find | | hledej | search |
| ověř, zkontroluj | verify, check | | vyčisti | sanitize (HTML), clean |
| převeď | convert | | dosaď | fill |
| zapiš | write | | přečti | read |
| nahraj | upload | | stáhni | download |
| odešli | send | | rozbal | extract |
| nainstaluj | install | | aktualizuj | update |
| povol | enable | | zakaž | disable |
| seřaď | sort | | spočítej | count |

### Small words

| Czech | English | | Czech | English |
|---|---|---|---|---|
| je | is | | má | has |
| smí, lze | can | | jen | only |
| bez | without | | s, se | with |
| nový | new | | starý | old |
| původní | original | | vlastní | custom |
| aktivní | active | | skrytý | hidden |
| zobrazený | visible | | hlavní | main |
| další | next | | předchozí | previous |
| počet | count | | celkem | total |
| pořadí | order | | datum, čas | date, time |
| od, do | from, to | | seznam | list |

## UI vocabulary (3.23)

The words the administration uses for people – the English admin and its source strings, with the Czech admin beside them.
UI wording only: database tables and columns, stored values, MCP tool names and their Czech aliases keep their names
(`update_design_system`, `list_requests`, `ka_requests`, `ka_novinky`…). A new screen, flash message or hint uses these words.

| Word (English admin) | Czech admin | Means | Not |
|---|---|---|---|
| Appearance | Vzhled | the menu group: Site appearance, Menu, Site parts, Pop-ups, Components | a screen |
| Site appearance | Vzhled webu | the screen with the styles, colours, fonts, shapes, logo and icon | the group |
| look | vzhled | what visitors see: the published look, the draft look (unpublished changes of the design system, classes, menus, logo, icon), Earlier looks | a stored format |
| design system | design systém | the stored tokens behind the look (colours, fonts, sizes, spacing) – advanced only: token export, Claude, the fleet kit | the screen, a preset |
| style | styl | a preset: a ready set of colours, fonts, sizes and corner radius (the Styles tab of Site appearance) | an element's styling in the builder, which is its own Style panel |
| Ask Claude | Zeptejte se Clauda | the screen and the dashboard box where people ask Claude for work | "Requests" as a name of a place |
| request | požadavek | one item in Ask Claude – what someone asked, its status and Claude's answer | the screen |
| Trash | Koš | the deleted items of one module (News, Pages, Collections…) – each module has its own Trash, restored or emptied there; nothing is shared | a site-wide bin |
| News | Novinky | the module and the listing on the site | – |
| Settings | Nastavení | the Settings screen and its tabs | – |
| Users | Uživatelé | the accounts screen (Roles beside it) | – |

Module names are English source strings like every other UI text (`Modules\News::NAME = 'News'`, `Modules\Users::NAME = 'Users'`);
the Czech, German and Spanish names come from the admin dictionaries. Menu paths in messages ("Settings → Mail") are written
with the same source strings (`Admin\MenuPaths`), so they become links in every admin language.
