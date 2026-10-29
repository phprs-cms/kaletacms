# Add-ons for Kaleta – the extension API

Kaleta 3.0 opens up to code from other developers. This guide is for developers who write an add-on. It is also for site owners who want to know what an add-on can do.

## Trust first

An add-on is PHP that runs with the same rights as Kaleta, just like a WordPress plug-in.

- Kaleta never uploads add-ons from the administration and never downloads them from the internet. Whoever manages the hosting copies an add-on into `extensions/<slug>/`.
- An administrator switches the add-on on in **Add-ons** and confirms they trust its code.
- If an add-on throws an error while it loads, Kaleta switches it off at once and shows the error in Add-ons.
- `'addons' => false` in `config.php` stops every add-on from loading, as a safe mode.
- Add-ons never run in the public demo.

## The folder

```
extensions/hello/
  extension.json   the manifest
  Extension.php    the class named in the manifest
  install.sql      optional: your tables
  public/          optional: images, styles and scripts the browser loads
```

The folder name is the add-on's **slug**: lowercase letters, digits and `_`, at most 31 characters.

Since 3.3.2 the web server serves only `extensions/<slug>/public/` to visitors (as `/extensions/<slug>/public/…`), and never a PHP file from it – since 3.19.1 also not with a path after its name (`run.php/x`), and nothing in `public/` but an existing file. PHP does not belong in `public/`. Everything else in the folder – your PHP, `extension.json`, `install.sql` – cannot be requested from the web. A site whose `.htaccess` was customised gets the new rule in `.htaccess.kaleta-nova` after the update; System status reminds the administrator to carry it over.

### extension.json

```json
{
  "name": "Hello",
  "version": "1.0.0",
  "description": "What it does, in one sentence.",
  "author": "You",
  "url": "https://example.com",
  "class": "Vendor\\Hello\\Extension",
  "entry": "Extension.php",
  "requires": { "kaleta": ">=3.0", "api": 1 }
}
```

`requires.api` must equal the API version Kaleta offers (`Kaleta\Extension\Api::VERSION`, now 1). `requires.kaleta` accepts `3.0`, `>=3.0`, `>=3.0 <4.0` or `^3`.

### install.sql

When the add-on is switched on, `install.sql` runs. It may only contain `CREATE TABLE IF NOT EXISTS ka_ext_<slug>_<name> (…)` statements, one per statement – each ends with a semicolon at the end of its line. Since 3.19.1 a second statement after a semicolon on the same line, or a `/*! … */` comment, is refused and nothing runs. Kaleta replaces `ka_` with the site's own table prefix.

`install.sql` is a convenience, not a sandbox: your add-on's PHP runs with Kaleta's rights anyway. The check keeps the file honest for the administrator who reads it before switching the add-on on.

If you change your tables in a later version, keep the old columns working, because Kaleta does not migrate add-on tables.

## The class

```php
final class Extension implements \Kaleta\Extension\ExtensionInterface
{
    public function register(\Kaleta\Extension\Api $api): void
    {
        // hand callables to $api; do not write anything here
    }
}
```

Kaleta calls `register()` once per request. It must be fast.

## The API (version 1)

| Method | What it does |
|---|---|
| `$api->on($eventType, fn ($type, $data))` | Runs after Kaleta records an event, e.g. `enquiry.received`, `build.published` or `backup.failed`. The full list of event types is in Settings → System status. |
| `$api->filter('head' \| 'footer' \| 'page.html', fn (string): string)` | Adds to `<head>` or before `</body>`, or changes the whole HTML of a public page. Private pages, such as the whistleblowing channel, are never filtered. |
| `$api->token($name, fn (array $attributes): string)` | `{{ext.<slug>.<name> key="value"}}` in texts and builds. Values go in plain double quotes. Tokens are filled only in what editors write, never in what a visitor sends, such as a search query. You return HTML, and you escape it yourself, attributes included. |
| `$api->adminPage($name, $title, fn (Request $request): string)` | Adds a page under Add-ons, for administrators only. Kaleta checks the CSRF token of a POST before your callable runs; include `$api->app()->session->csrfField()` in your forms. |
| `$api->mcpTool($name, $description, $schema, $access, fn (array $args), $requires = '', $title = '', $openWorld = false, $idempotent = false)` | A tool for Claude named `ext_<slug>_<name>`. The `$access` value (`read`, `draft`, `write` or `destructive`) decides which connections may call the tool and sets its `readOnlyHint` and `destructiveHint`. Write tools are kept in the change log, follow the site's guardrails for Claude, and can be undone with the session. `$requires` (3.3.2) is who may call it: `author`, `editor` or `admin` (the lowest role), or the ident of an admin section the user must have (`pages`, `news`, `enquiries`…). Without it, read and draft tools are open to every user, write tools need an editor and destructive tools an administrator. The MCP hints (3.8): `$title` is the short title Claude shows (without it, the name in words: `list_orders` → "List orders"); set `$openWorld` when the tool reaches outside the site (downloads an address, e-mails a customer, calls another service) and `$idempotent` when calling a write tool again with the same arguments changes nothing more. Named arguments keep it readable: `title: 'List orders', openWorld: true`. |
| `$api->job($name, $seconds, $label, fn (): string)` | A background job, run by cron or visits, at most every `$seconds` (at least 60). |
| `$api->get($key, $default)` / `$api->set($key, $value)` | Your own settings, stored separately for each add-on. |
| `$api->app()` | The site. Use it only for what the API does not cover yet; internal classes can change in any release. |

Who may call an add-on tool when `$requires` is left out:

| `$access` | Default `$requires` | Who that is |
|---|---|---|
| `read` | `author` | Every user with a Claude connection, news authors included |
| `draft` | `author` | Every user with a Claude connection, news authors included |
| `write` | `editor` | Editors and administrators |
| `destructive` | `admin` | Administrators only |

A read or draft tool that returns more than an author may see in the admin, such as enquiries, customers or settings, should set `$requires` to the matching section (`enquiries`) or role (`editor`, `admin`).

Rules for failures:
- A filter, listener or token that throws is skipped.
- An add-on that throws in `register()` is switched off.

## The contract

`tools/contracts/extension-api.json` records the API version, the methods with their parameters, the filters and the tool access levels. The contract tests compare it on every build.

These names keep working through every 3.x release. Breaking them needs Kaleta 4.0.

Everything else in Kaleta is internal and may change in any release: classes, tables, columns, views and CSS classes.

### Table names (notice ahead of 4.0)

If your add-on queries Kaleta's own tables through `$api->app()->db()`, write the English table names. Since 3.10, a
placeholder takes either name: `{news}` and `{novinky}` are both the news table today. Kaleta 4.0 plans to rename the
tables to their English names, and both placeholders will keep working through 4.x. The map of every table, with the
planned English names of the most-read columns, is `system/sql/names.php`. Column names do not change in 4.0.

## An example

`docs/examples/extensions/hello/` uses every part of the API. Copy it to `extensions/hello/` and switch it on in Add-ons to try it.
