# Kaleta guide

For whoever runs the site: from installation through the page builder to connecting AI.
[Česká verze](prirucka.md)

## 1. Installation and first steps

1. Upload the files to hosting (PHP 8.3+, MySQL 8 / MariaDB 10.6+), create an empty database and open `/install.php`.
2. In the form, choose a **starter site**: *Business website*, *Crafts and services* or *Consulting and agency*. Each brings
   its own style and Home, About us, Services and Contact pages built from ready-made sections with sample texts.
3. Choose **what you want switched on**: News, Forms and enquiries, Newsletter, Statistics, Redirects, language versions,
   the writing assistant… Features can be switched on and off at any time in the admin (**Features**); switching off deletes nothing. Bookings and Whistleblowing start switched off.
4. The installation also creates a hidden draft **Privacy policy** page in the site language, linked from the footer, the cookie bar and the form consent. Fill in the details in square brackets and publish it. A new page from the **Privacy policy** template (Pages → New page) follows what the site has switched on – enquiries, newsletter, statistics, analytics and marketing codes, maps – and fills in your company details. It is always a template to check, not legal advice.
5. The installer is in English, Czech and German. When it finishes, it shows the address for connecting Claude and
   a first prompt to try.
6. After logging in, **First steps** on the **Dashboard** guide you through: site appearance, company details, pages,
   the privacy policy and email.

The admin is at `/admin.php`. Roles: an **administrator** can do everything, an **editor** manages content, a **news
author** writes their own news and may publish only with the publishing permission. In **Users → Roles** you can create
**custom roles** – a named set of sections (for example “Sales” with Enquiries only); changing a role updates all its members.
Three ready-made roles are a starting point: **Client** (pages, news, collections, enquiries and statistics – the look,
settings and users stay with you), **Writer** (news for someone else to publish) and **Enquiries only**.

**Help on every screen.** Next to the heading of each part of the admin, **How to use this** opens its article in this
guide (in the admin language, if the guide has it); in the builder the article is in Help (**?**). The admin speaks
Czech, English and German – each user chooses in **My account**.

**Building the site for a client?** In **Settings → General → Built and looked after by** fill in your name, website,
e-mail, phone and logo: the sign-in screen and the foot of the admin show the client whom to ask for help. Before
handing over, **Site audit → Before handing over** lists what is still missing – site e-mail, an SMTP server, an
off-site backup copy, company details, indexing, the site icon, tracking without a cookie bar, the security contact,
administrators without two-step sign-in or a passkey, the client's own account, your contact and background tasks.

## 2. Page builder

Open the builder from **Pages → Builder** (or the “Edit here” link on the site). The middle is the **canvas – the real
page**, on the left the **Add / Structure** panel, on the right the properties of the selected element.

- **Adding:** click an element or a ready-made section in the Add panel, or **drag it onto the canvas** – a blue line shows
  where it will land (a frame means inside a container). Hover over a ready-made section to see its preview.
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

Submitted messages are in **Content → Enquiries**: status (new, read, resolved), reply by email, CSV export. When the form is on the
page an ad or a newsletter links to, the enquiry also shows the **campaign** from the address (`utm_source`, `utm_medium`, `utm_campaign`…) –
in the admin, the notification email, the CSV and the webhook, without cookies. Enquiries
contain personal data, so they are deleted automatically after a set number of months (24 by default).

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

The **Newsletter** extension adds a **Newsletter sign-up** element: visitors enter an e-mail and confirm it by a link
(double opt-in). Confirmed addresses are in **Content → Subscribers**; export them to CSV, including the unsubscribe
link, for your mailing tool.

Or connect the mailing service you already use in **Features → Newsletter**: Brevo, MailerLite, Mailchimp, Ecomail,
SmartEmailing, or any other service through a webhook (Make, Zapier, n8n). Enter the API key and the list; every confirmed
subscriber is then added to the list, and an unsubscribed one removed from it. The transfer runs in the background and
failed attempts are repeated; **Subscribers** shows the state of each address and can send all existing ones at once.

