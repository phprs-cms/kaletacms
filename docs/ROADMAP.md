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
9. 3.9.2 (security, after the daily audit of 10 October 2026): a Kaleta export can no longer place a media file with a script
   extension inside its name (photo.php.jpg); media/.htaccess and the nginx example block such names, migration 0084
   brings the new media/.htaccess to existing sites (N67); the nginx example closes extensions/ outside public/ (N68).
   With it the fixes found while moving a real site (SVG colours, relative media, {{obrazek}}, collection checks, max
   width, links in dark bands, hidden categories) and presets written in the site language.

## 3.10 – lighter Claude connections, regional languages, fresher updates (released 11 October 2026)

1. **Essentials tool set** (owner plan, item 1): a new Claude connection can list 36 essential tools (about 49 KB instead
   of 155 KB for all 136). `find_tools` finds the rest; three runners run them – `run_read_tool` (reads inside the site),
   `run_tool` (drafts and changes), `run_destructive_tool` (removing, sending, reaching outside; Claude confirms first) –
   through the same access, guardrails, change log and undo. Chosen on the consent screen, switchable in My account;
   existing connections keep all tools (migration 0085).
2. **Safe steps towards the 4.0 data model** (design §2, §4, §6): English table names next to the current ones
   (`system/sql/names.php`, `Core\Names`, undo included), `Core\Clock` and a System status check that the site's time zone,
   PHP and the database agree, regional language versions (en-GB, de-AT, pt-BR, zh-Hant).
3. **Update freshness** (N39-7, N39-8): a site refuses older or wrong-channel update information, does not remember dates in
   the future, warns after 60 days without fresh information and can forget what it remembered.
4. **Community showcase** on kaletacms.com: real sites only, submitted through a form and checked by hand.

Audited before release (N310): runners split by what they can do, the channel bound before update information is
remembered, English table names journaled for undo, MCP keeps working if migration 0085 has not run.

Fix:

5. 3.10.1 (after the daily audit): the translation overview (Pages → Translations and `translation_status`) shows each
   person only what their own page, news and collection lists show (N33); records of replaced Claude refresh tokens are
   kept 30 days instead of up to a year, still enough to detect a stolen token (N39-5).

## 3.11 – Arabic, drafts by default, no invented content (released 11 October 2026)

1. **Right-to-left languages** (owner decision 11 October 2026: fysiomed.eu keeps Arabic; design §5): Arabic as a site
   language with `dir="rtl"`, logical CSS properties, mirrored arrows and swipes, bidi isolation of inserted values and in
   admin lists, an Arabic visitor dictionary (native review welcome), Arabic search, Arabic WordPress import, RTL e-mails,
   readable Latin slugs for Arabic titles (`page-<id>` when nothing transliterates). Lines split as UTF-8 (byte 0x85 is
   part of Arabic letters).
2. **Outside UX review of 3.10** (Claude/kaleta-ux/ux-2026-10-11-3.10.md, UX310-1…30): new Claude connections default to
   "Drafts only – you publish" (owner decision); starter sites use visible placeholders and the pre-publish check, the
   Site audit and Claude report sample content; translating a builder page copies its build; System status and Backups
   and updates agree; truthful preset messages; a visible page title field; wording fixes.
3. kaletacms.com: the homepage design-system demo shows plain labels instead of token names.

Audited before release (N311): robots.txt rules kept when the file is not UTF-8, an application's strongest access shown,
drafts as the fallback of the consent and token forms, bidi controls dropped from RTL e-mails.

## 3.12 – WooCommerce moves, sorting by language, time-zone conversion (3.12.1 released 13 October 2026)

1. **fysiomed.eu readiness** (WooCommerce + Polylang, English and Arabic): WooCommerce products become a product catalogue
   (`Core\WpWooCommerce`), product categories become collection categories per language with redirects of the old
   addresses (default or detected base), images in batches of 30, collections with categories delete step by step
   (MySQL 6575), a hidden category goes visible with its first published item (owner decision), Arabic search normalises
   ة/ه and ٱ/ا (news re-indexed, migration 0089), a font warning for scripts the chosen font lacks (`Builder\FontScripts`).
   Measured at 396 products ×2: 28 steps, 847 old addresses, none missing.
2. **The rest of the outside UX review of 3.10:** logo and icon in the draft look, Portuguese and Chinese visitor texts
   with a warning for languages without them, Collection list fields by name, protected pages ticked in a list, the
   WordPress import preview (export language, add-language path), menu changes shown before publishing and "publish the
   menus only", the Form element inspector as one row per field, smaller polish.
3. **The last safe 4.0 steps** (design §1.2, §1.3, §6): alphabetical lists sort by the site language (`Core\Collation`;
   Czech unchanged), token and hash columns compared exactly (`utf8mb4_bin`, migration 0092), `NOW()` replaced by `Core\Clock`
   parameters, and a time-zone change offers to convert saved dates (`Core\TimeZoneConversion`, migration 0093; owner
   decision). The owner's 4.0 answers are in the design doc §8.

3.12.0 was tagged but not published (CI: the MariaDB EXPLAIN check and a slow-runner focus test failed); 3.12.1 keeps the
token columns in `utf8mb4_bin` (no conversion, no error 3988 for a non-ASCII value), and the inspector keeps the focused
field when it redraws.

Audited before release (N312): a time-zone conversion starts once even with parallel submits, out-of-range dates never
stall the background jobs, categories written by a drafts-only connection wait for review, imported redirects are always
literal, the import state is never written empty, category parent cycles are broken.

## 3.13 – Spanish admin, Claude's ready-made tasks, a true cookie table (3.13.1 released 13 October 2026)

1. **Spanish admin and installer** (owner request): `admin-es.php`, `admin-es.js`, `install-es.php`, "Español" in My
   account, the installer picks it from the browser. Machine translation – native review welcome.
2. **The connection's ready-made tasks are Kaleta's skills** (owner decision: no plugin directory – Kaleta runs on many
   owners' domains): 15 MCP prompts as complete runbooks (moving a site incl. WooCommerce/Polylang/WPML, launching a site,
   adding a language version, weekly care, newsletter, bookings, compliance check); `weekly_review` stays a read-only
   report; the plugin's skills are generated from the prompts; prompt names are part of the contract.
