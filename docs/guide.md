# Kaleta guide

For whoever runs the site: from installation through the page builder to connecting AI.
[Česká verze](prirucka.md)

## 1. Installation and first steps

1. Upload the files to hosting (PHP 8.3+, MySQL 8 / MariaDB 10.6+), create an empty database and open `/install.php`.
2. In the form, choose a **starter site**: *Business website*, *Crafts and services* or *Consulting and agency*. Each brings
   its own style and Home, About us, Services and Contact pages built from ready-made sections with sample texts.
3. Choose **what you want switched on** with one of three profiles: *Website* (pages, news, statistics, redirects and the
   Claude connection), *Website with enquiries* (the same with forms and the Enquiries inbox) or *Front office* (enquiries
   with customers, the pipeline of deals and quotes). **Choose yourself** shows the whole list to tick one by one.
   Customers and the Pipeline start switched off unless you pick Front office; Bookings and Whistleblowing always start
   off. Every feature can be switched on and off later in **Features**; switching off deletes nothing.
4. The installation also creates a hidden draft **Privacy policy** page in the site language, linked from the footer, the cookie bar and the form consent. Fill in the details in square brackets and publish it. A new page from the **Privacy policy** template (Pages → New page) follows what the site has switched on – enquiries, newsletter, statistics, analytics and marketing codes, maps – and fills in your company details. It is always a template to check, not legal advice.
5. The installer is in English, Czech, German and Spanish (it follows the browser's language). When it finishes, it shows the address for connecting Claude and
   a first prompt to try.
6. After logging in, **First steps** on the **Dashboard** guide you through: site appearance, company details, pages,
   the privacy policy and email.

The admin is at `/admin.php`.

**The Dashboard.** The first screen after signing in. At the top are **New page**, **Write a news item** and **View
site**, then the warnings that need a person (addresses visitors did not find, for example), each with a button to fix it,
and what waits for your review. **First steps** lead a new site through its set-up until every step is done – the office
has its own list – and the boxes count what waits in each section. Below are the visits of the last 14 days, the latest
enquiries and what was edited recently; each row opens its record.

**Features.** Administration → Features switches the built-in parts of Kaleta on and off – News, Forms and enquiries,
Customers, Pipeline, Quotes, Bookings, Newsletter, Statistics, language versions, the writing assistant, the Claude
connection and others. A switched-off feature disappears from the menu, its elements are no longer offered in the builder
and its addresses on the site answer “not found”, but nothing is deleted: switch it on again and everything is back. Some
features need another one (the Pipeline needs Customers) and switch it on with them.

**Users and roles.** Roles: an **administrator** can do everything, an **editor** manages content, a **team member** works
in the sections given to them (news only their own, published only with the publishing permission). Add people in
**Users** – each gets an e-mail to set a password, and an administrator can block an account or reset its second sign-in
step. In **Users → Roles** you create **custom roles** – a named set of sections (for example “Sales” with Enquiries only);
changing a role updates all its members. Four ready-made roles are a starting point: **Client** (pages, news, collections,
enquiries, customers and deals, statistics, business details and the appearance area – settings and users stay with you),
**Writer** (news for someone else to publish), **HR** (job applications and the job openings in Collections, nothing of the
customers) and **Office / sales** (enquiries, customers, deals, bookings, quotes and subscribers).

**Help on every screen.** Next to the heading of each part of the admin, **How to use this** opens its article in this
guide (in the admin language, if the guide has it); in the builder the article is in Help (**?**). The admin speaks
Czech, English, German and Spanish – each user chooses in **My account**. Claude's tools (MCP) always speak English.

**Building the site for a client?** In **Settings → Agency** fill in your name, website,
e-mail, phone and logo: the sign-in screen and the foot of the admin show the client whom to ask for help. Before
handing over, the **Handing over to a client** checklist in **Site audit** shows its progress (x of y done) and links each
row to where it is set – site e-mail, an SMTP server that has accepted a message (send a test e-mail), an off-site backup
copy, company details, indexing, the site icon, tracking without a cookie bar, the security contact, administrators
without two-step sign-in or a passkey, unused access, the client's own account (the Client role or a role with the
Appearance area), the monthly report going to the client, Claude's guardrails saved in Claude settings, your contact,
the decisions in the Notebook and background tasks.

**Locked out?** A lost password: **Forgotten your password?** on the sign-in page (it changes only the password). A lost phone
or passkey: a backup code after the password, or another administrator resets the account in **Users**. The only
administrator without a passkey and backup codes adds `define('KALETA_PASSKEY_ONLY_OFF', true);` to `config.php`: every
“passkey only” rule is off and an administrator whose only second step is a passkey signs in with the password alone. The
admin warns on every page until the line is removed – register a passkey again in **My account**, then remove it.

## 2. Page builder

Open the builder from **Pages → Builder** (or the “Edit here” link on the site). The middle is the **canvas – the real
page**, on the left the **Add / Structure** panel, on the right the properties of the selected element.

- **Adding:** click an element or a ready-made section in the Add panel, or **drag it onto the canvas** – a blue line shows
  where it will land (a frame means inside a container). Hover over a ready-made section to see its preview. The
  **Sections library** in the Add panel has two parts: **Ready-made sections** (hero, benefits, services, FAQ…) and
  **My sections** (what you saved from any page with “Save to my sections”). Each insert is a separate copy; a block that
  must stay the same everywhere is a **component** (Appearance → Components).
- **Moving:** the selected element has a ⠿ handle on the canvas – drag it elsewhere. You can also drag in the Structure.
  On tablets and phones tap **Move** (four arrows) and then the place where the element belongs.
- **Text:** double-click a heading or text to edit it right on the canvas; longer edits in the **Content** panel.
- **Style:** the **Style** panel – layout, size, spacing, typography, background. At the top, switch **Desktop / Tablet /
  Mobile**: a value for a smaller screen overrides the larger one only there. The **Hover** and **Press** toggles set the look
  on mouse hover (and keyboard focus) and when pressed – separately for tablet and mobile too. A **typography style**
  (Section heading, Lead…) sets the font in one choice; compose a shadow or border with the pencil ✎ next to the field.
  Grids have a **grid editor**: columns, rows and named areas. **Copy style / Paste style** moves the look to another element.
- **Classes:** in the **Advanced** tab, give an element a class (e.g. `karta`). A class style applies to every element with
  that class across the site – ideal for a repeated look.
- **Shortcuts:** Ctrl+Z undo, Ctrl+Shift+Z redo, Ctrl+D duplicate, Ctrl+C / Ctrl+V copy and paste (even between pages),
  Delete removes, Esc selects the parent element, **?** opens help – where you can also start the **editor tour**.
- **Copy between Kaleta sites:** Ctrl+C puts the element into the system clipboard as text together with its classes and
  components; Ctrl+V in the builder of another Kaleta site inserts it (missing classes and components are created by an
  administrator, existing ones stay). Images still load from the source site over https – replace them from this site's Media.
  Without clipboard access use **More actions → Copy / Paste from another Kaleta site (as text)**.
- **Preview:** next to the device switch choose a **wide monitor (1920 px)** or zoom 50–100 %.
- **Display conditions** (Advanced): show an element only between two dates (a promo banner), only to visitors or signed-in users,
  only in chosen language versions, or only when the address has a URL parameter (`?utm_campaign=jaro`, `?variant=b`).
  A page with a date, sign-in or URL parameter condition is assembled for every visit instead of being served from the page cache.
- **Saving and publishing:** changes save continuously as a **draft** – visitors see the published version until you
  press **Publish**. **Discard changes** restores the published version; **Versions** lists the last 20 publications.
  **Share** creates a link to the draft for a colleague or client: it opens without signing in, is valid for 1–7 days and is not indexed by search engines.
  If someone else edits the page meanwhile, the editor offers to load the newer version or overwrite it. Before publishing,
  a **check** flags buttons without links, images without descriptions, a missing main heading and low text contrast.
- **More elements:** icon, photo gallery with a viewer, tabs, accordion, carousel, map (loads only after a click),
  breadcrumbs, **counter**, **progress bars**, star **rating**, **countdown**, **social networks**, **search**,
  **back-to-top button** and **newsletter sign-up**. Sections support a **background video**, videos a **poster**,
  navigation a **mega menu** and background images **parallax**.

A page that used to be plain text is converted by the builder automatically. You can switch it back to text in the page settings.

**Pages** have their own search-engine title, sharing image and a noindex option (page settings → Search engines and
sharing). A deleted page goes to the **trash** and can be restored for 30 days; **Duplicate** creates a hidden copy including the build.

## 3. Site appearance

**Appearance → Site appearance** changes the whole site at once. Two words, used the same way everywhere: a **starter
site** is sample content plus a style (you choose it at installation), and a **style** is a ready set of colours, fonts,
sizes and corner radius. Kaleta has no PHP themes since 1.6 – the look comes only from Site appearance, shared classes and
the builder.

The page has tabs:

- **Style** – pick a style in one click, then fine-tune it in the other tabs. The style your site is using is marked
  *current*. For a look built from your brand, ask Claude – it saves colours and fonts to the design system.
- **Colours** – primary, secondary, text, background, surface. Shades are derived automatically. The **Readability** block
  checks WCAG contrast; change any pair marked in red.
