-- 3.17: the fleet console keeps history (Fleet\History). One row per site and day: the uptime checks of that day added up
-- as they run (every 5 minutes: answered or not, and the response time), and – written by the hourly job from the stored
-- heartbeat – the day's last status, version, attention and what waited. Kept 400 days. Counts only, never personal data.
CREATE TABLE IF NOT EXISTS ka_fleet_history (
    site_id           INT UNSIGNED NOT NULL,
    day               DATE NOT NULL,
    checks_ok         INT UNSIGNED NOT NULL DEFAULT 0,
    checks_failed     INT UNSIGNED NOT NULL DEFAULT 0,
    response_ms_total INT UNSIGNED NOT NULL DEFAULT 0,
    response_count    INT UNSIGNED NOT NULL DEFAULT 0,
    status            VARCHAR(10) NOT NULL DEFAULT '',
    version           VARCHAR(30) NOT NULL DEFAULT '',
    attention         SMALLINT UNSIGNED NULL,
    reasons           VARCHAR(255) NOT NULL DEFAULT '',
    enquiries_new     INT UNSIGNED NULL,
    requests_open     INT UNSIGNED NULL,
    waiting_total     INT UNSIGNED NULL,
    audit_total       INT UNSIGNED NULL,
    failed_sign_ins   INT UNSIGNED NULL,
    problems          INT UNSIGNED NULL,
    report_summary    VARCHAR(500) NULL,
    updated_at        DATETIME NULL,
    PRIMARY KEY (site_id, day),
    KEY ix_fleet_history_day (day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- 3.17: the periods a site did not answer the console's checks (Fleet\History): opened when the console marks the site
-- down, closed when it answers again. Kept 400 days.
CREATE TABLE IF NOT EXISTS ka_fleet_incidents (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    site_id    INT UNSIGNED NOT NULL,
    started_at DATETIME NOT NULL,
    ended_at   DATETIME NULL,
    status     SMALLINT NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY ix_fleet_incidents_site (site_id, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- 3.17: the monthly fleet report per customer (Fleet\Report): built on the 1st from the console's own history for the
-- previous month, one row per customer (ka_fleet_customers.id, 0 = sites without a customer) with the customer's name
-- as it was when the report was made. Kept 24 months. A summary of what happened – uptime, incidents, versions,
-- attention and the sites' own headline counts – never an invoice, never personal data.
CREATE TABLE IF NOT EXISTS ka_fleet_reports (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    month          CHAR(7) CHARACTER SET ascii NOT NULL,
    customer_id    INT UNSIGNED NOT NULL DEFAULT 0,
    customer       VARCHAR(150) NOT NULL DEFAULT '',
    created_at     DATETIME NOT NULL,
    rebuilt_at     DATETIME NULL,
    schema_version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    data           MEDIUMTEXT NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY ux_fleet_reports_month_customer (month, customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