3. **The cookie table every site generates** names what the code sets: `kaleta` (not PHPSESSID), the consent 6 months,
   the preview cookie and the consent id (found while checking kaletacms.com's privacy policy).
4. kaletacms.com: the claims audit of 13 October fixed on the site (EN/CS/DE), privacy policy and accessibility statement.

Audited before release (N313): weekly_review kept read-only, exact cookie names in the scan, prompt arguments capped, a
non-string MCP name answered as JSON-RPC, the consent id cookie Secure.

3.13.1 (13 October 2026): the "Connect an application" consent screen is styled again – since 3.11 its focused frame
did not load the stylesheet of the option cards, so the access choices were bare radio buttons and run-on text (owner
report from a live site). The choices are one list; the browser test checks the cards.

## 3.14 – Ready for 4.0, your own e-mail signature (3.14.1 released 18 October 2026)

1. **Updates:** after a refused install (the source offers a newer version than the one on the button) the screen shows
   the version offered now – before, it kept the remembered one for 12 hours and every click failed the same way (owner
   report from kaletacms.com).
2. **Ready for 4.0 on System status** (`Core\Readiness`, read-only, kept out of `Health::checks`): database server,
   rights of the database user to rename tables, add-ons and leftover layouts writing old table names (a bounded scan
   plus a runtime log, `Core\LegacyNames`), tags used in several languages, redirect sources that will collide once
   compared exactly, slugs shared across languages. Claude reads it in `get_health` (`ready_for_4_0`).
3. **Expand steps for 4.0:** redirect sources normalised in `source_norm` (utf8mb4_bin, migration 0094, filled by the
   job `redirect_sources`); tags get a language (migration 0095, `(jazyk, seo_link)` next to the global key);
   export format 3 defined and read (`Core\ExportFormat`, English keys from `names.php`; written only on request until 4.0).
4. **Every employee copies their own e-mail signature** (owner's idea) in My account, from their Team record – linked by
   e-mail or chosen by an administrator (migration 0096); no Collections access needed, never someone else's.
5. **Spanish blueprints:** all 20 blueprints in Spanish.

Audited before release (N314): hidden Team records linked only by an administrator's choice, an edited redirect
normalised again, the add-on scan robust and bounded, legacy tags kept on a re-save, the future-date warning kept.

3.14.1: a number field of a collection reads as a number in the site's language – {{price}} shows 1 490 / 1,490 / 1.490
and keeps the stored decimals (4.20), {{price_raw}} is the stored value for links and data (found while building the
industry example sites). The update test stops its server's workers (they outlived every run because lsof was not on
the PATH). The 18 industry example screenshots are on kaletacms.com/solutions and in docs/screenshots/examples.

## 3.15 – the bridge to 4.0, the daily audits closed (released 19 October 2026)

1. **Both table names** (`Core\Names`, `Db`): a site works on today's Czech tables and on the English names 4.0 renames
   them to, and notices a rename or a rollback by itself (a cached marker; one `information_schema` query only after a
   missing-table error). Migrations rewrite their table names on a renamed database; `tools/rename-tables.php` and a
   test.sh section prove both directions. 3.15 is the rollback target of 4.0.
2. **A separate update source for majors:** 4.x is published in `aktualizace-4.json`, which only 3.15+ reads; older sites
   get 3.15 first. A major is never installed automatically – only by an administrator, after the newest 3.x, a fresh
   backup and the confirmation; the Ready for 4.0 blockers (database server, rights to rename tables) stop it.
   `tools/release.php` writes the major manifest, `check-channel.php` refuses a 4.x in the 3.x manifest.
3. **Update prompt:** 14 days behind the latest release, the dashboard asks to update (no support window – owner).
4. **Counts in the admin menu** (owner's report): new enquiries, bookings to confirm and whistleblowing reports to
   acknowledge next to their menu items; screen readers hear "N new", a phone shows a dot on the Menu button.
5. **Upload staging (N10 closed):** files from a drafts-only Claude connection wait in `storage/staging` – visible to
   signed-in users and draft previews, public when published content uses them or when approved in Media, deleted
   after 30 days. nginx sites need the updated `/media/` block of `system/nginx.example.conf` for previews.
6. **Vault key out of the database (N42, owner):** new installs keep the encryption key of connector credentials and
   whistleblowing cases in config.php; existing sites move it with one button (re-encrypted in one transaction).
   A database restored on another server then also needs config.php.
7. **Instructions for Claude (N44, owner):** still editable over MCP; every change is in the change log with the old and
   new text and the dashboard asks an administrator to check it.
8. **Daily security audits:** N71 (`read_notebook` permission), N72 (site-import media: lower-case extensions, content
   check, case-insensitive header rules – data migration 0098), N73 (SVG comments), N74 (PHP 8.5 response headers),
   N18 (TOTP replay), N19 (tokens revoked when an administrator changes a password), N21 (ids that clobber the page),
   N45, N46 (whistleblowing page CSP), N48 (CSS escapes), N49; N9, N22, N47 were already fixed. Migrations 0097
   (media staging) and 0098 (media/.htaccess).

Audited before release (N315): a live class cannot publish a waiting file, the vault key is written only into the
top-level config array and verified before re-encrypting, 4.0 installs only after a successful check of the newest 3.x,
promotion reads every public place and every waiting file (and runs only after publishing – 34 s → 0.3 s on a large
site), the clean-up never deletes a used file, spam left out of the enquiry count, an atomic TOTP replay check.

**3.15.1 (19 October 2026):** **Hungarian for visitors** (`system/jazyky/hu.php`, found while moving licencepro.hu): the
skip link, menu, 404, search, form messages and cookie bar of a Hungarian site are no longer English. **Menu anchors:**
a link with a fragment (`/#products`) is never the current page – every anchor of the home page used to be highlighted.

**3.15.2 (19 October 2026):** **Several form recipients and a hidden copy** (found on fysiomed.cz, whose enquiries reached
only the site e-mail): the form's notification e-mails take several addresses separated by commas – one message, they see
each other in To – and a new *Hidden copy (Bcc)* field adds recipients that are only in the envelope. `Core\Mail` sends one
message to a list of addresses (`Mail::addresses`, a single invalid address refuses the whole list); with `mail()` the Bcc
header is left to the server's sendmail, with SMTP it never goes out.

**3.15.3 (19 October 2026):** **News text inside a wrapper keeps its spacing:** the build's reset of heading and paragraph
margins also reached the system content in the *Page content* element of the news item, news list and 404 wrappers, so
paragraphs, lists and figures of a news article were glued together. The reset now skips everything inside `.obsah`
(zero specificity kept) and the content looks the same as without the wrapper.

**3.15.4 (19 October 2026):** **Descriptions from HTML as plain text:** a news intro with `&nbsp;` (WordPress content, or any
intro after a Kaleta site import, which stores U+00A0 as the entity) showed `&amp;nbsp;` literally in the meta, og and JSON-LD
descriptions, the JSON feed and llms.txt. One helper `plain_text()` (strip tags, block ends to spaces, decode entities, collapse
whitespace, optional width) now builds every such description – news, categories, tags, collection items, screen mode.

## 3.16 – the monthly report you can keep, what waits across your sites (released 20 October 2026)

1. **Monthly report archive** (owner: a summary of what happened to the site that goes with the invoice – never hours,
   prices or a work log): on the 1st every site stores the previous month (`ka_monthly_reports`, migration 0099, 24
   months, the background job `report_archive`, also without cron). Settings → Reports lists the months: a print-ready A4
   page (the browser saves it as PDF – page numbers, the agency's branding), a download and the e-mail. The report adds
   requests handled and still open, changes by people and by Claude, Claude's sessions and scheduled runs, updates from→to,
   backups, security (counts only), the audit with fixed/new, enquiries by category, bookings, newsletters and what waits
   at month end. Months before 3.16 cannot be rebuilt (the logs are kept 30–180 days); a month can be rebuilt by hand.
   MCP `list_monthly_reports`, `get_monthly_report`.
2. **The heartbeat says what waits for people** (for the 3.17 inbox across sites): Ask Claude requests by status and
   their titles (switchable per site, on by default; e-mail-like text masked), the pending-review counts, enquiries without
   spam, bookings to confirm, security counts, certificate and domain expiry, Claude's runs, how far behind the site is and
   whether 4.0 is available. Never enquiry contents, e-mail addresses, IPs – and no whistleblowing count. The console
   scores the new reasons (requests waiting 48 h, certificate < 14 days, domain < 30 days, bookings, failed sign-ins) and
   shows a Waiting block on the site detail.

Audited before release (N316): one masker for titles and the report (spaced and written-out e-mails, full-width @, phone
numbers – `Core\Redact`), no free text of Claude's runs in the archive, nothing about the whistleblowing channel reaches the
console (problem counts, failing jobs, health texts), the print page escapes CSS strings, a hostile heartbeat cannot break
the console, a rebuilt report says when its open items were counted, moments stored with their offset.

## 3.17 – one inbox for all your sites, history, alerts, a report per customer (released 20 October 2026)

The fleet console's tabs: Inbox, Sites, Customers, Fleet reports, Alerts and digest, Shared kit.

1. **Inbox across all sites** (`Fleet\Inbox`, the console's new default tab): one list built only from the stored
   heartbeats and the console's own uptime check – request titles with status and age (new / in progress / done to
   review; counts when the site does not share titles), new enquiries (count), drafts and files waiting for approval (per
   kind of `PendingReview`, `PendingReview::URLS` shared with the dashboard), bookings to confirm, failed updates, sign-in
   attacks and reused tokens, certificate and domain expiring, health errors / failing jobs / no backup, audit findings,
   site down or silent. Sorted by urgency (urgent / waiting / when convenient), then the oldest first; filters kind (with a
   count per kind), site, customer, only urgent. Each row is a plain link (new tab, `rel=noopener`, no sign-in, no token) to
   the right screen of that site's administration. Rows of a site silent for 26 h are marked "possibly out of date"; a 3.15
   heartbeat gives what it has. Cards on a phone. MCP `fleet_inbox` (read-only, at most 200 items, texts marked as written
   on the sites).
2. **Customers** (migration 0100: `ka_fleet_customers`, `ka_fleet_sites.customer_id` + `note`, `ka_fleet_kits.customer_id`;
   `Fleet\Customers`): a customer per site, renamed once for all its sites (renaming to an existing name merges), set on
   the site detail (with the owner's console-only note) or in bulk from the list of sites (`action=sites`, columns Customer
   and Waiting, customer filter). The Customers tab: sites, waiting items, the latest fleet report, rename and "Allow version
   … now" for one customer (`Console::allowNowForCustomer`). A kit version can be meant for one customer's sites
   (`Kit::latestFor` / `announcementFor`). MCP `list_sites` gains `customer` (output and an optional filter).
3. **Daily history** (migration 0101, `Fleet\History`): `ka_fleet_history`, one row per site and day – every 5-minute
   uptime check adds to the day (answered or not, the time to the first byte, `Http::checks`); the hourly job
   `fleet_history` writes the day's status, version, attention and what waited from the stored heartbeat.
   `ka_fleet_incidents` keeps the down periods. 400 days kept. **Trends on a site's page**: uptime and average response this
   month and last month, 90 days as bars (inline SVG with a summary for screen readers and the days as a table), outages and
   versions; also in MCP `get_site` (`trends`).
4. **Alert rules** (Fleet → Alerts and digest, `Fleet\AlertRules`, job `fleet_alerts` every 5 minutes): site down,
   stopped reporting, certificate, domain, update failed, failed sign-ins, requests waiting – on/off and a threshold,
   recipients, quiet hours (default 24). Defaults = the behaviour before 3.17 (down and silent on); `Core\Alerts` no longer
   sends `fleet.site_down`/`fleet.site_silent` itself. **One source of thresholds**: `Console::THRESHOLDS` (silent 26 h,
   certificate < 14 days, domain < 30 days, 50 failed sign-ins in 7 days, a request waiting > 48 h) are a site's attention,
   the alert rules' defaults and the inbox's urgency; when the owner changes a threshold the inbox follows it
   (`AlertRules::thresholds`, also for a rule that is off).
5. **Weekly digest** (`Fleet\Digest`, job `fleet_digest`, off by default): Monday from 7:00, once an ISO week – attention,
   what waits (counts), outages and uptime of the week, versions installed, plain links; each customer's part links to the
   inbox filtered by that customer. Request titles only with `fleet_digest_titles` (off by default).
6. **Monthly fleet report per customer** (`Fleet\Report`, `ka_fleet_reports`, job `fleet_reports` on the 1st from 6:00, 24
   months): per site uptime, outages, versions, days needing attention and why, and the site's own headline counts from the
   heartbeat key `report_summary`. Printed with the 3.16 A4 page, the console's agency branding; view/print, download, make
   now, rebuild. MCP `fleet_report` (month, customer). Not an invoice, no personal data.
   **Grouped by the customer's id** (`ka_fleet_reports.customer_id`, 0 = the sites without one) with the name it had when it
   was made: a renamed customer keeps its archive (the list shows the name now – linked to the inbox filter – and the name
   then); merging two customers moves the reports along; the digest and the reports list the customers in the order of the
   inbox's customer filter, the sites without one last.

Open: a removed site's history goes with it (the stored fleet reports keep it); response time is the console's own view
(time to the first byte from the console), not the visitors'; a forgotten customer's reports stay under its old id and name.

## 3.18 – know whether Claude is connected (released 20 October 2026)

The owner: the connector to Claude is essential – the admin must say whether there is an active connection, and say so
when there is none, so nobody writes to Ask Claude while nothing picks the requests up. MCP is pull-only: the site knows
whether a connection exists, when Claude last called and whether a scheduled run picks requests up – not whether Claude
is "online".

1. **Reliable last call**: every MCP call that is served also stamps the refresh row of its OAuth connection (at most once
   a minute) and a refresh carries the last call over to the new pair – before, a connection idle for over an hour lost
   it (its access row expires and is cleaned up), so Claude settings, My account and the 60-day hygiene check showed live
   connections as unused. `Core\ClaudeConnection::status()`: state off | none | idle | active (a call within 7 days;
   recent within 24 h), pickup automatic | manual | stalled (a `requests` schedule that missed or failed its last run, or
   is more than 6 hours overdue), the earliest end of a connection (expiring within 14 days), new requests waiting.
   `AskClaude::connected()` now means "a valid connection exists now"; `claude_first_used` stays as history, and revoking
   every connection shows "Not connected" (and the Connect Claude card) again.
2. **The top bar of every screen** (for whoever may use Ask Claude or Claude settings, not in the public demo): Claude
   connected (last call) / Claude idle / Claude: requests wait (or scheduled run stopped) / Claude not connected – words,
   not colour alone (disc, diamond, ring); on a phone a dot with the words for screen readers; a link to Claude settings.
3. **Ask Claude entry points** – the dashboard box, a new request, "Ask Claude to translate it", a blueprint for Claude:
   without a connection a warning and **Save for later** instead of Send; manual pickup and a stalled run say so; the
   flash after saving says when Claude will see the request. The Requests list has the same status line and highlights a
   new request waiting over 48 hours while nothing picks it up.
4. **Claude settings** open with a status panel: state, last call, how requests are picked up, when the first connection
   ends, and the next steps (connect, set up a scheduled run, reconnect before it ends).
5. **System status** "Claude connection": a warning when new requests wait and Claude is not connected or the scheduled
   run does not run, or when a connection ends within 14 days. MCP `get_health` adds `claude_connection` {state,
   last_call, pickup, expires}.
6. **Fleet**: heartbeat key `claude_connection` (bounded by the console: enum values only, moments 1970–2100), attention
   `claude_not_connected` / `claude_run_stalled` when new requests wait, the site detail, an inbox row to the site's Claude
   settings, `list_sites` gains the state. A heartbeat from before 3.18 works as before.
7. **Pre-release security audit** (N318-1 … N318-5): a call is stamped only after the two-factor check and every other
   refusal, and only connections that can call count – of an account that is not blocked and meets the site's
   two-factor requirement (a connection whose every call is refused shows "Claude not connected", N318-1). Migration 0102
   adds `ka_api_tokeny (druh, expirace)` and `ka_oauth_rotated (rotated_at)` for the status queries, the schedule loop is
   one query, and OAuth refreshes are limited to 30 per connection (client + user) an hour – over it `429 slow_down` with
   `Retry-After`; the refresh token stays valid (N318-2). The last call comes from live connections only, and a
   disconnect, a revocation, a password change or reset and a deleted account delete the connection's rotation records
   (N318-3). Staff seeing the last-call time and the heartbeat's last call stay as they are (owner decisions, N318-4/-5).

Open: the header chip has no "expires soon" state (Claude settings and System status say it); no menu badge on the
Claude hub.

**3.18.1 (21 October 2026):** builder dialogs (Published versions, Share, Comments…) live on `<body>`, outside `.st`: with the
system in dark mode and the admin switched to light their buttons were white on white. The manual theme also sets
`color-scheme`, the dialog controls inherit font and colour, and a browser-test step checks both mismatches.

**3.18.2 (22 October 2026):**
- **Security (CodeQL #32, #42):** pasted HTML in the text editor was parsed in the admin page itself, so a pasted
  `<img onerror>` ran before the cleanup removed it. It is parsed in an inert document now; `href`/`src` drop `javascript:`,
  `vbscript:` and `data:` also behind tabs or control characters; `<mark>` survives a paste. The builder's inspector shows
  text typed on the canvas through the same cleanup, and the booking widget writes its messages as text (#38–#41). The
  other CodeQL and Semgrep alerts were dismissed on GitHub with their reasons (false positives, development and release tools).
- **Dashboard (fysiomed.cz):** a path-traversal probe in the 404 log no longer crashes the dashboard under `open_basedir` –
  `Audit::pathResolves` checks the media/image prefix and rejects `..` before `is_file`, and `NotFound::BOTS` treats `..`
  segments and `@fs/` as probes.
- **Site moves and front end** (found while moving the owner's sites): a heading for the news list (`news_title`); forms with
  several recipients labelled as such, styled consent and file fields, `:user-invalid`, the privacy link says it opens a new
  window; the phone menu dims the page and its button turns into a cross; a navigation can split the menu around a centred
  logo; the store locator is a map with a side panel (search label, "Nearest to me" as a location icon, markers in the site
  colour); category filter buttons on a collection list; the site import keeps the last line and redirects to the home page;
  WordPress news redirects use the public slug; emoji addresses survive a site move; the migration report counts forms and
  images inside components and item templates; the home page of a language version is no longer an orphan.

Audited before release (N3182): the paste cleanup empties every frame and does its clean-up on the nodes (a frame's raw
text could be joined into a real `</iframe>` by the old regular expression), keeps a pasted frame only from the services
the front end embeds, `rel` only with safe values (`noopener` with every `_blank`), and the link dialog uses the same
address check; every builder clipboard envelope goes through the server, which keeps Custom HTML only in a copy this site
signed, and the layer tree reads element text in an inert document; long `/media/` paths no longer crash Site audit under
`open_basedir`; the migration report follows each component once (cycles). OpenStreetMap on "map at once" stays as
labelled (owner decision, N3182-7). A second review found no bypass of the new cleanup; it closed the rest: only an administrator's copy is signed (N3182-8), image sizes on public pages and in newsletters and the logo check go through `Files::isFile` (N3182-9), a component build given as a plain list loses its code too (N3182-10), and the path cap is strictly below the platform maximum (N3182-11).

**3.18.3 (22 October 2026):**
- **Collections straight from the admin menu (owner's request):** each collection gets its own item in the left menu,
  right under Collections – a direct link to its items, so products or job openings are one click away instead of
  Collections → the collection. On by default for every existing and new collection (migration 0103, column
  `ka_kolekce.admin_menu`); *Show in the admin menu* in the collection's settings leaves one out. At most 8 (by name),
  the rest stay under Collections; the item is current (`aria-current`) on every screen of its collection instead of
  Collections, has the generic list icon (collections have no icon of their own) and no count (nothing in a collection
  waits for someone the way a new enquiry does). Only for people who may open Collections; the quick search lists every
  collection by name. MCP: `create_collection` and `update_collection` take an optional `admin_menu`, `list_collections`
  returns it; the site export and import carry it.
- **Admin list filters:** the language filter of collection items shows the language really chosen (it always showed the
  last additional language), pages and news apply a filter choice at once, and collection items filter by category and
  by each choice field.

## 3.19 – per-language addresses for every site, collection icons (released 26 October 2026)

Owner decisions of 21 and 22 October 2026: no physical renames of tables or columns to English ("it doesn't matter to most
users"), so the rest of the 4.0 contract ships as a normal 3.x release instead of a major with a release candidate.
Migrations 0104 (collection icon), 0105 (plain lookup keys on `seo_link`) and the data migration 0106 (the highest of the
release); `KALETA_DB_VERSION` 106. MCP tools, parameters and Czech aliases unchanged – one optional parameter, `icon`.

1. **Addresses unique per language on every site** (docs/design/4.0-data-model.md §3 step 2). Migration 0106 makes on
   each site the checks "The same address in every language" made (the per-language keys `(jazyk, seo_link)` of pages,
   news, news categories and tags exist – a missing one is added, which fails only when two rows of one language share a
   slug), drops the global keys and writes `slugs_per_language = 1`. Fresh installs: the setting on, `schema.sql` without
   the global keys; 0105 adds a plain key on `seo_link` (lookups in any version keep an index). **A site that cannot
   switch stays exactly as it is** (setting `0`, global keys, tags not split): the reason goes to `slugs_switch_failed`
   and the event `slugs.per_language_failed`; System status (Database → Addresses in every language) warns with the shared
   addresses or the database's message and what to do; Settings → General shows the reason and offers the switch, which
   runs 0106 again (`Slug::enableEverywhere`). **The switch is never offered to switch off** – a site that is on has no
   field; the code of 3.18.x honours the setting, so a rollback to 3.18.3 needs no global keys. `Slug::switchPerLanguage`
   stays (export import, the documented manual schema rollback). The unit-test guard on slug lookups without a language
   also reads the English placeholders, and the site audit looks tags up in their own version.
2. **Tags per language:** in the same migration, only where slugs became per language – a tag used by news items of
   several versions becomes one tag per language, the news re-linked to the tag of their own language. The rule: the tag
   keeps its row (id, description, image) in its own language when news of that language use it, otherwise it moves to
   the language with most of its news (a tie: the smaller code); each other language gets the existing tag with that slug
   in that language, or a copy (name, slug, description, image); languages in code order, so a second run changes nothing.
   Event `tags.split`. Tag pages answer only in their own version; merging stays within one version (its redirect carries
   the language prefix); the editor's tag suggestions are distinct names. Tags are in neither the sitemap nor the feeds.
3. **Redirects by the exact address** (§1.3): the front end (paths, `?p=`/`?lang=`), the site audit, the broken-link check
   and the migration report look redirects up only by `source_norm` (exact, `utf8mb4_bin`); every writer fills it (Redirects,
   the admin form, pattern rules, CSV/MCP, the Kaleta import) and the job `redirect_sources` catches a row written otherwise.
   0106 normalises every remaining row first. Groups that counted as one address under the Czech collation are reported –
   nothing is deleted: the event `redirect.collisions` (count and the first ten), System status (Database → Old addresses
   of redirects, warning with the groups and a link to Redirects) and Ready for 4.0. Within a group a visitor gets the
   row stored exactly as typed, else the oldest.
4. **An icon per collection** (owner, 22 October): column `ka_kolekce.icon` (NULL = the list icon of 3.18.3), chosen in
   the collection's settings from the built-in icon set (`Builder\Icons`, a grid of radio tiles without a script), shown by
   the collection's item in the left menu and in the list of collections. The settings show a short selection first (the
   list icon and eleven that suit collections – `Collections::ICON_SHORTLIST` –, plus the chosen icon) and the rest under
   "More icons" (`<details>`, open by itself when the chosen icon is one of those; owner, 25 October). MCP `create_collection` / `update_collection`
   `icon` (the English name – home, car, people… – or the stored key; `""` = the list icon; anything else refused with the
   list), `list_collections` returns it. The site export carries it, the import keeps only a key of the set.
5. From the 3.18.3 review: the choice-field filter re-checks the field key against `KEY_PATTERN` where it becomes SQL
   (`Builder\Collections::jsonField`, also `Core\Calendar`, `Core\Documents` and the iCalendar route – N3183-1); a user
   held at the required two-factor gate gets My account without the module menu, the collection links and the quick
   search (N3183-2).
6. **Privacy template** (N3182-7, owner 22 October): the privacy policy made at installation, in every visitor language
   it is translated into, says that a store locator set to "Load the map at once" sends the visitor's IP address to
   OpenStreetMap when the page opens, before consent – worded so that it applies only then. A policy made later from the
   template has the paragraph when a published build uses the option (`Core\Privacy::mapAtOnce`); the record of
   processing lists it too.

Export format 3 stays available on request, not the default.

**Rollback to 3.18.3** (tested with the 3.18.3 code on a 3.19 database): pages, news, categories, tags and redirects work,
sign-in and MCP `list_pages` too. Limits: 3.18.3 ignores the icon and the plain keys; its tag pages prefer the version's
own tag (as 3.19 does); it finds redirects by `z_adresy` under the Czech collation again (the groups in the collision
report answer as before 3.19, e.g. /café to the target of /cafe); a redirect it writes has no `source_norm` until the
3.19 job fills it after updating again. 3.18.3 still offers "The same address in every language" in Settings → General and
can switch it off while no slug is shared; back on 3.19 such a site stays off, and System status asks to switch it on again
(0106 has run, so nothing does it by itself).

Audited before release (N319): 0106 never switches a site that was on back off when it fails there – the failure is
recorded, System status and Settings → General say what to do and saving General runs it again (N319-1); a redirect whose
old address differs from a saved one only in accents (`café` / `cafe`, one row under the Czech collation until 4.0) is
refused with the saved one named in the admin form, MCP `save_redirect` / `save_redirects` and the CSV import, the daily
job skips it, and an automatic writer (slug change, imports) takes the row over with the event `redirect.replaced` instead
of silently retargeting it – letter case alone stays the same redirect (N319-2); Settings → General runs 0106 under the
migration lock with a time limit and turns a database error into the form's message (N319-3); the tag split relinks one
tag per transaction with explicit duplicates instead of `INSERT IGNORE` (N319-4); 0106 gets 300 s on a web request and a
redirect row without `source_norm` still answers and is filled on the way (N319-5); `stav.json`, the heartbeat and the
monthly report carry no database message and only the number of colliding redirect groups (N319-7); `create_collection`
with a preset checks `icon` first (N319-8); the store-locator check reads collection item templates of every language and
category templates (N319-9); the draft-look bar is hidden at the two-factor gate (N3183-2). Accepted: N319-6 (a visited
path longer than 255 characters matches a stored 255-character source; the migration report names the oldest row of a
collision group). Also fixed: the whistleblowing channel deleted a day's sent-report rows as they were written in the
last hour of the 25-hour day of the clock change, so the daily limit did not hold then (`Whistleblowing::forgetBefore`).

**3.19.1 (30 October 2026):** the open findings of the daily security audits (19–29 October).
- **N88:** an update rollback that cannot put a file back (full disk, a file of another owner) no longer deletes the
  originals and reports success: the set-aside folder stays, the event `update.restore_failed` names it and the files, the
  maintenance page stays up until a person fixes it (`storage/udrzba.lock` with a mark, honoured however old), System status
  shows it, and the admin message and the background job's e-mail say how many files, where they are and which version
  to upload over FTP.
- **N75:** each step of a sign-in (password, code, passkey) starts a new form token; a password change keeps it, so open
  tabs still save.
- **N76:** a redirect drops tabs, newlines and other control characters before the protocol-relative check, as browsers do;
  the admin "back" address refuses them.
- **N78:** builder anchors skip the ids the site's scripts look up (`gtag`, `grecaptcha`, `poptavka`, `l`, `datalayer`… –
  the list texts have had since 3.15); a stored one is not written when the page is rendered.
- **N79:** MCP `import_enquiries` keeps an enquiry's page only as `/…` or `http(s)://…`; the enquiry screens link only such a page.
- **N80:** the FTPS off-site copy goes through cURL and checks the server's certificate and name (PHP's FTP extension
  cannot); a self-signed certificate is refused with a clear message. Only where PHP has no cURL with FTPS does the
  extension remain, and the Backups tab warns that the certificate is not checked there.
- **N81:** every connection drops `NO_BACKSLASH_ESCAPES` from its session `sql_mode`; a backup without tables fails
  instead of being "ready", and an empty backup is refused at restore.
- **N82:** restoring and deleting a backup are written to the change log and the events (`backup.restored`,
  `backup.deleted`) – the restore's record after the restore, into the restored database.
- **N83:** the installer writes `config.php` with mode 0600 (0640 where PHP is not the owner of the site's folder); System
  status warns when everyone on the server can read it and fixes it with one click where PHP owns the file.
- **N84:** **Make a new signing key** (Settings → Backups and updates) replaces `secret_key` and so withdraws every shared
  preview link, gated download link, unlocked protected page, open visitor form and clipboard signature; the confirmation
  lists them, the change log and the event `security.signing_key_rotated` record it. While the encryption key is still in
  the database, the stored secrets are encrypted again under the new key in the same transaction.
- **N85:** the `install.sql` guard of add-ons checks the whole statement: a second statement after a semicolon on the same
  line (or a `/*! */` comment) is refused before anything runs.
- **N86:** `.htaccess` refuses an add-on's PHP in `public/` also with path info (`run.php/x`) and a double extension, and
  serves nothing in `public/` but an existing file (MultiViews cannot pick `run.php`); the development router and
  `nginx.example.conf` refuse the same. A customised `.htaccess` gets it in `.htaccess.kaleta-nova`, and System status says so.
- **N87:** switching an add-on on or off names the add-on and the direction in the change log; switching off records
  `addon.disabled`.
- Tests: the builder check of 3.6 UXA-12 and the whistleblowing tools/list check in `tools/test.sh` really run now.

Unchanged by owner decision or accepted: N3182-7, N318-4, N318-5, N315-9, N39-4, N37-4, S6.

Pre-release review (N3191): a new signing key reads the key in force under a lock inside its transaction and stops if a
stored value would turn unreadable (two rotations at once could lose the sealed data, N3191-1); the previous key is kept
(`secret_key_previous`) and opens a value a request sealed in that moment while the encryption key is still in the database
(N3191-2). The update puts a file on the rollback list before copying it, so a truncated copy comes back (N3191-3); the mark
of a rollback that left files behind is cleared only by an update that completes, and the Backups tab says so (N3191-4);
the mark is written before the rollback starts and a failure to write it is said (N3191-5). Forms and bookings return to
the anchor they render, never a reserved one (N3191-6). The `install.sql` guard also refuses `/*M!` and any `SELECT`;
the session `sql_mode` drops `ANSI_QUOTES` (and `ANSI`) too (N3191-7). The installer sets `config.php` only to 0600, where
PHP owns the site's folder – 0640 only from System status, with a warning about the FTP user (N3191-8). FTPS uploads end when
they stall instead of after 300 s (N3191-9). The `sql_mode` statement runs on its own and a host refusing it keeps the site
up (N3191-10). The signing key's confirmation also names share images, the whistleblowing limits, restored backups and that
Claude connections are not affected (N3191-11). The browser test tolerates Chrome 156's report of an inline handler parsed
in the paste cleanup's inert document (nothing runs).

**3.19.2 (31 October 2026):** the first fixes from the CPO and Head of UX audits of 30 October (`kaleta-audity/cpo-admin-2026-10-30.md`,
`ux-builder-2026-10-30.md`); the rest of their Top 15 is planned as 3.20–3.23.
- **U1:** "Save to my sections", "Save as component", a shared class saved, renamed or deleted from the panel and the
  writing assistant in the builder work again. From 1.4.0 (28 September) the builder's script sent `name`, `prompt` and
  `instruction` while the server read `nazev`, `zadani` and `pokyn`, so every such request answered "missing"
  (`BuilderActions::postEither`, `Components::actionFromElement`). The browser test now saves a section, a component and a
  class from the builder, so a renamed key cannot pass again.
- **C7:** opening a module whose feature is switched off (Bookings, Fleet, Newsletter…) says "The feature “…” is switched off –
  switch it on in Features" with the path as a link, instead of "You do not have access to this module" (`Kernel`, the
  error view renders menu paths).
- **C3:** the screen is called Integrations everywhere: the Statistics empty text, the Search Console and Enquiry-sheet
  messages and the Google reviews placeholder said "Administration → Connections", a menu entry that does not exist; the
  path is a link (`MenuPaths`: Administration → Integrations, Integrations, Features).

## 3.20 – connected admin (31 October 2026)

The second release from the CPO and Head of UX audits of 30 October: the admin's screens link sideways and back, so a
person never has to know where the next screen is. Migration 0107 (`ka_stranky.zmenil`, the person who last edited a page);
`KALETA_DB_VERSION` 107. MCP tools, parameters and Czech aliases unchanged.

1. **Pages list (C1, C11):** tabs All / Published / Hidden / Unpublished changes / Trash with counts (`status=published|hidden|draft|kos`),
   an Edited column (when and who – `zmenil`, filled from the history by 0107, stamped by every edit, publish, import, copy and
   `update_page`; builder autosaves do not stamp it, the sitemap's `lastmod` stays honest) and a Language column where language
   versions are on; the newest edit first, subpages under their parent. The dashboard tiles open the matching tab; the installer
   stamps the starter pages so "Recently edited" is not empty on day one.
2. **Enquiry → form → page (C2):** the enquiry detail says "From the form *X* on the page *Y* – Open in the builder · Page settings"
   (`Admin\EnquiryOrigin`: the stored element id, else the form by name, else the only form; item templates, pop-ups and site parts
   too); the builder honours `#element=<id>` and selects the element. Statistics rows that name a page get an "edit" link.
3. **Collections (C5, C16):** the items screen carries the listing page the preset made ("Listing page: /team – hidden · Edit in the
   builder · Publish · Add to the menu"), the creation message points there and the empty state no longer sends people to build
   a second list. The testimonial request on an enquiry offers "Create the References collection" when the site has none.
4. **News (C19, C20):** the category is always shown, read-only with one category, with "Manage categories"; Broken links is an
   entry under Site care (`broken_links`, the same feature and permission as News), News keeps its shortcut.
5. **Menu paths are links everywhere (C4):** `Module::hint()` renders every hint through `MenuPaths` with the modules the person may
   open – Newsletters, Bookings, Pages, Enquiries settings, Transfer, Schedules, News, Fleet, Settings and System status; new paths
   for Enquiries, Pages, Roles, Fleet console, Webhooks, Scheduled runs and Notebook.
6. **One way to connect Claude (C6):** every "How to connect Claude" leads to Claude settings → *How to connect*; the Connections
   box links "Create a personal token – My account". Token and OAuth code untouched.
7. **Builder panels link to the data (U11, U13):** Form → Enquiries and spam protection, Collection list → the collection (or "Add
   a collection"), Business details → edit them, Navigation → the menu, Booking → services and people – only where the person may
   go (`BuilderActions::dataLinks`); a component instance offers "Edit this component (changes everywhere)"; the component
   builder's settings link is "Component name and properties".
8. **Small dead ends (C22, U14):** provider documentation links in Integrations have names; Media "Used in" lists the pages and items as
   links; the cookies tab prefills the privacy-policy page and links it; Site audit links the Notebook; Whistleblowing adds the
   form to the footer menu with one click; the dashboard's enquiry status is its own column; one name "Sections library".

## 3.21 – builder for non-designers (1 November 2026)

The third release from the CPO and Head of UX audits of 30 October: the builder for people who are not designers. No
migration; `KALETA_DB_VERSION` stays 107. MCP tools, parameters and Czech aliases unchanged; `builder_schema` lists the new
content field type `termin` (`datetime`) for the Countdown target.

1. **Style tab per element (U2, D3):** a map of the style groups each element type needs (`RELEVANT_GROUPS` in stavitel.js,
   checked by unit tests against every element type and group); the rest waits behind "Show all properties". A group that has a
   value on any breakpoint or state is always shown; Spacing opens by default. Nothing is removed from the data model.
2. **Font size (U5):** a select of the scale steps (-1 Small … 5 Title) plus "Custom…"; a custom value is checked in the field
   with the server's `Style::LENGTH_PATTERN` and not saved when invalid. Stored values keep their form.
3. **Overrides across breakpoints (U3):** a dot on a device button when the element has its own values there, a device mark next
   to a field overridden on a smaller screen ("Set on Phone: …", click to switch), and "×" to return a field to the inherited value.
4. **One Visibility row (U4):** "Shown on" Desktop / Tablet / Phone in Advanced replaces the two checkboxes (same stored
   `zobrazeni: none` per breakpoint); an element hidden on the current device is a dashed 24 px strip on the editor canvas with
   "show", and Structure marks it. The public page is unchanged.
5. **Element menu and nesting path (U8):** right-click or Shift+F10 on the canvas or a Structure row – Duplicate, Delete, Copy,
   Paste, Copy style, Paste style, Save as component, Select parent element; a breadcrumb under the panel header selects ancestors.
6. **Keyboard and speed (U16, U18):** Alt+↑/↓ moves an element within its parent, Alt+←/→ out of it or into the container before
   it (listed in the shortcuts help); text typed in the Content field of a Heading, Button or Text reaches the canvas at once
   (only text and b/i/strong/em/u/br/p are drawn early; the server redraw follows).
7. **Small fixes (U9, U19, U24):** a heading's tag is set only as its Level; the group badge reads "Spacing 1"; "Pressed" instead
   of "Press"; the Countdown target is a date-and-time picker (stored as before, "YYYY-MM-DD HH:MM"). The stray "null" under the
   element header (since 3.20) is gone.

## 3.22 – content manager and replies (1 November 2026)

The fourth release from the CPO and Head of UX audits of 30 October: a role for the client's marketing person, replies sent
from the admin, and text that comes in from Word. Migrations 0108 (`ka_poptavky.replies`) and 0109 (`canonical_url` on pages,
news and collection items); `KALETA_DB_VERSION` 109. MCP: new tool `reply_to_enquiry` (sends a real e-mail, needs
`confirm: true` and a full-access connection), new parameter `canonical_url` on the page, news and collection item tools, new
Form property `confirmation_text`, new `site_info` key `can_edit_appearance`. Existing tools, parameters and Czech aliases
unchanged.

1. **Appearance area for roles (C9, D1):** an optional area "Appearance: menu, site parts, pop-ups and components" for roles
   and the per-user access. Off for every existing role and user; Site appearance (colours and fonts), settings, users,
   backups and Claude settings stay with the administrator. Every endpoint of those modules, the builder actions they share,
   their previews and the MCP tools check the area on the server. Changes that go live at once (publishing the menu, pop-up
   switches, saving a component or a part variant) also need permission to publish; a draft-only role saves drafts.
2. **Send a reply from the enquiry (C10, D2):** the drafted reply in an editable field, sent through the site's SMTP with the
   site's sender, Reply-To of the person replying and their e-mail signature, to the enquiry's own address only. The enquiry
   records who replied, when, the text and the delivery state; it is resolved unless "Keep the enquiry open" is ticked.
   Without SMTP the mail-program link stays. Anonymising keeps who and when only. A message's own Reply-To now wins over the
   site's reply address in all mail, so enquiry notifications keep the visitor's address.
3. **Canonical address (C13):** an optional field under "Search engines and sharing" on pages, news and collection items with
   item pages; an absolute http(s) address or a path on the site, anything else refused. The page prints one canonical link
   and `og:url` follows it. Canonical addresses from Yoast, Rank Math and SmartCrawl are kept on WordPress import.
4. **Confirmation e-mail text per form (C15):** the Form element's own text for the thank-you e-mail, shown while the
   confirmation is on; empty keeps today's text from "What happens next", "We reply within" and "Who replies".
5. **Link picker in the text editor (U6):** one field for an address or a search that suggests pages, news, collection item
   pages, files, language versions, anchors on the builder page, and `mailto:`/`tel:` for a typed e-mail or phone number;
   arrows and Enter pick. In the large editor and the builder Text element.
6. **Lists and quotes (U7):** numbered list and quote in the builder's text toolbar (headings stay with the Heading element);
   Word lists pasted into the editor become real nested lists, Google Docs lists stay as they are.
7. **Upload limits (C14):** System status says how to raise the limit on this server (`.htaccess`, `.user.ini`, `php.ini` or
   the hosting panel); the installer and a button in System status write a `.user.ini` with 16M/20M where PHP reads one and no
   file exists, never lowering a limit. Photos are scaled to 2000 px in the browser before upload, with a note "resized from
   … to …"; a batch too large for one request is split or refused with a clear message.

## 3.23 – handover (1 November 2026)

The fifth release from the CPO and Head of UX audits of 30 October: a site that can be handed over to a client. No migration;
`KALETA_DB_VERSION` stays 109. No setting key, POST field, MCP tool, parameter or Czech alias renamed; `site_audit` returns a
new `handover_checklist`, `builder_schema` field definitions carry a `source` hint.

1. **Settings split (C12, D4):** General keeps the site, home page, news, time zone, maintenance, screen mode, sharing and
   social networks and more options; new tabs Languages (site language, further versions, their names and descriptions, the
   same address in every language), Security (two-factor policy, automatic suspension, a link to Firewall) and Agency
   ("Built and looked after by"). Each tab saves only its own fields; a 3.22 General form left open still saves as before;
   old `tab=general#field` links land on the field's new tab.
2. **Reporting to a console (C8):** the former "Fleet console" tab, last, shown only when an agency is named or the site
   already reports to a console.
3. **Handing over to a client (C17):** one checklist with "x of y done" in Site audit instead of two groups; new checks for a
   client account (Client role or the Appearance area), the monthly report recipient, SMTP set up and tested (the last
   accepted message is recorded in `smtp_last_ok`), Claude settings saved once, the agency contact and the Notebook.
4. **UI vocabulary (C18):** English source strings for the News, Users and Settings modules and the menu paths (Czech
   unchanged); "Ask Claude" everywhere in the UI, "request" for one item; the first Site appearance tab is "Styles"; a UI
   vocabulary section in the glossary.
5. **Draft look for the Appearance area:** members with the Appearance area preview the draft look and the menu draft on the
   site with a read-only look bar; publishing the look stays with the administrator.
6. **Choices instead of typed keys and ids (U10):** Collection list, Store locator, Booking, News list and Previous/next take
   the collection, field, category, service and person from selects filled with the site's data (new read-only builder
   action `build_choices`, same permissions as the builder); "Custom…" for anything else; a stored value that no longer exists
   is shown as "(not found)", never dropped.
7. **Builder on a tablet (U15):** below 1024 px the panels become icon rails that open over the canvas (Esc closes), the Add
   panel has two columns and the top bar wraps; the canvas keeps about 88 % of an 820 px screen.

**3.23.1 (2 November 2026):** fixes from the CPO and Head of UX re-audits of 3.23.0 (`kaleta-audity/cpo-admin-2026-11-01.md`,
`ux-builder-2026-11-01.md`). No migration; no setting, MCP tool or parameter added or renamed.
- **Client role (N-C1, N-C9):** the ready-made Client preset now includes the Appearance area and Business details (D1); the
  handover row names what a client account lacks and links New user with the Client role already chosen; own two-step sign-in
  and the privacy-policy first step link to the exact place.
- **Mail language (N-C2):** draft-comment and testimonial-request e-mails fall back to the site language, never Czech; users
  created by an administrator keep an admin language.
- **Change log (N-C3):** one row per action with the record id ("reply #3"); a refused action writes no row.
- **Builder (N-U1, N-U5, N-U13, N-U15, N-U16):** a Word paste while editing on the canvas is cleaned like in the panel (lists
  stay lists); the tablet rails now apply up to 1179 px; the first selection at rail widths opens Properties; Alt+arrow moves
  are announced to screen readers; no console error after "Edit here".
- **Phone and wording (N-C4–N-C7, N-C10–N-C12, N-C15, C4, C18):** System status wraps on a phone and Settings tabs become a
  select below 600 px; the last stale menu paths are links; the reply form says when no signature goes with it; the
  testimonial request shows only on resolved customer enquiries, folded under the main actions, in one plain sentence.
- **Tests:** a local SMTP sink in the browser suite (enquiry reply, Appearance-area member, every Settings tab saved twice).

## 3.24 – team-ready website (2 November 2026)

The first step towards a website a small company's team works in every day (owner decisions of 2 November 2026: a
responsible person means responsibility only – no approval step and no access limit; notifications by e-mail; all of it in
the core). Migration 0110 adds `ka_stranky.owner_id`, `ka_kolekce_polozky.owner_id` and an index on assigned enquiries;
`KALETA_DB_VERSION` 110. No setting key, POST field, MCP tool, parameter or Czech alias renamed; new non-essential MCP tools
`my_work` and `assign`, `update_enquiry` gains `assignee`, `list_pages` / `list_collection_items` / `list_enquiries` gain
`owner` / `assignee`, `list_pending_review` gains `drafted_by`.

1. **Faster site while the team works (T1):** the public page cache is cleared only by actions that can change what the site
   shows; enquiries, notes, requests, users' own work, mail and report settings and the neutral MCP tools keep it. Every admin
   section, action and MCP write tool is classified, and an unclassified one clears. The administration also runs the light
   background jobs after its answer, so reminders and mail retries run on sites without cron.
2. **Responsible person:** pages and collection items have one; a Mine tab in Pages and in collection items; an e-mail to the
   person a page, an item or an enquiry is given to (never to oneself, never the enquiry content); a dashboard tile "Assigned
   to me"; review-by reminders go to the responsible person, at most one e-mail a day.
3. **My work:** one screen with my open enquiries, unassigned new ones, my pages and items that need a look and my requests
   Claude answered; first in the menu for everyone but administrators.
4. **When someone leaves:** Users shows what a person is responsible for and hands it over in one step, also when blocking or
   deleting the account.
5. **Builder:** a new page starts in the builder (empty builder page, a ready-made layout or a simple text page); the page
   title is not offered twice; "Draft by …" in Pages, the builder and Waiting for you.

## 3.25 – notes, a builder safety net and the Workspace menu (4 November 2026)

Owner decisions of 2 November 2026: no support or ticketing system; Content stays above Workspace in the menu. Migration 0111
adds `ka_comments` (enquiry notes now, comments on pages and items later); `KALETA_DB_VERSION` 111. No setting key, POST field,
MCP tool, parameter or Czech alias renamed; `update_enquiry` gains `add_note`, `list_enquiries` gains `notes_count`,
`list_build_versions` gains `kind` (draft history entries), `restore_build_version` accepts them.

1. **Enquiry thread:** internal notes are a history (author and time, never overwritten; the old note stays as the summary);
   the detail shows the customer's message, the replies and the notes in time order; a reply quotes the customer's message and
   gets the subject "Re: …". Notes follow the enquiry's retention, anonymisation and erasure.
2. **Builder safety net:** the Versions dialog lists the last 10 autosaves of the draft ("Draft – today 14:02 (by …)") next to
   the published versions; Compare shows a per-section text diff against the current draft; loading an autosave is undoable.
3. **Menu:** Dashboard, My work, Content, Workspace (Enquiries, Bookings), Appearance, Claude, Site care, Administration;
   Statistics moves to Site care, Business details to Content, Subscribers become a tab of Newsletter; groups fold and remember
   their state; anyone with 8 items or fewer gets a flat list; collection shortcuts show 5 and "More…".
4. **One link picker** for every link field in the builder and the menu editor: pages, e-mail and phone, by keyboard too.

## 3.26 – comments, mentions and notifications (6 November 2026)

Stage 2 of the team work: the site is where its team talks. Migration 0112 adds `ka_notifications` and
`ka_uzivatele.notify_email`; `KALETA_DB_VERSION` 112. No setting key, POST field, MCP tool, parameter or Czech alias renamed;
new non-essential MCP tools `list_comments` and `add_comment`, and a third tool set `work` for employees' own Claude
connections (existing connections keep `all` or `essentials`).

1. **Comments:** threads on pages (page settings and a Team tab in the builder next to the client's draft comments) and on
   collection items, next to the enquiry notes of 3.25; authors edit and delete their own, administrators any; plain text;
   comments go with their page or item when it is deleted for good.
2. **@mentions** in every thread with suggestions of the colleagues who may open that place; the server refuses anyone else.
3. **Notifications:** a bell with the unread count in the header and a Notifications screen; mentions, comments on my pages
   and items, assignments and review reminders; e-mail right away, once a day (about 08:00) or never, chosen in My account;
   kept 90 days; never the text of an enquiry.
4. **For Claude:** `list_comments` and `add_comment` (mentions only on full-access connections); the `work` tool set with the
   tools an employee needs (My work, enquiries, comments, assign, signature, requests, notebook, pages, collection items).
5. **Builder:** spacing and colours are chosen from the design system (with "Custom…" checked in the field).

**3.26.1 (3 November 2026):** a monthly report you want to read (owner request of 2 November). No migration; no setting, MCP
tool or parameter renamed; `get_monthly_report` gains `uptime` and `care`.
- **One look everywhere** (`Core\ReportVisual`): the e-mail, the report page and its print share the same components – a
  band with the month, a one-sentence summary, up to four headline tiles with the change against the month before, bar and
  column charts; tables and inline styles only, readable in Gmail, Outlook and Apple Mail and in their dark modes.
- **What it took to run the site** (`Core\ReportSummary::care`): the month's work as counts – done automatically, by Claude
  and by people – and how often the site needed a person's decision. Never hours, a work log or money.
- **Uptime:** a site paired with a fleet console fetches its own uptime days once a day (`/fleet/uptime`, signed both ways;
  older consoles answer 404 and nothing breaks) and the report shows them in the same strip as the Fleet site detail
  (`Core\UptimeStrip`, also used by the Fleet detail and the customer reports). Without a console the e-mail has no uptime part.
- **Fix (N3261-1):** after an update, background jobs no longer run in the same request with the previous version's code
  (`Updater::installedNow()`), so the update check cannot overwrite the new version line.

## 3.27 – tasks and follow-ups (4 November 2026)

Stage 3 of the team work (owner, 2 November: "Start with Step 3"). Migration 0113 adds `ka_tasks`; `KALETA_DB_VERSION` 113.
No setting key, POST field, MCP tool, parameter or Czech alias renamed; new non-essential MCP tools `list_tasks`, `save_task`
and `complete_task` (also in the `work` tool set), `my_work` gains `tasks`. No subtasks, dependencies, time tracking or
recurrence.

1. **Tasks** (`Core\Tasks`) on enquiries, pages and collection items or on their own: title, person, due date, optional reminder;
   anyone for themselves, someone else only when both may open the record; the person, the creator or an administrator changes
   it.
2. **Follow-ups:** after a reply, one click adds "Follow up: <form>" for tomorrow, in 3 days or in a week; pages and items with a
   review date offer a task for that day.
3. **My work:** tasks first – overdue, today, the next 7 days, later – with Done and snooze; a Team tab for administrators.
4. **Reminders:** the scheduler job `tasks` notifies once when a reminder or due time passes (`task_due` in the bell and by
   e-mail as each person chose).
5. **Hand to Claude:** a task becomes an Ask Claude request with a link back.
6. **Leavers and privacy:** open tasks are part of the hand-over; tasks handed to nobody (or left on a removed or blocked
   account) are listed under "Not assigned" in the Team tab with "Reassign to…" (MCP `list_tasks` `assignee:none` for
   administrators); tasks follow an enquiry's deletion and anonymisation.

## 3.28 – customers: contacts and companies (5 November 2026)

Stage 5 of the team work and the core of the built-in CRM (owner, 3 November: "ok start the step 5"; stage 4 skipped). From
here on Kaleta holds a customer database. Migration 0114 adds `ka_contacts`, `ka_companies`, `ka_contact_activities` and a
`contact_id` on enquiries, bookings and newsletter subscribers; `KALETA_DB_VERSION` 114. No setting key, POST field, MCP tool,
parameter or Czech alias renamed; new non-essential MCP tools `list_contacts`, `get_contact`, `save_contact`, `log_activity`
and `merge_contacts` (also in the `work` tool set).

1. **Customers** (Workspace → Customers, section `contacts`, given by the migration to everyone who has Enquiries): a list with
   search and filters, a company list, a customer's detail with a timeline of enquiries, replies, bookings, the subscription,
   comments, tasks and logged calls, meetings, e-mails and notes; a company's detail; CSV export and import for
   administrators; Ctrl K finds customers.
2. **One record per person:** new enquiries, bookings and confirmed subscriptions are linked after the response by the
   normalised e-mail address; older rows are linked by a background job in batches. Two different addresses are never merged
   by themselves – administrators merge by hand. A company is suggested from the e-mail domain, never for free-mail.
3. **A customer box** on every enquiry and booking.
4. **Retention and privacy:** `contacts_months` (default = the enquiries' period) anonymises customers with no activity; an
   expired enquiry leaves "first contact on … through the form …" without the message; finding, exporting and erasing personal
   data cover customers; the record of processing has "Customer relations"; Claude's changes to customers cannot be undone.
5. **Encrypted off-site backups (D-W12):** the database copy for FTPS or S3 is encrypted (XChaCha20-Poly1305, or AES-256-GCM
   without sodium) with a recovery key kept outside the database; until an administrator saves the key, off-site database
   copies wait and the site says so; restoring on a new hosting needs the key.

## 3.29 – one fleet screen for twenty sites, and visits without bots (3 November 2026)

The first of two Fleet console releases for an owner running about twenty sites (owner, 3 November: "Do just first and
second release"), plus honest visit numbers (owner, 3 November: visits "crazy high … compared to the Google Analytics").
The console still never reaches into sites: sites report, the console only links. Migration 0115 adds `views` and
`window_start` to `ka_stat_navstevnici`; `KALETA_DB_VERSION` 115. No setting key, POST field, MCP tool, parameter or Czech
alias renamed; new non-essential MCP tools `fleet_today` and `fleet_health`; `get_stats` gains `counting` and
`confirmed_since`.

1. **Today** (the console's new start tab): everything that needs attention across all sites in one ranked list – urgent
   first, then by weight and age – with the site, the customer, how long it has lasted and an Open link the console builds
   from the site's address and a fixed admin path (`Fleet\Checks::link`). Filters by customer and severity; the Inbox is
   its own tab, old inbox links keep working.
2. **Health matrix:** one row per site, one column per check (`Fleet\Checks::CHECKS`, 18 checks: uptime, reporting,
   updates, off-site and local backup, certificate, domain, mail records, SMTP, two-step sign-in, automatic updates, cookie
   scan, privacy policy, accessibility statement, handover, Claude connection, jobs, cron), worst first or by customer;
   sites before 3.29 show "Update the site to see".
3. **Workload:** enquiries this and last week, unanswered after 24 h, median hours to first reply, open and overdue tasks,
   new customers – per site and in total, counts only.
4. **Heartbeat:** sites now also send states, days and counts for off-site backup, mail setup, two-step sign-in, the
   cookie scan, the privacy and accessibility pages, the handover checklist and the workload – never hosts, names,
   addresses or texts; the console bounds every new key (`Fleet\Checks::clean`).
5. **Visits confirmed by the browser:** a page view counts only when `image/vitals.js` reports a page actually shown to a
   person (`POST /hit`, signed path, no cookies); `Front\Stats::hit` re-checks bots (a longer list), prefetch, origin,
   signed-in users and a flood cap (300 views a day, 10 in 10 seconds). Rendering and the page cache count nothing; signed-in
   users are never counted; visitors without JavaScript are no longer counted. Old rows stay; Statistics, the monthly report
   and the chart mark the day the new counting started (`stats_confirmed_since`).
6. **Admin footer:** the heart link reads "Support the development of Kaleta CMS" (Czech "Podpořte vývoj Kaleta CMS").

**3.29.1 (3 November 2026):** say why the visits dropped, a quieter menu, and password links that work again (owner,
3 November). No migration; no setting, MCP tool, parameter or Czech alias renamed.
1. **Visits drop explained:** `Front\Stats::spansSwitch`/`recentSwitch` is the one rule wherever visits are compared or
   charted – the dashboard chart, the monthly report (tile, rows, e-mail, page), MCP (`get_stats.counting_note`,
   `get_monthly_report.counting_changed`, `get_site.visits_counting_changed`) and the Fleet console (site page, fleet
   reports): across the switch to browser-confirmed counting a drop is marked "counting changed on …", never a down arrow
   or an alert. The heartbeat carries `stats_confirmed_since`, bounded by `Fleet\Console::day`.
2. **Menu after sign-in:** only Content is open (`Admin\MenuGroups::OPEN_AT_SIGN_IN`), plus the group of the current screen;
   the person's own opening and folding lasts until they sign out.
3. **Invitation and password links set the password again** (PR #35): `Admin\PasswordReset::page()` let its empty defaults
   win over the token since 1.4.0, so the form posted an empty token and no password was ever saved. Also: sign-in with an
   e-mail address when exactly one account has it (same limits, lock and two-step checks, no enumeration), and a password
   link opened in a signed-in browser signs that browser out after the new password is saved.

## 3.30 – the Fleet console, second release: inventory, expiry calendar, rollout and console tasks (3 November 2026)

The second of two Fleet console releases for an owner running about twenty sites (owner, 3 November). The console still
never reaches into sites: sites report and ask, the console answers and links. Migration 0116 adds
`ka_fleet_sites.update_window`, `ka_fleet_customers.update_window` and `ka_fleet_expiry_reminders` and turns `canary` rings
into `pilot`; `KALETA_DB_VERSION` 116. No setting key, POST field, MCP tool, parameter or Czech alias renamed (`list_sites`
reports the ring `pilot` where it said `canary`); new non-essential MCP tools `fleet_inventory`, `fleet_calendar` and
`fleet_plan`, and the prompt `fleet_fix`.

1. **Inventory** (`Fleet\Inventory`): version, channel, PHP, database, web server family, features, add-ons, languages and
   last report per site, a filter per column, search and CSV export (formulas blocked). New heartbeat keys `db_server`,
   `web_server`, `features`, `addons`, `languages`, `recovery_key_since` – system names and a day only, bounded by
   `Inventory::clean`.
2. **Expiry calendar** (`Fleet\Calendar`): certificate and domain dates on a month view and a list with the thresholds of
   `Fleet\Checks`, recovery keys, reminders by the hourly job `fleet_expiry` (30 and 7 days by default) once per date and
   threshold (`ka_fleet_expiry_reminders`), e-mail to the alert recipients and the bell for administrators; an iCal feed per
   administrator (`/fleet/calendar.ics`, only its hash stored, revocable).
3. **Rollout** (`Fleet\Rollout`): rings pilot / normal / careful with delays (0 h, 48 h, 168 h by default); normal and careful
   sites also wait until every pilot runs the release without problems (a pilot on a newer version counts; one that
   stopped reporting while healthy is left out); maintenance windows per customer with a per-site
   override (at least two hours; a window crossing midnight belongs to the day it starts). Security releases and versions allowed by hand still
   go everywhere at once. A Rollout tab shows per ring what is updated, may update, waits, is held by pilots or failed.
4. **Console tasks** (`Fleet\ConsoleTasks`): 3.27 tasks about `fleet_site:<id>` or `fleet_customer:<id>` – a panel on the site
   page and a new customer page, counts in Sites and Customers, overdue tasks on Today (amber), My work, the bell and
   reminders as usual; a task follows its site, a customer with an open task stays, merging moves tasks.
5. **Fleet plan for Claude** (`Fleet\Plan`, MCP `fleet_plan`, prompt `fleet_fix`): per site the open Today items, console
   tasks and the site's own connector address (`<url>/mcp`, never a token); Claude works one site at a time through that
   site's connection; the console runs nothing. The site page shows the connector address with a copy button.

## 3.31 – the sales pipeline, CRM stage 6 (8 November 2026)

Stage 6 of the staged CRM (owner, 1 November): one pipeline of deals next to contacts, enquiries and tasks. Migration 0117
adds `ka_deals` and `ka_deal_stage_history`; `KALETA_DB_VERSION` 117. No setting key, POST field, MCP tool, parameter or
Czech alias renamed; new non-essential MCP tools `list_deals`, `save_deal`, `move_deal` and `pipeline_summary`.

1. **Deals** (`Core\Deals`, admin `Modules\Pipeline` in the Workspace group): a board and a list, a deal page with its
   history, comments and tasks, boxes on the contact, company and enquiry pages, and a "Create deal" step from an enquiry.
   A value is a number and a currency only; no forecasts, no weighted totals.
2. **Stages** (setting `pipeline_stages`, 3–12 stages, each open, won or lost): every move is kept in the history; won and
   lost close the deal and lost asks for a reason (`Deals::LOST_REASONS`).
3. **Work on a deal**: `deal:<id>` is a target for comments, tasks, notifications and responsibility
   (`Assignment::KINDS['deal']`); the bell and e-mails name a deal only by its number. My work and the Team tab list deal
   tasks. A unit test now checks that every target kind is parsed, swept, assignable and labelled.
4. **Privacy**: an enquiry linked to a deal is kept while the deal is open and for the retention period after it closes;
   a contact with an open deal is not anonymised. Erasing or anonymising a contact leaves the deal without the person
   (`Deals::unlinkContacts`), and comments on that person's deals follow the rule of the person's own comments (erased –
   deleted, anonymised – text removed). The personal data file lists the comments and tasks under each deal.
5. **Reports**: the monthly report and summaries show counts only, never values. Merging two contacts moves their deals;
   the site export leaves deals out; deals are not journalled for undo.
6. **Fixes**: the Settings redirect for a moved field goes only to a same-origin http(s) path (code scanning #44); the
   dummy bcrypt hashes in `Core\Auth` are no longer written as one literal (code scanning #45); a Fleet expiry reminder
   whose e-mail fails is retried by the mail queue (after 5, 30, 120 and 720 minutes), and `fleet_expiry` reports how many
   are waiting.

**3.31.1 (10 November 2026):** fixes after 3.31 (owner, 9 November). No migration; no setting, MCP tool, parameter or
Czech alias renamed; new setting `fleet_certificate_reminders`, new MCP output key `fleet_calendar.certificate_reminder_days_before`.
1. **Stages keep their kind while deals are in them**: `Deals::saveStages` refuses to change open/won/lost of a stage that
   holds deals that do not fit the new kind and says how many (`Deals::dealsFitKind` – data left inconsistent by 3.31.0
   can still be repaired); labels and order still change.
2. **A customer's timeline without Pipeline access** shows a deal only as "A deal" with its time and number – no title,
   stage, person or link (`Deals::neutralTimeline`, also in MCP `get_contact`).
3. **`save_deal` with an enquiry honours `stage`** (an open stage; the first open stage stays the default).
4. **Fleet certificate reminders 14 and 7 days before** (`fleet_certificate_reminders`); domain registrations keep
   `fleet_expiry_reminders` (30 and 7). A reminder already sent within a threshold counts for it (`Calendar::covered`).
5. **Today: an update rollout held back by the pilot sites for more than 3 days** is one item per release (also in MCP
   `fleet_today`), linking to the Rollout tab, which now says how to release it.
6. **Backups**: an encrypted copy uploaded back for a restore (`.enc`) no longer counts as a local backup anywhere
   (`Backup::listLocal` – schedule, System status, dashboard, monthly report, `get_health`, heartbeat); local backups and
   uploaded copies rotate separately (10 each, ties in the same second broken by name) and a restore holds its file with its
   own marker (`.hold-<name>.<token>`, expired after an hour and then deleted) and rotates only after it finished; a failed off-site copy records `backup.failed` (`offsite`; for media once when it starts failing) with a
   short text from `RemoteBackup::failureText` (no password, user name, e-mail addresses or paths; texts saved before
   3.31.1 are masked when System status, `get_health` or the heartbeat read them).
7. **Smaller**: System status points the automatic suspension and its warnings to Settings → Security; Customers and Pipeline link to their
   own guide articles; Czech labels for the customer CSV import and export.

**3.31.2 (10 November 2026):** security release from the deep audit of 10 November (N99–N103). No migration; no MCP tool,
parameter or Czech alias changed.
1. **N99 (high): script through a builder class.** `image/web.js` swaps a collection list only from the same origin and
   path and only from an HTML answer of that page; class names starting with `ka-` are reserved for Kaleta
   (`Build::CLASS_PATTERN` – builder, MCP, shared classes, imports, kits) and are stripped from builds saved earlier.
2. **N100:** turning two-step sign-in on asks for the current password; an account that has a second factor keeps it
   until it is turned off with the password. Recovery (backup code, administrator reset) is unchanged.
3. **N101:** a personal token needs the current password; a password change (My account, or by an administrator in Users)
   revokes the account's personal tokens unless the form says otherwise. The update itself revokes nothing.
4. **N102:** `/ulohy` (cron and the check after an update) runs in maintenance mode, so an update is no longer rolled back
   there and tasks keep running.
5. **N103:** a failed automatic update is tried again after 1 hour and after 12 hours (`Updater::RETRY_DELAYS`); the check
   re-asks on 502/503/504; the site e-mail hears of the first and the last failure.
6. **Pre-release review:** in Users an administrator's own e-mail, password and two-factor reset are ignored (the form
   points to My account, which asks for the current password); setting another user's password or turning off their
   second factor asks for the acting administrator's current password (recovery unchanged: an administrator resets
   others; a locked-out sole administrator has the reset e-mail and backup codes).
7. **Pre-release review:** every "current password" check (`Auth::confirmPassword` – My account, Users) counts on the
   sign-in's counter: ten wrong in a row lock the account for 15 minutes; the session stays and the form says so.
8. **Pre-release review:** when another installation holds the update lock, the background job makes no backup and
   postpones its try by 10 minutes (`Updater::BUSY_WAIT`, `update_attempt` = `verze|pokusy|další`) instead of retrying
   on every request.
9. **Pre-release review:** rich text sanitized by `Html::safe` (news and page texts of non-administrators, category and
   tag descriptions, imports, a collection field in Custom HTML) drops `ka-` classes (any case); the editor writes none.

## 3.32 – the CRM for daily use, and a safety net (10 November 2026)

The first release of the plan of 10 November – 10 December: the Workspace read and used every day, and a safety net under
the whole system. Migration 0118 adds two indexes (`ix_poptavky_datum`, `ix_notifications_target`); `KALETA_DB_VERSION`
118. New setting `default_currency`. No setting key, POST field, MCP tool, parameter or Czech alias renamed; MCP changes
are additive: `save_deal` with `enquiry` titles the deal by who and what unless `title` is given and makes the creator
responsible when nobody is named, and `my_work` lists unanswered enquiries nobody handles whether read or not, while they
are new or no older than 30 days. MCP keeps
raw numbers for deal values and keeps the form and number for enquiries (no `name`).

1. **Names** (`Core\RecordTitle`): an enquiry is named by who and what – the person's name (else the company, else the
   e-mail) and the collection topic or the first six words of the message – in the enquiry list (new Who column), the
   enquiry's page title, My work, task rows, the bell and the dashboard's latest enquiries; a deal names its source
   enquiry. A place in the bell shows the name only to someone who may open that section; e-mails, MCP `my_work` and the
   change log keep the number and the form (a deal is still named only by its number there). The form notification's
   subject adds who wrote it – the name or the company, never the e-mail address; the mail log (`ka_posta.predmet`) and
   the `mail.failed` event keep only "<form>: <site>" (`Mail::send` `$logSubject`; a queued message keeps the full subject
   in its body until it is sent). A deal made from an enquiry does not outlive its person: wherever the enquiry is deleted,
   expires, is erased or anonymised, a deal title that is still the generated one, names who wrote or quotes the message
   becomes "#<id> – <topic>" before the enquiry data goes (`Deals::forgetEnquiryWords`, `Deals::neutralTitle`; erasing or
   anonymising the customer does the same).
2. **My work**: an enquiry stays under "Unanswered enquiries nobody is responsible for" until someone is responsible or a
   reply is sent – opening it is not handling it – while it is new or arrived within the last 30 days
   (`MyWork::UNASSIGNED_DAYS`; the dashboard tile does not query a list it leaves out). "Take it" makes such an enquiry yours (only while nobody has it). Every
   task can be edited in its row, and its title opens the record it is about.
3. **Create deal and defaults**: "Create deal" on an enquiry opens a short New deal form prefilled from it (title from who
   and what, customer, company, the person handling the enquiry, first open stage; value, currency and close offered but
   empty; a customer not linked yet shows as "the enquiry's customer" and is linked when the deal is made, "—" is
   explicitly nobody). A new deal without a word about its responsible person gets its creator; an explicit nobody stays nobody. The
   reply form of an enquiry offers "Follow up if they do not answer" (tomorrow, in 3 days, in a week) as a task of one's own.
4. **Glossary** (`Core\Glossary`, pinned by a unit test in cs, de and es): "Responsible person" everywhere (was "Handled
   by" on an enquiry and "For" on a task; the bell says "… made you responsible"); the people of the CRM are Customers
   ("New customer", tabs "People" and "Companies"); in Czech the enquiry kind Sales is "Prodej" (a deal stays "Obchod") and
   a lost stage is "ztracená" in every sentence.
5. **Money and default currency**: one formatter (`Deals::formatValue`, `Deals::moneyFormat`) for cards, lists, totals,
   the deal page and the customer's deals – thousands separated, `.00` left out, the sign or code placed by the admin
   language (£18,000, 18 000 Kč). Settings → General → Currency (`default_currency`, `Deals::defaultCurrency`): automatic
   = the company's country, else the currency most of the site's deals use (an existing site keeps its currency), else
   CZK on a Czech site and EUR elsewhere as in 3.31 – the time zone is no evidence; a value saved without a currency takes
   it.
6. **Row buttons, landing, dashboard, look bar**: row actions are at least 32 px high (40 px on touch screens), 8 px apart.
   People who see no content section land on My work after sign-in (`Admin\Kernel::landsOnMyWork`; administrators keep
   the Dashboard). The dashboard leads with Waiting for you and the tiles; Connect Claude, First steps and the Ask Claude
   examples fold away. The unpublished-changes bar shows only on the dashboard and the Appearance screens.
7. **Accessibility and System status**: Privacy and cookies fits a phone; a table wrapper that scrolls sideways is a
   focusable, named region; yes/no boxes are named by their row; the Statistics and dashboard charts are described for
   screen readers. System status lists its problems first (linked to their rows) and folds groups that are all fine, and
   warns when the daily clean-up (the retention of personal data) has not succeeded for more than 2 days
   (`Scheduler::cleanupStale`). The dashboard's latest enquiries leave spam out, as the enquiry list does.
8. **Safety net**: a PHP deprecation is logged once a day and the page goes on (`App::handleError`; the test suites set
   `KALETA_STRICT_DEPRECATIONS` and still fail on one); `Core\ErrorLog` is the one writer of `chyby.log` – request
   method and path, user number, version and a short trace, e-mails and tokens masked (also 32+ letters-only or
   digits-only strings and the value of "Duplicate entry '…'"), rotated at 1 MB under a lock that re-checks the file, so
   two requests never rotate the history away; System status ignores the cut first line of the log's tail. No clean-up runs
   on a GET page load any more: the trash of news, pages and items and the sweep of orphaned threads moved to the new
   daily job `trash`, the enquiry retention stays in `cleanup`. CI guards: `tools/check-migrations.php` (numbering,
   `KALETA_DB_VERSION`, shipped migrations untouched), `node --check` on every script, a PHPStan checksum and pinned
   browser test packages. `tools/test.sh` stops every background server it started on exit.

## 3.33 – import you can trust (11 November 2026)

A move to Kaleta that says what it did: the import lands where the old pages were, keeps what the old pages had and tells
what still needs a person. No migration (`KALETA_DB_VERSION` stays 118). New setting `starter_pages` (the installer's
record of its sample pages). New MCP tool `read_old_page` (read-only, non-essential, open world; listed only for
connections that may run `import_website`). New parameters `keep_starter_pages` and `replace_starter_pages` on
`import_website` and `import_wordpress`. No setting key, POST field, MCP tool, parameter or Czech
alias renamed; output keys are additive: `import_website` – `starter_pages` (preview), `starter_pages_result`,
`addresses_changed`, `old_site_reachable`, `kept`, `attention`, a starter choice's `how`, `visible` and `home`,
`home_waiting` in the result; `import_wordpress` – `starter_pages` (preview and summary, `home_waiting`),
`addresses_changed`; `read_old_page` – `partial` and `stylesheets_note`; `migration_report` – `on_sample_pages`, `site_wide_forms`, `title_changes`, `old_site_reachable` and
the row status `sample`; `site_audit` – `move`; `list_pages` – `layout_not_rebuilt`.

1. **Empty install for a move**: the installer offers "Empty site for a move" (`web=move`) – only a Home placeholder, the
   hidden privacy policy template and the default look; no About us, Services, Contact, news category or welcome news.
   Every install records its sample pages with a fingerprint of what it wrote (`Core\StarterPages`, setting
   `starter_pages`); "Start from an export" and every Kaleta export import write an empty record (`{}` – no sample pages,
   never the legacy rule). A site from 3.20–3.32 without a record has the legacy rule, which must prove the installer's
   own row: the k-th lowest page carries the k-th starter title and address, one shared `zmeneno` within 60 s after the
   first account was created, no description, SEO title, image or noindex, no build or text version, no translation, no
   row in `ka_import_mapa` and no imported Kaleta export – when in doubt it is not a sample page.
2. **Starter pages replaced**: when an import would create a page at the address of an untouched sample page (no edit
   stamp, no draft, the same fingerprint) – or the old home page meets the sample Home – the sample page goes to the trash
   under a free `-sample` address and the imported page takes the address (its subpages follow it). An edited or
   translated sample page is never replaced. A page recognised only by the legacy rule is never replaced by default: it
   is unticked in the admin preview and replaced over MCP only when `replace_starter_pages` names its old address. The
   sample Home is replaced and `home_page` switched only when the imported home page is visible – imports bring hidden
   pages, so the sample Home stays the home page (`/` never goes 404) and the preview, the result (`home_waiting`) and
   MCP `next` say what to do after publishing. The preview offers the choice per page (a ticked box in the admin,
   `keep_starter_pages` over MCP, `["*"]` = all) and says when a published sample page will be replaced by a hidden one;
   the result lists what was replaced and kept. A WordPress import analysed before 3.33 replaces none. Import and export
   lists the untouched sample pages of an existing site with how each was recognised and moves them to the trash in one
   step (Home stays, and so does a published page recognised only by the legacy rule).
3. **Nested addresses** (`Core\ImportPath`): both importers keep the old path, parents and all (`/services/heating/boilers`),
   instead of flattening it with a 301; the parent page is linked (also when it is imported after the child). Over 120
   characters the last part is shortened (never below 20); a first part the system uses lands at the last part as before.
   Redirects only where the address changed; every changed address is listed.
4. **What the URL importer keeps** (`Builder\HtmlWidgets`): page-builder widgets are recognised generically, by structure
   and meaning – the ARIA pattern (a button with `aria-controls` and its panel, `role=tab`/`tabpanel`), `<details>`, the
   addresses of map and video frames, players drawn by a script, counters in data attributes – with no code path per
   builder. They become Form, FAQ, Tabs, Video, Map, Embed and Counter elements; a form whose fields a script draws becomes
   a visible placeholder and an attention line; honeypot and captcha fields are left out. Linked files (PDFs, documents,
   videos) go into Media within the download limits (10 per page, 200 files and 300 MB per import run); a video file that
   did not come into Media is left out with an attention line, never hotlinked. The old `<title>` becomes the SEO title
   and `og:image` the share image. Tested with fixture pages of Elementor,
   Divi, WPBakery, Gutenberg, Breakdance, Squarespace, Webflow and Wix.
5. **build_from_html** shares the recognisers: map and video frames, embedded booking pages and forms, `data-counter`
   numbers, accordions and tabs become their elements (also for an administrator, whose other frames stay Custom HTML;
   an autoplay, loop or muted `<video>` – a hero or background video – stays Custom HTML for an administrator); the notes
   say what was recognised.
6. **read_old_page** (`Core\OldPage`): an old page cut into header, top-level content blocks and footer as cleaned HTML,
   each with its background, colours and fonts, plus the page's palette, fonts, CSS custom properties and base look – so
   Claude rebuilds a layout without a second connection. It reads the old site like `import_website` (public addresses,
   pinned connection, limits, robots.txt, politeness gap), only for administrators whose connection may import; the answer
   marks the page as untrusted data. CSS is read in linear time (a rule body over 20 KB is skipped) and the stylesheets
   share a 20 s budget with the gaps between requests – past it the answer is `partial`.
7. **Layout queue** (`Core\ImportLayout`): both importers remember a fingerprint of the build they stored (in
   `ka_import_mapa`, no migration); a page whose published build still has it and that has no draft is "layout not rebuilt
   yet" – a label and the tab "Layout not rebuilt (n)" in the admin list of pages, `layout_not_rebuilt` in `list_pages`.
   Any edit of the build clears it; discarding the draft of an untouched page brings it back. The cleanup job forgets the
   fingerprint of a rebuilt page, so it is never hashed again.
8. **Honest migration report**: an old address that lands on an untouched sample page is an error (`sample_page`), not
   "ok"; a form on most old pages (a newsletter pop-up, a footer form) is reported once as a site-wide form with one site
   check, `form_missing` stays on pages with a form of their own; a changed title is a note; an old site that never
   answered is said plainly (also in `import_website`). Attention lines of an import are stored in English and shown in
   the reader's language – the same over MCP and in the admin.
9. **Move block** (`Core\MoveStatus`): Site audit (above the hand-over checklist) and `site_audit` (`move`) show where a
   move stands – pages and news imported, imported pages whose layout is not rebuilt yet (the same flag as the list of
   pages), sample pages left, redirects, lost and site-wide forms, whether the newest report is clean – counts and links
   only, nothing new stored. A site without imported pages or a report never sees it.

## 3.34 – job applications apart from enquiries (11 November 2026)

Applications to job openings leave the enquiries and get a section of their own. Migration 0119 creates
`ka_applications` (no foreign keys; `source_enquiry_id` unique, so a move never runs twice); data migration 0120 (the
highest number of the release) switches the feature `applications` on for a site with a Job openings collection,
starts `applications_months` at `job_applications_months` when that was set and otherwise at the site's
`enquiries_months` (an update never shortens the retention; 0 = keep stays keep), and moves every enquiry from a jobs
collection (`zdroj = kolekce:<idk>`) into an application – fields without file paths, CVs listed for sealing, position,
status, replies, responsible person (only one who may open Applications), the old note as an internal note, comments,
tasks and notifications – then deletes the enquiry row and a customer record created only from moved applications
(derived from the data: `ka_applications.source_contact_id`); a deal made from one keeps going without the link. The
move is resumable: each row in its own transaction, a run of at most 15 seconds, the rest on the next requests
(`data_migrations_unfinished`, `Core\Migration`). `KALETA_DB_VERSION` 120. New settings `applications_months` (6),
`applications_pool_months` (12), `applications_notify_full` (off), `applications_claude` (on), `application_templates`,
and the internal `applications_retention_confirmed` (the highest application id confirmed), `applications_seal_after`,
`applications_move_until`, `applications_move_deferred`, `applications_files_since` and `data_migrations_unfinished`;
`ka_applications` carries `attachment_paths` (where the files are, random names only) and `moved_at`. New MCP tools
`list_applications`, `get_application`, `update_application` and `reply_to_application` (extension `applications`, the
work set, never the essentials; English on both sides of the Translator). Additive keys: `site_info` – `features`
(`applications`: `enabled`, `claude`, `can_open`); `list_enquiries` – `applications_hint` on rows of kind job while the
feature is on; `my_work` – `applications_assigned`, `applications_unassigned`; the target `application` for `add_comment`,
`list_comments`, `save_task`, `list_tasks` and `assign`; the Form fields `purpose` and `pool_consent` in the builder
vocabulary; `get_application` – `attachments_locked`; `builder_schema` – `read_only` on a field; `erase_personal_data` –
`erased.applications_held`. No setting key, POST
field, MCP tool, parameter or Czech alias renamed.

1. **What an application is** (`Core\Applications`): a submission of a Form on a job's item page (the item template of a
   jobs collection, the rule of 2.11) or of a Form whose new property Purpose is "Job application" – only while the
   feature is on. It is stored in `ka_applications`, never in the enquiries: no customer record, no deal, no triage, no
   webhook and no "enquiry received" event. The position is looked up on the server – the job of the page the form came
   back to, or on a general form a field labelled like a position matched to an open job; nothing posted is trusted. A
   form imported by 3.33 stays an enquiry form (no Purpose, a page source) until it sits on a job page or gets the Purpose.
   Only someone who may open Applications changes the Purpose (`Build::$purposeAllowed`); others see it read-only and a
   save keeps what the form had.
2. **The section and who gets it**: Applications in the Workspace group is granted explicitly (`Module::GRANTED_EXPLICITLY`)
   – nobody but administrators has it after the update, and the screen tells an administrator to choose. The ready-made
   role HR (Roles) gives Applications, Collections (the job openings) and Requests, and nothing of enquiries or customers.
3. **CVs**: a narrow list of types (PDF, Word, ODT, RTF, text, JPEG, PNG; at most three files – more are refused with a
   message); the detected content must fit the extension and is never HTML, PHP, a script or a program. Each file is
   encrypted with its own random key in `storage/applications/` (not served by the web server on Apache – `storage/` and
   the folder carry a deny-all `.htaccess`; nginx needs the documented deny rule of `system/nginx.example.conf`); the keys,
   names and sizes are sealed with the Vault key of the purpose `applications` (`Vault::SEALED` – moved and resealed with
   the other secrets). A CV that cannot be stored fails the submission with a message to the applicant. A list the key
   cannot open is shown as "CVs cannot be opened – encryption key" (detail, list, System status, MCP), is never purged
   while locked, and while the Vault is `missing` a new list is sealed with the database key (`db:`) and resealed once the
   key is back. A file is downloaded only by a person with the section; MCP lists a CV by name and size and never passes
   its content to Claude. Every sealed file also carries its own key wrapped with the Vault key (header `KCV1`), is
   written next to its place and renamed, and the plain column `attachment_paths` names where the files are, so the
   files can always be found. Sealing a moved CV is crash-safe (`sealEntry`): the copy is written to a name derived from
   the old path, the row's list names it, the copy is opened with the stored key and compared, and only then the plain
   file goes – an interrupted run is finished by the next one (a leftover plain file is checked and removed, a copy that
   does not open is listed plain again). A new upload goes only once its row is stored; encrypted files no row lists
   and half-written ones are removed after 7 days (`sweepOrphans`). Sealing and the moves either way take one database
   lock (`Applications::lockFiles`); a move back deletes the application only while its list is the one it read.
4. **Statuses, responsibility, notes and tasks**: six fixed statuses – New, Reviewed, Interview, Offer, Hired, Rejected
   (with a reason) – any may follow any, every change in the history; a list with tabs (Open, Waiting = new without a
   reply for over a week, each status, All), filters and "with selected". A responsible person, internal notes, tasks and
   notifications use the target `application:<id>` (`Handover::KINDS`, `Assignment::KINDS`); a leaver's undecided
   applications go only to someone who may open Applications. Colleagues' notifications name an application only by its
   number and position.
5. **Replies with templates**: the enquiry reply machinery (SMTP only, Reply-To the sender, the signature, the history with
   its delivery state) with three templates – received, invitation, rejection – in every visitor language, rewritable per
   site language (`application_templates`). A form without a confirmation of its own now sends the applicant the
   "received" template – with no text the visitor typed: the name only when it is a plain name (letters, spaces, hyphens,
   apostrophes, at most 60 characters), the position is the job's own title. The mail log keeps "Reply to application #N: <site>", never a name or the position; the e-mail
   to the form's recipients carries the position and a link only unless `applications_notify_full` is on.
6. **Retention and future openings**: a decided application goes `applications_months` after the decision, an undecided
   one after that long without any activity (the responsible person is told 7 days before, only when they may open
   Applications); an optional consent checkbox
   on the form (`pool_consent`) keeps an application for `applications_pool_months`; a withdrawn consent is recorded by a
   person. Deletion removes the sealed files first, then the row, its notes, tasks and notifications.
7. **Personal data**: a personal data request finds the applications sent from an address (their own e-mail field – not one
   that merely names it) and erases them with their files – also when the CV list cannot be opened (the files are found
   from `attachment_paths`); one whose list cannot be opened and that has no `attachment_paths` stays and is reported
   (`applications_held`, the event `applications.delete_held`); the same for an administrator's delete; applications are never journalled for undo, never in the site export, blueprints or the fleet heartbeat,
   never sent to the site's AI assistant, and Claude reads them only through the connection of a user with the section
   while `applications_claude` is on – on every task path too (`list_tasks`, `complete_task`, `save_task`). The privacy
   text says so.
8. **The update on a site with career forms**: migration 0120 moves the enquiries of the jobs collections (see above);
   their old CV files stay in `storage/prilohy` until the daily clean-up seals them (an id cursor, 200 a day; the
   settings tab counts the CVs still waiting). Nothing moved – by 0120 or by the move by form – is purged until an
   administrator has saved Applications → Settings after it moved: `applications_retention_confirmed` is the highest
   application id confirmed then, so a later move (by anyone) gates only the rows it moved; until then the settings tab
   and the list say how many applications would go and since when, and System status notes moved applications that have
   waited more than 30 days (`moved_at`). A large site moves over several requests: the enquiries still to move stay out
   of everything people without Applications see – the list and detail, the CSV export, downloads and actions by id, My
   work, the customer's timeline, the dashboard, deals and testimonials from an enquiry, bell entries and MCP – and out of
   both retentions and the assistant's triage (`Enquiries::visible`, one condition; a unit test lists every query on the
   enquiries); a row that fails stays an enquiry and is named by number only in the event `applications.move_failed`. The
   customer clean-up runs in batches within the same time and the migration finishes only when no record is left to look
   at; the check is the delete itself, inside the transaction that unlinks the record (a record whose only other enquiry
   has already expired counts as lone and goes). Run by the update request of an older release, 0120 only switches the
   feature, the retention and what is to move on – nothing moves and no customer record goes until the 3.34 code serves
   a request (and not while the update checks itself), so a rolled-back update strands nothing; that first run also takes
   the job enquiries that arrived in between. The move is recorded as counts (the change log and the event
   `applications.moved`), never a person.
9. **Move by form and one by one**: for a career form that is not on a job page, administrators move the enquiries of one
   form (name + source) to Applications and back in Applications → Move by form (`Core\ApplicationMove`, batches of 200,
   safe to run again; the way back decrypts the CVs into `storage/prilohy` and reads them back before anything goes – an
   application whose CVs cannot be opened or written stays an application and is reported). The way back is confirmed on
   a review of the chosen form that says how many move and how many stay and why: an application the retention of
   enquiries (or of job enquiries) would delete at once stays, and so do hired ones and those kept for future openings
   unless the administrator ticks that they go too; the consent, the status and its history do not exist in Enquiries.
   A person with both sections moves a single enquiry with "Move to Applications" on its detail – the same steps for one
   row.
10. **Across the admin**: My work lists the undecided applications one is responsible for and the new ones nobody is;
    Ctrl K finds an application by number, name or position (only with the section); the items list of a jobs
    collection shows Applications (new / all) per job; the builder fills a choice field with the open jobs.
11. **JobPosting**: an identifier, `directApply` when the job page holds a form, remote work from the new preset field
    Workplace (TELECOMMUTE with the company's country), the full address in the company's own town.
12. **Monthly report**: recruitment counts per status and position – never a person; in the month applications left the
    enquiries the enquiry tile says "counting changed" instead of showing a drop.
13. **Czech: a lost deal is "prohraná" again** (owner decision): the 3.32 glossary word "ztracená / ztráty / ztracené"
    for a lost deal or stage goes back to "prohraná / prohry / prohrané" in the Czech admin, reports and e-mails
    (`Core\Glossary`, its unit test pins "Prohraný" and forbids "ztracen…"); the rest of the 3.32 glossary stays, and
    English, German and Spanish are unchanged.
14. **Backups and keys**: backups hold the database only. The restore screen marks a backup older than the first encrypted
    CV or move (`applications_files_since`) and its confirmation says what is lost: applications received after it, and
    that moved ones come back as enquiries. A job enquiry restored next to its application twin stays moved for everyone,
    and deleting it never removes a CV an application lists; a CV sealed after the backup opens from its encrypted copy
    with the key of its header (the download of a restored enquiry, the sealing taking it back for a restored
    application) while the Vault key is the same (or the previous signing key). A new signing key first seals again the CV
    lists held under the key in the database (`db:`) and is refused while one is left.

## 3.35 – sign in with a passkey, and tighter rules for connections and roles (11 November 2026)

Passkeys become the first way to sign in, and five security rules close what the review of 3.33/3.34 left open. Migration
0121 (schema, plus one backfill) adds five columns: `ka_uzivatele.passkey_handle` (the account's opaque WebAuthn user handle
– 16 random bytes, never the account number or e-mail), `ka_uzivatele.passkey_only` (the account's own choice),
`ka_uzivatele_klice.discoverable` (a passkey registered since 3.35 – resident, with user verification),
`ka_uzivatele_klice.rp_id` (the host a passkey is bound to; existing rows get the host of the current `site_url`) and
`ka_contact_activities.merged_email` (the marker of a merge note). `KALETA_DB_VERSION`
121; 0120 stays the data migration of 3.34, the highest number of its release (`tools/check-migrations.php` only needs every
new number above the last tag, and `Core\Migration` runs data migrations by name after the `.sql` files, so 0120 still runs
on a site that jumps from 3.33 straight to 3.35). New setting `signin_passkey_only` (Settings → Security); new `config.php`
switch `KALETA_PASSKEY_ONLY_OFF`. Nothing renamed for MCP: no tool, parameter, Czech alias or result key goes; additive keys
– `untrusted` (the list of keys that hold text from outside the team) on `list_enquiries` (each enquiry), `triage_enquiries`,
`get_contact`, `list_contacts`, `list_bookings`, `list_comments` (on an enquiry or a contact), `list_draft_comments`,
`list_collection_items` (an item a customer's testimonial made) and `get_stats`; `list_applications` and `get_application`
(3.34) carry the same list instead of their sentence; a sentence in the server instructions says what it means. Full
connections only now: `migration_report` (like `read_old_page`), the `url` of `upload_file`, new downloads of
`save_collection_items` `media`, and an add-on tool that declares `openWorld`.

1. **Sign in with a passkey** (`Auth::signInWithPasskey`, `image/klice.js`): "Sign in with a passkey" on the sign-in page
   and the passkeys in the user-name autofill (conditional UI) – no user name, no password. The server finds the account
   from the passkey and its opaque user handle, needs the device's user verification (UV), checks the counter, and uses
   the password's limits (10 failures per address in 15 minutes, 10 in a row lock the account for 15 minutes for the
   password, a blocked account never signs in) with one answer for every failure – nothing tells whether an account exists.
   A lock after wrong passwords never blocks a valid passkey: the passkey sign-in, the passkey second step and "Confirm
   with your passkey" verify the assertion of a locked account – a valid one signs in and ends the lock, an invalid one
   counts on (review M1); the password and the codes stay locked. Passkeys no
   longer need two-factor sign-in; every account may add them in My account and rename them.
2. **The sign-in rules per account** (`Core\SignInPolicy`, `state()`):

   | Account | Passkey alone | Password + app code | Password + passkey | Password + backup code | Password alone |
   |---|---|---|---|---|---|
   | password only | – | – | – | – | yes |
   | two-factor (app) | – | yes | – | yes | no |
   | passkey (with or without the app) | yes | yes (with the app) | yes | yes | no |
   | passkey only (account or site) | yes | **no** | yes | yes | no |

   A passkey counts as the second factor for `require_2fa` – in the admin (`Auth::isMissingRequired2fa`) and for Claude
   connections (`ClaudeConnection::canCall`).
3. **Passkey only** – per account (My account, a fresh confirmation both ways) and per site (`signin_passkey_only`). It
   applies only once the account has a working passkey (discoverable, registered with UV), so nobody is locked out: an
   account without one signs in as before and hears it should add one. Only an administrator who has signed in with a
   working passkey can turn the site setting on; turning it off and changing `require_2fa` need a fresh proof (review L2).
   The escape hatch: `define('KALETA_PASSKEY_ONLY_OFF', true);` in `config.php` switches every "passkey only" off (the
   password and the second step work again) and also lets an administrator whose only second step is a passkey (no app,
   no backup codes) sign in with the password alone (review L1); every admin page and System status warn while it is
   there. Documented in `config.sample.php`, the README (Locked out of the admin) and the guide.
4. **Recovery**: a backup code after the password (also for passkey-only accounts); an administrator's reset in Users (with
   their own password) removes the passkeys, the app, the backup codes and "passkey only"; a password-reset e-mail changes
   the password only – the second step stays.
5. **Registering and removing passkeys, backup codes**: adding or removing a passkey and changing "passkey only" need a
   fresh confirmation – a passkey or backup-code step within the last 10 minutes, or the current password typed in that
   form (a step with the app's code does not count: `Auth::isFreshlyVerified`, the strong proof). One check for every
   sensitive change, `Auth::freshProof` (review M2): an account that signs in with a passkey only is never confirmed by its
   password – turning passkey only off, passkeys, new backup codes, a personal token, a new password or e-mail and the app
   on/off need the strong proof or "Confirm with your passkey" (now also in My account, and for the forms of Settings and
   Users); the OAuth consent follows the same rule on every host. Backup codes (review L4, `Core\BackupCodes`): 64 random
   bits each (xxxx-xxxx-xxxx-xxxx), stored as HMAC under a salt of the list, used up and replaced by compare-and-swap of
   the stored list; older lists keep verifying until replaced. The handle of an account is created by compare-and-swap
   too (review L3). The first passkey of an
   account without backup codes brings eight, shown once. New (owner decision 11 Nov 2026): "New backup codes" in My
   account for an account with a passkey and without the authenticator app – the same fresh confirmation, the codes shown
   once, the old ones stop working (`Account::mayRenewBackupCodes`). Turning the app off keeps the passkeys and the codes.
6. **Old passkeys**: a passkey registered as the second step before 3.35 keeps working after the password (without UV, as
   before); it is marked "second step only", and "Register again" replaces it with one that signs in alone. Used alone, its
   owner hears exactly that.
7. **Hosts**: WebAuthn binds passkeys to the host of the site URL (`SignInPolicy::hostMatches`); another host name of the
   site (an alias, www or not, the IP) gets a link to the canonical address instead of the passkey buttons. Each passkey
   stores its host (`rp_id`); only those of the host of `site_url` count in the rules (`SignInPolicy::keysForHost`,
   `Auth::workingKeys`, review M3) – after a move to another host an account signs in with its password and its app or
   backup codes, never a dead end. A new `site_url` on another host (www vs. no www too) while passkeys exist or the site
   requires them needs a ticked confirmation and a fresh proof; System status reports passkeys of another host, My
   account marks them. The site address is saved without a default port, a trailing dot or slash (review L6).
8. **Consent for a new full connection** (owner decision 11 Nov 2026): "Can also publish" on the OAuth consent is issued
   only with a fresh proof – a completed sign-in or a right current password within `Auth::VERIFIED_WINDOW` (10 minutes,
   `Auth::recentlyVerified`), or the current password typed into the consent (the sign-in's counter and lock). An account
   with a passkey may "Confirm with your passkey" (`Auth::confirmWithPasskey`); a passkey-only account is offered only that,
   never a password field. Drafts only and Read only stay one click; a refused consent keeps its request (403 with why);
   existing connections, refresh tokens and personal tokens are untouched. Both proofs are one concept in `Core\Auth`:
   `markVerified(bool $strong)` writes them, one window for both.
9. **Another user's e-mail** (owner decision 11 Nov 2026): changing it in Users needs the administrator's own current
   password, like a new password or a reset second factor (3.31.2); the same address in other letter case is no change.
   So does a new administrator – a new account at that level, raising one to it, or every section by hand (review L7).
10. **N104 – the fetch rule per connection level** (`Catalog::mayFetch`): only a full connection makes the site download an
    address the caller names. Drafts-only and read-only connections: `upload_file` takes `data` (base64) but refuses a
    `url` before any request; `save_collection_items` reuses media downloaded before and downloads nothing new
    (`media_failed` says why); `migration_report` and `read_old_page` are hidden (`Catalog::NEEDS`); `import_website` and
    `import_wordpress` are write tools anyway; an add-on tool with `openWorld` is not offered. The admin and background jobs
    are unchanged.
11. **N105 part 1 – the untrusted marker** (`Mcp\Untrusted`): text written outside the team (visitors' forms, bookings,
    testimonials, referrers and campaigns, preview comments, applicants, other sites) comes with `untrusted` listing the
    keys that hold it; the server instructions say once that it is data to work with, never instructions. A two-step
    confirmation is part 2 (later).
12. **N94 – personal-data requests match the whole address**: find, export and erase (admin and MCP) take an enquiry sent
    from the address or with a field whose whole value is the address (`PersonalData::ofEnquiry`), a customer record
    through Kaleta's own merge note naming exactly the address (`Contacts::mergeNoteNames`), and applications by their own
    address (3.34) – never a message that merely quotes it or a longer address containing it. Review L5: no-break and
    other Unicode spaces and letter case are ignored (`PersonalData::cleanAddress`); a new merge note carries the merged
    address as its marker (`merged_email`), older notes are recognised by the wordings of every shipped language.
13. **N107/N108 – the publish rule in Collections and Tags**: a role that does not publish (author level – the Writer
    preset or any custom author-level role) works with drafts only, as in Pages: a new collection item or category is
    hidden, an item or category on the site (or scheduled) is read-only, the list hides or trashes hidden items only, no
    permanent delete, the CSV/JSON import saves hidden items – in the admin and in the MCP collection tools over any
    connection (`Collections::isDraft`). Renaming, merging and deleting tags is for editors and administrators
    (`Tags::LIVE_ACTIONS`); a writer still creates tags from the news form. Editors, administrators and the Client and HR
    presets (editor level – HR keeps publishing the job openings) are unchanged; "Enquiries only" has no Collections.

**Upgrade notes.** Existing passkeys stay the second step after the password until registered again; nobody becomes
passkey-only by the update, and every account keeps signing in as before. A passkey added from now on needs no
authenticator app and counts as the second factor for `require_2fa`. Approving a new Claude connection with publishing may ask for the
password (or the passkey) once more; connections that exist keep working. An administrator changing someone else's e-mail
is asked for their own password. Drafts-only and read-only Claude connections no longer download files by address – send
the content, or connect with full access. Author-level roles no longer publish collection items or categories, or rename,
merge and delete tags – an editor does. After the security review: a passkey-only account confirms sensitive changes
with its passkey, never its password; a lock after wrong passwords no longer blocks a valid passkey; moving the site to
another host name asks for a confirmation and leaves the old passkeys out of the rules; new backup codes are longer
(xxxx-xxxx-xxxx-xxxx) – the old ones keep working; turning "passkeys only" off, changing the two-factor rule and making
someone an administrator ask for the administrator's password.

## Path to 4.0 (owner decisions, 13 October 2026)

| Release | What |
|---|---|
| 3.14 | Ready for 4.0 check, the expand steps above, own signatures. |
| 3.15 – the bridge | Code that works on both the old and the renamed tables (the rollback target of 4.0); 4.0 offered only on a separate update source that 3.15+ reads, so older sites never jump straight to a major version. No support window is promised for 3.15 – administrators are prompted to update to the latest release so security fixes apply. |
| 4.0-rc | The contract steps in one release: per-language slugs for every site and the global keys dropped (§3), tables renamed (§4), export format 3 by default, tags keyed by language, redirects looked up by `source_norm`. Rehearsed on copies of the owner's sites; update tests from 3.15 and from 1.0.0 through 3.15; a rollback drill to 3.15. |
| 4.0 | After two weeks of the candidate on the owner's sites. Never installed automatically – the administrator starts it, a backup comes first. MCP tools, parameters and Czech aliases unchanged. |
| 4.1 | UTC instants table by table (§6 step 2); team hierarchy (departments, "reports to"), an org chart and the right contact shown automatically (owner's idea). |
| 5.0 | Column renames per entity. |

`zh` means Simplified Chinese (owner, 13 October 2026). Whether add-ons query core tables is answered by the 3.14
readiness check on real sites.

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
