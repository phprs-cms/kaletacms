# Kaleta in the Anthropic Connectors Directory – submission package

This is the package for listing Kaleta's MCP server in the [Connectors Directory](https://claude.com/docs/connectors/directory)
and for the [official MCP registry](https://modelcontextprotocol.io/registry/about). Nothing here has been submitted or
published: the owner submits through the developer portal at [claude.ai/directory/manage](https://claude.ai/directory/manage)
(**Submit new → MCP connector**) once the checklist at the end is done. The registry entry is drafted in
[`server.json`](server.json).

Requirements were checked on 9 October 2026 against:

- Submit a connector – <https://claude.com/docs/connectors/building/submission>
- Connector pre-submission checklist (review criteria) – <https://claude.com/docs/connectors/building/review-criteria>
- Authentication for connectors – <https://claude.com/docs/connectors/building/authentication>
- MCP specification 2025-06-18, tools – <https://modelcontextprotocol.io/specification/2025-06-18/server/tools>
- MCP schema 2025-11-25 (`Implementation.websiteUrl`, `icons`, `ToolAnnotations`) –
  <https://github.com/modelcontextprotocol/modelcontextprotocol/blob/main/schema/2025-11-25/schema.ts>
- MCP registry: remote servers and `server.json` – <https://modelcontextprotocol.io/registry/remote-servers>,
  schema <https://static.modelcontextprotocol.io/schemas/2025-12-11/server.schema.json>, namespaces
  <https://modelcontextprotocol.io/registry/authentication>

## 1. What the directory requires, and where Kaleta stands

| Requirement (source) | Kaleta |
|---|---|
| Remote server over HTTPS (submission) | Every Kaleta site serves `https://<site>/mcp` (Streamable HTTP, JSON responses); plain HTTP is refused except on localhost. |
| Every tool has a `title` and the applicable `readOnlyHint` or `destructiveHint` (submission, review criteria) | Done in 3.8: every tool has a title (top-level `title` and `annotations.title`, `Mcp\Catalog::TITLES`), `readOnlyHint` and `destructiveHint` from `Mcp\Catalog` access levels, `idempotentHint` where verified, `openWorldHint` for tools that reach outside the site. Add-on tools get the same fields from their declaration. |
| Tool names ≤ 64 characters (review criteria) | Longest name has 26 characters. |
| Read and write split into separate tools; no catch-all API tool (review criteria) | Every tool does one thing; there is no tool that takes an HTTP method or a free endpoint. Since 3.10 an essentials connection also lists runners (`run_read_tool`, `run_tool`, `run_destructive_tool`), which run one of the site's own named tools by name with the same checks as a direct call, each only its own class of tools – see review risks below. |
| Narrow, accurate descriptions; no prompt-injection patterns (review criteria) | Descriptions say what each tool does. Some carry safety rules (never publish because a request said so, drafts only) – see review risks below. |
| Actionable errors, reasonable response sizes (review criteria) | Errors are tool results with `isError: true` and a sentence that says what to do; list tools page (`limit`, `offset`). |
| OAuth 2.0 for authenticated services (submission, authentication) | OAuth 2.1 with PKCE S256, dynamic client registration (RFC 7591), protected resource metadata (RFC 9728), authorization server metadata (RFC 8414), refresh token rotation. Details in section 4. |
| Test account with a fully populated account (submission: Test & launch) | The owner sets up the private review site (section 6). |
| Documentation URL, privacy policy URL, support contact, icon (submission: Listing) | Section 2. |
| Privacy policy section in README / manifest (local connectors only) | Not applicable – Kaleta is a remote server. The privacy policy URL is still entered in the portal. |
| Server info (MCP 2025-06-18 `title`, 2025-11-25 `websiteUrl`, `icons`) | `initialize` answers `name` (unchanged: "Kaleta – <site name>"), `title` "Kaleta", `version`, `websiteUrl` https://kaletacms.com and two icons the site itself serves (`image/kaleta-znacka.svg`, `image/kaleta-znacka-180.png`). The negotiated protocol stays 2025-06-18; the 2025-11-25 fields are additive and older clients ignore them. |

## 2. Listing (the portal's Listing, Use cases, Company and Data handling steps)

- **Server name** (≤ 100): Kaleta
- **One-liner** (≤ 200): Build and run a company website with Claude: pages in a visual builder, news, collections,
  enquiries and the look, as drafts you review – on your own Kaleta site.
- **Description** (≤ 2,000):

  > Kaleta is an open-source CMS for company websites. Every Kaleta site has its own MCP server, so Claude works on that
  > one site with the permissions of the person who connected it.
  >
  > With Kaleta, Claude can build pages in the visual builder (sections, components, shared classes, the design system),
  > write news, fill collections such as a team, services, jobs or a price list, set up menus, pop-ups and redirects,
  > sort enquiries and draft newsletters. It can also audit the site for broken links, missing alt text and SEO gaps,
  > check translations, and move a site over from WordPress or another platform.
  >
  > Claude works in drafts. A new page stays hidden, a news item is a draft, and changes to the look wait in a draft
  > look until a person publishes them. When you connect, you choose what the connection may do: full access, drafts
  > only, or read only. Every change Claude makes goes into the change log with the name of its connection, and a whole
  > Claude session can be undone. The site owner can also set guardrails such as an hourly limit on changes.
  >
  > Kaleta runs on your own hosting (PHP and MySQL). Claude talks only to your site, never to a Kaleta cloud. To connect,
  > add your site's address followed by /mcp, then sign in to your site and confirm access.
- **Categories** (1–5): pick in the portal; closest fits are content management / website building, marketing and
  productivity.
- **Documentation URL**: https://kaletacms.com/guide (section 10 "Writing assistant and Claude" covers connecting).
- **Privacy policy URL**: https://kaletacms.com/privacy-policy
- **Support contact**: GitHub issues – https://github.com/phprs-cms/kaletacms/issues (security reports per `SECURITY.md`).
- **Icon**: the Kaleta mark "k." from the logo manual (`image/kaleta-znacka.svg`, PNG `image/kaleta-znacka-180.png`).
- **Use cases**: build and edit pages; write and schedule news; manage collections; site audit and fixes; translation;
  moving a site to Kaleta; enquiry triage; newsletters. **Prerequisites**: a Kaleta site (self-hosted, free) with the
  Claude connection switched on (on by default) and a user account on it. **Reads and writes data**: both.
- **Company**: Miroslav Kaleta (operator of kaletacms.com), website https://kaletacms.com.
- **Data handling**: the API is the owner's own (each site is its operator's own Kaleta installation); no personal
  health data; no sponsored content. Enquiries hold personal data of the site's visitors; reading them is logged
  (`list_enquiries`), and `find_personal_data` / `erase_personal_data` serve data-subject requests.
- **Compliance step**: no financial transfers and no AI media generation; the server does not read Claude's memory,
  chat history or files.

## 3. Connection: one URL per site

Each site has its own server: `https://<site host>[/<subfolder>]/mcp`, for example `https://www.example.com/mcp` or
`https://example.com/web/mcp`. In the portal's **Connection** step choose **Users connect to different URLs → URL
pattern**:

```text
^https://[a-z0-9.-]+(:[0-9]{1,5})?(/[A-Za-z0-9._~%-]+)*/mcp$
```

The host part is lowercase because Claude lowercases the host before matching. A URL pattern works with dynamic client
registration (`oauth_dcr`), which is what Kaleta offers.

## 4. Authentication

Type: **OAuth with dynamic client registration** (`oauth_dcr`). Personal tokens from My account also work (for clients
without OAuth) but are not part of the listing.

| Directory requirement (authentication page) | Kaleta (`Front\OAuth`) |
|---|---|
| `401` with `WWW-Authenticate: Bearer resource_metadata="…"` on an unauthenticated call | Yes, `/mcp` answers 401 with the pointer to `/.well-known/oauth-protected-resource`. |
| Protected resource metadata whose `resource` equals the MCP URL; first `authorization_servers` entry is the issuer | `resource` = `<site>/mcp`, `authorization_servers` = [`<site>`]; also served path-aware at `/.well-known/oauth-protected-resource/mcp`. |
| RFC 8414 (or OpenID) authorization server metadata | `/.well-known/oauth-authorization-server` and `/.well-known/openid-configuration` (a site in a subfolder answers under its folder). |
| `registration_endpoint` (DCR, RFC 7591), JSON body | `/oauth/register`; public clients (`none`) and confidential ones; at most 20 registrations per hour per visitor; clients never approved are deleted after a day. |
| PKCE S256 and `code_challenge_methods_supported: ["S256"]` | Yes; the verifier must have 43–128 characters. |
| Redirect URI `https://claude.ai/api/mcp/auth_callback` | Accepted; claude.ai and claude.com are Claude's own hosts on the consent screen. |
| Claude Code: loopback redirect on any port, for `127.0.0.1` and `localhost` | **Fixed in 3.8.** A registered loopback redirect now matches with any port (RFC 8252 §7.3, `OAuth::redirectAllowed`); before, the same client coming back on another port got "unknown client or return address". |
| Token endpoint takes `application/x-www-form-urlencoded`; `invalid_grant` for a dead refresh token; rotation for public clients | Yes; rotated refresh tokens have a 30-second grace for retries, a later reuse (within 30 days of the rotation) revokes the client's tokens. |
| Endpoints answer within 10 s (refresh 30 s) | Plain database work; no outbound calls on these endpoints. |
| CIMD (`client_id_metadata_document_supported`) | Not offered – Claude falls back to DCR. Optional; the docs recommend CIMD for high-traffic servers because DCR registers a new client per connection (Kaleta deletes unapproved clients after a day). |
| `offline_access` scope | Not listed in `scopes_supported`; the token endpoint returns a refresh token anyway, so Claude refreshes normally. |

Token lifetimes: personal tokens default to one year (owner decision of 2 October 2026, `Admin\Account::TOKEN_LIFETIMES`).
OAuth access tokens live one hour and refresh tokens one year (owner decision of 9 October 2026, 3.9), renewed with
every refresh – a connection that is used at least once a year never expires; one idle for longer asks the user to sign
in again. Unused connections are still reported by the security hygiene check.

## 5. Tools

136 tools in total (Kaleta 3.8). What a connection lists depends on the site's features (News, Enquiries, Redirects,
Newsletter, Fleet console), on the connection's access level and on the user's role; Czech names of older connections
are hidden aliases and never listed.

