-- 3.39: states accepted on the fleet console (Fleet\Acceptances) – one check of one site accepted in its current state with a
-- note until a day; stored on the console only. A table that already exists (an earlier attempt) is skipped.
CREATE TABLE IF NOT EXISTS ka_fleet_acceptances (
    site_id     INT UNSIGNED NOT NULL,
    check_id    VARCHAR(40) NOT NULL,
    state       VARCHAR(10) NOT NULL,
    note        VARCHAR(200) NOT NULL DEFAULT '',
    ends_on     DATE NOT NULL,
    accepted_by INT UNSIGNED NULL,
    accepted_at DATETIME NOT NULL,
    PRIMARY KEY (site_id, check_id),
    KEY ix_fleet_acceptances_ends (ends_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
