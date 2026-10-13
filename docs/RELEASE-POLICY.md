# Release and support policy

This is what you can rely on when you build sites on Kaleta – for yourself or for clients. It applies from Kaleta 2.1.

## Versions and pace

Kaleta versions are `MAJOR.MINOR.PATCH`.

| Release | What it brings | How often |
| --- | --- | --- |
| **Minor** (2.1, 2.2…) | New features and improvements. Nothing you use stops working. | Every week on the Latest channel; about once a month on the Stable channel (from 3.8) |
| **Patch** (2.1.1…) | Fixes only. | When needed |
| **Security** (a patch marked as security) | A fix for a vulnerability. Installs itself unless the administrator turned that off. | When needed, as fast as possible |
| **Major** (3.0…) | Removes what was deprecated earlier (see below). No surprise removals. | At most once a year |

Every release is announced in the [GitHub releases](https://github.com/phprs-cms/kaletacms/releases) and in the
[changelog on kaletacms.com](https://kaletacms.com/changelog), in English, Czech and German.

## Which versions are supported

**The latest release.** A site on any earlier version reaches it in one step from **Settings → Backups and updates** –
there are no required stops in between. Every release is tested by updating real installations of older releases
(from 1.2 onward) on MySQL and MariaDB.

**Release channels (from 3.8).** In **Settings → Backups and updates** a site follows one of two channels:

- **Latest** (the default for every site) – a new minor version every week.
- **Stable** – the stable line: security fixes only, and a move to a newer minor about once a month, after that minor
  has run on Latest. The current stable line is the version in the stable manifest (`aktualizace-stable.json`); it gets
  security patches too, until the channel moves on. A site on Stable is never offered an older version than it runs.

Details are in [SECURITY.md](../SECURITY.md#release-channels).

Security releases install themselves by default: the site backs up its database, verifies the publisher's signature and
the checksum, and replaces the system files. The administrator gets an e-mail. How vulnerabilities are reported and fixed
is in [SECURITY.md](../SECURITY.md).

## Deprecation

When something has to go, it is:

1. **announced** in the release notes of a minor release, with what to use instead;
2. **shown on the sites that still use it**, in **Settings → System status**;
3. **kept working for at least two minor releases and at least six months**;
4. **removed only in a major release**.

## What counts as the public contract

These are covered by the rules above. They are recorded in [`tools/contracts/`](../tools/contracts) and the tests fail when
any of them is removed or changed outside a deprecation:

- **The Claude connection (MCP):** tool names, their parameters and parameter types, which parameters are required, and
  each tool's annotations (read, write, destructive). The hidden Czech tool names of older connections keep working too.
  The ready-made tasks (MCP prompts, since 3.13 in the contract): every name, earlier ones included, and its arguments –
  none removed, none made required.
- **The extension API** for add-ons ([`docs/EXTENSIONS.md`](EXTENSIONS.md)): its version, public methods and filters.
- **Design tokens** – both the stored names (`--ka-barva-primarni`) and the English ones (`--ka-color-primary`) – so
  custom CSS in shared classes keeps working.
- **Builder elements** and their content properties, so every saved page keeps rendering.
- **The site export** (Administration → Import and export): an export can be imported into the same or any later release.
- **Addresses of your content:** an update never changes the address of a page, news item, collection item or feed.
  When you change the address of a page, news item or category yourself, Kaleta adds a redirect from the old one
  (Redirects extension).
- **The update channel and its signatures**, so installed sites keep receiving updates.

## What is not a contract

- PHP classes, functions and database tables outside the extension API. Add-ons build on the versioned extension API
  (since 3.0, `docs/EXTENSIONS.md`, recorded in `tools/contracts/extension-api.json`); everything else builds on the export,
  the Claude connection, webhooks and feeds.
- The HTML of the admin and the exact HTML of public pages (the look is yours through the design system and classes; the
  markup around it may improve).
- The wording of texts, and anything marked as experimental in its release notes.
