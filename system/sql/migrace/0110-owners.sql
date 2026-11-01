-- 3.24: a responsible person for pages and collection items (responsibility only: My work, assignment e-mails, review
-- reminders – never an approval step or an access limit), and an index for "enquiries assigned to me". NULL (the default,
-- every existing row) = nobody. No foreign key, like ka_stranky.zmenil: a removed account never cascades; the admin shows
-- "(former user)" and Users offers to hand the work over before an account is blocked or deleted. The code of 3.23 does not
-- read the columns. A column or key that already exists (an earlier attempt) is skipped by the migration runner (1060/1061).
ALTER TABLE ka_stranky ADD COLUMN owner_id INT UNSIGNED NULL DEFAULT NULL, ADD KEY ix_stranky_owner (owner_id);
ALTER TABLE ka_kolekce_polozky ADD COLUMN owner_id INT UNSIGNED NULL DEFAULT NULL, ADD KEY ix_kolekce_polozky_owner (owner_id);
ALTER TABLE ka_poptavky ADD KEY ix_poptavky_prirazeno (prirazeno, stav);
