# Kaleta guide

For whoever runs the site: from installation through the page builder to connecting AI.
[Česká verze](prirucka.md)

## 1. Installation and first steps

1. Upload the files to hosting (PHP 8.4+, MySQL 8 / MariaDB 10.6+), create an empty database and open `/install.php`.
2. In the form, choose a **starter site**: *Business website*, *Crafts and services* or *Consulting and agency*. Each brings
   its own look and Home, About us, Services and Contact pages built from ready-made sections with sample texts.
3. Choose **what you want switched on**: News, Forms and enquiries, Newsletter, Statistics, Redirects, language versions,
   the AI assistant… Extensions can be switched on and off at any time in the admin (**Extensions**); switching off deletes nothing.
4. The installation also creates a hidden draft **Privacy policy** page in the site language, linked from the footer, the cookie bar and the form consent. Fill in the details in square brackets and publish it.
5. After logging in, **First steps** on the **Dashboard** guide you through: site appearance, company details, pages,
   the privacy policy and email.

The admin is at `/admin.php`. Roles: an **administrator** can do everything, an **editor** manages content, a **news
author** writes their own news and may publish only with the publishing permission. In **Users → Roles** you can create
**custom roles** – a named set of sections (for example “Sales” with Enquiries only); changing a role updates all its members.

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
- **Preview:** next to the device switch choose a **wide monitor (1920 px)** or zoom 50–100 %.
- **Display conditions** (Advanced): show an element only between two dates (a promo banner) or only to visitors or signed-in users.
- **Saving and publishing:** changes save continuously as a **draft** – visitors see the published version until you
  press **Publish**. **Discard changes** restores the published version; **Versions** lists the last 20 publications.
  **Share** creates a link to the draft for a colleague or client: it opens without signing in, is valid for 1–7 days and is not indexed by search engines.
  If someone else edits the page meanwhile, the editor offers to load the newer version or overwrite it. Before publishing,
  a **check** flags buttons without links, images without descriptions, a missing main heading and low text contrast.
- **More elements:** icon, photo gallery with a viewer, tabs, accordion, carousel, map (loads only after a click),
  pop-up window (opened by a button linking to `#window-anchor`, on its own after a delay, after scrolling or on exit),
  breadcrumbs, **counter**, **progress bars**, star **rating**, **countdown**, **social networks**, **search**,
  **back-to-top button** and **newsletter sign-up**. Sections support a **background video**, videos a **poster**,
  navigation a **mega menu** and background images **parallax**.

A page that used to be plain text is converted by the builder automatically. You can switch it back to text in the page settings.

**Pages** have their own search-engine title, sharing image and a noindex option (page settings → Search engines and
sharing). A deleted page goes to the **trash** and can be restored for 30 days; **Duplicate** creates a hidden copy including the build.

## 3. Site appearance

**Appearance → Site appearance** changes the whole site at once:

- **Presets** – a finished look in one click (colours, fonts, sizes), then fine-tune it.
- **Colours** – primary, secondary, text, background, surface. Shades are derived automatically. The **Readability** block
  checks WCAG contrast; change any pair marked in red.
- **Dark mode**, **fonts**, **sizes** (base font on phones and monitors, heading ratio, content width), **corner radius**,
  logo and icon.

- **Typography styles** – size and weight of the named styles (Main title, Section heading, Lead…) you pick for
  elements in the builder. A change here applies across the whole site.
- **Design tokens** – download and load the look in the W3C Design Tokens format (DTCG) for Figma or Tokens Studio.

On the right is a live preview of the home page (desktop / phone). Nothing is saved until you press Save.

## 4. Site parts: header, footer, wrappers

**Appearance → Site parts.** Until you publish a part from the builder, the theme draws it.

- **Header and footer** appear on every page. The **Logo**, **Navigation** (hidden behind a button on phones) and
  **Company details** elements fill themselves.
- **Wrappers** (news item, news list, 404 page) add sections around content the system assembles. The **Page content**
  element marks where the system inserts it.
