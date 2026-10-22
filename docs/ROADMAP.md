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
