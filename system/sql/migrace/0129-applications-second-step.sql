-- 3.40 (applications II): the counts of the statistics tab and the monthly report (ka_application_counts – per month and
-- position, never a person, so the yearly numbers survive the retention), the renewal of the consent for future openings
-- (one link token stored as sha256, its expiry, when the e-mail went) and the day the deletion reminder announced.
-- A column or table that already exists (an earlier attempt) is skipped by the runner (1060, 1050).
ALTER TABLE ka_applications ADD COLUMN pool_token CHAR(64) CHARACTER SET ascii NULL;
ALTER TABLE ka_applications ADD COLUMN pool_token_until DATETIME NULL;
ALTER TABLE ka_applications ADD COLUMN pool_renewal_sent_at DATETIME NULL;
ALTER TABLE ka_applications ADD COLUMN deletion_warned_on DATE NULL;
ALTER TABLE ka_applications ADD KEY ix_applications_pool (pool_until);
ALTER TABLE ka_applications ADD KEY ix_applications_pool_token (pool_token);
CREATE TABLE IF NOT EXISTS ka_application_counts (
    month       CHAR(7) CHARACTER SET ascii NOT NULL,
    job_item_id INT UNSIGNED NOT NULL DEFAULT 0,
    job_title   VARCHAR(200) NOT NULL DEFAULT '',
    received    INT UNSIGNED NOT NULL DEFAULT 0,
    interview   INT UNSIGNED NOT NULL DEFAULT 0,
    offer       INT UNSIGNED NOT NULL DEFAULT 0,
    hired       INT UNSIGNED NOT NULL DEFAULT 0,
    rejected    INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (month, job_item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
-- the applications kept so far: received in the month they came, the status they hold now in the month they reached it
-- (the history before 3.40 is not replayed); run again it adds nothing (INSERT IGNORE on the month and position)
INSERT IGNORE INTO ka_application_counts (month, job_item_id, job_title, received, interview, offer, hired, rejected)
SELECT s.m, s.j, MAX(s.t), SUM(s.r), SUM(s.i), SUM(s.o), SUM(s.h), SUM(s.x) FROM (
    SELECT DATE_FORMAT(received_at, '%Y-%m') AS m, IFNULL(job_item_id, 0) AS j, job_title AS t, 1 AS r, 0 AS i, 0 AS o, 0 AS h, 0 AS x FROM ka_applications
    UNION ALL
    SELECT DATE_FORMAT(status_at, '%Y-%m'), IFNULL(job_item_id, 0), job_title, 0, status = 'interview', status = 'offer', status = 'hired', status = 'rejected'
    FROM ka_applications WHERE status IN ('interview', 'offer', 'hired', 'rejected')
) s GROUP BY s.m, s.j;
