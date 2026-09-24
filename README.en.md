<p><picture><source media="(prefers-color-scheme: dark)" srcset="image/kaleta-logo-tmavy.svg"><img src="image/kaleta-logo.svg" alt="Kaleta" height="48"></picture></p>

# Kaleta

**An open-source CMS for business websites** – services, testimonials, team, careers, contact and news. A visual page builder
whose output reads like hand-written HTML, an AI assistant, Claude over MCP, and a WordPress importer.
[Česky](README.md)

> **Status: before the 1.0 release.** The features below are done and covered by tests; do not use it on production sites yet.

## Features

- **Page builder** – the canvas is the real page. Drag elements and ready-made sections into place, style them separately
  for desktop, tablet, mobile and hover. Drafts save continuously and go live only when you publish; older versions can be restored.
- **Library of 39 ready-made sections** (heroes, services, pricing, testimonials, team, gallery, contact with a form…) and
  **three starter sites** at installation. Texts in Czech and English, following the page language.
- **Design system** – one-click presets, colours with a readability (WCAG) check, fonts, fluid sizes and spacing – all tokens,
  so changing a colour restyles the whole site.
- **Site parts** – header, footer and wrappers for the news item, news list and 404 page in the builder, including
  variants for selected pages (a landing page without navigation).
- **Components** – reusable blocks with properties; editing a component updates it everywhere.
- **Collections** – custom content types (testimonials, team, products, branches…) with their own fields, listed in the
  builder with `{{field}}` tags, filters, sorting and pagination, and item pages with a builder template.
- **Forms and enquiries** – an enquiry form without CAPTCHA or cookies, enquiries in the admin, email notifications,
  CSV export and automatic deletion of personal data.
- **Company details** – address, company ID, opening hours and map once in Settings; the site shows them and search
  engines get LocalBusiness structured data.
- **AI** – the assistant drafts a new section from a description, rewrites element text, suggests headlines, SEO
  descriptions, proofreading and translation (Claude, OpenAI, Google Gemini or Mistral). Claude can also build the site
  over **MCP**: it turns HTML into builder content and edits site parts, collections and the design – always as a draft to approve.
- **News** (blog), multilingual sites, SEO and llms.txt, cookie-free analytics, redirects, backups and signed updates,
  **WordPress import** (straight into the builder, too).

## Principles

- **No technical debt:** plain PHP 8.4+, no framework, Composer or build step; no third-party plugins.
- **Clean output:** one builder element = one HTML tag, CSS only for what the page uses, in cascade layers (`@layer`);
  JavaScript only where it is really needed. Tests enforce it.
- **Web 2026:** fluid type and spacing, container queries, OKLCH colours (`color-mix`), the Popover API, view transitions.
- **AI as a first-class user:** whatever works in the editor works over MCP – same schema, validation and permissions.
- **Privacy and accessibility by default:** no third-party scripts or fonts, colour contrast checks.
- **FTP installation**, signed updates.

## Installation

1. Upload the repository to hosting with PHP 8.4+ and MySQL 8 / MariaDB 10.6+.
2. Create an empty database.
3. Open `https://your-site.com/install.php`, fill in the form and choose a starter site.

Nginx does not read `.htaccess` – use the example in `system/nginx.priklad.conf`. The user guide is in [docs/guide.md](docs/guide.md).

## Development

```bash
php -S localhost:8080 system/dev-router.php
```

Tests: `php tools/testy.php` (unit, no database) and `tools/test.sh` (clean install plus a walk through the site, admin,
builder and MCP; needs MySQL; `WEB=remeslo tools/test.sh` tests another starter site). Contributor rules and
architecture notes are in [`CLAUDE.md`](CLAUDE.md) (Czech).

## Licence

GNU GPL version 2 or later. The licence text is in [`LICENSE`](LICENSE).
