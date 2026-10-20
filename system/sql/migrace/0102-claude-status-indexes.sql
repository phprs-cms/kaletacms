-- 3.18 (audit finding N318-2): the Claude connection status (Core\ClaudeConnection) is read on every admin screen of
-- administrators and Ask Claude staff, by System status and the fleet heartbeat. It counts the live connections – a range
-- on the kind and expiry of a token instead of a scan of every token ever issued – and takes the newest refresh of a live
-- connection by rotated_at (also what the hourly refresh limit of Front\OAuth and the cleanup of old records read).
-- A key that already exists (an earlier attempt) is skipped by the migration runner (error 1061).
ALTER TABLE ka_api_tokeny ADD KEY ix_tokeny_druh_expirace (druh, expirace);
ALTER TABLE ka_oauth_rotated ADD KEY ix_oauth_rotated_at (rotated_at);
