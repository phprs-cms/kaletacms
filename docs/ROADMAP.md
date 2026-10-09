# Kaleta roadmap

What is planned next. Dates are not promised; releases ship when they are tested.

## 1.2 – languages for the EU and beyond (released 25 September 2026)

- Around forty site languages; visitor texts translated into Czech, English, German, French, Spanish, Italian, Polish and
  Slovak, other languages fall back to English with dates in their own format.
- System addresses in the site language (`/news`, `/search`), old addresses redirect permanently.
- Site language chosen in the installer; imprint (Impressum) template and company fields.
- Language switcher and light / dark / device-based look with a switcher for visitors.
- Admin: distinct menu icons, chart tooltips, GitHub Sponsors link in the footer.
- 1.2.1: related content on item pages (a Collection list filtered by the shown item's field, without the item itself).
- 1.2.2: MCP accepts objects and arrays sent as JSON text (item values and builds were dropped or refused); breadcrumbs
  mark only the current page.
- 1.2.3: MCP reports unknown parameters and never resets the menu on unreadable items; Escape closes submenus.
- 1.2.4: tidy submenus and a centred mega menu panel; Escape works on every page with a submenu.
- 1.3.1: the mobile menu scrolls when it is taller than the screen; an automatic pop-up waits until the visitor closes the menu.
- 1.3.2: collections in several languages – a translated item keeps its address, a detail template per language, breadcrumbs to the translated section page; builder canvases without site pop-ups.
- 1.3.3: translating with Claude – a page translation starts as a copy of the original build, texts only for translation, the header and footer of a new language start as a copy.
- 1.3.4: a language is offered to visitors (switcher, hreflang, sitemap) only once its home page is published; MCP reads "true"/"false" sent as text correctly and reports collection item keys the collection does not have.
- 1.3.5: reading a site part over MCP creates nothing, the page list shows language addresses, and the template header loads the logo on language versions.
- 1.3.6 (security release): {{field}} values in Custom HTML are escaped, MCP tools check the user's sections, fixes from an admin review (admin language, news addresses, pop-up settings, spacing).
- 1.3.7: Language switcher element (for example in the footer), first visit in the browser's language, language filters in admin lists.

## 1.3 – pop-ups, newsletter services and a clearer Site appearance (released 26 September 2026)

### Pop-up builder

Today a pop-up is an element inside one page. 1.3 turns pop-ups into site-wide pieces built in the builder, like the
header and footer:

- **Types:** centred modal, slide-in from a corner, top or bottom bar, full screen.
- **Triggers:** after N seconds, after scrolling N %, exit intent, click on a link or button (`#popup-name`), inactivity,
  after N page views in a visit.
- **Where it shows:** all pages, selected pages, collection item pages or news, a language version, device
  (desktop / phone), date range, visitors coming from a campaign (`utm_*`) or a referring site.
- **How often:** once per visit, once per N days, until closed, never again after a form in it was sent – remembered
  in the visitor's browser, no cookies.
- **Library:** ready-made pop-ups – newsletter sign-up, lead magnet with a form, announcement bar, discount, event.
- **Accessibility:** focus kept inside, Esc closes, reduced motion respected, no pop-up covers the cookie bar.
- **Results:** views, closes and conversions (form sent) per pop-up, counted cookie-free like the site statistics.
- Claude can create and change pop-ups over MCP like other site parts.

### Site appearance, clearer

- One set of words everywhere: a **starter site** is content plus a style (chosen at installation), a **style** is a
  ready set of colours, fonts and corner radius, a **theme** is only the legacy custom PHP theme.
- Tabs instead of one long form: Style, Colours and readability, Fonts and sizes, Shapes, Dark mode, Brand, Import
  and export (W3C design tokens), with the live preview kept.
- No more styles are added on their own – a custom look is made in the design system or by Claude from the brand;
  a new style comes only together with a new starter site.

### Newsletter

Two steps: the first in 1.3, the second in 1.5.

1. **Subscribers sent to the mailing service the site already uses**. After the double opt-in the address goes to
   Brevo, MailerLite, Mailchimp, Ecomail or SmartEmailing (API key and list in the admin), or to any service through the
   existing webhook (Make, Zapier). Unsubscribing in Kaleta removes the address there too. Deliverability, bounces and
   spam rules stay with the specialist service.
2. **1.5 – a minimal built-in mailing for small lists** – “send the latest news to subscribers”, see 1.5 below.

## 1.4 – English identifiers in the code base (1.4.0 and 1.4.1 released 28 September 2026)

The code moves from Czech names to English so contributors can read it. Nothing changes for sites: stored data, build
JSON, CSS hooks of the public site, public templates, MCP tools and old admin links keep working. Terms and the list of
what stays are in [docs/glossary.md](glossary.md).

Done in 1.4.0:

1. Preparation: the glossary, `tools/rename.php` (renames by PHP tokens, refuses name collisions), old class names as
   aliases, `tools/test-update.sh` (every change is tested as an update from the previous release) and a browser test.
2. Tools and tests.
3. PHP classes, functions, constants and variables (`Kaleta\Builder`, `Admin\Modules`…); release packages carry the
   previous release's class files for the update request.
4. Admin and installer templates, admin and site scripts (`tools/rename-js.mjs`).
5. Admin URLs `admin.php?module=pages&action=edit`, old URLs redirected, permissions migrated.

Done in 1.4.1:

6. Settings keys in English (migration 0026; old keys still accepted until 2.0).
7. UI source texts in English, Czech moved to a dictionary like the other languages; code comments in English.

Next, in 1.4.x:

8. Admin CSS classes and `data-*` attributes.

## Direction after 1.4

Decided by the owner on 28 September 2026:

- **Claude over MCP is the main way to work with a site.** Most of what the admin does – create, edit and delete – has to
  work over MCP too. Enquiries stay readable over MCP.
- **Themeless:** the look of a site comes only from the design system, shared classes, components and site parts. No
  PHP themes and no custom layouts.
- **Newsletters follow the design system** instead of being built in the builder.
- **Safe redesigns:** site-wide look changes get drafts, like pages.

Order: first make the MCP path complete, then make look changes safe, then make the site portable, then make it
findable, then remove what is left of the old ways. Each step uses the previous one.

## 1.5 – newsletter mailing (released 28 September 2026)

- **One newsletter template, styled by the design system** – colours, fonts, logo and corner radius come from the site's
  tokens; no e-mail builder. The newsletter has a subject, an intro text, the latest (or chosen) news items, a button and
  the company footer. One fixed renderer writes table-based HTML with inline styles and a plain-text part.
- Preview, test e-mail to yourself, send now or scheduled. Sending in batches through the mail queue, only through an
  SMTP relay set in Settings (Brevo, Amazon SES, Mailgun…); without a relay the feature stays off.
- Background jobs run on visits, so a low-traffic site would stall a send: sending is refused unless the cron (`/ulohy`)
  ran recently, and Health shows its last run.
- One-click unsubscribe (`List-Unsubscribe`, RFC 8058), no open tracking; a send log with the date and the count only.
- Claude: `draft_newsletter` and `send_test_newsletter`; the real send only on an explicit request and with the publish
  permission, like publishing.

## 1.6 – Claude can do most of what the admin does (released 28 September 2026)

1. **Delete and restore over MCP:** news, collection items, collections, categories, pop-ups, components, saved
   sections and media; restore a page, news item or collection item from the trash; mark an enquiry handled or delete it.
   Collection items get a trash like pages. Destructive tools run only on an explicit request.
2. **Components and saved sections:** `list_components`, `save_component`; sections saved by people in the admin show
   up in `builder_schema` and `insert_section`. `update_category`.
3. **Enquiries stay available to Claude:** every read is written to the change log, and the /claude page, the FAQ, the
   guide and the privacy policy template say so.
4. **Context for Claude:** `site_info` returns the extensions, the languages (with their published state) and the cron
   state; every tool carries MCP annotations (read-only, destructive, idempotent), so clients know what to confirm.
5. **English build vocabulary over MCP:** build JSON keys and element types are English at the MCP boundary (`type`,
   `content`, `style`, `children`, `form`…). Input accepts both the English and the Czech form, output is English.
   Stored builds do not change.
6. **Parity guard:** `tools/test.sh` drives MCP by the English names, and a unit test fails when an admin write action
   has neither an MCP tool nor an explicit “admin only” entry (users, roles, keys, updates and backups stay admin only).
7. **Themeless:** custom PHP layouts are removed – no site uses one. Front templates are no longer overridable, the
   layout choice and `site_info.sablona` go away. Health warns about a custom layout folder that is still there.

## 1.7 – safe redesigns (released 28 September 2026)

1. The design system, shared classes, components, the menu and header or footer variants get a **draft**, with publish,
   discard and the last 20 versions, like page builds.
2. **One signed preview of the whole site** with the draft look and all page drafts.
3. MCP look tools write to the draft by default; `publish_look` and `discard_look`. Site appearance in the admin gets the
   same Publish button.
4. The change log shows look changes before and after; the previous look is restored with one click.
5. **Ready-made templates of site parts:** four headers, four footers and plain or extended wrappers – structure only,
   the look comes from the design system; they go into the part's draft (admin and `apply_part_template`).

## 1.8 – own and move your site (released 28 September 2026)

1. **Import of a Kaleta export:** “Start from an export” in the installer and Transfer → Import on an empty site; builds
   go through the same sanitising as any build, users and secrets are never carried. It serves host moves and agency
   starter kits at once.
2. **Backups include media:** an incremental media copy to the same FTPS or S3 target, a daily database backup when
   content changed, and one restore guide for both.
3. Page export carries its classes and components.
4. Signed webhooks (HMAC header) and a delivery log with retries.
5. The public REST API is marked deprecated: MCP is the way to integrate, webhooks carry events out, and the site export
   is the way out.

## 1.9 – findable: SEO and collections as landing pages (released 28 September 2026)

1. **Collection items as full pages:** SEO title, description, noindex, share image, versions and scheduled visibility.
2. **Site audit** in the admin and over MCP (`site_audit`): broken links across pages, builds, the menu and items, missing
   descriptions, duplicate titles, hidden pages in the menu, alt texts, the most frequent 404s.
3. Structured data per collection: Service, Person, Product/Offer, Event or FAQ.
4. The privacy policy template is generated from the enabled features – still a template with a disclaimer.
5. **Deprecation report in Health:** old class names, settings keys or helpers in custom code, pages using the old
   per-page pop-up element – a full release to react before 2.0.

## 2.0 – one clear system (released 29 September 2026)

Not new features – everything that exists is one English, builder-based system.

1. **Compatibility layers removed:** old class names, old admin URLs, old settings keys and old template helpers. The MCP
   `update_settings` keeps accepting old keys, so no connection breaks.
2. **One pop-up system:** per-page pop-up elements move to site pop-ups with a click trigger, then the element goes.
3. **The public REST API is removed** (deprecated in 1.8).
4. The hidden Czech MCP tool names stay – connections never break.

## Direction after 2.0

Decided on 29 September 2026 after an evaluation of the product and the market: Kaleta does what it set out to do, but
nobody outside kaletacms.com uses it yet, and an MCP server alone no longer sets a CMS apart. The next releases earn trust
first, then make "Claude runs your site, safely" the product, then prove business value, then serve agencies. About one
minor release a month; the [release policy](RELEASE-POLICY.md) says what stays compatible.

## 2.1 – dependable (released 29 September 2026)

1. **Release and support policy** ([RELEASE-POLICY.md](RELEASE-POLICY.md)): monthly minor releases, one-step updates from
   any version, at least two minor releases and six months between deprecation and removal, removals only in a major.
2. **Recorded public contracts** (`tools/contracts`): MCP tools and parameters, design tokens and builder elements; the
   tests fail when any of them is removed or changed.
3. **English design token names** (`--ka-color-primary`, `--ka-space-m`…) next to the stored ones.
4. **Output budget in CI** (`tools/test-lighthouse.sh`): every starter site scores 99–100 in Lighthouse. Pages without
   their own description get one from their first longer paragraph; lazy images use `sizes="auto"`.
5. **MCP layer rebuilt** on one catalog (`Mcp\Catalog`) with one method per tool; PHPStan in CI.
6. **Docker image** (`ghcr.io/phprs-cms/kaleta`) and `compose.yaml`.
7. The daily check of the update channel and the project website runs again; documentation caught up.

## 2.2 – Claude, in charge and safe (released 29 September 2026)

1. **What a connection may do:** everything the user may, drafts only, or read only – chosen on the consent screen and
   for personal tokens, never beyond the user's role. Existing connections keep full access. A connection limited to
   drafts or reading sees only the tools it may use, and publishing through it is refused before anything is saved.
2. **The change log tells a person's change from Claude's** and names the connection; Claude reads it with `list_changes`.
3. **Claude on by default** for new installations, "Connect Claude" in First steps, OAuth for sites in a subfolder
   (`/.well-known/openid-configuration` under the site).
4. **Instructions for Claude** from the site owner, given to every connection, and MCP resources and prompts (build a
   page, audit and fix, translate a page, write news, weekly review).
5. **Settings over MCP:** extensions, language versions, the cookie bar, SEO switches, analytics codes and head code.
6. The MCP protocol version is negotiated (2025-06-18, 2025-03-26, 2024-11-05).

## 2.3 – leads (released 29 September 2026)

1. **Where leads came from:** the first page of the visit, its campaign and the referring site go with every enquiry and
   newsletter sign-up – remembered for the browser tab only, with the visitor's consent to marketing (a setting, off by
   default).