- **Dark mode** – off (always light), matching the visitor's device, or always dark – with its own text, background and surface
  colours. A **switcher for visitors** adds a light / dark / match-device choice to the header next to the languages; the
  choice is remembered in the visitor's browser.
- **Fonts and sizes** – heading and text fonts (also your own WOFF2 files), base font on phones and monitors, heading ratio,
  content width, and the **typography styles** (Main title, Section heading, Lead…) you pick for elements in the builder.
- **Shapes** – corner radius for buttons, cards, images and form fields.
- **Logo and icon**.
- **Import and export** – download and load the look in the W3C Design Tokens format (DTCG) for Figma or Tokens Studio.

On the right is a live preview of the home page (desktop / phone). Nothing is saved until you press Save.

**The draft look.** A change of the design system, of a shared class or of a menu does not reach visitors at once: it goes
to one **draft look** – from Site appearance, the builder, the menu editor and Claude alike. While there is one, every
admin screen shows a bar with what it changes and three buttons:

- **Preview the whole site** – a signed link that shows the site with the draft look and with the drafts of all pages and
  site parts, for you or a colleague (valid for a day). A bar at the top of the preview ends it.
- **Publish the look** – everything at once. The look before is kept under **Site appearance → Import and export →
  Earlier looks** (the last 20); **Back to this look** loads it into the draft again.
- **Discard** – the published look stays.

A brand-new shared class applies at once – it changes nothing that is already published, and new pages need it.

## 4. Site parts: header, footer, wrappers, pop-ups

**Appearance → Site parts.** Until you publish a part from the builder, its built-in default design is shown.

- **Start from a template:** four headers (logo left and menu right, centred logo, with a contact bar, minimal), four
  footers (columns, compact, with the imprint, with a call to action) and plain or extended wrappers. A template is a
  clean skeleton – colours, fonts and spacing come from your design system. It goes into the part's draft, so the site
  changes only when you publish it; check it on the whole site with **Preview the whole site**.

- **Header and footer** appear on every page. The **Logo**, **Navigation** (hidden behind a button on phones) and
  **Company details** elements fill themselves.
- **Wrappers** (news item, news list, 404 page) add sections around content the system assembles. The **Page content**
  element marks where the system inserts it.
- **Variants:** for the header and footer, choose **Add variant**, name it and tick the pages. The variant applies only
  there. An empty variant hides the part – handy for a landing page. Under **Also on** a variant can take a kind of
  content instead of single pages: news items, the news list (with categories, tags and search), the item pages of a
  collection, or every page under a parent page. A ticked page always gets its variant; otherwise the first variant by
  name whose choice fits wins. Claude does the same with `save_part_variant` (`news_items`, `news_list`, `collections`,
  `under_pages`).

**Appearance → Menu** builds the main menu and the footer menu: pages, custom links, news and groups, with one submenu
level under each item. Reorder by dragging or with the arrows. Until you save it, the menu is built from pages “in navigation”.
A saved menu goes into the draft look like a change of the design system, so visitors see it once you publish the look; on
a site with language versions each language version has its own main menu and footer menu, chosen at the top of the editor.

**Appearance → Pop-ups** are windows over the page – a newsletter sign-up, a download for an e-mail, an announcement
bar, a discount or an event. Start from a ready-made template, build the content in the builder and publish it; then set
in **Settings** when and where it shows and turn it on.

- **Type:** a window in the middle, a slide-in panel in the corner, a bar at the top or bottom, or full screen.
- **Trigger:** after a number of seconds, after scrolling part of the page, when the visitor is about to leave, after
  seconds without activity, after a number of pages in the visit – or only by a link or button to `#popup-<address>`
  (that link opens the pop-up at any time).
- **Where:** the whole site, or selected pages, collection item pages and news; a language version, a period, phones
  or computers only, visitors from a campaign (`utm_*`) or from a given site.
- **Frequency:** once per visit, once every N days, until the visitor closes it or sends the form in it, or every time.
  The visitor’s browser remembers it – no cookies.
- **Results:** views, closes and conversions (a form sent or a newsletter sign-up in the pop-up), counted without
  cookies. A form in a pop-up lands in Enquiries like any other.

Escape and the × button close every pop-up, focus moves into a window and returns afterwards, animations respect
reduced motion, and a pop-up waits until the visitor has dealt with the cookie bar.

## 5. Components

Save a block you use in several places (a service card, a contact strip) as a **component**: select it in the builder and
click the “Save as component” icon in the panel header. Edits in **Appearance → Components** apply everywhere.

Whatever should differ between uses, set as **properties** (e.g. Heading, Link) and insert them into the component with
a `{{heading}}` tag. For each use, fill in the values in the Content panel; an empty field means the default.

## 6. Collections

**Content → Collections** are lists of similar things with their own fields – testimonials, team, products, branches, a price list.

1. Create a collection and its fields (short text, longer text, formatted text, image, link, number, date).
2. Add items.
3. In the builder, insert a **Collection list**. Its inside is the template for one card – put `{{nazev}}` (name),
   `{{url}}` (item page) or your own field tags into texts, images and links. The Content panel shows the available tags.

**Straight from the menu (3.18.3):** every collection also has its own item in the left menu, right under
**Collections** – a direct link to its items (products, job openings…), current on every screen of that collection.
Untick *Show in the admin menu* in the collection's settings to leave one out. The menu shows at most 5 collections
(by name), then *More…*; the others stay under Collections, and the quick search (Ctrl K) finds every collection by its name. Only
people who may open Collections see these items. Claude switches it with `admin_menu` of `create_collection` and
`update_collection`; `list_collections` says it for every collection.

**An icon per collection (3.19):** in the collection's settings, pick an **Icon** from the built-in set (a house, a car,
people, a calendar…) – the collection's item in the left menu and the list of collections show it; *List (default)* keeps
the generic list icon. Visitors never see it. Claude sets it with `icon` of `create_collection` and `update_collection`
(the English name, e.g. `"icon": "home"`; `""` puts the list icon back), and `list_collections` returns it.

The list supports **sorting** (also by a field, e.g. price), a **fixed filter**, **filter buttons** for visitors and
**pagination**. When you enable **item pages** for a collection, each item gets an address `/collection/item`; design that
page in the builder via **Detail template**.

On a site with **language versions**, give each item a language. A translated item keeps the same address, e.g.
`/compare/wordpress` and `/de/compare/wordpress`; the language switcher and `hreflang` link the two. Each further
language has its own **Detail template (DE)**. It starts as a copy of the default template, and until you publish it,
items in that language use the default one. The breadcrumbs lead to the translation of the page with the collection's
address (for `/compare`, e.g. `/de/vergleich`).

**Item pages are full pages (1.9).** Each item has **Search engines and sharing** like a page: its own title for search
engines, a description, a sharing image and *Hide from search engines (noindex)* – a noindex item stays reachable but is
left out of search engines, the sitemap, `llms.txt` and site search. Without them the name, the beginning of the first
longer text and the first image are used. A hidden item can **publish itself** at a set time, and every save keeps the
previous version in the **Item history** (the last 20), one click brings it back.

**Structured data for search engines:** in the collection settings say what the items are – a service, a person, a
product, an event or a question with an answer – and which fields fill the properties (price, job title, start…).
Item pages then carry schema.org data next to your company details. An offer needs a price field and a currency.

**Many items at once (3.7):** **Collections → the collection → Import from CSV or JSON** takes a CSV file (from Excel or
another system, comma, semicolon or tab; a Czech Excel file in Windows-1250 is converted) or a JSON list, up to 5,000 rows.
Columns named like a field (its key or label) or like *name*, *slug*, *language*, *order* are paired by themselves; you
can change the pairing, and the preview says for every row whether it will be added, changed or refused and why. An
item is found by its slug, or by the slug made from its name, so importing the same file again updates the items
instead of adding them twice; an empty cell leaves a value as it is. New items arrive hidden unless you tick
*Show new items on the site at once*. An image or file column with `https://` addresses is downloaded into Media after
the items are saved, a few at a time, and the page continues by itself. Claude does the same with
`save_collection_items` – up to 200 items per call with the rules of one item, `dry_run` to show what would change, and
`media` to download images by address (a drafts-only connection saves hidden items only).

**Categories (3.7).** **Categories** on the items screen sort a collection into groups with pages of their own: a category
and its subcategories (two levels), each at `/collection/category` and `/collection/category/subcategory`. A category has
a name, an address, a description, an image, its own title and description for search engines, an order and a switch
*published on the site*; on a site with language versions each language has its own name, address and texts, while the
tree, the order and the image are shared. Tick the categories on each item (an item may be in several) – *Add item* next
to a category ticks it for you. A category page shows breadcrumbs, the description, the subcategories as cards and the
items of the category and its subcategories, 12 to a page (`?page=2`, each page with its own canonical address); the
pages are in the sitemap with `hreflang` to their translations and carry schema.org `CollectionPage` and
`BreadcrumbList`. An address is never both a category and an item of the same collection: saving one refuses the
other's address (an item address made from the name gets a number instead), and should old imported data still clash,
the item keeps its page. A subcategory asked for at the first level (`/collection/subcategory`) redirects to its own
address, so old WordPress category links are best mapped with the pattern redirects of 3.6.