**Content → Newsletters** sends your latest news to confirmed subscribers straight from the site. There is no e-mail
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

## 8. Company details

**Business details:** registered name, business type, company ID, VAT ID, address, phone, **opening hours** (one per
line, e.g. “Mo–Fr 8:00–17:00”, “Sa 9–12”, “Su closed”), map link and coordinates. The **Company details** element shows
them anywhere on the site and search engines receive them as structured data – so Google shows your hours and address.
For an **imprint** (legal notice, Impressum) fill in the commercial register entry and who represents the company, then
create a page from the **Imprint** template (Pages → New page) – the Company details element in *Imprint* mode lists all
filled-in details of the operator.

## 9. News, SEO and languages

**News** is the company blog: categories, tags, scheduled publishing, trash, version history. SEO takes care of itself
(sitemap, canonical URLs, structured data, `llms.txt` for AI search). Enable further language versions (`/de/…`) under
**Features → Language versions** and pick the languages in Settings – around forty languages, including all EU languages.
Visitor texts (Search, Read more, forms, cookie bar) are translated into Czech, English, German, French, Spanish, Italian,
Polish and Slovak; other languages show them in English, with dates in their own format. The installer lets you choose the
site language separately from the admin language.

German has two forms of address, formal (Sie) and informal (du), chosen separately for the admin and for the site. The admin
follows each person's choice (**My account → Form of address in German**; the installer asks too). The texts for visitors
follow **Settings → General → Form of address in German**, so you can use "du" in the admin and still address your customers
with "Sie". Claude writes German texts for visitors in the form of address of the site, whatever the admin speaks to you.

System addresses follow the language: Czech versions use `/novinky` and `/hledani`, all others `/news`, `/news/category/…`,
`/news/tag/…` and `/search`. The other form redirects permanently, so old links and search rankings keep working.

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
  **My account** and add `--header "Authorization: Bearer <token>"`.

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

My account shows the access of every connected app and token. The **change log** marks every change Claude made with the
name of its connection, and can show only Claude's changes or only people's; Claude reads it too (`list_changes`).

**Instructions for Claude.** Under **Claude settings** write what Claude should keep to – how you address
customers, words to use or avoid, house rules. Every connection gets them when it connects. Claude also offers ready-made
tasks (in the Claude app under **+**): build a page, run the audit and fix what is safe, translate a page, write a news
item, and a weekly review of the site.

**Settings over the connection.** Besides the site name and company details, Claude can switch extensions and language
versions, the cookie bar, SEO switches, analytics codes and the code in `<head>` – when you ask for it.

## 11. Moving from WordPress or another Kaleta site

**Administration → Import and export → Import from a website** (2.6): enter the address of a site on any platform – Wix,
Webnode, Jimdo, Squarespace, Joomla, Drupal or WordPress without an export. Kaleta reads its sitemap (or follows its links),
shows what it found, and on confirmation turns each page into a hidden builder page with its images in Media; blog posts
become news, and old addresses redirect to the new ones. The header, footer, menus, cookie bars and forms of the old site
are left out and the look comes from your design system. Claude does the same with `import_website` and can then match
the look and tidy the texts.

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

To **restore a site after losing the hosting**: install Kaleta on the new hosting with the same table prefix, upload the
latest database backup over FTP into `storage/zalohy/`, copy the `media/` folder back from the FTPS or S3 copy, then click
**Restore** at that backup in Settings → Backups and updates and sign in with the accounts from the backup.

**Import and export → Export of the whole site** creates an open package with the content and media – for moving (see
above) or keeping your content outside Kaleta. Enquiries, subscribers and accounts are not exported.

The public read-only API of 1.x (`/api/novinky`) ended in 2.0. Use the JSON Feed (`/feed.json`) or RSS for news, the
Claude connection (MCP) for working with the site, webhooks for events and the site export to take your content out.
