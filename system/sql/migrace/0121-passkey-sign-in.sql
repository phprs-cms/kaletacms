-- 3.35: sign-in with a passkey alone. passkey_handle is the account's opaque WebAuthn user handle (16 random bytes,
-- base64url – never the account number or e-mail), created with the first passkey registered since 3.35; passkey_only is
-- the account's own choice to sign in with a passkey only (Core\SignInPolicy – it applies once the account has a working
-- passkey). discoverable marks a passkey registered since 3.35 (resident, with user verification): it signs in without a
-- password; the older ones (0) stay the second step after the password. The code of 3.34 reads none of them. A column that
-- already exists (an earlier attempt) is skipped by the migration runner (1060).
-- 3.35 review M3: rp_id is the host a passkey is bound to (WebAuthn) – only the passkeys of the host of site_url count in the
-- sign-in rules (Core\SignInPolicy::keysForHost). The passkeys registered before are given the host of the current site_url
-- (lower case, without the scheme, the port, a path or a trailing dot); '' (no site_url yet) counts as the current host.
ALTER TABLE ka_uzivatele ADD COLUMN passkey_handle VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '';
ALTER TABLE ka_uzivatele ADD COLUMN passkey_only BOOL NOT NULL DEFAULT 0;
ALTER TABLE ka_uzivatele_klice ADD COLUMN discoverable BOOL NOT NULL DEFAULT 0;
ALTER TABLE ka_uzivatele_klice ADD COLUMN rp_id VARCHAR(255) NOT NULL DEFAULT '';
UPDATE ka_uzivatele_klice SET rp_id = COALESCE((SELECT TRIM(TRAILING '.' FROM LOWER(SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(n.hodnota, '://', -1), '/', 1), ':', 1))) FROM ka_nastaveni n WHERE n.promenna = 'site_url'), '') WHERE rp_id = '';
-- 3.35 review L5: merged_email is the stable marker of the note a merge of two customers writes (Core\Contacts::merge) – the
-- normalised address merged in; a personal-data request finds the note by it in any language. Older notes keep being
-- recognised by their wording (Contacts::mergeNoteNames).
ALTER TABLE ka_contact_activities ADD COLUMN merged_email VARCHAR(190) NOT NULL DEFAULT '';
