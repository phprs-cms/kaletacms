-- 3.12 (docs/design/4.0-data-model.md §1.3 – expand): tokens, hashes and client ids compare as exact bytes (utf8mb4_bin)
-- instead of Czech text, so a lookup no longer folds case. The character set stays utf8mb4 – the connection's – so MySQL and
-- MariaDB alike compare a query value against the column without converting the column, and every lookup keeps its key
-- (WHERE otisk = ? uses uq_tokeny_otisk; with ascii_bin, MariaDB 10.6 and 11.4 did not use the key in CI). Every value is hex or base64
-- that Kaleta generated, so nothing can collide. The code of 3.11 keeps working: it compares exact strings anyway. Each
-- column keeps its type, NULL and default; keys are rebuilt by the same statement. utf8mb4_bin pads trailing spaces like
-- the old collation, so even that comparison stays the same.
ALTER TABLE ka_uzivatele MODIFY obnova_otisk CHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT '';
ALTER TABLE ka_stat_navstevnici MODIFY otisk CHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL;
ALTER TABLE ka_api_tokeny MODIFY klient CHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL, MODIFY otisk CHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL;
ALTER TABLE ka_uzivatele_klice MODIFY otisk_id CHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL;
ALTER TABLE ka_souhlasy MODIFY id_souhlasu CHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL;
ALTER TABLE ka_odberatele MODIFY token CHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL;
ALTER TABLE ka_oauth_klienti MODIFY client_id CHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL, MODIFY tajemstvi CHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT '';
ALTER TABLE ka_oauth_kody MODIFY otisk CHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL, MODIFY client_id CHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL;
ALTER TABLE ka_oauth_rotated MODIFY hash CHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL, MODIFY client_id CHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL, MODIFY salt CHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL;
ALTER TABLE ka_fleet_sites MODIFY public_key VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL;
ALTER TABLE ka_fleet_pairing MODIFY code_hash CHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL;
ALTER TABLE ka_fleet_kits MODIFY sha256 CHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL;
ALTER TABLE ka_testimonial_requests MODIFY token_hash CHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL;
ALTER TABLE ka_whistleblowing_cases MODIFY code_hash CHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL;
ALTER TABLE ka_bookings MODIFY token_hash CHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL;
