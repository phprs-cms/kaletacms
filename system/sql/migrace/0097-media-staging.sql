-- 3.15 (audit finding N10): a file a Claude connection limited to drafts stores through MCP waits for approval outside the
-- web root (storage/staging/, Core\MediaStaging). NULL = public in media/ (every existing file); a time = staged since
-- then – it goes public with the content that uses it, or a person approves it in Media; after 30 days it is deleted.
-- The code of 3.14 does not read the column: it would list a staged file whose media/ path does not exist yet.
ALTER TABLE ka_media ADD COLUMN staged_at DATETIME NULL AFTER datum, ADD KEY ix_media_staged (staged_at);
