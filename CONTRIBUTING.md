# Contributing to Kaleta

Thank you for helping. Kaleta is a small project, so a short, focused change with a test is the easiest to accept.

## Before you start

- **Bugs:** open an [issue](https://github.com/phprs-cms/kaletacms/issues) with the Kaleta version (Admin → Updates), what
  you did, what you expected and what happened. Screenshots and the PHP error log help.
- **Security problems:** do not open a public issue – follow [SECURITY.md](SECURITY.md).
- **New features:** open an issue first and describe the use case. Kaleta has no third-party plugins by design; features
  ship as built-in extensions that are tested together, so not every idea fits.

## Setting up

You need PHP 8.3+ and MySQL 8 or MariaDB 10.6+.

```bash
php -S localhost:8080 system/dev-router.php
```

Open `http://localhost:8080/install.php` and install into an empty database.

## Tests

Every pull request must pass:

```bash
php tools/unit-tests.php          # unit tests, no database
tools/test.sh                # clean install and a walk through site, admin, builder and MCP (needs MySQL;
                             # the database kaleta_test is dropped and created again)
tools/test-english.sh        # the English installer, site and admin must contain no Czech
tools/test-migrations.sh        # database upgrade from 1.0.0
```

Add a test for what you change – a unit test in `tools/unit-tests.php`, or a check in `tools/test.sh` for anything that needs
a running site.

## Writing tests that pass in CI

CI runs the suites on a fresh runner: the MySQL service in UTC, PHP and the shell in Europe/Prague (a zone with summer time),
two PHP versions side by side. A test that only passes on your machine turns `main` red for everyone, so:

- **No `curl … | grep -q`.** The scripts run with `set -euo pipefail`; `grep -q` stops reading at the first match, `curl`
  dies of SIGPIPE and the check fails at random. Pipe into `contains` (defined in `tools/test.sh`, it reads all the input),
  or save the response with `curl -o "$WORK/response"` and grep the file. The same goes for `mcp …` and `sq …`.
- **No MySQL `NOW()`, `CURDATE()` or `UTC_TIMESTAMP()` in seeded data or comparisons.** The site writes times with PHP's
  `date()` in its own time zone; the database's clock is UTC on CI. Use `site_time` (`'$(site_time)'`,
  `'$(site_time '-1 hour')'`, `$(site_time tomorrow Y-m-d)`) – `INTERVAL` arithmetic on it is fine. The scripts force their
  own MySQL sessions to UTC, so this mistake fails locally too.
- **Background jobs run after a visit.** After any public request (also `/tasks` or the older `/ulohy`) the site runs the due jobs once a minute.
  When a check counts what a queue delivered, mark that trigger as just run first (`notification_check`, see `tools/test.sh`).
- **Your own ports and database names.** `tools/test.sh` uses `PORT` to `PORT+17`; run parallel suites with their own
  `PORT` and `DB_NAME` (for example `DB_NAME=mine_op PORT=9850 tools/test.sh`). Kill only the `php -S` you started.
- **Never stop or restart MySQL** from a test or while suites run – other suites share it.
- **No real-looking secrets.** Gitleaks scans the whole history: generate passwords and tokens at run time, or allow-list a
  made-up value in `.gitleaks.toml`.
- **Run PHPStan before you push** if you can (`phpstan analyse -c phpstan.neon.dist`); CI runs it on every change.

## Code

- PHP 8.3 (the oldest supported; PHPStan checks it, CI runs it) with `declare(strict_types=1)`, namespace `Kaleta\`. Match the surrounding code: identifiers and comments are
  currently in Czech (moving to English is on the [roadmap](docs/ROADMAP.md)).
- No new runtime dependencies and no build step. CSS goes into the existing layers, JavaScript only where it is really
  needed.
- Anything shown to visitors or administrators goes through `t()` and needs an English translation; `tools/find-czech.php`
  checks that no Czech leaks into the English interface.
- Architecture notes for contributors (in Czech) are in [CLAUDE.md](CLAUDE.md).

## Translations

Visitor texts live in `system/jazyky/<code>.php` (for example `de.php`), the English admin in `system/jazyky/admin-en.php`.
A new language is one dictionary file keyed by the Czech source text; `tools/add-translations.py` adds entries. Languages without
a dictionary fall back to English with dates in their own format. The admin and the installer speak Czech, English, German
and Spanish (`admin-<code>.php`, `image/jazyky/admin-<code>.js`, `install-<code>.php`): a new admin text needs an entry in each,
with the same placeholders – `tools/unit-tests.php` checks it.

## Commits and pull requests

- Write commit messages, pull requests and issues in English.
- Keep one topic per pull request and describe what changed and how you tested it.
- By contributing you agree that your contribution is licensed under GPL-2.0-or-later, the licence of the project.
