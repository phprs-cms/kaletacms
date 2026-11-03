-- 3.30: update rings and maintenance windows of the fleet console (Fleet\Rollout) and the reminders of its expiry calendar
-- (Fleet\Calendar). A site's ring is pilot | normal | careful; the test sites of before ("canary") are the pilot ring now.
-- A maintenance window is "<ISO days 1-7>|HH:MM-HH:MM" in the console's time zone (the same start and end = the whole day):
-- per customer ('' = any time) and optionally per site (NULL = its customer's window, '' = any time). A reminder of an
-- expiring certificate or domain is sent once per site, kind, date and "days before" – the rows say which were sent. The
-- code of 3.29 reads "pilot" as a normal site and does not read the new columns or the table. A column or table that
-- already exists (an earlier attempt) is skipped by the migration runner (1050/1060).
UPDATE ka_fleet_sites SET ring = 'pilot' WHERE ring = 'canary';
ALTER TABLE ka_fleet_sites ADD COLUMN update_window VARCHAR(40) NULL;
ALTER TABLE ka_fleet_customers ADD COLUMN update_window VARCHAR(40) NOT NULL DEFAULT '';
CREATE TABLE ka_fleet_expiry_reminders (
    site_id     INT UNSIGNED NOT NULL,
    kind        VARCHAR(20) NOT NULL,
    expires     DATE NOT NULL,
    days_before SMALLINT UNSIGNED NOT NULL,
    sent_at     DATETIME NOT NULL,
    PRIMARY KEY (site_id, kind, expires, days_before),
    KEY ix_fleet_expiry_reminders_sent (sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
