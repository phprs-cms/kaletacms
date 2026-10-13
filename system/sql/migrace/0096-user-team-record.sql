-- 3.14: an administrator may link a user account to the person's record in the Team collection (Users → edit user), so
-- that every employee copies their own e-mail signature in My account. NULL = automatic: the record whose e-mail is the
-- account's. A column of the account, not a setting: it goes with the account when it is deleted, and the code of 3.13
-- simply does not read it. No foreign key – an item in the trash or deleted makes the link fall back to the e-mail match.
ALTER TABLE ka_uzivatele ADD COLUMN team_item_id INT UNSIGNED NULL AFTER bio;
