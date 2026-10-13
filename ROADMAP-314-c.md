### 3.14 – work-stream C: own e-mail signatures, Spanish blueprints

- **Every employee copies their own e-mail signature (owner's idea).** My account has a second tab, *My e-mail signature*
  (`admin.php?action=account&tab=signature`), for every signed-in user, no Collections permission needed: the same preview,
  Copy button, plain text and where-to-paste help as the Collections screen (shared partial `views/admin/email_signature.php`).
  The record comes only from the account, never from the request (`Builder\TeamRecord::forUser`): an administrator's choice in
  Users → edit user ("Team record"), else automatically the record whose e-mail field equals the account's e-mail
  (case-insensitive, hidden records too, never one in the trash; the Team preset first when there are several people collections).
  A chosen record that is deleted, trashed or no longer in a people collection falls back to the e-mail match. Not linked:
  a hint to ask an administrator. The Users list says who is linked and how; the Team items list says staff can copy their own
  signature once someone is linked.
- **Storage:** migration 0096 adds `ka_uzivatele.team_item_id INT UNSIGNED NULL` (NULL = by the e-mail). A column of the
  account rather than a setting: it goes with the account when it is deleted, needs no JSON map in settings, and 3.13 code
  ignores it (expand only). No foreign key, so trashing or deleting a record just falls back.
- **Spanish blueprint texts:** all 20 shipped blueprints carry `es` next to en/cs/de for every name, description, fact label,
  question, help and audit message (neutral Spanish, "tú", the terms of `admin-es.php`). `Blueprint::text()` picks the admin
  language and falls back from a regional code to its base language. Unit test: every language object of every manifest file
  has en, cs, de and es, filled in and translated.