2. **Statistics that answer "what brings enquiries":** pages, campaigns, first pages and sites with leads and conversion
   rates, devices and campaigns of visits, pop-up conversions – the same report for Claude (`get_stats`).
3. **Forms:** several ticked options, a hidden value. A webhook per form was left out: form fields are edited by editors,
   and webhook addresses stay with administrators; the site webhook sends the form's id for routing.
4. **Embed element** for Calendly, Google Calendar and Forms, Microsoft Forms, Tally, Typeform, Airtable, Spotify and
   SoundCloud, loaded after a click; **code in the head of one page** for administrators.
5. **Accessibility in the site audit** (European Accessibility Act): contrast, link texts, alt texts, tables, frames and
   the accessibility statement; the cookie bar respects **Global Privacy Control**.

## 2.4 – for agencies (released 29 September 2026)

1. **The admin in German** – every screen and the builder; each user picks the language in My account. Polish and
   French follow once there is demand.
2. **Handing a site to a client:** ready-made roles (Client, Writer, Enquiries only), the agency's name, contact and logo
   on the sign-in screen and at the foot of the admin, and a **Before handing over** check in the site audit (also for
   Claude: `site_audit`, `kind: handover`).
3. **A guide link on every screen:** each part of the admin and the builder opens its article in the guide on
   kaletacms.com, in the admin language.
