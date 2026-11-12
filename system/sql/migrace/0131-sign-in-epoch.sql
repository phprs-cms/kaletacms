-- 3.41 (N116): a change of the second factor (the authenticator app on or off, a passkey added or removed, "passkey only",
-- an administrator's reset) raises the account's sign_in_epoch; a sign-in made under an older value ends, like after a new
-- password. A column that already exists (an earlier attempt) is skipped by the runner.
ALTER TABLE ka_uzivatele ADD COLUMN sign_in_epoch INT UNSIGNED NOT NULL DEFAULT 0;
