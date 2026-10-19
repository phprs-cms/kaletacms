# 3.16 – work-stream A: the monthly report as an archived, printable summary

- **Archive** (migration 0099, `ka_monthly_reports`, `Core\ReportArchive`): the background job `report_archive` (hourly, also on
  visits, before the clean-up and the e-mail) stores the previous month once – independent of the e-mail – and keeps 24 months.
  Nothing exists before 3.16; the Reports tab says from which month reports are kept. An administrator can rebuild a stored month
  "from the data still available" (with a warning: the sources are kept 30–180 days); the row is marked `rebuilt_at`.
- **Content** (`MonthlyReport::build`, existing keys kept): requests to Claude (done this month, still open), changes per area by
  people and by Claude (change log without sign-ins and reads), pages published (build.published) and news published, look
  publishes, Claude sessions and scheduled runs (status counts, task, summary), update versions from→to, off-site backup,
  security counts (firewall by reason, sign-ins, failed sign-ins, security events), the site audit by kind with fixed/new against
  the previous archived month, enquiry categories, bookings by status, newsletters sent, what waits at the month's end. Every
  section only when its feature is on; `MonthlyReport::scrub` removes anything with "@" from the whole JSON. No hours, no prices.
- **Settings → Reports** (new tab, administrators): the archived months with View and print (A4 page, `Core\ReportPage`,
  page numbers and the site/month at the foot of every page via `@page` margin boxes, no table row or short section split),
  Download (the same HTML file, no script), Send by e-mail (an archived month; never makes the job resend the last one) and
  Rebuild. The e-mail switch and the recipients moved here from Mail; the e-mail is written from the archived month and adds
  "Work on the site" and a security line.
- **MCP**: `list_monthly_reports`, `get_monthly_report` (read, administrators only, not in essentials; contracts additive).
- Deferred: a real PDF file (the browser's "Save as PDF" covers it today); converting the dates inside archived JSON when the
  time zone changes (the row's own columns are converted).