4. Moved to Later: visitor dictionaries keyed by English text – an internal change with no visible effect, better done
   on its own than next to a new admin language.
- 2.4.1: the guide gains Media, Statistics, Privacy and cookies, and Sending e-mail, and those screens link to them;
  SMTP errors in the admin language, clearer hints for the agency logo, bulk delete in Media and Google Analytics.

## 2.5 – easy to start (released 29 September 2026)

Kaleta does what it set out to do, but few people have tried it. 2.5 makes the first install short wherever it happens.

1. **The installer in German**, next to English and Czech.
2. **No database typing on platforms:** Docker, Coolify and similar platforms set the database (`KALETA_DB_*`); the
   installer asks only for the site and the administrator.
3. **Installation without the browser:** `php install.php` for scripts;
   the container installs itself on the first start when the address and the administrator's password are set.
4. **A Coolify template** (`docker/coolify.yaml`), tested in CI like `compose.yaml`.
5. **Straight to Claude:** the last installer screen shows the site's Claude address and a first prompt to try.

## 2.6 – try it, move in, measure (released 1 October 2026)

1. **Import from any site by its address:** the pages of a site on any platform (Wix, Webnode, Jimdo, Squarespace, Joomla,
   Drupal, WordPress without an export…) become builder pages with their images, and the old addresses redirect.
2. **A public demo** of the admin at demo.kaletacms.com, reset every hour.
3. **Google Tag Manager** in one field, with Consent Mode and ready-made conversion events for campaigns.
4. **An optional CAPTCHA** for forms: hCaptcha, Google reCAPTCHA v3 or Cloudflare Turnstile, on top of the built-in
   protection.
5. **Installed like WordPress:** upload the files, create a database, open install.php. The Docker image, the Coolify
   template, the command-line installer and the database from environment variables (2.1 – 2.5) are removed.

## Direction after 2.6

Decided on 2 October 2026: the owner approved 102 items of a feature map – what business sites use on other platforms and
what Kaleta could become. They are ordered so that each release stands on the ones before: first moving sites (2.7),
then a site that runs itself (2.8), many sites as one (2.9), the business as data (2.10), content types that keep
themselves current (2.11), leads and forms (2.12), Google and CRM connections (2.13), upkeep and EU duties (2.14), Claude as
the site's operator (2.15), shared design and blocks across sites (2.16), and in 3.0 an extension API, appointment
booking and structured importers.

