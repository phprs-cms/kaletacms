-- 3.35: sign-in with a passkey alone. passkey_handle is the account's opaque WebAuthn user handle (16 random bytes,
-- base64url – never the account number or e-mail), created with the first passkey registered since 3.35; passkey_only is
-- the account's own choice to sign in with a passkey only (Core\SignInPolicy – it applies once the account has a working
-- passkey). discoverable marks a passkey registered since 3.35 (resident, with user verification): it signs in without a
-- password; the older ones (0) stay the second step after the password. The code of 3.34 reads none of them. A column that
-- already exists (an earlier attempt) is skipped by the migration runner (1060).
ALTER TABLE ka_uzivatele ADD COLUMN passkey_handle VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '';
ALTER TABLE ka_uzivatele ADD COLUMN passkey_only BOOL NOT NULL DEFAULT 0;
ALTER TABLE ka_uzivatele_klice ADD COLUMN discoverable BOOL NOT NULL DEFAULT 0;
