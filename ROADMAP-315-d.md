### 3.15 – the pre-release audit closed (security-2026-10-19-release-3.15, N315-1 … N315-12)

- **N315-1** – a shared class never makes a waiting file public by itself: the promotion scan no longer reads `ka_tridy`;
  a class's background goes public only with a build a person (or a full connection) publishes (`MediaStaging::promoteBuild`
  from `Builder\Publisher`), the draft look or Media. A new class whose name a published build already lists waits in the
  draft look (`Look::setClass`, `HtmlConverter::saveToSite`), so a drafts-only connection cannot restyle a published page.
- **N315-2** – `Vault::withKey` writes only into the one array config.php returns at its top level (token parser: no
  `if`/`else`/unbraced or second return, a closure or function before it is fine); `writeConfig` reads the file back
  (loaded in its own scope, or the parser when the file declares functions) and puts the old content back on a mismatch –
  the administrator gets the line instead. A one-line `return [...]` now works too.
- **N315-3** – Kaleta 4.0 installs only after a fresh check of the 3.x source confirms the newest 3.x
  (`Updater::majorBlocker`: no error, not refused or stale, no newer 3.x – also one that needs a newer PHP).
- **N315-4** – the scan goes through every waiting file (keyset batches of 300) and every public place (category texts,
  item descriptions, document versions, booking services, menus, facts, the author box, social drafts, page head code); the
  30-day clean-up never deletes a file live content uses (published content → public, a live class → kept, Media says why).
- **N315-5** – the scan runs only after a change in a section that shows content (`Admin\Kernel::PUBLISHING_MODULES`), a
  full connection's write call, scheduled publishing and the job; an anonymous request asks at most every 5 minutes per file.
  The scan itself reads each table once with a LIKE per upload folder (`media/2026/10/`, also JSON-escaped) instead of a
  LIKE per waiting file. Micro benchmark (3,000 published pages, 1,500 news, 300 waiting files, local MySQL): one scan
  34.4 s → 0.29 s (same files found); a POST in Enquiries/Users… 34.4 s → 0 (not called); an anonymous request for a known
  waiting path 114 ms → 6 ms.
- **N315-6** – re-encryption reads the rows `FOR UPDATE`; Settings says older backups still hold the old key with the data.
- **N315-7** – `MediaStaging::promote` runs under `GET_LOCK` per file (`Db::lockName`); the second request finds it done.
- **N315-8** – `check-channel.php` refuses a 4.x in `aktualizace-stable.json` and checks `aktualizace-N-stable.json`;
  `release.php` never writes a 4.x manifest to the 3.x files.
- **N315-9** – kept by decision (contract): `get_due_agent_runs` stays read/`readOnlyHint`, `migration_report` stays read;
  the reasons are in `Mcp\Catalog` and docs/connectors-directory.md.
- **N315-10** – the Enquiries badge and the Dashboard count leave spam out, as the list does.
- **N315-11** – the TOTP replay check is one `INSERT … SELECT … WHERE NOT EXISTS` under a lock per account.
- **N315-12** – add-on `install.sql` gets the English names on a renamed database; `Backup::restore` renames tables left
  under the other scheme (`alignTableNames`), a table under both names is left and named in the event
  `backup.tables_both_names`.
