-- 3.54: newsletter audiences and news consent at the form. A newsletter goes to everyone confirmed ('all') or to the
-- confirmed subscribers of its language ('language'), optionally narrowed to customers (audience_filter 'customers');
-- unsubscribed_count is the aggregate of unsubscriptions through its links. A subscriber keeps the proof of consent:
-- the wording shown, the source (page, collection item or site part) and the element id. Every default means the
-- behaviour before 3.54, so the update changes no recipient. Each statement is skipped when it already ran
-- (Migration::ALREADY_APPLIED – 1060 the column exists), so the migration can run again.
ALTER TABLE ka_newsletters ADD COLUMN audience VARCHAR(20) CHARACTER SET ascii NOT NULL DEFAULT 'all';
ALTER TABLE ka_newsletters ADD COLUMN audience_filter VARCHAR(20) CHARACTER SET ascii NOT NULL DEFAULT '';
ALTER TABLE ka_newsletters ADD COLUMN unsubscribed_count INT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE ka_odberatele ADD COLUMN consent_text VARCHAR(500) NOT NULL DEFAULT '';
ALTER TABLE ka_odberatele ADD COLUMN consent_source VARCHAR(64) NOT NULL DEFAULT '';
ALTER TABLE ka_odberatele ADD COLUMN consent_element VARCHAR(16) NOT NULL DEFAULT '';