## 2.7 – move the sites (released 2 October 2026)

1. **Migration through two connections:** the `migrate_site` prompt – Claude reads the old site (for example through its
   Breakdance or WordPress connection) and rebuilds it here as drafts.
2. **Migration report:** every old address checked against the new site before the domain is switched – missing pages,
   pages not published yet, redirect chains, lost descriptions, forms and images – plus the checks before handing over.
   In Import and export and as `migration_report`.
3. **Old form entries** into Enquiries (`import_enquiries`).
4. **SEO plugin data** (SmartCrawl, Yoast, Rank Math) and **custom post types and fields** in the WordPress import.
5. **Builder:** a transparent header that turns solid on scroll, icons in menus, a mega menu with columns, more display
   conditions (language, URL parameter), copy and paste between sites, more scroll animations, motion while scrolling and
   hover effects.

## 2.8 – a site that runs itself (released 2 October 2026)

1. **Background jobs:** one list of what the site does by itself, run by cron or by visits, one run at a time, with the
   last run, the last success and the error of each job in System status. The installer shows the cron line.
2. **Events:** one record of what happened (enquiries, publishing, backups, updates, failed mail and webhooks, 404
   spikes, failing jobs, blocks) – read by the alert e-mails and by Claude (`list_events`, `get_health`).
3. **Alert e-mails** for warnings and errors, at most one an hour.
4. **Updates that undo themselves:** after an update the site checks itself; a server error puts the previous files back.
5. **Firewall:** blocked addresses, networks and countries, a request limit, and a day's block for probing other systems.
6. **Domain and mail watch:** SPF, DMARC and DKIM with the record to add, certificate and domain expiry – daily.
7. **Security hygiene:** unused accounts and Claude connections, connections without an expiry; suspended by a setting.
8. **Speed:** real-user Core Web Vitals in Statistics without cookies, an audit of pages that got slower, preloaded
   custom fonts.

## 2.9 – many sites as one (released 2 October 2026)

1. **Site keys:** every site has its own Ed25519 key pair, like the publisher's update signatures.
2. **Fleet console:** a Kaleta install with the extension "fleet" shows every paired site on one screen, the ones that
   need attention first, with a signed hourly report from each site and its own uptime check every 5 minutes.
3. **Staged updates:** test sites first, the rest after 48 hours without problems; security releases at once.
4. **The console cannot get into the sites** (decided on 2 October 2026): sites always call the console, never the other
   way round, and the console holds no token for any site. Claude reads the fleet on the console (`list_sites`,
   `get_site`) and changes a site through that site's own connection.
5. **Monthly report by e-mail** for each site's owner, with the agency's branding.
6. Fixed: automatic security updates now run; alert e-mails are in the site's language.

## 2.10 – the site knows the business (released 2 October 2026)

1. **Business facts:** typed facts written once and used everywhere as `{{fact.key}}` – in pages, site parts, pop-ups,
   components, news, buttons and links; facts with a schema.org property join the organisation; llms.txt lists them.
   Computed facts (`{{years_since:2004}}`, `{{count:references}}`) never go stale.
2. **Claims inventory:** sentences that state numbers as plain text, and – after a fact changes – every sentence that still
   states the old value. The site audit flags unknown facts and proof numbers typed in by hand.
3. **Opening hours with exceptions:** holidays and shorter days, open now (`{{hours.status}}`), today's hours, a notice bar
   ahead of an exception, the exceptions in the structured data, and a printable door sign.
4. **People and links between collections:** a field that links an item to an item of another collection (a person to a
   branch, a reference to a service), a ready-made team collection, and the page of a person who left leads to the team
   page instead of a 404. E-mail signatures from people records.
5. **True until and review by** on pages, news, collection items and pop-ups: they hide themselves or ask for a review.
6. **Collection list filters** without reloading the page, with the names of linked items.
7. 2.10.1: tokens inside `<code>` and `<pre>` are examples and are never filled in (guides, documentation).
8. 2.10.2: no red “update source is not reachable” right after an update; a failed check is asked again after an hour.

## 2.11 – content types that run themselves (released 2 October 2026)

1. **Ready-made collections:** a gallery of presets – team, branches, services, products, references, price list, events,
   FAQ, job openings, machines, courses, documents and an official notice board – each created in one click with its
   fields, item pages, structured data and a hidden page that lists it. New field types: date and time, file, location,
   choice, parameters and variants. The Collection list shows upcoming, current or past items by their dates.
2. **Events calendar:** a repeating event moves to its next date by itself, iCal for the whole calendar and each event,
   registration that closes when it is full or over, past events in an archive.
3. **Product catalogue without a checkout:** parameters to compare side by side, variants, datasheets and an enquiry
   basket the server checks against the products.
4. **Job openings that close themselves:** JobPosting with the closing date, an application form with a CV, applications
   deleted after a set number of months.
5. **Document library:** versions kept, a stable address of the latest file, download counts, expiry; a form can send a
   file by e-mail after the sign-up.
6. **Branches and a store locator:** LocalBusiness data for each branch, a list with search, the nearest branch and a map
   that loads only after a click.
7. **Official notice board:** posting and takedown dates, a permanent archive and an append-only audit trail.
8. **Screen mode** for a reception or showroom: news, upcoming events, today's hours, rotating on their own.
9. **Industry blueprints:** a package with the collections, facts, questions, audit checks and instructions for Claude a
   kind of business needs – clinic, manufacturer, craftsman, driving school, farm, municipality – applied in the admin or
   over MCP, and the current site exported as one.

