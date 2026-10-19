## 3.15 – work-stream A: open findings of the daily security audits

Every open finding of `security-2026-10-19.md` is fixed, and the older items still listed as open on 8 October were checked against the code.

- **N71** `read_notebook` checks the Notebook section, the same check as `write_notebook`. `site_info` names the pinned notes only to users who have the section.
- **N72** Site import from a Kaleta export:
  - Media get lower-case extensions (`Photo.JPG` → `Photo.jpg`). Pages, builds, media rows and settings follow the new name (`SiteImport::renameMedia`).
  - Images must really be JPG/PNG/GIF/WebP/AVIF/ICO, and attachments must pass the upload content check (`Files::forbiddenContent`).
  - The two header rules in `media/.htaccess` get `(?i)`. Migration **0098** (data) carries the new file to existing sites, as 0084 did. A customised file gets a `.kaleta-nova` next to it, and System status reports a file without the rules.
- **N73** `Svg::sanitize` removes XML comments.
- **N74** Response headers come only through `last_response_headers()` (Newsletter, Assistant, Webhook). A unit test checks that `system/src` never reads the bare `$http_response_header`.
- **N18** A code from the authenticator app signs in only once: the step is kept in `ka_kontrola_ip` (`typ = 'totp'`), with no schema change.
- **N19** When an administrator sets a new password in Users, the form offers to revoke the user's personal tokens too. The box is ticked, as in My account.
- **N21** `Html::safe` keeps anchor ids. It drops ids that reuse the site's own hooks (`ka-…`, `obsah`, `cookies-lista`, `L`, `dataLayer`…). Generated outline anchors avoid these ids too.
- **N45** `get_due_agent_runs` refuses a read-only connection, so it can no longer use up a run it could never report. The contract and the read class are unchanged.
- **N46** The whistleblowing page:
  - It sends a CSP that keeps it on this site, so embeds in the header or footer cannot reach a third party.
  - It sends `Referrer-Policy: no-referrer`.
  - The firewall log keeps no address for a refused report page.
- **N48** The CSS checks in `Build::code` decode CSS escapes and drop comments before they match.
- **N49** Three small fixes:
  - The import state files are not offered as Kaleta exports.
  - Non-admins see only their own files in Media clean-up.
  - A cancelled OAuth sign-in shows only an RFC 6749 error code.
- **N42** (part) Every backup download is now recorded in the change log. Moving the vault key out of the database stays a proposal (below).
- **S5** Documented in `upload_file`: the domain rule only keeps redirects on the named host. The protection is the IP pin.
- Already fixed earlier: N9 and N22 (3.3.3, `7d4f29da`), N47 (`bcf4f2b4`, images pinned by digest).
- Proposals for the owner:
  - N42: keep the vault key outside the database.
  - N44: who may set `claude_instructions` over MCP.
  - N45: reclass `migration_report`.