Design the category page in the builder via **Category page template** (on the Categories screen; each language may
have its own). It fills in `{{nazev}}`, `{{popis}}` (the description), `{{obrazek}}`, `{{pocet}}` (visible items, the
subcategories included) and `{{nadrazena}}` / `{{nadrazena_url}}` (the parent). Two settings of the **Collection list**
do the rest: *List* – items or categories – and *Category* – an address, or `*` for the category page being shown. With
`*` a list of items shows that category's items (and pages with `?page=`), a list of categories its subcategories; on
an item page `*` means the item's first category, which gives a "more from this category" list. Elsewhere a list of
categories with an empty *Category* shows the top-level ones – a catalogue overview on any page.

**Previous / next item (3.7).** The element of that name links the neighbouring items on an item page – *Previous
project* and *Next project* under a reference, for example. It follows the order of the administration (order, then
name) or the date (previous = older), stays within the item's category when it has one, can show the names and
thumbnails (the first image field, or another one by its key) and has labels of your own. It is a navigation landmark
with `rel="prev"` and `rel="next"` links; outside an item page it shows nothing.

Over the Claude connection the same is `list_collection_categories`, `save_collection_category` and
`delete_collection_category`, `categories` (slugs) on `save_collection_item`, `category` on `list_collection_items` and
`category_template: true` with the build tools. A drafts-only connection saves hidden categories only.

## 7. Forms and enquiries

The **Form** element (or the *Enquiry form* section) adds an enquiry form. In the Content panel you set the fields (text,
email, phone, list, radio buttons, several ticked options, date, number, consent, and a hidden value – e.g. the product the
form is about, sent with every enquiry but never shown), button text, thank-you message or thank-you page, a confirmation
to the sender and the notification email. Hidden fields and a submission limit fight spam without CAPTCHA or cookies.
If spam still gets through, **Settings → Privacy and cookies → Spam check for forms** adds hCaptcha, Google reCAPTCHA v3 or
Cloudflare Turnstile on top (2.6): the provider's script loads only on pages with a form, the answer is checked on the
server, the secret key never leaves the admin, and a single form can opt out in its settings.
The site can also send each new enquiry to a CRM or Make/Zapier (Settings → Webhooks → New enquiry webhook); conversion
tracking gets a `kaleta:odeslano` event (and a `dataLayer` entry).

