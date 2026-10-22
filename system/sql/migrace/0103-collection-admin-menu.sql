-- 3.18.3: a collection can have its own item in the admin's left menu – a direct link to its items (products, job openings),
-- so nobody has to go Collections → the collection. On by default for every existing and new collection; switched off
-- in the collection's settings or through MCP (update_collection admin_menu). The code of 3.18.2 does not read it.
-- A column that already exists (an earlier attempt) is skipped by the migration runner (error 1060).
ALTER TABLE ka_kolekce ADD COLUMN admin_menu TINYINT(1) NOT NULL DEFAULT 1 AFTER detail;
