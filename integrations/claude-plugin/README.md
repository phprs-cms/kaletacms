# Kaleta for Claude

A Claude plugin for [Kaleta](https://kaletacms.com) sites. It works together with the Claude connection (MCP) that
every Kaleta site has at `https://<your-site>/mcp`. The connection gives Claude the tools; the plugin adds five skills
that tell Claude how to use them for the jobs people ask for most.

| Skill | What it does |
|---|---|
| `migrate-from-wordpress` | Moves a WordPress site (or a site on any other platform) to Kaleta. It imports the content, rebuilds Breakdance or Elementor layouts in the Kaleta builder, recreates collections, forms and redirects, and checks the move with the migration report. |
| `launch-site` | Takes a fresh install to a finished site: business details and facts, a blueprint, the look, pages, header, footer and menu, legal texts from templates, the accessibility statement and a health check. |
| `weekly-care` | Runs the weekly care: health, site audit, broken links, image descriptions, addresses not found, enquiry triage, the notebook and scheduled runs for a Claude routine. |
| `set-up-bookings` | Sets up online bookings: services, people, hours, exceptions, booking rules and texts, the booking page, and pending bookings. |
| `compliance-check` | Checks the record of processing, cookies, the privacy policy template, accessibility, and personal data requests. Never legal advice. |

Every skill works the way the Kaleta connection does. It builds drafts and hidden pages, and publishes only when you
ask. It asks before anything is deleted, discarded or sent. It never asks for passwords, API keys or tokens in the
chat. What a connection may do is set when you connect: everything your account may do, drafts only, or read only.
The skills respect that.

A connection also has a tool set, chosen when you connect: **Essentials** (the default for a connector) lists the core
tools and keeps every conversation lighter; Claude reaches the other tools a skill names with `find_tools`, then
`run_read_tool` (reads) or `run_tool` (changes). **All tools** lists everything at once. Switch it for any connection in Kaleta under My account.

## Where it works

The plugin is a standard Claude plugin folder: `.claude-plugin/plugin.json`, `skills/<name>/SKILL.md` and `.mcp.json`.
It has no executables, hooks or agents, so every surface accepts it.

| Surface | Skills | Connection to your site |
|---|---|---|
| Claude Code (terminal, IDE extensions, the desktop app's Code tab) | Yes | The plugin's `.mcp.json` connects to the address you enter when you enable the plugin, and you sign in on the site. |
| claude.ai chat (web, desktop app, mobile apps) | Yes | Add your site as a custom connector first (below). Chat ignores a plugin server whose address comes from user configuration. |
| Cowork (desktop app) | Yes | Add your site as a custom connector first (below). Cowork does not ask for configuration values. |

A plugin installed on your claude.ai account also appears in Claude Code as a synced plugin. The skills use the tool
names of any connected Kaleta site, whether the connection comes from the plugin, from a connector or from
`claude mcp add`.

## Connect your site

Each Kaleta site has its own address and its own sign-in. Your site shows the exact address in the administration
under Claude → Settings and connections. It is usually `https://www.example.com/mcp`, or
`https://www.example.com/folder/mcp` for a site in a subfolder. The site needs HTTPS.

**claude.ai, the desktop app and Cowork: add the connector first, then install the plugin.**

1. Settings → Connectors → Add custom connector. Enter the address of your site's connection.
2. Claude sends you to your site. Sign in with your Kaleta account and choose what the connection may do. Nothing is
   copied by hand.
3. Install the plugin (below). Connected apps are listed, and can be disconnected, in Kaleta under My account.

**Claude Code.** When you enable the plugin, Claude Code asks for the *Kaleta site connection address*. Enter the
address above. Then run `/mcp` and sign in on your site when Claude Code asks. If you have already added the site with
`claude mcp add --transport http kaleta https://www.example.com/mcp`, it keeps working next to the plugin. To change
the address later, open `/config`.

**More than one site.** The plugin's own connection points to one site. Add each further site as its own connector in
claude.ai, or with `claude mcp add` under its own name in Claude Code. Then tell Claude which site you mean.

## Install

**Claude Code, from this repository** (after the owner publishes a marketplace, see below):

```bash
/plugin marketplace add phprs-cms/kaletacms
/plugin install kaleta@kaleta
```

**Claude Code, from a folder** (for testing): `claude --plugin-dir integrations/claude-plugin`.

**claude.ai, the desktop app and Cowork:** Customize → Plugins → Add → Upload plugin, and choose
`kaleta-claude-plugin-<version>.zip`. You can also use Add → Add marketplace with the GitHub repository.

## Build the zip

```bash
php tools/build-plugin.php
```

The script writes `dist/kaleta-claude-plugin-<version>.zip`, where the version is `KALETA_VERSION`. It refuses to build
when the manifest's version differs. The plugin's own folder sits at the top of the zip, which is the form claude.ai's
Upload plugin accepts. `php tools/unit-tests.php` checks the plugin:

- every `SKILL.md` follows the Agent Skills format;
- every tool a skill names exists in the Kaleta connection;
- every other snake_case word in a skill is a parameter of a tool, a setting the connection may change, or a term the
  skill lists in `metadata.kaleta-terms`;
- the manifest and `.mcp.json` are valid.

## Publish (for the maintainer)

To publish a marketplace from the public repository, add `.claude-plugin/marketplace.json` at the repository root:

```json
{
    "name": "kaleta",
    "owner": { "name": "Kaleta", "url": "https://kaletacms.com" },
    "plugins": [
        {
            "name": "kaleta",
            "source": "./integrations/claude-plugin",
            "description": "Runbooks for Kaleta sites over the site's Claude connection."
        }
    ]
}
```

Users then run `/plugin marketplace add phprs-cms/kaletacms`. A separate small repository with the plugin at its root
works the same way and is lighter to clone. Check the plugin with `claude plugin validate integrations/claude-plugin`
before each release. Raise `version` in `.claude-plugin/plugin.json` with `KALETA_VERSION`: users stay on the version
they have until it changes.

## Sources

The format follows the documentation as of October 2026:

- [Plugin manifest reference](https://code.claude.com/docs/en/plugins/manifest-reference) – `plugin.json`, `userConfig`, `${user_config.KEY}`.
- [MCP in Claude Code](https://code.claude.com/docs/en/mcp) – remote `http` servers in `.mcp.json`, sign-in with `/mcp`.
- [Plugin marketplaces](https://code.claude.com/docs/en/plugins/marketplace-reference) – `marketplace.json`, relative sources.
- [Plugin feature support across platforms](https://claude.com/docs/plugins/platform-support) – what chat, Cowork and Claude Code load.
- [Plugin structure and testing](https://claude.com/docs/plugins/build) – Upload plugin, the Connectors tab.
- [Agent Skills specification](https://agentskills.io/specification) – `SKILL.md` frontmatter: `name`, `description`, `license`, `metadata`.