## 2.12 – leads and forms (released 3 October 2026)

1. **Multi-step forms and quote calculators:** steps, fields shown by another answer, and a price estimate the visitor
   sees live and the server computes again.
2. **Enquiry triage:** a kind, a priority and a drafted reply for every enquiry – by a rule, by Claude or, when switched
   on, by the AI assistant; a person's sorting wins and nothing is sent by itself.
3. **Forms that know where they are** (the service, product or page an enquiry came from) and **a thank-you with next
   steps** – when you reply, counted in your opening hours, and who.
4. **Calls, e-mail and WhatsApp clicks as leads**, per page and cookie-free.
5. **Testimonial requests with consent:** a personal link; the answer arrives as a hidden draft reference.
6. **New elements:** pricing table, before/after slider, hotspots, timeline.
7. **Automatic share images** from the title and the brand colours.

## 2.13 – connected to Google and the CRM (released 3 October 2026)

1. **Connections:** a curated list of outside services (Google, Bing Webmaster Tools, HubSpot, Pipedrive, Raynet) with
   encrypted credentials, OAuth with PKCE, a rate limit, a delivery queue with retries and a log without content.
2. **Google Business Profile:** the opening hours and their exceptions go out, news can become posts, reviews and the
   rating come back – with a Google reviews element and two facts.
3. **Search data in Statistics:** Search Console and Bing queries, pages and sitemap counts, daily, also for Claude and
   in the monthly report.
4. **Enquiries to a Google Sheet and to the CRM** (HubSpot, Pipedrive, Raynet), per form, job applications only on request.
5. **Social post drafts** for every published news item – edited, copied and posted by a person, never by the site.

## 2.14 – clean and compliant without effort (released 3 October 2026)

1. **Links that look after themselves:** a changed address rewrites the site's own links; 404s get a suggested target
   and, opt-in, a redirect by themselves; orphan pages and broken links across the site, for Claude to fix as drafts.
2. **Content hygiene:** media clean-up (unused, duplicate, oversized; alt texts in bulk), a content check in the editor,
   a translation overview and bulk editing in lists.
3. **EU duties as templates:** a cookie scanner with a `{{cookie_table}}`, anonymising enquiries instead of deleting
   them, a record of processing, an accessibility statement from the audit and an accessibility toolbar for visitors.
4. **Personal data requests:** find, export or erase everything about one e-mail address.
5. **Whistleblowing channel:** encrypted reports with a case number and a code, follow-up, appointed readers and the
   legal deadlines – never over MCP.
6. **Password-protected pages** – never cached, indexed or searchable.

## 2.15 – Claude as the site's operator (released 3 October 2026)

1. **Requests for Claude:** staff write requests with attachments in the admin; Claude does them as drafts and answers
   with notes and links; the requester gets an e-mail when it is done.
2. **Comments on drafts:** a shared preview can take comments on elements – no account, the link is the permission.
3. **Every change says why:** write tools take a reason, kept in the change log.
4. **Guardrails for Claude:** an hourly change limit, protected pages, no deleting – for every connection.
5. **Agent notebook:** decisions, wording rules, credits and history, read and written in the admin and over MCP.
6. Moved to a later release: undoing a whole agent session and scheduled agent runs.

## 2.16 – shared across your sites (released 3 October 2026)

1. **Shared design kit:** the fleet console publishes versions of its design system, classes, components and saved
   sections; member sites that opt in receive each version as a draft look, component drafts and library sections –
   never published by themselves, signed and verified, without custom code.
2. Shared blocks are covered by the same kit: a component kept on the console arrives on the sites as a draft.

## 2.17 – the rest of Claude as the site's operator (released 3 October 2026)

1. **Undo a whole Claude session:** every content row a session changes is kept before and after; Change log → Claude
   sessions takes it back in one step, leaving rows people changed since unless told otherwise.
2. **Scheduled runs:** the site keeps schedules (review, report, triage, requests, custom), a Claude routine asks what is
   due and reports back, drafts only; missed runs raise an alert.
3. **The admin on phones:** tabs on one scrolling row, one header row, the menu as a sheet.

## 3.0 – opening up (released 3 October 2026)

1. **Add-ons and the extension API:** code from other developers in `extensions/<slug>/`, hooked in through a versioned
   API (events, filters, tokens, admin pages, tools for Claude, jobs, settings), switched on by an administrator who trusts
   it, switched off by itself when it fails. A recorded contract that holds through 3.x (docs/EXTENSIONS.md).
2. **Online booking of appointments:** services, people with their hours and days off, free times that respect breaks,
   buffers, lead time and daylight saving, no double booking, confirmations, cancel links, reminders; no payments.
3. **Structured importers:** a common base with a preview, a mapping and a batch runner; Ghost, Blogger, Joomla, Drupal and
   Webflow next to the WordPress importer.

## 3.1 – ask Claude from the dashboard (released 3 October 2026)

1. **Ask Claude:** a box on the dashboard whose text becomes a request for Claude (with attachments, the title from its
   first sentence), example requests that depend on the sections the person may open, the person's latest requests, and
   whether a scheduled run picks requests up. "Copy for the Claude app" copies the text with the site's address.
2. Not a chat inside the admin: the site still never runs Claude and holds no Anthropic key (decided on 3 October 2026).
3. 3.1.1 (after a UI/UX and a product review): the requests and scheduled-run prompts match what a drafts-only connection
   may call – what it may not save goes into the note as a proposal, checked by a test; Ask Claude knows whether Claude is
   connected and stays out of the demo, "Connect Claude" is the first step; the Client and Enquiries only presets can ask
   Claude; Whistleblowing only for its readers; confirmations name the action and are red when dangerous; own icons for
   seven sections; the sidebar keeps the current section in view; a skip link; the Scheduled runs page no longer
   overflows; German dates, "Fakta", "Menü"; Czech texts left in the English admin translated.