An **attachment** field accepts PDF, images, documents, drawings and ZIP up to 10 MB per file. A form whose visitors send
larger files – scans, drawings – can raise its own **attachment size limit** in the Content panel up to 25 MB (3.7). The
panel says what the server accepts (the smaller of PHP's `upload_max_filesize` and `post_max_size`): a higher limit never
applies beyond it, and the form tells visitors the limit that really holds. A file over it comes back as an error on that
field instead of quietly missing from the enquiry; to accept more, ask the hosting provider to raise the PHP limits.

**Google Tag Manager** (Settings → Analytics, 2.6): enter the container ID (`GTM-…`). Consent mode is built in – with the
built-in cookie bar the container starts only after the visitor allows analytics or marketing. Kaleta sends conversion
events to the data layer: `generate_lead` (a form was sent), `sign_up` (newsletter), `popup_conversion`, `click_phone`,
`click_email` and `file_download`. The tab offers a container template with these triggers and GA4 and Google Ads tags
to import into Tag Manager.

**Settings → Webhooks** holds both webhook addresses (a new enquiry, a published news item). The call goes out right after
the page is sent, so a slow receiver never delays a visitor, and every call is signed: the headers `X-Kaleta-Timestamp`
and `X-Kaleta-Signature` (`sha256=` HMAC-SHA256 of `timestamp.body` with the signing secret shown on the tab) let the
receiver check that the call came from your site. The **delivery log** shows every call; a failed one is retried after 1,
5 and 30 minutes and 2 and 12 hours, and a given-up call can be sent again with one click. **Send a test call** checks the
connection. Webhook addresses and the secret are never available over the Claude connection.

**Enquiries.** Submitted messages are in Workspace → Enquiries: status (new, read, resolved), reply by e-mail, CSV export. When the form is on the
page an ad or a newsletter links to, the enquiry also shows the **campaign** from the address (`utm_source`, `utm_medium`, `utm_campaign`…) –
in the admin, the notification email, the CSV and the webhook, without cookies. Enquiries
contain personal data, so they are deleted automatically after a set number of months (24 by default). Every retention period –
enquiries, job applications, customers, quotes, bookings, whistleblowing cases, consent records – is set in one place,
**Settings → Privacy and cookies → How long data is kept**; a shorter period is confirmed on its own screen first.

**Replying (3.49).** A reply goes from the site with your own e-mail as Reply-To and your signature under it. **Saved
replies** (Enquiries → Saved replies, administrators and editors) are your firm's texts, one tab per language version of
the site: pick one in the reply form – the ones in the enquiry's language come first – and `{{name}}`, `{{topic}}` and
`{{company}}` are filled in. A reply and the quote e-mail can carry **attachments**: up to five files, together under
5 MB – internal files you may open (the files linked to the enquiry's customer or to the quote are listed, unticked) or files
from your computer. They are sent at once and never stored; Claude never attaches anything. Long lists – customers,
companies, deals, products, files – are chosen by typing a few letters; without JavaScript they stay ordinary lists.

**Files on a record (3.49).** On a customer, company, deal or quote page, **Link a file** links an internal file to it – the
price sheet of a deal, the contract of a customer. Everyone sees only the files of folders that are for them; the file's
page lists where it is linked. Deleting or anonymising the record removes the links, never the file.

**Where leads came from.** Turn on **Settings → Cookies → Remember where leads came from** and every enquiry and newsletter
sign-up also records the **first page of the visit**, its campaign and the site that sent the visitor – even when the form
is on another page. It is remembered for the visitor's browser tab only, and only when they allow marketing in the cookie
bar (turning it on adds that choice). **Statistics** then show which pages, campaigns, first pages and sites bring
enquiries and sign-ups, with conversion rates, next to visits, devices and pop-up conversions; Claude reads the same
report with `get_stats`. A browser that sends the Global Privacy Control signal counts as "only necessary" without the bar
asking.

The **Embed** element shows a booking calendar, a form or a player from Calendly, Google Calendar, Google Forms,
Microsoft Forms, Tally, Typeform, Airtable, Spotify or SoundCloud – paste the address you share, choose the height. It
loads only after a click, so the page sends nothing to the service before that, and a link to the service is always next to
it. An administrator can also add **code to the head of one page** (Page settings → Search engines and sharing), e.g. the
conversion tag of a landing page.

**Subscribers.** The Newsletter extension adds a **Newsletter sign-up** element: visitors enter an e-mail and confirm it
by a link (double opt-in). Confirmed addresses are in **Content → Newsletter → Subscribers**; export them to CSV, including
the unsubscribe link, for your mailing tool.

Or connect the mailing service you already use in **Features → Newsletter**: Brevo, MailerLite, Mailchimp, Ecomail,
SmartEmailing, or any other service through a webhook (Make, Zapier, n8n). Enter the API key and the list; every confirmed
subscriber is then added to the list, and an unsubscribed one removed from it. The transfer runs in the background and
failed attempts are repeated; **Subscribers** shows the state of each address and can send all existing ones at once.

**Content → Newsletter** sends your latest news to confirmed subscribers straight from the site. There is no e-mail
builder: one template follows your design system – colours, fonts, corner radius and logo – and holds a subject, a
preview text, an introduction, the latest (or chosen) news items, an optional button and your company details with an
unsubscribe link. Save the draft, check the preview, send a test to yourself, then send now or at a set time (sending
needs the publishing permission). Two things must be set up first, and the screen tells you when they are missing:

- an **SMTP server** in **Settings → Mail** – Brevo, Amazon SES, Mailgun or your own mailbox; bulk mail through the
  hosting's `mail()` would end up in spam. Set **Newsletters: e-mails per hour** to your service's limit;
- **cron** calling the tasks address from **Settings → Health** every few minutes: the e-mails go out in batches on each
  call, so a newsletter never stalls on a quiet site.

Every e-mail carries the subscriber's own unsubscribe link and one-click unsubscribe for mail clients. Nothing tracks
opens; links to your site carry `utm` parameters, so **Statistics** show the visits a newsletter brought. The list of
recipients is kept only while sending – afterwards only the counts and dates remain. Claude can draft newsletters and send
tests too, and sends to subscribers only when you ask.

**Subscribers: languages and addresses we do not write to.** Every subscriber keeps the language version they signed up
in, and an address a mail server refused as unknown or a person who asked not to be written to is skipped by every
newsletter and testimonial request (3.51).

**Sending: pause, the brake, what the footer holds.** A newsletter on its way can be paused, resumed or cancelled, it
pauses itself when sending keeps failing, and its footer names the sender – the company name and postal address from
Business details – and why the e-mail arrived (3.51).

- **Pause, resume, cancel.** While a newsletter is being sent its page offers **Pause sending** – nothing more goes out,
  also not from a cron call already under way – and **Cancel the newsletter**; a paused one offers **Resume sending**. A
  cancelled one never sends the rest. A newsletter that stays paused for 7 days is cancelled by itself. Claude can pause a
  send when you ask (no confirmation – stopping is always safe); resuming and cancelling are yours.
- **The brake.** When 5 of the last 50 e-mails fail at the mail server (since the start or the last resume) the site pauses
  the send by itself and e-mails its author. Addresses the server refuses as unknown do not trip it while others are being
  delivered – an old list finishes normally. When nothing got through at all (the server refuses the site), the e-mails of
  that stretch go back to the queue and leave after you resume. Check **Settings → Mail** and **System status** first.
- **Addresses that bounced.** An address the server refused as unknown (5.1.1 and its kin) is given up at once and, when
  the newsletter delivered to someone, remembered: later newsletters skip it. A server that refuses everyone can never fill
  that list.
- **The footer.** Every newsletter names its sender and says why it arrived. Without the company name and postal address
  in **Settings → Business details** a newsletter cannot be sent – a scheduled one goes back to the drafts and its author
  gets an e-mail. The two sentences are default wording in **Settings → Mail**, per language; `{{reason}}` in the
  introduction repeats the reason sentence there. Have the wording checked for your country.
- **The counts.** When a newsletter ends, recipients = sent + failed + skipped (unsubscribed in the meantime, on the list of
  addresses we do not write to, or not sent because it was cancelled). Links carry a campaign name only when the newsletter
  goes to at least 5 people. The send form warns, without stopping you, when the sending domain has no SPF or DMARC record
  or when subscribers also go to a connected mailing service.

**Online bookings.** With the Bookings feature on, visitors book an appointment on the site through the **Booking**
element: they pick a service, a person and a free time, and get a confirmation by e-mail with a link to cancel; the site reminds them before the time. Administrators
set up the **Services** (length, a break after, price text, which people do it, whether you confirm each booking first), the **People** with their weekly hours and days off, and the
**Settings** – how far ahead and how soon people may book, the cancel deadline and the reminder. The list shows the upcoming
bookings by day; open one to confirm, decline or propose another time, mark it done or not come, or add a booking for a
customer who phoned. Bookings hold personal data: they follow the retention of enquiries and the customer gets them on the
timeline.

### Departments, reports to and absences

Administrators create departments in **Users → Departments** (a name, a head, optionally the department it is part of)
and give each account its department and whom it reports to. Everyone sees the result in **My account → Org chart** –
names, positions, work phones and e-mails of active accounts only, without any script and ready to print.

Each person adds their own absence in **My account** (first and last day, a deputy, a reason from a fixed list – never
health details); administrators can add anybody's in Users. The rule is always the same:

- while someone is away **today**, every notification about what they are responsible for (assignments, comments, task
  reminders, review dates, quote and application reminders) still reaches them **and** a copy reaches their stand-in,
  marked "standing in for …". A mention stays theirs only;
- the stand-in is the deputy of that absence – an active account, not away themself, who may open the thing – otherwise
  whom the person reports to under the same conditions, otherwise nobody (one step, never a chain);
- a department may name forms in **New enquiries from forms**: a new enquiry from such a form is handled by the head, or
  by the head's stand-in while the head is away. Nothing is routed until you name a form;
- a task three days past its due date is escalated once to whom its assignee reports to;
- who is responsible for an existing record never changes – only who hears about it and who gets new enquiries.

Claude sees departments, reports to and who is away until when (never the reason) in `list_colleagues`.

**An absence also closes booking times (3.49).** When a booking person is linked to an account (Bookings → People → Account),
an absence of that account – of any reason – closes their free times for those whole days: visitors, the Booking element,
proposed times and Claude no longer offer them. Nothing is copied: removing the absence brings the times back at once.
Bookings and offered times already in those days are never moved or cancelled – the message after saving the absence says
how many there are, and the booking shows the label *the person is away*. A person's form in People lists the absences
from the account (days only); **Link by e-mail** on the People list links every booking person whose e-mail is the e-mail of
exactly one account.

## 8. Company details

**Business details:** registered name, business type, company ID, VAT ID, address, phone, **opening hours** (one per
line, e.g. “Mo–Fr 8:00–17:00”, “Sa 9–12”, “Su closed”), map link and coordinates. The **Company details** element shows
them anywhere on the site and search engines receive them as structured data – so Google shows your hours and address.
For an **imprint** (legal notice, Impressum) fill in the commercial register entry and who represents the company, then
create a page from the **Imprint** template (Pages → New page) – the Company details element in *Imprint* mode lists all
filled-in details of the operator.

**Facts and claims.** Business details → **Facts** holds the numbers and sentences the site states – the year the company
was founded, the number of projects, a price from, the warranty, the area you serve. Write a fact once and use it
everywhere as `{{fact.key}}` in a text, a button or a link (`tel:{{fact.company_phone}}`): the site fills in the value when
a page is shown, so a change here changes every page. The list shows where each fact is used, and **Claims** finds the
sentences with numbers, years and amounts still written as plain text, so you can turn them into facts before they go
stale. Some numbers work themselves out – years since a date, counts of what is on the site. Claude reads and changes facts
with `list_facts` and `save_fact`.

## 9. News, SEO and languages

**News** is the company blog: categories, tags, scheduled publishing, trash, version history. SEO takes care of itself
(sitemap, canonical URLs, structured data, `llms.txt` for AI search). Enable further language versions (`/de/…`) under
**Features → Language versions** and pick the languages in Settings – around forty languages, including all EU languages.
Visitor texts (Search, Read more, forms, cookie bar) are translated into Czech, English, German, French, Spanish, Italian,
Polish, Slovak, Hungarian, Portuguese, Arabic and Chinese; other languages show them in English, with dates in their own format. The installer lets you choose the
site language separately from the admin language.

**Categories and tags.** Every news item belongs to one **category** (News → Categories): it has a name, an address made
from the name, an order and, on a site with language versions, the language its news belongs to – a news item takes the
language of its category. A category cannot be deleted while it still has news. **Tags** (News → Tags and topics) are made
as you type them in the news editor; here you merge two spellings into one (the news items get the other tag and the old
address redirects) or give a tag a description, which turns its page into a **topic** – an introduction above the list of
its news that also serves as the description for search engines.

**Settings → Languages.** The language of the site's own texts (Search, News, Read more…), which the site also declares to
search engines, the other language versions once the feature is on, and the name and description of the site in each of
them – an empty field means the same text as in the default language. Here too is the form of address for German visitors
and the switch *The same address in every language*. Pages, news, categories and collection items get their language in
their own settings.

**Settings → SEO and GEO.** The site does most of it by itself – addresses, descriptions, the sitemap, structured data and
`llms.txt` for AI search. The tab decides only the essentials: whether search engines may index the site at all, whether AI
search engines and assistants may use its content (well-behaved bots respect it; it is not a technical protection), the
form of addresses (without a trailing slash, with one, or `.html` – the other forms redirect with 301) and the verification
codes for Google Search Console and Bing, with the address of the sitemap to submit there.

German has two forms of address, formal (Sie) and informal (du), chosen separately for the admin and for the site. The admin
follows each person's choice (**My account → Form of address in German**; the installer asks too). The texts for visitors
follow **Settings → Languages → Form of address in German**, so you can use "du" in the admin and still address your customers
with "Sie". Claude writes German texts for visitors in the form of address of the site, whatever the admin speaks to you.

System addresses follow the language: Czech versions use `/novinky` and `/hledani`, all others `/news`, `/news/category/…`,
`/news/tag/…` and `/search`. The other form redirects permanently, so old links and search rankings keep working.

**The same address in every language (3.19):** a page, a news item, a category or a tag may use the same address once in
each language version – `/kontakt` and `/en/kontakt` are two pages, as on a multilingual WordPress site. This is how every
site works since 3.19; system addresses and language codes stay reserved in every language. **Tags** belong to a language
version too: the update gave every language its own tag where one tag was used by news items in several languages (the
news items keep their tags, each in its own language), a tag page lists the news of its own version, and tags merge only
within one version. If the update could not switch a site (two items of one language share an address, or the database
user may not change tables), the site works as before and **System status → Addresses in every language** says why and
what to do; once that is fixed, tick *The same address in every language* in **Settings → Languages**.

**Redirects compare the old address exactly (3.19):** letter case and the slashes around still do not matter, but `/cafe` and
`/café` are two addresses now. Redirects whose old addresses used to count as one are listed on **System status → Old
addresses of redirects** – nothing was deleted; keep one of each group in Redirects.

**Translating with Claude:** ask Claude to translate a page or the whole site. Over the Claude connection a translation
starts as a copy of the original page's build, so the layout stays the same and only the texts and links change. The
header and footer of a new language start as a copy of the default language's ones.
A language whose home page has no published translation yet is not offered to visitors: it stays out of the language
switcher, `hreflang` and the sitemap until you publish its home page, so you can prepare a translation in peace.

On the first visit, a visitor whose browser prefers one of the site's languages is sent to that version of the page (when it
has a translation); their choice in the language switcher is remembered in the browser, without cookies. Search engine
robots are never redirected. The switcher sits next to the menu by default; to move it to the footer, add the **Language
switcher** element to the footer (a dropdown that opens upwards) and turn off *Language switcher* on the Navigation element.

## 10. Writing assistant and Claude

Enable the **writing assistant** under **Features** – it uses your own key. Choose a provider (Anthropic Claude, OpenAI, Google Gemini, Mistral), enter
the API key and model. The assistant only suggests. Text goes to the provider only when you click an assistant button.

- In the builder: **✨ Create a section with AI** (describe what it should contain) and **Rewrite with AI** on element text
  (shorter, longer, more formal, friendlier, fix mistakes). Check the result and fill in facts yourself. Ctrl+Z undoes the change.
- In news: headlines, intro, SEO description, tags, proofreading, image descriptions and translation into another site language.

The **Claude connection (MCP)** is switched on in new installations (under Features on older sites). Then:

- **Connector in the Claude app (recommended):** in Settings → Connectors add a custom connector with the address
  `https://your-site.com/mcp`. Claude sends you to the website to sign in and confirm access (OAuth), nothing to copy.
  Connected apps are listed (and can be disconnected) in **My account**. This needs the site on HTTPS. A site in a
  subfolder works too – the Claude app finds the sign-in there; for a tool that does not, use a personal token.
- **Claude Code:** run the command below and confirm access in the browser. Alternatively, create an access token under
  **My account** (it asks for your current password) and add `--header "Authorization: Bearer <token>"`. Changing the
  account's password revokes its personal tokens unless you untick that in the form; connected apps are always disconnected.

```bash
claude mcp add --transport http kaleta https://your-site.com/mcp
```

Claude then builds the whole site with your account permissions: it sets the look (colours, fonts, shared classes),
uploads images and fonts to Media, assembles pages, the header, the footer and the item page of collections, fixes single elements by id, sets the site
name, company details and redirects from old addresses, and writes news. It can also create header and footer **variants** for
selected pages, set a page's parent, language version and scheduled publishing, bring back an older published build into the
draft, and read enquiries (only with access to Enquiries). After every build change it gets a **signed
preview link** to the draft (valid for 60 minutes, opens without signing in) – it checks the result and can pass the
link to you. With each save it also gets the **check before publishing** (buttons without links, images without descriptions,
the heading outline) so it can fix them first. Page and site-part builds and news are created as a **draft** and published only when you say so (and only
with publishing permission). The menu, design system and shared classes go into the **draft look**, which visitors see
only once it is published (by you, or by Claude when you ask). Site settings, redirects, collection items and page text
take effect straight away – the previous page text and item go to the version history. Claude can move a page to
the trash (restorable for 30 days in the admin); the site e-mail, webhooks, mail, backups and security settings cannot
be changed over the connection.

**What a connection may do.** When you connect Claude (or create a token), you choose:

- **Everything your account may do** – build, edit and publish, never beyond your role;
- **Drafts only** – Claude builds pages, writes news drafts and prepares look changes, and you publish them; it cannot
  change settings, redirects or anything else on the live site;
- **Read only** – Claude reads pages, settings, the audit and the change log and suggests changes.

**Which tools it lists.** You also choose the tool set: **Essentials** (the default when you connect Claude) lists the
tools for pages, builds, the look, collections, media and requests – a much shorter list, so every conversation starts
faster and keeps more room for your work. Claude finds the other tools (news, redirects, settings, bookings…) with
`find_tools` and runs them when it needs them – reads with `run_read_tool`, drafts and changes with `run_tool`, and
deleting, sending or anything that reaches outside the site with `run_destructive_tool`, which Claude confirms with you
first (your Claude app asks for each runner separately) – with the same access and rules. **All tools** lists
everything at once (the default for a personal token – Claude Code and scripts). **Work** is for a colleague's own Claude
(my work, enquiries, customers, deals, tasks, comments, job applications), **Migration** for moving a site to Kaleta (the
imports, old pages, the builder, redirects and the migration report) and **Fleet** for the fleet console (offered only
where the Fleet console extension is on). A tool set only decides what Claude sees first – never what it may do: that is
the access you chose and your role. Connections from before 3.10 keep all tools; switch any connection in My account
(Change the tool set).

My account shows the access and the tool set of every connected app and token. The **change log** marks every change Claude made with the
name of its connection, and can show only Claude's changes or only people's; Claude reads it too (`list_changes`).

**Claude settings.** Claude → Claude settings opens with the state of the connection, the address to connect and what
to do next. Under **Instructions for Claude** write what Claude should keep to – how you address customers, words to use or
avoid, house rules; every connection gets them when it connects. The **guardrails** say what Claude must never do on this
site even when asked, and the site audit checks that they are saved before a hand-over. Claude also offers ready-made tasks
(below).

**Ask Claude.** Claude → Ask Claude is for colleagues who do not use Claude themselves: they write a request in plain words
(“add the new opening hours to the contact page”), and the request waits until a connected Claude picks it up – when
somebody asks it to, or on a scheduled run. Claude works in drafts and writes back what it did; the request shows its state
and the answer, and the person who asked gets an e-mail when it is done. The same box is on the Dashboard; without a connection it
saves the request for later.

**Settings over the connection.** Besides the site name and company details, Claude can switch extensions and language
versions, the cookie bar, SEO switches, analytics codes and the code in `<head>` – when you ask for it.

### Is Claude connected?

Claude works on the site only when it calls it – when someone asks it something in the Claude app or Claude Code, or when a
scheduled run fires. The site cannot tell whether Claude is "online", but it knows whether a connection exists, when Claude
last called and whether requests are picked up by themselves. The top bar of every admin screen shows it next to your
account (on a phone as a dot; the words are there for screen readers), and it leads to **Claude settings**:

| In the top bar | What it means | What to do |
|---|---|---|
| **Claude connected** (green dot) | A connection exists and Claude called the site in the last 7 days; the time of the last call is next to it. | Nothing. |
| **Claude idle** (amber) | A connection exists, but Claude has not called for 7 days – or not at all yet. | Ask Claude something about the site, or set up a scheduled run. |
| **Claude: requests wait** (amber) | The scheduled run that picks up requests missed or failed its last run, or is more than 6 hours late, and new requests wait. With nothing waiting it says **scheduled run stopped**. | Check the routine in Claude (Scheduled runs). |
| **Claude not connected** (red ring) | No valid connection – none was made, all were revoked or have expired. | Connect Claude (Claude settings shows the address). |

Without a connection **Ask Claude** says so before you write: the button becomes **Save for later**, a saved request
waits until someone connects Claude, and so do the page's "Ask Claude to translate it" and the blueprint request. When
requests are picked up only when someone asks Claude, the box says so with the time of the last call; when a scheduled run
does it, the box says when it runs next. The **Requests** list has the same line at the top and highlights a new request
that has waited over 48 hours while nothing picks it up. **Claude settings** open with the state, the last call, how
requests are picked up, when the first connection ends and what to do next. **System status** warns when new requests wait
and Claude is not connected or the scheduled run does not run, and when a connection ends within 14 days; `get_health`
gives the same as `claude_connection`, and a fleet console shows it for every site (and puts such a site into its inbox).

### Ready-made tasks for Claude

Every Kaleta connection brings its own ready-made tasks – complete step-by-step runbooks for the jobs people ask for
most. They come with the connection itself, so they work on every Kaleta site and in every Claude app, with nothing to
install. In claude.ai and the Claude desktop app open **+** and choose the task under your site's connector; in Claude Code
type `/` and pick it as `/mcp__kaleta__migrate_site` (the part in the middle is the name you gave the connection). Some
tasks ask for a detail first, such as the old site's address or the language code. Every task works in drafts, follows
your instructions for Claude, and asks before anything is published, deleted or sent.

| Task | What Claude does |
|---|---|
| `migrate_site` | Moves a site from WordPress (with WooCommerce, Polylang or WPML) or any other platform: the import, the look, rebuilt pages, collections, redirects with patterns and 410, and the migration report. |
| `launch_site` | Takes a fresh install to a finished site: details and facts, a blueprint, the look, pages, header, footer, menu, legal templates and a check before launch. |
| `add_language_version` | Adds a language version – also regional or right-to-left – and translates pages, collections, menus, the header and the footer; checks the fonts; publishes it when you say so. |
| `weekly_care` | Health, what changed, the audit, broken links, addresses not found, enquiries and translations, with the three things to do next. |
| `newsletter_release` | Drafts a newsletter from your latest news, sends you a test, and sends it to subscribers only after your explicit yes. |
| `set_up_bookings` | Sets up online bookings: services, people, hours and exceptions, rules and e-mail texts, the booking page. |
| `compliance_check` | The record of processing, cookies, the privacy policy template, accessibility and personal data requests – templates, never legal advice. |
| `build_page`, `translate_page`, `write_news` | A new page, a translation of one page, a news item – as drafts. |
| `audit_and_fix` | Runs the site audit and fixes what is safe. |
| `work_requests`, `review_pending`, `scheduled_run` | Works through your staff's requests, lists what waits for your review, does the scheduled runs of a routine. |
| `draft_blueprint` | Makes a blueprint for a kind of business none of the shipped ones fits. |

The optional "Kaleta for Claude" plugin for Claude Code carries five of these runbooks as skills, generated from the same
tasks; you do not need it when your site is connected.

## 11. Moving from WordPress or another Kaleta site

**Administration → Import and export → Import from a website** (2.6): enter the address of a site on any platform – Wix,
Webnode, Jimdo, Squarespace, Joomla, Drupal or WordPress without an export. Kaleta reads its sitemap (or follows its links),
shows what it found, and on confirmation turns each page into a hidden builder page with its images in Media; blog posts
become news, and old addresses redirect to the new ones. The header, footer, menus and cookie bars of the old site are
left out and the look comes from your design system. Since 3.33 the page keeps what its builder made of it – Elementor,
Divi, WPBakery, Gutenberg, Breakdance, Wix, Squarespace or Webflow alike: a form becomes a Form element with the same
fields (a form drawn by a script gets a placeholder, and the result says where), an accordion or FAQ keeps its questions
and answers, tabs, counters, YouTube, Vimeo and video files, and maps become their elements, linked files (PDF and other
documents) go into Media, and the old page title and share image become the page's SEO title and share image. Claude does
the same with `import_website`, reads an old page section by section with its colours and fonts (`read_old_page`) and
can then match the look and tidy the texts.

**Large sites (3.7):** the import and the migration report (**Import and export → Check the move before going live**, Claude's
`migration_report`) read the old site's sitemaps in batches and go through up to 3,000 addresses – a shop with a thousand
products in two languages fits. They respect the old site's `robots.txt`: a page it disallows is never downloaded (the
report still checks that its address works here), its `Crawl-delay` is kept (up to two seconds), and without one there
is a short pause between requests. Claude reads a long report 100 problems at a time with `offset`.

