# Contributing to Kaleta

Thank you for helping. Kaleta is a small project, so a short, focused change with a test is the easiest to accept.

## Before you start

- **Bugs:** open an [issue](https://github.com/phprs-cms/kaletacms/issues) with the Kaleta version (Admin → Updates), what
  you did, what you expected and what happened. Screenshots and the PHP error log help.
- **Security problems:** do not open a public issue – follow [SECURITY.md](SECURITY.md).
- **New features:** open an issue first and describe the use case. Kaleta has no third-party plugins by design; features
  ship as built-in extensions that are tested together, so not every idea fits.

## Setting up

You need PHP 8.4+ and MySQL 8 or MariaDB 10.6+.

```bash
php -S localhost:8080 system/dev-router.php
```

Open `http://localhost:8080/install.php` and install into an empty database.

## Tests

Every pull request must pass:

```bash
php tools/testy.php          # unit tests, no database
tools/test.sh                # clean install and a walk through site, admin, builder and MCP (needs MySQL;
                             # the database kaleta_test is dropped and created again)
tools/test-english.sh        # the English installer, site and admin must contain no Czech
tools/test-migrace.sh        # database upgrade from 1.0.0
```

Add a test for what you change – a unit test in `tools/testy.php`, or a check in `tools/test.sh` for anything that needs
a running site.

## Code

- PHP 8.4 with `declare(strict_types=1)`, namespace `Kaleta\`. Match the surrounding code: identifiers and comments are
  currently in Czech (moving to English is on the [roadmap](docs/ROADMAP.md)).
- No new runtime dependencies and no build step. CSS goes into the existing layers, JavaScript only where it is really
  needed.
- Anything shown to visitors or administrators goes through `t()` and needs an English translation; `tools/cestina.php`
  checks that no Czech leaks into the English interface.
- Architecture notes for contributors (in Czech) are in [CLAUDE.md](CLAUDE.md).

## Translations

Visitor texts live in `system/jazyky/<code>.php` (for example `de.php`), the English admin in `system/jazyky/admin-en.php`.
A new language is one dictionary file keyed by the Czech source text; `tools/slovnik.py` adds entries. Languages without
a dictionary fall back to English with dates in their own format.

## Commits and pull requests

- Write commit messages, pull requests and issues in English.
- Keep one topic per pull request and describe what changed and how you tested it.
- By contributing you agree that your contribution is licensed under GPL-2.0-or-later, the licence of the project.
