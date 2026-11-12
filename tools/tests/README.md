# Tests

The two suites keep their commands; their checks live here, one file per topic.

| Command | Files | What |
|---|---|---|
| `php tools/unit-tests.php [filter …]` | `tests/unit/bootstrap.php`, then `tests/unit/NNNN-<topic>.php` | no database |
| `tools/test.sh` | `tests/smoke/bootstrap.sh`, then `tests/smoke/NNNN-<topic>.sh` | clean install, site, admin, MCP (MySQL) |

Both runners take the files in number order. A file whose name does not match `NNNN-<topic>.php` / `.sh`
(lower case, digits, dots and hyphens) or two files with the same number stop the run, so nothing is skipped silently.

## How to add a test

- **A new topic is a new file.** Take the next free number after the highest one (step 10, e.g. `1790-3.33-invoices.php`),
  start it with the section's one-line header as the existing files do. Do not append to someone else's file just because
  it is the last one – that is what made the old single files a merge conflict in every release.
- **Unit file:** `<?php`, `declare(strict_types=1);`, the `use` imports it needs, then `check('label', $actual, $expected);`
  calls. All files share one global scope (a later file may use a variable an earlier one prepared), so prefix your
  variables (`$i333…`) as before. `php tools/unit-tests.php 1790` runs just that file (any part of a file name works, e.g.
  `fleet`); what earlier files prepare for it (variables, the active language) is replayed first without counting.
- **Smoke file:** plain bash, sourced into the one shell of `tools/test.sh` – the same site, cookie jar and database as
  every section before it. Start with `echo "== <topic>"`, report with `check`, `expect` or `echo "  ok     …"` /
  `ERRORS=$((ERRORS+1))`. A section that needs state of an earlier one simply gets a higher number; the order is the
  contract. Helpers used by many sections belong in `bootstrap.sh` only if they are needed before the first section.
- **Paths:** the checkout is `KALETA_ROOT` (or `dirname(__DIR__, 3)`) in a unit file and `$ROOT` in a smoke file.
- A guard that reads the smoke suite as text uses `smoke_test_source()` (bootstrap.php), not `tools/test.sh`.
- `KALETA_UNIT_LABELS=<file> php tools/unit-tests.php` writes every check's file and label – compare two lists to prove
  that a refactoring kept every check.
- **No new source pins (3.36 T3).** A check that searches the text of a product file (`str_contains(file_get_contents(KALETA_SYSTEM …), …)`)
  passes when the behaviour is broken and fails when a correct refactoring moves a line. The existing ones carry `[source-pin]` in their
  label, the runner prints how many there are, and `2610-…` fails when a new one appears (`unit_source_pins()` in bootstrap.php finds
  them; a rule that walks the whole tree is no pin). Test the behaviour instead; when you replace a pin, lower `UNIT_SOURCE_PIN_CEILING`.
- **A database error is an error (3.36 T3).** A `catch (\PDOException …)` calls `Db::rethrowUnlessMissing($e)` when it only covers the
  time before a migration, uses the error, or says "any database error" and why – `2600-…` checks every catch in `system/`.
- **Background jobs and the clock (3.36 T4).** In a smoke section, `background_jobs hold|resume|due|settle` (bootstrap.sh) instead of
  writing `notification_check`; a second site gets `test_seams <dir>`. In a unit file `Clock::freeze('2026-03-29 01:30:00')` … `Clock::freeze(null)`.
- **Query budgets (3.36 T3).** The test copy answers every admin request with `X-Kaleta-Queries` (`Core\TestSeams`); `2610-…` keeps the busy
  lists under their budgets and checks that more rows add no statement.