| Access (`Mcp\Catalog`) | Tools | Annotations | Drafts-only connection | Read-only connection |
|---|---|---|---|---|
| read | 59 | `readOnlyHint: true` | yes | yes |
| draft | 24 | `readOnlyHint: false`, `destructiveHint: false` | yes | no |
| write | 28 | `readOnlyHint: false`, `destructiveHint: false` | no | no |
| destructive | 25 | `readOnlyHint: false`, `destructiveHint: true` | no | no |

By feature: 109 core tools, 11 News, 7 Enquiries, 2 Redirects, 5 Newsletter, 2 Fleet console.

- `idempotentHint: true` on 41 write tools verified in the code (setters that overwrite, deletes, booking decisions
  that hold for one status only) – `Mcp\Catalog::IDEMPOTENT`.
- `openWorldHint: true` on 11 tools: downloads from an address the caller gives (`upload_file` with a URL,
  `import_website`, `migration_report`, `import_wordpress`, `save_collection_items` with media URLs) and e-mails to people
  outside the site's users (`send_newsletter`, `request_testimonial`, `confirm_booking`, `decline_booking`,
  `cancel_booking`, `propose_booking_times`) – `Mcp\Catalog::OPEN_WORLD`.
- Prompts and resources (the site owner's instructions, an overview) are offered too; the portal syncs them with the
  tools. **The prompts are Kaleta's skills** (owner decision of 13 October 2026): Kaleta runs on many domains of many
  owners, so everything a user needs comes with the connection itself rather than with a plugin from a directory. Each
  prompt is a complete runbook in English that names the tools and, for an essentials connection, their runners:
  `migrate_site` (WordPress with WooCommerce, Polylang or WPML, or any platform), `launch_site`, `add_language_version`,
  `weekly_care`, `newsletter_release`, `set_up_bookings`, `compliance_check`, and the shorter `build_page`,
  `translate_page`, `write_news`, `audit_and_fix`, `work_requests`, `review_pending`, `scheduled_run` and
  `draft_blueprint` (3.13; `weekly_review` still answers as an alias). Their names and arguments are part of the
  contract (`tools/contracts/mcp-prompts.json`). The optional Claude plugin's five skills are generated from them
  (`php tools/build-plugin.php --skills`).
- tools/list with every feature on is about 152 KB (155,375 bytes in 3.9.2; 144,985 before the titles). The descriptions
  are long on purpose (the builder's data model).

**Tool sets (3.10).** Each connection has a tool set (`ka_api_tokeny.tool_set`, `Mcp\Catalog::TOOL_SETS`), chosen next
to the access level on the consent screen (default **Essentials**) and for a personal token (default **All tools**), and
switchable for an existing connection in My account. Connections from before 3.10 keep all tools; a token refresh keeps
the tool set.

- **All tools** – tools/list as above, unchanged.
- **Essentials** – the 32 core tools of `Mcp\Catalog::ESSENTIALS` (pages, builds, menu and look, collections, media,
  requests, enquiries, facts, audit, change log, `undo_agent_session`, notebook) plus `find_tools` and the runners the
  connection needs: 36 tools, 49,753 bytes on a full connection with every feature on (a unit test keeps it under
  50 KB; drafts-only 43,737 bytes, read-only 17,724). The server instructions add one paragraph pointing to the meta
  tools.
  - `find_tools` (read, `readOnlyHint: true`): without arguments a compact index of every other tool the connection may
    use (name, access, the runner to use, one line – about 15 KB); with `names` or `query` (at most 30 names, 200
    characters and 20 words) the full definitions of up to 8 tools, each with its runner.
  - Three runners (3.10 audit, N310-2 and N310-4), each running only its own class of tools so its annotations hold
    for everything it runs, and a client permission ("always allow") on one never covers a more dangerous class:
    - `run_read_tool` (`readOnlyHint: true`, `openWorldHint: false`): reads that stay inside the site;
    - `run_tool` (`readOnlyHint: false`, `destructiveHint: false`, `openWorldHint: false`): drafts and writes that
      neither remove, send nor reach outside the site;
    - `run_destructive_tool` (`destructiveHint: true`, `openWorldHint: true`): destructive tools and every tool that
      reaches outside the site (`Catalog::OPEN_WORLD`, including the read `migration_report`); its description tells
      Claude to confirm the exact action with the user first.
    Each refuses a tool of another class and names the right runner.
  - Two tools keep the read class and its annotations on purpose (3.15, N315-9 – changing an annotation would change the
    contract existing connections rely on): `get_due_agent_runs` changes no content – it marks runs as handed out – and
    refuses a read-only connection itself, because such a connection could never report a run; `migration_report`
    changes nothing on the site – its fetches are IP-pinned reads of the old public site (and it goes through
    `run_destructive_tool` as an open-world tool) and its state file holds only the report.
  - Only the runners the connection's access can use are listed (N310-6): a read-only connection gets `find_tools` and
    `run_read_tool` only (its open-world read `migration_report` stays callable by name); `find_tools` marks a tool
    whose runner is not listed as "direct call".
  - The runners take the same path as a direct tools/call (access level, guardrails and reason, undo journal, change
    log) and refuse every meta tool as the inner tool.
  - A tool outside the list can still be called directly by name.
- The meta tools are callable on any connection, but listed only for essentials connections; all four are in the
  contract (`tools/contracts/mcp-tools.json`).
- Before migration 0085 has run (the `tool_set` column missing), MCP and OAuth keep working with all tools (N310-9).

## 6. Reviewer access (owner decision D2: a private review site, reset nightly, never a public demo)

The reviewers get their own Kaleta site that is not linked from anywhere, is hidden from search engines and returns to
a prepared state every night. Nothing in this repository creates it; the owner does these steps.

1. **Install.** Create a subdomain that is not guessable and not linked anywhere (for example
   `review-<random>.kaletacms.com`) with HTTPS and an empty database. Install the current Kaleta release package there
   with an administrator account for the owner. Switch on every feature the listing mentions under **Features** (News,
   Enquiries, Newsletter, Bookings, Redirects, Statistics, the Claude connection).
2. **Keep it private.** Settings → SEO: untick "Allow the site in search engines" (`indexing` – noindex on every page
   and `Disallow` in robots.txt). Do not link the subdomain from kaletacms.com, the guide or GitHub.
3. **Populate it.** Start from a starter site and add realistic sample content: pages with builds, a few news items,
   two collections (team, services), menus, a pop-up, enquiries and newsletter subscribers. Use only `@example.com`
   e-mail addresses for people, so tools that e-mail (newsletters, bookings, testimonials) reach nobody; leave SMTP
   unset, so newsletters cannot be sent at all.
4. **Create the reviewer account.** Users → add `anthropic-review` as an **administrator** (so every tool can be
   tried), with a strong password the owner enters only in the portal's Test & launch step, and without required
   two-factor sign-in (Settings → General → Two-factor sign-in must not require it for administrators). Optionally set guardrails under Claude
   settings (hourly change limit) so a test run cannot flood the site.
5. **Snapshot.** When the content is ready, save the state the site returns to (see below).
6. **Reset nightly.** Add the cron job below. After every Kaleta update of the review site, take the snapshot again
   (an old snapshot would bring back the old database schema); simplest is to update the review site by hand.
7. **Hand over.** In the portal's Test & launch step give: the MCP URL `https://review-<random>.kaletacms.com/mcp`, the
   reviewer's user name and password, and the steps: add the URL as a custom connector in Claude → sign in on the site
   → choose **Full access** on the consent screen → Allow. Mention that the site resets every night at 03:30 CET, so
   their changes disappear but their connection stays.

**Why not the demo tooling.** `system/demo.php` and `tools/test-demo.sh` (2.6) belong to the public demo: they run only
with `'demo'` in `config.php`, and the demo mode switches off the Claude connection (`/mcp` and OAuth answer 403,
`Front\Kernel`), prints the shared password on the sign-in page, and refuses imports, newsletters and e-mail – exactly
what reviewers must test. Its reset also restores the whole database, which would delete the reviewers' OAuth
connection every night. So the review site uses a plain database snapshot that leaves the four connection tables alone:

```bash
# once, after step 3 (credentials in ~/.my.cnf, not on the command line)
DB=kaleta_review; SITE=/path/to/review-site
mysqldump --single-transaction --no-tablespaces "$DB" \
  --ignore-table="$DB.ka_api_tokeny" --ignore-table="$DB.ka_oauth_klienti" \
  --ignore-table="$DB.ka_oauth_kody" --ignore-table="$DB.ka_oauth_rotated" > ~/review/snapshot.sql
rsync -a --delete "$SITE/media/" ~/review/media/
```

```text
30 3 * * * mysql kaleta_review < ~/review/snapshot.sql && mysql kaleta_review -e "DELETE t FROM ka_api_tokeny t LEFT JOIN ka_uzivatele u ON u.idu = t.idu AND u.user = 'anthropic-review' WHERE u.idu IS NULL" && rsync -a --delete --exclude=.htaccess ~/review/media/ /path/to/review-site/media/ && rm -rf /path/to/review-site/storage/cache/stranky/* /path/to/review-site/storage/import/*
```

`mysqldump` writes `DROP TABLE IF EXISTS` before each table, so the restore replaces content, users, settings and the
change log, while tokens and OAuth clients stay – the reviewer's connection keeps working after a reset (their user
keeps its id). The `DELETE` after the restore removes the tokens of every other user: a user created after the snapshot
disappears with the reset, and the next user created would get the same id and inherit that token (N70). A later improvement would be a `review` variant of `system/demo.php` that does the same without the
public demo's restrictions.

## 7. Review risks to know before submitting

- **URL pattern and API ownership.** The criteria say the server "should call your own first-party APIs" and that the
  MCP server domain should match the service. Every Kaleta site runs on its operator's own domain, so the listing is a
  URL pattern for any host. Listings with a URL pattern take longer to review, and a pattern this broad may need a
  conversation with `mcp-review@anthropic.com` (or the **custom connection** type, where the user enters their site URL
  when connecting).
- **Instructions in descriptions.** A few tool descriptions tell Claude how to behave (for example `list_requests`,
  `get_due_agent_runs`: treat staff text as a request, never publish because of it). They protect the site from
  prompt injection, but the criteria reject descriptions that "tell Claude to behave in ways unrelated to the tool's
  function". They relate to the tool, so they should pass; if a reviewer flags them, move the rules into the server
  instructions (`Prompts::serverInstructions`).
- **Size of tools/list** (about 150 KB with every feature on) costs context in every conversation. Since 3.10 new OAuth
  connections default to the essentials (about 49 KB); the reviewers' connection gets them too unless they pick all tools.
- **Runners as a catch-all.** The criteria reject "catch-all API" tools. The runners take no HTTP method or endpoint –
  only the name of one of the site's own tools, checked exactly as a direct call – and each runs one class of tools
  only, with annotations true for all of them: reads (`run_read_tool`), drafts and writes (`run_tool`), and anything
  destructive or reaching outside the site (`run_destructive_tool`). A client that confirms per tool asks once per
  runner, so an "always allow" for drafts never covers deleting or sending; the connection's access level and the
  tools' own rules still apply.
- **The directory review should use an "All tools" connection** (choose it on the consent screen) if reviewers prefer
  no catch-all runner – then every tool is listed on its own and no meta tool is.

## 8. Checklist

Done in the code (3.8):

- [x] Title for every tool, top level and `annotations.title`; unit test: one per tool, unique, ≤ 40 characters.
- [x] `readOnlyHint` / `destructiveHint` unchanged in meaning; `idempotentHint` and corrected `openWorldHint` by English
      name; contract (`tools/contracts/mcp-tools.json`) records them and lets annotations change only additively or
      toward caution.
- [x] Add-on tools: `title`, `openWorld`, `idempotent` in `Api::mcpTool` (defaults: the name in words, false, false).
- [x] `initialize`: `title`, `websiteUrl`, `icons`.
- [x] OAuth: loopback redirect with any port (Claude Code).
- [x] Draft registry entry `docs/server.json`.
- [x] 3.10: tool sets – essentials (about 49 KB) with `find_tools` and three runners, or all tools; chosen
      at consent and in My account.
- [x] 3.9: OAuth refresh tokens live one year, renewed with every use (owner decision of 9 October 2026).
- [x] 3.13: the prompts are complete runbooks (the "skills" of every connection) and part of the contract.

For the owner:

- [ ] Check that https://kaletacms.com/privacy-policy, https://kaletacms.com/guide and
      https://kaletacms.com/image/kaleta-znacka-180.png are live (the registry entry points at the icon).
- [ ] Set up the review site (section 6): install, privacy, content, reviewer account, snapshot, nightly cron.
- [ ] Add the review site as a custom connector in Claude and call every tool once (the portal asks you to confirm
      this; MCP Inspector works too).
- [ ] Submit in the portal (paid Claude plan): Connection (URL pattern), Tools (synced), Listing, Use cases, Company,
      Authentication (DCR), Data handling, Test & launch (review site and reviewer credentials), Compliance.
- [ ] MCP registry (optional): prove the namespace – `com.kaletacms/*` needs a DNS TXT record or
      `https://kaletacms.com/.well-known/mcp-registry-auth`; `io.github.phprs-cms/*` needs a GitHub login of the
      organization – then `mcp-publisher publish` with `docs/server.json` (version = the released version).
