# Kaleta roadmap

What is planned next and what the owner has decided about the road to 4.0. Dates are not promised; releases ship when
they are tested. What each released version brought is in the [changelog](CHANGELOG.md); what stays compatible is in the
[release policy](RELEASE-POLICY.md).

## Next

3.46 closed the privacy and backup findings: a restore keeps today's sign-ins, erasures and unsubscribes, backups are kept by
age, administrator settings and new Claude connections ask "Confirm it is you", and private files are sealed at rest and
copied off-site encrypted. A security re-audit of 3.46 and a restore and erasure trial on a copy of a real site come
first; then 3.47 – one pattern (the screens of 3.38–3.42 get the patterns of 3.37). Each release lists what it brought
in the changelog when it ships.

## Path to 4.0 (owner decisions of 13 October 2026, revised on 21 and 22 October and 10 November 2026)

**No English renaming of tables or columns – not in 4.0 and not later.** The owner rejected it on 21 and 22 October 2026
("it doesn't matter to most users") and confirmed it in the plan of 10 November 2026. The Czech tables and columns stay;
`system/sql/names.php` (`Core\Names`) stays as the map of English aliases, so `{news}` and `{novinky}` both work in
queries and add-ons may keep either; new tables and columns get English names. The 3.15 bridge in `Core\Db` (a database
whose tables carry the English names: the marker, the retry after a missing table, the `$english` branch of
`Migration::statements`) is removed by the next major release – no release ever renamed a database.

| Release | What |
|---|---|
| 4.0 | No date and no fixed content yet. What needs a major: removing the 3.15 bridge, export format 3 by default (format 2 still read), a unique key on `source_norm` once the reported collisions are resolved. Never installed automatically – the administrator starts it, a backup comes first. 4.x is published in `aktualizace-4.json`, which only 3.15+ reads. MCP tools, parameters and Czech aliases unchanged. |
| 4.1 | UTC instants table by table (docs/design/4.0-data-model.md §6 step 2). |

No support window is promised for any 3.x release – administrators are prompted to update to the latest release so
security fixes apply. `zh` means Simplified Chinese (owner, 13 October 2026).

## Not planned

- English renames of database tables or columns (21 and 22 October 2026, confirmed on 10 November 2026) – `Core\Names`
  keeps the English aliases for queries instead.
- A second e-mail renderer or an e-mail builder; campaign features (segments, automations, A/B tests, open tracking).
- A chat with Claude inside the admin that runs on the site's own Anthropic key (decided on 3 October 2026) – "Ask Claude"
  hands the work to Claude through requests instead.
- Multisite in one installation, e-commerce, memberships, a visitor-facing AI chat, a headless or GraphQL API, real-time
  co-editing.
- More style presets, approval workflows, PHP themes.
- A fleet console that reaches into sites – remote commands or Claude tokens held by the console (decided on 2 October 2026).
- Reversed on 2 October 2026 and delivered: form logic (2.12), appointment booking, structured importers and an
  extension API (3.0). Add-ons are installed by copying a folder – never uploaded or downloaded by Kaleta.

## Later

- Visitor dictionaries keyed by English text, like the admin ones (moved from 2.4).
- Legal text templates with a clear disclaimer (privacy policy, terms, cookie policy) per country.