## 3.2 – one admin, grouped by job (released 4 October 2026)

After a UI/UX and a product review; owner decisions of 4 October 2026. Idents, URLs, settings keys and MCP names stay.

1. **The menu by job:** Content · Company · Customers · Appearance · Claude · Site care · Administration. Hubs keep related
   screens behind one item with tabs: **Business details** (company and opening hours, facts, claims, blueprints – open to
   editors, the legal identifiers stay with administrators), **Claude settings** (connecting, instructions, guardrails,
   every connection; Ask Claude, scheduled runs, the notebook and the sessions as tabs), **Features** (with Add-ons).
   System status is its own screen; old Settings tab addresses redirect.
2. **Drafts-only Claude does more:** it saves hidden collection items, proposed opening-hours exceptions that a person
   applies, triage suggestions and notebook entries. Facts stay with full access.
3. **Waiting for you** on the dashboard: everything that waits for a person – drafts of pages and site parts, news drafts,
   hidden items, proposed hours, finished requests, comments on drafts, the draft look. `list_pending_review` and the
   prompt `review_pending` for Claude.
4. **Bookings and Whistleblowing are features**, off on new installs and kept on where they are used; Statistics has one
   switch (the feature).
5. **Clearer names:** Ask Claude, Integrations (with cards for webhooks, the mailing service and analytics), Features,
   Blueprints, Writing assistant (your own key); "Claude never runs on the site". Calmer Integrations and Import and
   export screens; First steps suggest a blueprint.
6. 3.2.1 (found while retaking the screenshots): dates in words in the English and German admin no longer show Czech
   month names; hidden steps of multi-step forms and the booking element's fallback field stay hidden, and radio buttons no
   longer stretch to the full width; "Select parent element" and Esc in the builder select the parent again (broken since
   1.4); the last messages that still said "Extensions" or "AI assistant" say Features and Writing assistant.
7. 3.2.2: the booking calendar no longer stays on "Loading…" after a day is picked (the month and the free times shared
   one request counter since 3.0); picking a day marks it without reloading the month, and a late answer for another day
   or service is dropped. A browser test books through the calendar.
8. 3.2.3 (owner decision of 4 October 2026): with Bookings switched off, the cancel page and the .ics file from the
   e-mails keep working, so people who booked can still cancel; new bookings and free times stay off.

## 3.3 – blueprints for more kinds of business (released 5 October 2026)

1. **Twenty blueprints:** next to the six of 2.11 – a software / SaaS company, an agency, IT services, a law firm,
   accountant or consultant, a real estate agency, a garage, a photographer or creative freelancer, a restaurant or café,
   a hotel or guesthouse, a shop or showroom, a beauty or wellness salon, a gym or studio, a language school or course
   provider, a non-profit or club. Each asks, tracks and checks – it never invents prices, clients or legal facts.
2. **Four ready-made collections** they need: pricing plans, a food and drink menu, rooms and accommodation, property
   listings.
3. **For any other business:** the prompt `draft_blueprint` – Claude asks the owner, drafts a manifest, shows it and applies
   it only when the owner agrees; the Blueprints screen sends the same request to Claude, and `get_blueprint` lists what a
   manifest may contain.
4. **The Blueprints screen** groups them by kind of business (Services and trades, Health, sport and learning, Food and
   stays, Shops and production, Software and agencies, Public and non-profit), has a search and asks before applying.
5. 3.3.1: each card of the pricing plans has a "Choose this plan" button to the plan's link (none when it has no link).
6. 3.3.2 (security, after the audit of 6 October 2026): imported content and the sanitizers never turn attribute text into
   markup and imported pages never become Custom HTML; user attributes cannot take over the site's script hooks; a fact
   used as a link is checked as a link; add-on tokens are filled only in authored content; gtm_id and Matomo are no
   longer settable over MCP; whistleblowing reports are rate-limited and erased personal data leaves the undo journal;
   smaller hardening of guardrails, page passwords, extensions/, the fleet, add-on tools, updates and admin tokens.
7. 3.3.3 (security, after the audit of 7 October 2026): facts in link attributes are filled tag by tag and a text fact
   cannot start with a script scheme; one shared check pins every outgoing request to a public address (encoded and IDN
   hosts, IPv6, NAT64); imported settings are validated and content imported before 3.3.2 is checked again; sign-in gives
   one answer for locked, blocked and wrong, counts behind the proxy and per IPv6 /64, and sessions end after 8 hours idle
   or 24 hours; e-mail and passkey changes need the password; no third-party CAPTCHA on the reporting channel, reports in
   a flood are accepted and flagged; the right page password always opens the page.
8. 3.3.4 (security, after the audit of 8 October 2026): the Claude sign-in (OAuth) approves only the request the person
   saw; the consent screen leads with where the app returns, warns for hosts that are not Claude's, marks apps never
   approved and pre-selects drafts only for foreign hosts; an optional setting allows only Claude's own apps; a code or a
   refresh token is redeemed once (a 30-second retry of the same refresh gets the same pair, a later reuse revokes the
   app); unused registrations are removed after a day. Existing connections keep working.

## 3.4 – contributions from the community (released 8 October 2026)

The first release with pull requests from an outside contributor, Christian (czepter), each reviewed, tested and completed:

