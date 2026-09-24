# MiroCMS guide

For whoever runs the site: from installation through the page builder to connecting AI.
[Česká verze](prirucka.md)

## 1. Installation and first steps

1. Upload the files to hosting (PHP 8.4+, MySQL 8 / MariaDB 10.6+), create an empty database and open `/install.php`.
2. In the form, choose a **starter site**: *Business website*, *Crafts and services* or *Consulting and agency*. Each brings
   its own look and Home, About us, Services and Contact pages built from ready-made sections with sample texts.
3. After logging in, **First steps** on the **Dashboard** guide you through: site appearance, company details, pages,
   your first news item and email.

The admin is at `/admin.php`. Roles: an **administrator** can do everything, an **editor** manages content, a **news
author** writes their own news and may publish only with the publishing permission.

## 2. Page builder

Open the builder from **Pages → Builder** (or the “Edit here” link on the site). The middle is the **canvas – the real
page**, on the left the **Add / Structure** panel, on the right the properties of the selected element.

- **Adding:** click an element or a ready-made section in the Add panel, or **drag it onto the canvas** – a blue line shows
  where it will land (a frame means inside a container). Hover over a ready-made section to see its preview.
- **Moving:** the selected element has a ⠿ handle on the canvas – drag it elsewhere. You can also drag in the Structure.
- **Text:** double-click a heading or text to edit it right on the canvas; longer edits in the **Content** panel.
- **Style:** the **Style** panel – layout, size, spacing, typography, background. At the top, switch **Desktop / Tablet /
  Mobile**: a value for a smaller screen overrides the larger one only there. The **Hover** toggle sets the hover look.
- **Classes:** in the **Advanced** tab, give an element a class (e.g. `karta`). A class style applies to every element with
  that class across the site – ideal for a repeated look.
- **Shortcuts:** Ctrl+Z undo, Ctrl+Shift+Z redo, Ctrl+D duplicate, Ctrl+C / Ctrl+V copy and paste (even between pages),
  Delete removes, Esc selects the parent element.
- **Saving and publishing:** changes save continuously as a **draft** – visitors see the published version until you
  press **Publish**. **Discard changes** restores the published version; **Versions** lists the last 20 publications.
  If someone else edits the page meanwhile, the editor offers to load the newer version or overwrite it. Before publishing,
  a **check** flags buttons without links, images without descriptions and a missing main heading.
- **More elements:** icon, photo gallery with a viewer, tabs, accordion, carousel, map (loads only after a click),
  pop-up window (opened by a button linking to `#window-anchor`) and breadcrumbs.

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
tracking gets a `mirocms:odeslano` event (and a `dataLayer` entry).

Submitted messages are in **Content → Enquiries**: status (new, read, resolved), reply by email, CSV export. Enquiries
contain personal data, so they are deleted automatically after a set number of months (24 by default).

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

Enable **Claude connection (MCP)** under Extensions. Create an access token under **My account**. In Claude Code, run

```bash
claude mcp add --transport http mirocms https://your-site.com/mcp --header "Authorization: Bearer <token>"
```

In the Claude app, add a custom connector with the address `https://your-site.com/mcp` and the same header. Claude then,
with your account permissions, builds pages and sections, edits the header, footer, components, collections and design,
and writes news. Everything is created as a **draft** and published only when you say so.

## 11. Moving from WordPress

**Administration → Import and export → WordPress:** upload a WordPress export (Tools → Export, an XML file). The import
turns posts into news, pages optionally **straight into the builder**, downloads images into Media and creates redirects
from old addresses. You can run the import again – whatever it already converted is skipped.

## 12. Backups, updates, export

**Settings → Backups and updates:** automatic backups (also off-server via FTP or S3) and signed updates. **Import and
export → Site export** creates a package with the content (pages, news, collections, site parts, classes) and media for
moving elsewhere. Enquiries and accounts are not exported.