**Administration → Import and export → WordPress:** upload a WordPress export (Tools → Export, an XML file). The import
turns posts into news, pages optionally **straight into the builder**, downloads images into Media and creates redirects
from old addresses. You can run the import again – whatever it already converted is skipped.

The import also brings the **navigation menus** (3.6): links to imported pages, posts and categories point to their new
addresses, custom links stay, and submenus are kept. The menus go to the draft look, so the live navigation changes only
when you publish the look. No accounts are created for WordPress authors – a news item belongs to the user here with the
author's e-mail, otherwise to you. Tick **everything hidden** to bring news in as drafts and pages hidden. Claude does the
same with `import_wordpress`, always hidden: it takes an export uploaded with `upload_file` (the file stays private, it
never goes to Media) or the export's address, works in batches it resumes, and reports what came over, what was skipped
and why, the redirects and the menus.

### Moving a bilingual Arabic site

A WordPress site in English and Arabic (Polylang or WPML, often with a WooCommerce shop) moves in one import:

1. **Export everything** in WordPress (Tools → Export → All content). With WPML, use its *Export and Import* add-on so the
   languages and translation groups are in the file. A large export goes over FTP into `storage/import/`.
2. **Import with the language versions added** – tick *add the language versions … to the site* in the preview (Claude:
   `import_wordpress` with `add_languages: true`). Arabic becomes a language version of the site, each page, post and
   product goes to its version and is linked to its English original (the language switcher and hreflang follow it).
