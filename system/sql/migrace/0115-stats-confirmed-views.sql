-- 3.29: visits confirmed by a browser (Front\Stats::hit). The daily visitor hash gets a counter of its page views and the
-- start of its current block of views (Unix time – a moment that no time zone change has to convert) – the flood guard: a visitor adds at most Stats::DAILY_VIEWS views a day, and one
-- that sends Stats::BURST_VIEWS views within Stats::BURST_SECONDS is a machine. The rows live for two days like the
-- hashes themselves; nothing about the visitor is added. The setting stats_confirmed_since is written by the first
-- counted beacon. A column that already exists (an earlier attempt) is skipped by the migration runner (1060).
ALTER TABLE ka_stat_navstevnici ADD COLUMN views SMALLINT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE ka_stat_navstevnici ADD COLUMN window_start INT UNSIGNED NOT NULL DEFAULT 0;
