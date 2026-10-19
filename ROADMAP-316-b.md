### 3.16 (stream B) – the heartbeat carries what waits for people (groundwork for one inbox across sites in 3.17)

- **New heartbeat parts** (`Fleet\Heartbeat`, all optional, null when the feature is off; a part that fails is null and never stops the report):
  `requests` (new, in_progress, done_to_review, oldest_waiting_hours, and – when the site shares them – up to 20 items
  {id, title ≤ 120 with e-mail addresses masked, status, created_at, updated_at}), `pending_review` (counts per `Core\PendingReview`
  kind, site-wide via the new `PendingReview::counts()`), `enquiries_new_not_spam`, `bookings_pending`, `security_7_days`
  (firewall refusals by reason, failed sign-ins, accounts locked now, `security.*` events – never addresses or names),
  `domain_watch` (certificate and domain expiry dates, SPF/DMARC/DKIM found or not – never records, hosts or error texts),
  `agent_runs` (runs by result in 7 days, the next due time and task kind, Claude sessions in 7 days), `updates`
  (behind_days, major_available), `report_month` (TODO at the merge with stream A: the archive table name).
- **Owner decision 19 Oct 2026:** the titles of Ask Claude requests may be sent – site setting `fleet_request_titles`, on by default,
  switch on Settings → Fleet console. Enquiries, e-mails and contents never leave the site.
- **Left out on purpose:** anything about the whistleblowing channel (even a count could point at a reporter in a small company).
- **Console:** the new keys are cleaned with their own shapes and bounds (`Console::cleanPart`, titles masked again); new reasons
  for attention – requests waiting > 48 h (10), bookings to confirm (5), certificate < 14 days (40), domain < 30 days (30),
  ≥ 50 failed sign-ins in 7 days or a reused refresh token (10). "Enquiries waiting" (list column, attention, MCP `list_sites`)
  now counts new enquiries without spam; `enquiries_unanswered` keeps its meaning (spam included) for older sites.
  The site detail has a compact "Waiting" block: request titles as plain links to the site's own Requests screen, review
  counts, bookings, security, certificate and domain dates, Claude runs, updates.
- **3.17:** the inbox across sites (one list of request titles and waiting counts from every site), filters by kind.