3. **Addresses stay Latin.** An Arabic translation of a product takes its original's address (`/ar/product/knee-brace`);
   Arabic pages, posts and products without an original get a transliterated address (`/ar/mn-nhn`). Every old address
   redirects: `/ar/…` with the Arabic slug, `?lang=ar`, `?p=123` and the product categories under WooCommerce's
   `/product-category/…`.
4. **The shop becomes a catalogue** made from the Products preset: code, price, availability, parameters, summary,
   description and the featured image; product categories become categories of the collection with pages in both
   languages. Variations, orders and coupons stay in WordPress (the report lists them). A shop with its own category
   base needs a pattern redirect – the migration report shows it.
5. **Set the home page** to the imported one: the Arabic version appears on the site (switcher, hreflang, sitemap) once its
   translation of the home page is published.
6. **Choose a font with Arabic letters.** Site appearance and the site audit warn when the heading or text font has none;
   choose *Modern sans-serif* (the device's system font) or *Arabic sans-serif*, or upload Noto Sans Arabic, IBM Plex
   Sans Arabic, Cairo or Tajawal as a custom font.
7. **Run the migration report** against the old site before switching the domain; it should find every old address here.

Measured on a generated shop with 396 products in each language (`tools/fixtures/wordpress-scale.php`): the content
arrives in 14 batches (about 7 seconds), the 396 images in another 14, each request well under 128 MB.

**Moving a Kaleta site** to another host or starting a new site from an agency's starter kit: on the old site create
**Import and export → Export of the whole site**, then install Kaleta on the new host and choose **Start from an export**
in the installer. Sign in, open **Import and export → Import from Kaleta** and upload the `.zip` (a larger one over FTP
into `storage/import/`). The preview shows what the export holds; after you confirm, a database backup is made and the
import runs in batches on its own – pages, news, collections, components, shared classes, site parts, menus, pop-ups,
redirects, the media library and its files, the site name, company details, languages and the look. Every row keeps its
number, so all links between pages, menus and components stay valid, and every build goes through the same checks as
a save in the builder. User accounts, passwords, keys, tokens and the mail and backup settings are never in an export,
so the new site keeps its own; imported news belong to you. The import works only on an empty site – a fresh install,
optionally with a starter site.

The content file `obsah.json` in the archive is versioned, and its `README.txt` describes it. Kaleta 3.14 writes format 2
(Czech table and column names), which every Kaleta since 1.8 imports, and reads format 3 too: the same content under the
English names of the rename map (`pages`, `news`, `tags`, `slug`, `language`…), which Kaleta 4.0 will write. Dates stay in
the site's own time in both.

**One page** travels too: **Pages → Export** downloads a JSON file with the page's build, the shared classes it uses and
its components (including components inside components). **Pages → Import** on another site creates the page hidden,
adds the missing classes (a class the site already has keeps its own look) and the components, and reuses a component
imported before.

## 12. Site audit and System status

**Administration → Site audit** goes through the whole site and lists what to fix, each with a link to where you fix it:
links to pages that do not exist (in pages, site parts, templates, components, pop-ups, the menu, items and news) and
broken external links found in the background, pages and item pages without a description, duplicate titles, menu
items pointing at hidden or deleted pages, the builder checks (buttons without a link, images without a description,
the heading outline), addresses that often end in “page not found” and have no redirect, and **accessibility** for the
European Accessibility Act (WCAG 2.2 AA): colour contrast of the design system, links and buttons whose text does not
say where they lead (“click here”), images in text without a description, empty links, tables without header cells,
frames without a title, and a missing accessibility statement. Claude runs the same audit with `site_audit` and can fix
what it finds.

**Addresses not found.** When visitors repeatedly land on an address that does not exist, the start screen says so and
**Review the addresses** opens the list in Redirects. For each one choose **Redirect** (the form is filled in, add the
target) or **Ignore** (nothing replaces it, or it is a bot); **Ignore all** dismisses the warning until a new address
appears. Probes of bots looking for other systems are not recorded, and an address that works again drops out by itself.

**Patterns, gone addresses and many redirects at once (3.6).** In **Redirects** an asterisk stands for the rest of an
address: `/blog/*` → `/news/*` sends `/blog/2019/post` to `/news/2019/post`, and `/*/amp` → `/*` drops a suffix. An
exact redirect always wins over a pattern, the longer fixed beginning wins among patterns, and a rule that would lead
in a circle is refused. A pattern never sends visitors to another site unless its target is a full address you typed
(a wildcard there is allowed only after the domain). The type **gone (410)** needs no target: the address answers that
the page was removed for good, so search engines drop it – useful for spam addresses left by a hacked site. **Import
from a CSV file** takes one redirect per row (old address, target, code 301, 302 or 410) or the CSV export of the
WordPress Redirection plugin, shows what will be added, changed or refused, and saves only after you confirm; up to
5,000 rows at a time. Claude does the same with `save_redirects` (up to 500 per call, `dry_run` to check first).

**System status.** Site care → System status checks what keeps the site running: PHP and its extensions, the folders the
site writes to, the encryption key and stored passwords, the core files, two-step sign-in of administrators, the background
jobs (cron), backups and updates, the mail DNS records (SPF, DMARC, DKIM), the certificate and the domain registration,
whether `config.php` is readable only by the site, and the Claude connection. A row in red or amber says what to do, and
some are fixed with one click. Here is the tasks address for cron, the error log
(the last 40 entries), the e-mail the site sends when a backup, an update or a job fails (at most one an hour) and the
status in JSON for monitoring tools such as UptimeRobot.

**Firewall.** Settings → Firewall guards the public site and the Claude connection – never the administration, so you
cannot lock yourself out, and addresses of your local network are never blocked. Block single addresses or networks, whole
countries (only behind Cloudflare or when the hosting sends the country), visitors who send too many requests and bots
probing for other systems; such a visitor is blocked for a while. The tab says what the site runs behind and lists the
last 50 refused requests; the log keeps 30 days.

**Broken links.** Site care → Addresses → Broken links lists the links in published pages, news items and collection items
that lead nowhere – to a page that no longer exists here, or to another site that answers with an error. The check runs in
the background, one news item every few minutes, so it never slows the site; each row says where the link is and opens
the place to fix it. A link that works again drops out by itself. The site audit counts the same links, and Claude reads
them with `list_broken_links`.

**Kaleta 2.0** is one clear system: the compatibility layers of the 1.x releases are gone. The per-page pop-up element
became a site pop-up during the update – same content, trigger and frequency, shown only where it was, and its button
opens it (Appearance → Pop-ups). Old admin addresses of 1.3 lead to the start screen, old class names and helpers of
1.3 no longer exist, and the settings answer only to their current names – the Claude connection still accepts the old
ones, so no connection breaks.

## 13. Backups, updates, export

**Settings → Backups and updates:** signed updates and automatic database backups – every day something changed on the
site, otherwise once a week; the last 10 are kept. **Off-site copies** upload each backup to an FTPS server or S3 storage
(Amazon S3, Backblaze B2, Wasabi, Cloudflare R2) and copy the `media/` folder to the same place – only new and changed
files, in the background, so even a large media library gets there bit by bit. The tab shows whether the copy is complete.
Since 3.19.1 an FTPS copy checks the FTP server's certificate, as a browser does: a server with a self-signed certificate
(common on a home NAS) is refused until it has a certificate from a recognised authority such as Let's Encrypt – or use S3.
On the rare server whose PHP has no cURL with FTPS, the copy is still encrypted but the certificate cannot be checked, and
the tab says so.

**Signing key (3.19.1):** the site signs the links it hands out – shared previews of drafts, downloads of gated documents,
unlocked password-protected pages – with its own key. **Make a new signing key** in Settings → Backups and updates
withdraws all of them at once, for example when a preview link went to the wrong person. The confirmation lists what stops
working: every shared preview link and the whole-site preview, gated download links already e-mailed, unlocked protected
pages (visitors type the password again), forms open in visitors' browsers (one reload), Custom HTML in elements copied to
the builder clipboard before, share images get new addresses (`/og/…`, social networks fetch them again), today's limits
per sender of the whistleblowing channel and today's visitor count of the statistics start again. Claude connections are
not affected – their tokens do not depend on this key. Restoring a backup made before the new key keeps the new key
(since 3.46). It is written to the change log.
While the encryption key has not been moved to config.php yet, the stored connector secrets and whistleblowing reports are
encrypted again under the new key in the same step; while that move is half done, a new signing key waits.

**config.php (3.19.1):** it holds the database password and the encryption key, so the installer makes it readable only by
the site – mode 0600 where PHP runs as the owner of the site's folder (most shared hosting), otherwise 0640 so that the web
server's group can still read it. System status warns when everyone on the server can read it and, where PHP owns the
file, fixes it with one click (**Make config.php readable only by the site**); otherwise set the mode in your FTP client or
the hosting's file manager.

To **restore a site after losing the hosting**: install Kaleta on the new hosting with the same table prefix, upload the
latest database backup over FTP into `storage/zalohy/`, copy the `media/` folder back from the FTPS or S3 copy, then click
**Restore** at that backup in Settings → Backups and updates and sign in with the accounts from the backup.

**Import and export → Export of the whole site** creates an open package with the content and media – for moving (see
above) or keeping your content outside Kaleta. Enquiries, subscribers and accounts are not exported.

The public read-only API of 1.x (`/api/novinky`) ended in 2.0. Use the JSON Feed (`/feed.json`) or RSS for news, the
Claude connection (MCP) for working with the site, webhooks for events and the site export to take your content out.

## 14. Sales: pipeline and quotes

Customers and the Pipeline are features: switch them on in **Features**, or choose the *Front office* profile when you
install. Quotes are a feature of their own.

**Pipeline** (Workspace → Sales) keeps your deals from the first contact to won or lost on one board. Create a deal with
**New deal**, or with **Create deal** on an enquiry – the customer, the company and the title are filled in from it. The
board has a column for each stage; move a card by dragging it, with the keyboard (Space, the arrows, Space) or with the
stage menu. **Won** and **Lost** close a deal, and Lost asks for the reason. The page of a deal says its stage once, in
**Move**, and keeps the stage history, the enquiry it came from, its quotes, the responsible person and the team's comments
and tasks. Plan the next step as a task on the deal – the filter “No next step” shows the deals nobody is moving.
Administrators rename, add and reorder the stages in **Stages**. Reports count deals, never their values.

**Quotes** are switched on in Features. **Internal prices** are prices only the team sees (a row per product or variant,
or your own rows; a CSV file can be imported); changing them needs the right *Quotes: change internal prices*. Make a quote
with **New quote**, or from an enquiry, a deal or a customer – the customer and the lines from the enquiry basket come
along. A quote is sent by e-mail (Settings → Mail must have an SMTP server); the customer opens it by a private link,
downloads a PDF and – when the settings allow it – accepts or declines it on that page. The number is given when it is
first sent. Claude prepares quotes as drafts and sends one only when you ask.

## 15. Customers

Customers are a feature: switch it on in **Features** (or choose the *Front office* profile when you install); contacts are
kept for every enquiry also while it is off. A **customer** appears by itself when somebody sends an enquiry, books an appointment or signs up for the newsletter – one
record per e-mail address; **New customer** adds one by hand. The page of a customer has one **timeline**: enquiries with
the first line of the message, replies, bookings, deals, quotes and the team's log of calls, meetings, e-mails and notes
(**Log a call / meeting / e-mail / note**). Its **Tasks** panel lists its own tasks and, under *On its enquiries and
deals*, the next open task of each of its enquiries and deals, with a link to where it is done (3.50; deals only for
whoever has the Pipeline).

People of one firm belong to a **company** (the Companies tab), which has its own page with its people and deals. Two
records of one person are merged by an administrator with **Merge with…** – always a decision of a person. Ctrl K (⌘K on a
Mac) finds a customer by name, e-mail or company from anywhere. Customers hold personal data and are never shown on the
site: a customer with no activity for the period in **Settings → Privacy and cookies** (24 months by default) is
anonymised (see chapter 18).

## 16. Job applications

**Applications** come from the forms on your job pages and from forms whose *Purpose* is “Job application”; attached CVs
are stored encrypted. Only the people with the section Applications see them – tick it in Users or give the ready-made
role **HR**. The list opens on the applications not decided yet; *Waiting* shows new ones without a reply for more than a
week. Move an application through New, Reviewed, Interview, Offer and Hired – or Rejected, with a reason; the **Board**
shows one position in columns. Reply from a template (received, invitation, rejection) and the status moves with it; the
templates for each language are in the settings of Applications.

The **Talent pool** keeps people who agreed to hear about future openings until their consent runs out. Applications are
deleted automatically after the period in Settings → Privacy and cookies, and each one shows the day.

## 17. Internal files

**Internal files** are the team's documents – price lists, contracts, manuals – that never get a public address. An
administrator creates folders and decides who sees each one: everybody with the section, chosen roles or departments.
Upload a file into a folder with a description and tags so colleagues find it by a word; **Replace** keeps the older
versions, each still downloadable, and the status says whether a document is still valid. Files are downloaded only
through the administration. A file can be linked to a customer, a deal or an enquiry and then appears in its box. Only an
empty folder can be deleted; a file is deleted by an administrator or by whoever uploaded it. Claude finds files by their
name and description but never gets their contents.

## 18. Privacy and retention

**Whistleblowing:** the channel takes reports on its own page (`/_report`), with up to five attached files; they are stored
encrypted, and only the readers chosen in its settings open a case – others with the section see case numbers and dates.
Confirm each report and give the reporter feedback in time – the screen shows both deadlines. The reporter gets a case
number and an access code to follow the case, read your messages and add information without giving a name. Closed cases
are deleted after the period in the settings.

**Personal data on request:** when somebody asks what the site holds about them, open **Enquiries → Personal data
request** and enter their e-mail address – the screen lists every place (enquiries with their attachments, the
subscription, e-mails waiting to be sent, testimonial requests, bookings), downloads it as a file for the person and, when
you tick that you want it, erases it for good. Claude does the same when you ask it to *find their personal data*, and its
answer also covers customers, applications and comments. Erasing is always your decision, and a backup restored later
applies the erasures again.

**Retention:** Settings → Privacy and cookies holds every retention period in one place – how long enquiries,
applications, customers (24 months without activity by default, then anonymised), quotes, bookings, the cookie consent log
and other records are kept. The site deletes older ones by itself; a shorter period than before is confirmed on its own
screen. Claude prepares the **record of processing activities** for your privacy policy from what the site really does. Among the
records kept for a fixed time: the recipients of a newsletter, deleted the day after it ends, and a paused newsletter,
cancelled after 7 days.

**Cookie bar.** The same tab decides the cookie bar: the built-in one (its text and the link to your privacy policy, in
every language version), an external one by its code, or none for a site that sets no tracking cookies. The built-in bar
records the consents given as evidence for an inspection, for 36 months by default. Analytics and marketing codes from
Settings → Analytics start only after the visitor allows them, and a browser that sends the Global Privacy Control signal
counts as “only necessary”. The **Spam check for forms** is on this tab too.

## 19. My work and My account

Two screens belong to every signed-in person, whatever their role: **My work** in the menu and **My account** behind
your name at the top. Neither is a section an administrator gives or takes away, and neither shows anything you may not
open elsewhere. The bell next to your name leads to your notifications.

**My work.** Lists what waits for you: your tasks first, then the enquiries you handle, quotes to finish or follow up, the
pages and items you are responsible for and the answers to your requests to Claude – each row opens the place where the
work is done. **New task** adds a task for yourself or a colleague, with a due date and a reminder; complete or snooze it
right in the list. **Summary** shows how much the team did in a period, as counts only, and administrators also have the
**Team** tab with everybody's open tasks and the tasks nobody has. Giving a record a **Responsible person** never hides it
from anybody else.

**Notifications.** The bell collects mentions, comments on what you are responsible for, things assigned to you and review
dates, kept for 90 days. **Mark all as read** clears the count. How you hear about them by e-mail – each one right away,
one summary every morning, or none – is your own choice in My account; a notification about a record opens that record,
and nothing in the list is sent to anybody else.

**My account.** Its tabs are yours alone. **My details** – name, e-mail, the admin language (and the form of address in
German) and how notifications reach you. **Absence** – add the days you are away with a deputy: while you are away a copy
of your notifications goes to the deputy (chapter 7 has the rules). **Security** – your password, two-step sign-in with an
app, passkeys and backup codes. **Claude** – the apps and personal tokens connected under your account, with their access
and tool set, ready to disconnect (chapter 10). **Signature** – the signature under your e-mail replies. **Colleagues** –
the positions and work phones of your colleagues, A–Z or by department, and the org chart.

## 20. Media library

**Content → Media** holds every image and file of the site: photos for pages and news, logos, PDFs to download, fonts.
Upload by choosing files or dragging them onto the page, or drop an image straight into the text in the editor. Sort
files into **folders**, search by name, description or file name, and move several files at once with the boxes in the
list. A file stored by a Claude connection limited to drafts waits under **Waiting for approval** until you publish it.

**Image sizes and descriptions.** Every uploaded image gets smaller variants and a WebP copy by itself, and the site
serves each visitor the size their screen needs. Give each image a **description for screen readers (alt)** – blind
visitors and search engines read it; **Replace** swaps the file and keeps every place that uses it. Media → **Unused
files** lists what no page, build, news item, item, component, pop-up, newsletter or setting points at, the same file
uploaded twice, oversized images to make smaller and images without a description, to fill in together.

**Where an image is used.** The detail of a file lists every place that uses it – news, builds, collection items, the
logo – so you know what changes before you replace or delete it. Media has no trash: a deleted file is gone, and files
the site still uses are skipped when you delete several at once. Claude uploads files with `upload_file`, finds images
without a description with `list_media_without_alt` and describes them with `update_media`.

## 21. Statistics and the change log

**Statistics.** Site care → Statistics counts visits without cookies and without storing anything about a person – only
sums per day. It shows visits and page views, the most visited pages, the sites and campaigns visitors came from, devices,
pop-up conversions and – when leads are remembered – which pages and campaigns bring enquiries and sign-ups. Only visits
confirmed by a visitor's browser count, so bots and your own visits are left out. **Speed for visitors** measures how fast
pages load in real browsers (LCP, CLS, INP) and the site audit warns when a page gets slower. Claude reads the same report
with `get_stats`.

**Settings → Analytics.** The built-in statistics are switched on or off here – no cookies and no consent needed. Next to
them you can add Google Analytics, Google Tag Manager or another tool (Matomo, Plausible, your own code): paste the ID or
the code, and with the built-in cookie bar it starts only after the visitor allows analytics or marketing. Kaleta sends
its conversion events (a form sent, a sign-up, a call or e-mail click, a file download) to the data layer, and the tab
offers a Tag Manager container template with them (chapter 7). Keep Google Analytics in one place, or visits count twice.

**The change log.** Site care → Change log (administrators) lists every change made in the admin and over the Claude
connection – who, when, what and in which section – newest first. Filter it by who made the change (people and Claude,
people in the admin, or Claude), by section and by a word in the detail; every change Claude made carries the name of its
connection. The log keeps the history for six months, not the content: an older text comes back from a page's versions
or an item's history. Claude reads the log with `list_changes`.

**Undoing a Claude session.** Changes one Claude connection makes in a row form a **session** (Change log → Claude
sessions). **Undo** puts back everything the session changed – pages, builds and drafts, news, collection items, menus,
settings, the look – as it was before. Rows a person or another session changed afterwards are left as they are and listed;
undo again with *Also overwrite later changes* to put them back too. Sessions are kept for a limited number of days, shown
on the screen.

## 22. Claude's notebook, scheduled runs, blueprints and connections

**Claude's notebook.** Claude → Notebook keeps notes for whoever works on the site next – Claude in a new conversation or
a colleague: decisions (“prices always without VAT”), what was promised to the client, where things are. Notes have a topic
and can be pinned, so the important ones come first; search finds a note by a word. Claude reads the notebook at the start
of its work and writes into it with `write_notebook`, so a decision made once is not asked about again. The hand-over
checklist counts the decisions in the notebook.

**Scheduled runs.** Claude → Scheduled runs keeps the schedules for a routine in Claude – what, how often and when: pick up
requests every morning, weekly care on Mondays, a newsletter each month. The site does not run Claude itself: **Set up in
Claude** gives the prompt for a routine in your Claude app, which connects over a drafts-only connection, does what is due
and reports back. Each schedule shows its next due time and last run with a history, and can be paused; the top bar says
when a run that picks up requests stopped.

**Blueprints.** Business details → Blueprints apply a ready-made plan for a kind of business – twenty ship with Kaleta, from
a restaurant, a clinic or a craftsman to a municipality or a software company – or a blueprint from another site: its questions become facts, and its checks say what the site still
lacks (opening hours, a price list, a booking page). Taking a blueprint off removes its checks and keeps your content.
**Download** saves the current site as a blueprint for the next one; Claude can draft one for a kind of business none of
the shipped ones fits (`draft_blueprint`).

**Connections to outside services.** Administration → Integrations (administrators) connects the site to Google (Search
Console, Business Profile, Sheets), Bing, HubSpot, Pipedrive and Raynet: the site's own OAuth app or an API token, saved and
never shown back, the service's settings, connecting and disconnecting, and the last calls. Then, for example, new enquiries
go to a Google Sheet or to your CRM, opening hours reach your Business Profile, and the queries people typed in Google and Bing
appear in Statistics. Only the permissions the site needs are asked for, and the keys are never available over the Claude connection.

## 23. Fleet console

The fleet console is for agencies and IT teams who look after several Kaleta sites: one Kaleta installation with the
**Fleet console** feature shows all of them on one screen. **Today** shows first what needs attention – a failed backup,
an update waiting, a certificate about to expire; the inbox collects what waits on every site, and each site keeps its
history and its tasks. Sites belong to customers: the console makes a monthly report for each customer and sends alerts by
your rules. New versions reach the sites in rings, so your test sites get them first.

**Reporting to a console.** On each site, Settings → Reporting to a console pairs it with the console: make a pairing key
in Fleet → Add a site and paste it here (it is valid for 24 hours and only once). The site then sends a signed report every
hour – version, health, background jobs, backups, counts of what waits – never personal data, and the console never reaches
into the site. Here you also let the console decide when new versions install, see the key fingerprints, send a report now
or disconnect. The tab appears once an agency is named in Settings → Agency or the site is paired.

## 24. Add-ons and the other settings

**Add-ons.** Administration → Add-ons lists code from other developers copied into the `extensions/` folder of the site:
switch each one on or off, see what it needs and the error that switched it off, and open the admin pages it adds. Nothing
is uploaded or downloaded from the administration – a developer or the hosting copies the folder, an administrator switches
it on. Add-ons talk to Kaleta only through its documented interface (the Extension API), and an add-on whose error stops a
page is switched off by itself; how to write one is in `docs/EXTENSIONS.md`.

**Settings → General.** The site name and description, the site address (used for every absolute link – change it when the
site moves to another domain), the site e-mail, links to social networks, the time zone, the currency deals start with, the
home page and the address and title of the news, the page cache and **maintenance mode**, which shows visitors a notice while
you work. Changing the
time zone asks whether saved dates are converted; changing the host name warns that passkeys of the old address stop
working.

**Settings of sections.** Some settings live with their section – quotes and the price list, applications, the stages of the
pipeline, bookings, saved replies of enquiries, the whistleblowing channel, Claude's instructions. This tab lists all of them in one place, only for the sections you may open, with a link to each.
It has no fields of its own; the retention periods of every section are in Settings → Privacy and cookies, and the
features themselves in Features.

**Settings → Security.** The rules of signing in for everybody: who must have two-step sign-in (an app or a passkey) – such
a person reaches only My account after signing in until they set it up – and whether accounts with a passkey sign in with it
only. **Suspend automatically** blocks accounts and revokes connections unused for a long time, checked once a day; without
it, System status and the site audit only report them. Blocking addresses and countries is in Settings → Firewall.

**Settings → Mail.** How the site sends its e-mails – password links, notifications, enquiries, replies, quotes and
newsletters: through the hosting's `mail()` (nothing to set up, but some messages end up in spam) or through your own
**SMTP server** – a mailbox at your host, Google Workspace, or a service such as Brevo, Mailgun or Amazon SES, which fills in
the server, the port and the encryption. **Send a test e-mail** checks it; a message the server does not take stays in a
queue and the background jobs try it again.

**Settings → Reports.** Once a month ends, the site makes a report of what happened to it: requests handled, changes by
people and by Claude, updates, backups, security, the site audit, traffic, enquiries, bookings and the newsletter – counts,
never people. View and print the archived reports, or have a short version e-mailed in the first days of each month to you
or your client, with the agency's branding from Settings → Agency. Uptime appears only with a fleet console.
