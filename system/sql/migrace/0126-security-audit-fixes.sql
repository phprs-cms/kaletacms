-- 3.39 (security audit of 10 Nov 2026).
-- N106: the TOTP seed of an account is sealed with the key in config.php (Core\Vault::sealSecret, purpose "totp"):
-- "kaleta-sealed:" + base64 of the nonce, the tag and the seed – about 120 characters, so the column grows. A plain seed
-- stays readable; the first administrator request after the update and the daily security task seal the stored ones.
ALTER TABLE ka_uzivatele MODIFY totp_tajemstvi VARCHAR(255) NOT NULL DEFAULT '';