- **Variants:** for the header and footer, choose **Add variant**, name it and tick the pages. The variant applies only
  there. An empty variant hides the part – handy for a landing page.

**Appearance → Menu** builds the main menu and the footer menu: pages, custom links, news and groups, with one submenu
level under each item. Reorder by dragging or with the arrows. Until you save it, the menu is built from pages “in navigation”.

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

## 7. Forms and enquiries

The **Form** element (or the *Enquiry form* section) adds an enquiry form. In the Content panel you set the fields (text,
email, phone, list, radio buttons, date, number, consent), button text, thank-you message or thank-you page, a confirmation
to the sender and the notification email. Hidden fields and a submission limit fight spam without CAPTCHA or cookies.
The site can also send each new enquiry to a CRM or Make/Zapier (Settings → General → New enquiry webhook); conversion
tracking gets a `kaleta:odeslano` event (and a `dataLayer` entry).

Submitted messages are in **Content → Enquiries**: status (new, read, resolved), reply by email, CSV export. When the form is on the
page an ad or a newsletter links to, the enquiry also shows the **campaign** from the address (`utm_source`, `utm_medium`, `utm_campaign`…) –
in the admin, the notification email, the CSV and the webhook, without cookies. Enquiries
contain personal data, so they are deleted automatically after a set number of months (24 by default).

The **Newsletter** extension adds a **Newsletter sign-up** element: visitors enter an e-mail and confirm it by a link
(double opt-in). Confirmed addresses are in **Content → Subscribers**; export them to CSV, including the unsubscribe
link, for your mailing tool.

## 8. Company details

**Settings → Company:** registered name, business type, company ID, VAT ID, address, phone, **opening hours** (one per
line, e.g. “Mo–Fr 8:00–17:00”, “Sa 9–12”, “Su closed”), map link and coordinates. The **Company details** element shows
them anywhere on the site and search engines receive them as structured data – so Google shows your hours and address.

## 9. News, SEO and languages

**News** is the company blog: categories, tags, scheduled publishing, trash, version history. SEO takes care of itself
(sitemap, canonical URLs, structured data, `llms.txt` for AI search). Enable further language versions (`/en/…`) under
**Extensions → Language versions** and pick the languages in Settings.

## 10. AI assistant and Claude

Enable the **assistant** under **Extensions**. Choose a provider (Anthropic Claude, OpenAI, Google Gemini, Mistral), enter
the API key and model. The assistant only suggests. Text goes to the provider only when you click an assistant button.

- In the builder: **✨ Create a section with AI** (describe what it should contain) and **Rewrite with AI** on element text
  (shorter, longer, more formal, friendlier, fix mistakes). Check the result and fill in facts yourself. Ctrl+Z undoes the change.
- In news: headlines, intro, SEO description, tags, proofreading, image descriptions and translation into another site language.

Enable **Claude connection (MCP)** under Extensions. Then:

- **Connector in the Claude app (recommended):** in Settings → Connectors add a custom connector with the address
  `https://your-site.com/mcp`. Claude sends you to the website to sign in and confirm access (OAuth), nothing to copy.
  Connected apps are listed (and can be disconnected) in **My account**. This needs the site on HTTPS in the domain root.
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
with publishing permission). The menu, design system, shared classes, site settings, redirects, themes, collection items
and page text take effect straight away – the previous page text goes to the version history. Claude can move a page to
the trash (restorable for 30 days in the admin); the site e-mail, webhooks, mail, backups and security settings cannot
be changed over the connection.

## 11. Moving from WordPress

**Administration → Import and export → WordPress:** upload a WordPress export (Tools → Export, an XML file). The import
turns posts into news, pages optionally **straight into the builder**, downloads images into Media and creates redirects
from old addresses. You can run the import again – whatever it already converted is skipped.

## 12. Backups, updates, export

**Settings → Backups and updates:** automatic backups (also off-server via FTPS or S3) and signed updates. **Import and
export → Site export** creates a package with the content (pages, news, collections, site parts, classes) and media for
moving elsewhere. Enquiries and accounts are not exported.
