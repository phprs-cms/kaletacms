-- 3.39 review M3: an accepted state on the fleet console remembers the check's specific problem (Fleet\Checks 'reason', e.g.
-- off / failing for the off-site backup, update_failed / security:<version> for updates) and ends when it changes – a worse
-- problem of the same colour is never muted. A column that already exists (an earlier attempt) is skipped by the runner (1060).
ALTER TABLE ka_fleet_acceptances ADD COLUMN reason VARCHAR(64) NOT NULL DEFAULT '' AFTER state;
-- acceptances made before (3.39 development only) carry no reason: they end, the operator accepts again if still wanted
DELETE FROM ka_fleet_acceptances WHERE reason = '';
