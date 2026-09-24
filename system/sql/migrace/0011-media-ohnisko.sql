-- Ohnisko obrázku: kam se má ořez soustředit, když se fotka vyplňuje do jiného tvaru (object-position, např. „50% 30%“).
ALTER TABLE ka_media ADD COLUMN ohnisko VARCHAR(12) NOT NULL DEFAULT '' AFTER barva;
