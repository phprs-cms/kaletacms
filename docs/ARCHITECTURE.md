# Architecture

A short map of Kaleta for contributors: how a request runs, which layer code belongs to, and where to look. The rules for
day-to-day work are in [CONTRIBUTING.md](../CONTRIBUTING.md); the database is described table by table in
[schema.md](schema.md).

Kaleta is plain PHP (8.3+) with no framework and no Composer: a PSR-4 autoloader in `system/bootstrap.php` maps
`Kaleta\X\Y` to `system/src/X/Y.php`. MySQL 8 / MariaDB 10.6+ through PDO, server-rendered HTML, a little vanilla
JavaScript, no build step.

## How a request runs

| Entry | What runs | Answer |
|---|---|---|
| `index.php` | `Core\App::boot()` → `Front\Kernel::handle()` | the public site, feeds, `/mcp`, OAuth, forms |
| `admin.php` | `Core\App::boot()` → `Admin\Kernel::handle()` | the administration (`?module=…&action=…`) |
| `install.php` | `Install\Installer::handle()` | the installer of a new site |
| `/mcp` (via `index.php`) | `Mcp\Server` → `Mcp\Tools` and its handlers | the Claude connection (JSON-RPC) |
| cron `/tasks` | `Core\Scheduler` | background jobs; without cron they run after visits |

1. **Boot.** `App::boot()` reads `config.php`, opens the database lazily (`Core\Db`), applies the site's time zone and the
   language. Settings come from `ka_nastaveni` through `Core\Settings` on first use (large values only when asked for).
2. **Public site.** `Front\Kernel::handle()` checks stored redirects, maintenance and the firewall, answers a cache hit from
   `Core\PageCache` (anonymous visitors only), then routes by path through the rows of `Front\Kernel::ROUTES` (3.50): the home page, `/novinky/…` (the news slug is a setting),
   collections, pages by slug (`Modules\Pages::RESERVED_SLUGS` are taken), files like `sitemap.xml` or `llms.txt`. A page is
   a build (JSON tree) rendered by `Builder\Build::render()` inside the site parts (header, footer) into
   `system/views/front/base.php`; SEO tags and structured data come from `Front\Seo`.
3. **Administration.** `Admin\Kernel` signs the user in (`Core\Auth`: password, TOTP, passkeys), checks the CSRF token
   and the section rights, and calls `actionX()` of the module class in `Admin\Modules\…`; views are plain PHP in
   `system/views/admin/`. Every POST that can change the site clears the page cache.
4. **After the answer.** `index.php` and `admin.php` send the response first, then hand queued work to the background:
   webhooks, mail (`Core\Mail` queue), contact linking, scheduled publishing (`Core\Notifications`) and updates
   (`Core\Updater`).

## Layers

Each namespace may call only the ones listed for it (the rule and today's exceptions: [design/code-layers.md](design/code-layers.md),
kept by a unit test).

| Namespace | What it is | May call |
|---|---|---|
| `Compat` | HTML5 parser for PHP 8.3 | – |
| `Core` | database, settings, the rules of the data (news, CRM, bookings, imports, reports, updates) | Compat, Connectors, Extension |
| `Connectors` | adapters of outside services (Google, CRMs, Bing) | Core, Compat |
| `Extension` | the add-on API (`Extension\Api`) | Core, Compat |
| `Import` | structured importers | Core, Compat, Builder |
| `Builder` | builds, elements, design system, collections, components | Core, Compat, Connectors, Extension, Front |
| `Front` | the public site: routing, rendering, cache, forms | Core, Compat, Connectors, Extension, Builder |
| `Fleet` | the fleet console and the site's heartbeat | the above but Mcp, Install, Admin |
| `Mcp` | the MCP server, its tools and translators | everything but Admin and Install |
| `Install` | the installer | Core, Compat, Builder, Front |
| `Admin` | the administration | everything |

A rule of the data belongs in **Core** even when only one screen uses it today – MCP, the background jobs and the
importers will need it too. An admin module reads the request, calls Core and renders.

## Where things live

