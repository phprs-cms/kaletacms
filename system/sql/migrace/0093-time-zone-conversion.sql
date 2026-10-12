-- 3.12 (docs/design/4.0-data-model.md §6): when the time_zone setting changes, Settings offers to convert the saved
-- moments (or to keep their clock time). The conversion runs in batches, table by table, and records where it is.
CREATE TABLE ka_time_zone_conversions (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    from_zone   VARCHAR(64)  NOT NULL,                    -- the zone the saved dates were written in
    to_zone     VARCHAR(64)  NOT NULL,                    -- the zone they are converted to (the new time_zone setting)
    started_by  INT UNSIGNED NULL,                        -- the administrator who chose to convert
    started_at  DATETIME     NOT NULL,
    finished_at DATETIME     NULL,                        -- NULL = still running (the background job "time_zone" continues it)
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE ka_time_zone_progress (
    conversion_id INT UNSIGNED NOT NULL,
    table_name    VARCHAR(64)  NOT NULL,                  -- without the prefix, as in Core\TimeZoneConversion::INSTANTS
    position      SMALLINT UNSIGNED NOT NULL DEFAULT 0,   -- smaller tables first: the busy little ones are done in the first second
    upto_key      BIGINT UNSIGNED NULL,                   -- a numeric primary key: rows above it were written after the start (already on the new clock)
    last_key      VARCHAR(1000) NOT NULL DEFAULT '',      -- JSON of the primary key of the last converted row; '' = not started
    rows_done     INT UNSIGNED NOT NULL DEFAULT 0,
    values_done   INT UNSIGNED NOT NULL DEFAULT 0,
    ambiguous     INT UNSIGNED NOT NULL DEFAULT 0,        -- values in the hour a daylight-saving change repeats: the first occurrence was taken
    finished_at   DATETIME NULL,
    PRIMARY KEY (conversion_id, table_name),
    CONSTRAINT fk_time_zone_progress_conversion FOREIGN KEY (conversion_id) REFERENCES ka_time_zone_conversions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
