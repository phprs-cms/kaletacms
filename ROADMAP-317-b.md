# 3.17 – work stream B: the fleet console keeps history, alerts well, reports per customer

- **Daily history** (migration 0101, `Fleet\History`): `ka_fleet_history` – one row per site and day. Every 5-minute uptime
  check adds to the day (answered or not, the time to the first byte, `Http::checks`); the hourly job `fleet_history` writes
  the day's last status, version, attention score and reasons and what waited (enquiries, open requests, "Waiting for you",
  audit, failed sign-ins, problems, the site's report headline) from the stored heartbeat. `ka_fleet_incidents` keeps the
  down periods (opened at the first failed check of the row, closed when the site answers). 400 days kept.
- **Trends on a site's page**: uptime and average response this month and last month, 90 days as bars (inline SVG with a
  summary in `aria-label` and the days as a table in `<details>`), outages and versions in the 90 days. Also in MCP
  `get_site` (`trends`).
- **Alert rules** (Fleet → Alerts and digest, `Fleet\AlertRules`, job `fleet_alerts` every 5 minutes): site down, stopped
  reporting (hours), certificate (days), domain (days), update failed, failed sign-ins (count in 7 days), requests waiting
  (hours) – on/off and threshold, recipients (default alerts_email, then the site e-mail), quiet hours (default 24) counted
  from the events `fleet.alert_sent`. Defaults = the behaviour before 3.17 (down and silent on, the rest off);
  `Core\Alerts` no longer sends `fleet.site_down`/`fleet.site_silent` itself (no double e-mail); `alerts_enabled` stays the
  master switch.
- **Weekly digest** (`Fleet\Digest`, job `fleet_digest`, off by default): Monday from 7:00, once an ISO week, by customer –
  attention, what waits (counts), outages and uptime of the week, versions installed, plain links. Request titles only with
  `fleet_digest_titles` (off by default). Preview in Fleet → Alerts and digest.
- **Monthly fleet report per customer** (`Fleet\Report`, `ka_fleet_reports`, job `fleet_reports` on the 1st from 6:00,
  24 months): per site uptime, outages, versions, days needing attention and why, and the site's own headline counts from the
  new heartbeat key `report_summary` (visits, enquiries, requests handled, changes by people/Claude, updates, backups – counts
  only, whitelisted on the console). Printed with the 3.16 A4 page (`report_print.php` takes `kind`, `footer`, `brand`,
  `rebuiltNote`), the console's agency branding; view/print, download, make now, rebuild. MCP `fleet_report` (month,
  customer; read, fleet extension, not in essentials). Not an invoice, no personal data (`MonthlyReport::scrub`).
- **Customers**: `Console::customerOf($site)` reads `$site['customer']` and returns '' until stream A's column exists – at
  the merge, align it with A's column (a name or an id → name).

Open: a removed site's history goes with it (the stored fleet reports keep it); response time is the console's own view
(time to the first byte from the console), not the visitors'.
