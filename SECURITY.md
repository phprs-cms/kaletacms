# Kaleta security policy

## Reporting a vulnerability

Please **do not report security issues publicly** in Issues. Use GitHub's private reporting
(*Security → Report a vulnerability*) or email **info@kaletacms.com**. Describe the version, the steps
and the impact. We reply within 3 working days and usually release a fix within 14 days; critical issues
are fixed as soon as possible.

## How a fix reaches users

1. The fix ships as a new version marked **security release**.
2. Every installation checks for new versions twice a day. Unless the administrator has turned it off,
   it **installs a security release on its own**: it backs up the database, verifies the checksum and the
   publisher's signature, and replaces the system files.
3. The administrator gets an email and a notice in the admin. Sites with automatic updates turned off
   update with one button.
4. After the fix is released we publish a security advisory (GitHub Security Advisory) with a description
   and credit to the reporter.

Every earlier version updates to a supported one in one step. Release pace, deprecation and what stays compatible:
[docs/RELEASE-POLICY.md](docs/RELEASE-POLICY.md).

## Release channels

From Kaleta 3.8 a site chooses its update channel in *Settings → Backups and updates*. Both channels are signed with
the same publisher key and verified the same way.

| Channel | What the site is offered | Default |
| --- | --- | --- |
| **Latest** | the newest release (`aktualizace.json`): a new minor version every week, and security releases | yes – every site, unless the owner switches |
| **Stable** | only the release in the stable manifest (`aktualizace-stable.json`): security patches of the stable line, and a move to a newer minor about once a month | no |

### Supported versions

| Version | Security fixes |
| --- | --- |
| The latest minor (Latest channel) | yes |
| The current stable line – the minor named in `aktualizace-stable.json` (Stable channel) | yes, as patch releases of that line |
| Any other version | no – update to a supported one |

Until the first stable manifest is published, only the latest minor is supported.

A site on Stable is never offered a version older than the one it runs. A site that switches from Latest while it runs a
newer minor than the stable line is offered nothing until the stable line passes its version; System status says so,
and security fixes reach such a site only on Latest. A site with a custom update source keeps it: the Stable channel
reads `aktualizace-stable.json` next to that source's `aktualizace.json`.

## Update signatures

Every update manifest (`aktualizace.json`, `aktualizace-stable.json`) is signed with the publisher's Ed25519 key, twice:

- **v1** (`podpis`): the version, the SHA-256 of the package and the security-release flag. Kaleta 1.0 to 3.8 check
  this one, so it stays in every manifest.
- **v2** (`podpis2`, from Kaleta 3.9): every field a site acts on – version, release date, package URL, SHA-256,
  minimum PHP, security flag, channel, key id and the list of changes – in a canonical encoding with byte lengths
  (`Kaleta\Core\Signature::manifestMessage`). Only the key named in the signed key id counts.

A site on Kaleta 3.9 or later checks v2 as soon as it reads the manifest, before it offers anything:

- a manifest with an invalid v2 signature is refused: nothing is offered or installed;
- a manifest of version 3.9.0 or later without v2 is refused (the signature was stripped);
- a manifest of an older version without v2 is read under the v1 rules, but its unsigned channel is ignored, so it is
  never a stable-channel release, and the installation checks the PHP version the package itself requires before it
  writes a file.

The v1 signature and the SHA-256 of the downloaded package are checked at installation, as before. Sites up to 3.8 read
only v1: for them the channel and the minimum PHP stay unsigned (accepted for 3.8; an install on a PHP the release does
not support is rolled back by the post-update check). A mirror of the update source must serve the manifest unchanged –
the package URL is signed.

## What the system protects

Prepared statements everywhere, `password_hash` passwords, CSRF protection on every admin action, escaped
output, re-encoded uploaded images, `system/` and `storage/` not reachable from the web, and updates only
with an Ed25519 signature. The system uses no third-party libraries at runtime.
