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

Only the latest released version is supported.

## What the system protects

Prepared statements everywhere, `password_hash` passwords, CSRF protection on every admin action, escaped
output, re-encoded uploaded images, `system/` and `storage/` not reachable from the web, and updates only
with an Ed25519 signature. The system uses no third-party libraries at runtime.