1. **Configurable news address** (#11): `/blog`, `/aktuality` or any slug instead of /novinky or /news, with 301s from the
   old addresses and from earlier slugs, redirects that keep working under the slug, and the system addresses reserved.
2. **Preferred URL form** (#19): without a trailing slash, with one, or .html – the other forms redirect; Claude's
   connection, OAuth discovery, the cron and links in e-mails are never redirected.
3. **German informal address** (#21): du alongside Sie, chosen separately for the admin and for the website.
4. **Tentative bookings** (#22): a service can require the provider's confirmation – accept, decline with a message or
   propose other times; the slot is held meanwhile.
5. **Claude in English** (#13): MCP results, summaries, server and OAuth errors are English on every site.

Fix:

6. 3.4.1: the builder's Add panel inserts elements again, by click and by drag (broken since 1.4.0: a new element was
   built under keys the builder does not read); moving an element into another section works again too.
7. 3.4.2 (security, after the audit of the 3.4 contributions on 8 October 2026): the preferred URL form (#19) could be
   used as an open redirect (a request for //host/… was sent to another site); the booking tools that e-mail the customer
   (confirm_booking, propose_booking_times) stop at the "no deleting" guardrail for Claude; choosing a proposed time twice
   no longer sends a second confirmation.

## 3.5 – the first hour (released 8 October 2026)

The first release of the 30-day plan: what a new owner meets in the first hour, from the UX review of 8 October 2026.

1. **No empty live pages:** a new page with nothing to show (from a template, or over MCP) stays hidden and out of the
   navigation until its first content is published, then goes on the site as chosen; a publish date wins.
2. **Connect Claude first:** until Claude has connected, the dashboard leads with the address (with a Copy button), three
   steps and a warning when the site is not on HTTPS; the address has a Copy button wherever it is shown.
3. **Bookings set-up:** a three-step card (person, service, a Book page), Mon–Fri 9–17 for a new person on a site without
   opening hours, and a warning when visitors would find no free time in the next 14 days. **Hours per service** for one
   person (#24, Christian).
4. **Site appearance:** the preview shows the saved draft look; look changes read "Modern sans-serif → Rounded", with
   colour swatches.
5. **English everywhere it should be:** about fifteen Czech leftovers in English installs fixed (home page address,
   examples, a mail error, the news address), no country claimed unless set, and a check for Czech text in PHP sources.
6. **Cookie bar:** compact on phones (147 instead of 340 px), reached right after the skip link, never hides the
   keyboard focus; the categories really stay behind Settings; a descriptive link text.
7. **Accessibility and speed:** the accessibility toolbar opens above its button, valid Countdown and footer markup,
   SVG logos with a size, the first image of a page loads first; axe runs in the browser test.
8. **Navigation:** "Also on tablets" puts a long menu behind the button up to 1023 px.
9. **Tests and CI:** the site's clock instead of the database clock, pinned scanner images, no more flaky pipes or
   leftover servers; "Writing tests that pass in CI" in CONTRIBUTING.

## 3.6 – migration I (released 8 October 2026)

The second release of the 30-day plan: what moving the owner's WordPress sites needs first.

1. **WordPress import over MCP** (`import_wordpress`): Claude imports a WordPress export (uploaded privately, from an
   address, or from storage/import) in resumable batches; everything arrives hidden; WordPress **menus** come into the
   draft look with links to the new addresses; authors map to users with the same e-mail; pages laid out with
   Breakdance, Elementor, Oxygen or Divi are reported. The admin import and MCP share one code path, and the admin can
   import everything hidden. Redirects never take over an address the site already uses.
2. **Redirects for migrations:** prefix and wildcard rules (`/blog/*` → `/news/*`), **410 Gone**, a CSV import with a
   preview (up to 5,000 rows, Excel and the WordPress Redirection plugin), and `save_redirects` for up to 500 rows a call.
3. **Header and footer variants by rule:** for news items, the news list, items of chosen collections and pages under a
   parent – not only for listed pages.
4. **Dark mode you can read:** primary and secondary colours of their own in dark mode, computed for contrast and
   adjustable; the readability check covers links on surfaces and the focus ring.
5. **Menus for touch and keyboard:** submenus are real disclosures (tap on tablets, Enter/Space/Esc), the phone menu is an
   accordion.
6. **Builder pack:** a one-line bar for unpublished look changes and "Save and publish menu", a heading level control and
   no second H1 from ready-made sections, a compact top bar, an image field with a thumbnail, and the inspector follows
   typing on the canvas.
7. "Support Kaleta" in the admin footer leads to kaletacms.com/why-free.

Fix:

8. 3.6.1 (security, after the audit of 9 October 2026): the SVG upload cleaner checks every attribute – two attributes
   sharing a local name (`onload` and `x:onload`, `href` and `xlink:href`) let the second one through unchecked.

## 3.7 – migration II (released 9 October 2026)

The third release of the 30-day plan: what the owner's first five sites need before they move from WordPress.

1. **PHP 8.3:** the minimum drops from 8.4 to 8.3 (`KALETA_MIN_PHP`). On 8.3 Kaleta loads its own HTML5 parser,
   serializer and selector engine (`system/compat`, `Kaleta\Compat`) behind the PHP 8.4 `Dom\` API; 8.4 and newer use
   PHP's own. Release manifests carry `min_php`, and a site is never offered a release its server cannot run.
2. **Many items at once:** CSV/JSON item import with a preview (up to 5,000 rows, images fetched afterwards, everything
   hidden), `save_collection_items` (up to 200 items a call, dry run), and the site import up to 3,000 addresses with
   robots.txt and a pause between requests.
3. **Collection categories:** nested categories with landing pages of their own, a Category page template in the
   builder, the Collection list of categories, a Previous / next item element, and a per-form attachment limit.
4. **English system addresses:** `/tasks`, `/subscription`, `/form`, `/consent`, `/conversion`, `/status.json`; the
   Czech addresses stay as aliases.
5. **Safer by default:** batch tools and imports count every row against Claude's hourly change limit, reserved before
   the call so parallel calls cannot overshoot it; no page or item can take a system or category address; robots.txt,
   sitemap and import-file limits; anchored identifier patterns all use `/D`.
6. The 4.0 data model is designed (`docs/design/4.0-data-model.md`); building it follows the 30-day plan.
7. PHP 8.3 with the per-function JIT (`opcache.jit = 1235`) crashes on builder pages – an engine bug of 8.3; System status
   names that mode and asks for the default `tracing`. The compat parser avoids the `SplObjectStorage` methods PHP 8.5
   deprecates.

## 3.8 – reach (released 9 October 2026)

The fourth release of the 30-day plan: Kaleta where Claude users look, and a calmer update rhythm for those who want it.

1. **Ready for the Connectors Directory:** every MCP tool has a human-readable title and the hints a client needs
   (read-only, destructive, idempotent, open-world – the open-world list now by English name, so a Czech alias gets the
   same hints); the server introduces itself with a title, website and icon. Additive only: names, parameters, Czech
   aliases and the read-only/destructive hints are unchanged, and the contract check now refuses any change to them.
   OAuth accepts a loopback redirect on any port (RFC 8252), as Claude Code needs. `docs/connectors-directory.md` is the
   submission package, `docs/server.json` the MCP registry entry.
2. **Release channels (D3):** Settings → Backups and updates offers Latest (a minor every week, the default for every
   site) or Stable (security fixes, a new minor about once a month). Stable reads a second signed manifest
   (`aktualizace-stable.json`), never offers a downgrade and says when a site is ahead of it; System status, the
   monthly report, MCP and the fleet heartbeat name the channel. `tools/release.php --channel=stable --package=…`
   publishes it.
3. **Limits before parsing:** every place that parses HTML, SVG or XML first checks size (5 MB), nesting (512), elements
   (50,000) and attributes (256) in one linear pass (`Core\HtmlLimits`), on every PHP version. Oversized input is
   refused with the measured value – in the admin, over MCP and in import reports – and never passed through.
4. **Kaleta for Claude:** a plugin with five skills (migrate from WordPress, launch a site, weekly care, set up bookings,
   compliance check) in `integrations/claude-plugin`, built by `tools/build-plugin.php`; a unit test keeps every tool
   name in the skills real.

Audited before release (N38): the HTML pre-scan is linear on every input, the check and the parser always read the same
UTF-8 text, a loopback OAuth port is a plain number, and the weekly-care skill prepares live edits for approval first.

## 3.9 – migration III (released 9 October 2026)

What the owner's multilingual WordPress sites need before they move, and the first 3.x steps of the 4.0 data model.

1. **The same address in every language version** (4.0 design §3 step 1): Settings → General, "The same address in every
   language" lets a Czech and an English page, news item, category or collection item share a slug. Off by default (no
   change for existing sites); switching it on drops the global unique keys for that site, switching it off is refused
   while versions share a slug. Migration 0083 also widens every language column to `VARCHAR(35)` ascii (§2 step 1), and
   every language pattern goes through `Core\Language`.
2. **Polylang and WPML imports:** a WordPress export arrives as linked language versions – pages, posts, categories,
   collection items and menus in their own version, translations linked, missing languages added, old `/en/…` and
   `?lang=` addresses redirected, and slug clashes listed in the report when slugs are not per language.
3. **Cookie bar per language (UXM-11):** the text and the policy link of the cookie bar can differ per language version.
4. **Mail services in Settings → Mail:** Brevo, Mailgun, Amazon SES, Postmark, SendGrid, MailerSend, SMTP2GO, Mailjet or
   Mandrill fill the server, port and encryption, say what the user name and password are, and which DNS records to add;
   an existing configuration stays as it is.
5. **English query parameters** (PR #16 by czepter): Kaleta writes only English parameter names; the old Czech ones stay
   as aliases (`Request::LEGACY_QUERY`), so links in e-mails, shared previews, bookmarks and cached forms keep working.
6. **Signed update manifests v2** (N38-3, N37-4): a second signature covers every field a site acts on – the channel,
   the PHP requirement, the address and the changes; the v1 signature stays for sites up to 3.8. A 3.9 site also reads the
   PHP requirement from the signed package itself before writing a file.
7. **Claude connections last a year:** an OAuth refresh token lives 365 days, renewed with every use (owner decision).

Audited before release (N39): a new password ends the user's Claude connections, a multilingual import adds language
versions only when asked, the WordPress header has a memory cap, and switching per-language slugs off never ends half-done.

Fix:

8. 3.9.1: the phone menu of the Navigation element keeps its rows close together (the bar's gap belonged to wide screens) and
   every submenu arrow in the same place; "Support Kaleta" in the admin footer, the README and the GitHub Sponsor button lead
   to Buy Me a Coffee (https://buymeacoffee.com/Kaletacms).

## Not planned

- A second e-mail renderer or an e-mail builder; campaign features (segments, automations, A/B tests, open tracking).
- A chat with Claude inside the admin that runs on the site's own Anthropic key (decided on 3 October 2026) – "Ask Claude"
  hands the work to Claude through requests instead.
- Multisite in one installation, e-commerce, memberships, a visitor-facing AI chat, a headless or GraphQL API, real-time
  co-editing.
- More style presets, approval workflows, PHP themes.
- A fleet console that reaches into sites – remote commands or Claude tokens held by the console (decided on 2 October 2026).
- Reversed on 2 October 2026 and delivered: form logic (2.12), appointment booking, structured importers and an
  extension API (3.0). Add-ons are installed by copying a folder – never uploaded or downloaded by Kaleta.

## Later

- Visitor dictionaries keyed by English text, like the admin ones (moved from 2.4).
- Legal text templates with a clear disclaimer (privacy policy, terms, cookie policy) per country.
- Right-to-left languages.
