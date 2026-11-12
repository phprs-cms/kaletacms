-- 3.39 (security audit of 10 Nov 2026).
-- N106: the TOTP seed of an account is sealed with the key in config.php (Core\Vault::sealSecret, purpose "totp"):
-- "kaleta-sealed:" + base64 of the nonce, the tag and the seed – about 120 characters, so the column grows. A plain seed
-- stays readable; the first administrator request after the update and the daily security task seal the stored ones.
ALTER TABLE ka_uzivatele MODIFY totp_tajemstvi VARCHAR(255) NOT NULL DEFAULT '';
-- N110: through which Claude connection a notebook note was written and that connection's access; a note from a drafts-only
-- connection is a proposal (Core\Notebook). Existing notes count as written by a person ('' – unknown before 3.39).
-- A column that already exists (an earlier attempt) is skipped by the migration runner (1060).
ALTER TABLE ka_notebook ADD COLUMN via VARCHAR(100) NOT NULL DEFAULT '' AFTER author;
ALTER TABLE ka_notebook ADD COLUMN access VARCHAR(10) NOT NULL DEFAULT '' AFTER via;