| What | Where |
|---|---|
| Classes | `system/src/<Namespace>/` |
| Views (admin, site, e-mail) | `system/views/` |
| Schema of a new site / migrations | `system/sql/schema.sql` / `system/sql/migrace/NNNN-name.sql` (or `.php` for a data migration) |
| English names of tables and columns | `system/sql/names.php` (`Core\Names`), rendered into [schema.md](schema.md) |
| Dictionaries | `system/jazyky/<code>.php` (site), `admin-<code>.php`, `install-<code>.php`; admin scripts `image/jazyky/admin-<code>.js` |
| Admin CSS and JS | `image/admin.css`, `image/admin.js`; the page builder `image/stavitel.js` + `stavitel.css` |
| Site CSS and JS | `image/sablona.css` (frame), `image/web.css` + `image/web.js` (shared elements) |
| MCP contract | `tools/contracts/*.json` (`php tools/contracts.php` – changes must be additive) |
| Job applications | `Core\Applications` (intake, reading, status and deletion) with `Core\ApplicationFiles` (the sealed CVs), `Core\ApplicationRetention` (deadlines and the daily clean-up), `Core\ApplicationReplies` (templates and replies), `Core\ApplicationMove` and `Core\ApplicationCounts`; the old names stay as forwards on `Core\Applications` (3.47) |
| Quotes | `Core\Quotes` (identity and rights, reading, saving, the customer block, the status life cycle and the snapshot) with `Core\QuoteLines` (totals, discounts and one line as stored), `Core\QuoteNumbers` (the number format and the counter per year) and `Core\QuoteIntake` (the lines of an enquiry and the prepared draft); the old names stay as forwards on `Core\Quotes` (3.49). Enquiry reads carry `Core\EnquiryVisibility::visible()` |
| Replies and attachments | `Core\ReplyMail` (one sender: Reply-To, signature, quotation), `Core\SavedReplies` (the firm's texts per language), `Core\ReplyAttachments` (files a person chooses, never queued) and `Admin\AttachmentBox` (3.49) |
| Choosing by search | `views/admin/pick.php` (an ordinary select or input with `data-pick`) and the "choosing by search" block of `image/admin.js`; the read-only `search_json` actions of the modules answer (3.49) |
| Files on a record | `Core\InternalFileLinks` – the only class naming `ka_internal_file_links`, with the one access rule (3.49) |
| Rules that Core took over in 3.50.1 | `Core\Routes::RESERVED_SLUGS` and `slugReserved()`, `Core\Clock::edited()` (page slugs and the edit stamp), `Core\EnquiryVisibility::NOT_SPAM` and `Core\EnquiryFiles::delete()` (enquiry spam rule and attachment clean-up), `Core\RedirectStore` (`twin`, `add`, `takeOver` – every redirect writer), `Core\StatsGate` (`isOn`, `enabled`, `isBot`, `BOTS`); the old names on `Admin\Modules\Pages`, `Enquiries`, `Redirects` and `Front\Stats` stay as one-line forwards (unit 4632) |
| Quote baskets and the price list | basket lines only from the basket field of the published form (`Products::basketLabels`, smoke 3900); a collection is mapped to the price list only in Quotes → Price list settings (`setup`); the office's first steps are `views/admin/first_steps.php` |
| Claude's limits | the hourly limit of changes counts per user (all of their connections); undo goes by connection name and user (`AgentJournal::connectionLabel`) |
| Add-ons of other developers | `extensions/<slug>/`, talking only to `Kaleta\Extension\Api` ([EXTENSIONS.md](EXTENSIONS.md)) |
| Tests | `tools/tests/unit/` and `tools/tests/smoke/` ([README](../tools/tests/README.md)), browser suite `tools/test-browser.sh` (step files in `tools/tests/browser/`, 3.49) |

## How to add …

- **An admin module:** a class in `Admin\Modules\` (extends `Admin\Module`), its views in `system/views/admin/<module>/`,
  an entry in the menu and the section rights (`Users::defaultModules()`), and the same ability through MCP (the parity
  map in the unit tests lists every admin action).
- **An MCP tool:** the handler and its schema row in one `Mcp\Handlers\…` trait (`<trait>Definitions()`, built with
  `Mcp\Schema`; the name in `Tools::ORDER`), the English description in `Mcp\Translator::TOOLS`, the annotations in
  `Mcp\Catalog`, a contract entry (`php tools/contracts.php --update` records `mcp-tools.json` and `mcp-definitions.json`),
  and never in the catalogue's essentials. Output keys are additive only.
- **A setting:** a key with its default in `Core\Settings::DEFAULTS`, a row in `Core\Settings::DECLARATIONS` (3.50: `tab` and
  `type` for a form field, and the flags `secret`, `export`, `mcp`, `per_language`, `lazy` that are true for it) and, with a tab,
  a `$field(...)` row in `system/views/admin/settings/<tab>.php`. Code asks `Settings::fields()`, `keysWith(flag)` and
  `claudeMaySet(key)`; the old lists (`Vault::SECRET_SETTINGS`, `SiteExport::SETTINGS`, `Settings::PER_LANGUAGE`, `LAZY`) stay
  only as mirrors for code of older releases during an update, held equal by unit 4541 (`php tools/settings.php <key>` shows all).
- **A public route:** after the page cache, a row `[method, exact path or anchored pattern, handler]` in
  `Front\Kernel::ROUTES` and a private `route…()` method that returns `null` when the address is not its own (feature off,
  wrong token), so the next row gets a turn. Rows run top to bottom; unit 4531 holds the full ordered list of handlers, so a
  new row means a new line there, and a one-segment path joins `Routes::NEWS_RESERVED`. The gates before the cache (redirects,
  firewall, maintenance …) stay as code in `handle()`.
- **A migration:** the full change in `schema.sql`, a new file in `system/sql/migrace/` with the next number, and
  `KALETA_DB_VERSION` raised in `system/bootstrap.php`; then `php tools/schema-doc.php` and `tools/test-migrations.sh`.
  Write it for MySQL and MariaDB alike.
- **A builder element:** a class in `Builder\Elements\` registered in `Build::ELEMENTS`, its content fields, allowed tags
  and base CSS; an English entry in `Mcp\Vocabulary`.
- **A new kind of task target:** `Inbox::TARGETS`, `Inbox::parse`, `Tasks::cleanTarget` and `sweep`, `Assignment::KINDS`
  and its label in `my_work` (a unit test checks all of them).
- **An interface text:** `t('English text')` and the translations through `tools/add-translations.py`.

## Czech and English

Kaleta started as a Czech project. Since 1.4 all identifiers, comments and new interface texts are English; what stays
Czech is the data model that installed sites and MCP connections depend on: older table and column names, the keys of a
build's JSON and of the design system, a few setting values (`dark_mode` = `vypnuto | auto | tmavy`), file names in
`image/` and the admin's CSS classes and `data-` attributes. They are never renamed. New tables, columns, settings,
classes and attributes are English. At the MCP boundary `Mcp\Translator` and `Mcp\Vocabulary` translate the Czech names to
English and back, so Claude sees an English interface. For the meaning of a Czech column see [schema.md](schema.md); for
product terms see [glossary.md](glossary.md).
