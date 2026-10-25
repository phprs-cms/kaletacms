-- 3.19 (owner, 22 October 2026): a collection can have its own icon – a key of the built-in icon set (Builder\Icons::SET),
-- chosen in the collection's settings or through MCP (create_collection / update_collection icon), shown by its item in
-- the admin's left menu and in the list of collections. NULL = the generic list icon of 3.18.3. The code of 3.18.3 does not
-- read it. A column that already exists (an earlier attempt) is skipped by the migration runner (error 1060).
ALTER TABLE ka_kolekce ADD COLUMN icon VARCHAR(40) NULL AFTER admin_menu;
