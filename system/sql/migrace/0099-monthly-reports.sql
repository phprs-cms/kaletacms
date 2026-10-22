-- 3.16: the archive of the monthly reports (Core\ReportArchive). Once the month has turned, a background job builds the
-- report of the previous month (Core\MonthlyReport::build) and keeps it here – whether or not the report goes out by e-mail –
-- because its sources are kept 30–180 days only. 24 months are kept; a rebuild from the data still available is an explicit
-- administrator action (rebuilt_at). Counts, titles and paths only, never personal data.
CREATE TABLE IF NOT EXISTS ka_monthly_reports (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    month          CHAR(7) CHARACTER SET ascii NOT NULL,
    created_at     DATETIME NOT NULL,
    rebuilt_at     DATETIME NULL,
    schema_version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    data           MEDIUMTEXT NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY ux_monthly_reports_month (month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
