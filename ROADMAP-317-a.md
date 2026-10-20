## 3.17 – stream A: the fleet console's inbox and customers

- **Inbox across all sites** (Admin → Fleet console → Inbox, the console's new default tab; `Fleet\Inbox`): one list built only
  from the stored heartbeats and the console's own uptime check – request titles with status and age (new / in progress /
  done to review; counts when the site does not share titles), new enquiries (count), drafts and files waiting for approval
  (per kind of `PendingReview`), bookings to confirm, failed updates, sign-in attacks and reused tokens, certificate and
  domain expiring, health errors / failing jobs / no backup, audit findings, site down or silent. Sorted by urgency
  (urgent / waiting / when convenient), then the oldest first; filters kind (with a count per kind), site, customer, only
  urgent. Each row links with a plain link (new tab, `rel=noopener`, no sign-in, no token) to the screen of that site's
  administration (`PendingReview::URLS` shared with the dashboard). Rows of a site whose last report is older than 26 h are
  marked "possibly out of date". A 3.15 heartbeat (no 3.16 parts) gives what it has. Cards on a phone (`vypis-karty`).
- **Customers** (migration 0100: `ka_fleet_customers`, `ka_fleet_sites.customer_id` + `note`, `ka_fleet_kits.customer_id`;
  `Fleet\Customers`): a customer per site, renamed once for all its sites (renaming to an existing name merges), set on the
  site detail (with the owner's console-only note) or in bulk from the list of sites; Customers tab with sites, waiting
  items, rename and "Allow version … now" for one customer (`Console::allowNowForCustomer`). A kit version can be meant for
  one customer's sites (`Kit::latestFor` / `announcementFor`; other sites stay on the newest kit meant for everyone).
- **List of sites** (now `action=sites`): columns Customer and Waiting (`Inbox::waitingBySite`), customer filter, attention tabs kept.
- **MCP:** `fleet_inbox` (read-only, administrators, fleet extension; kind/site/customer/urgent_only; at most 200 items;
  `written_on_the_site` marks site-written texts), not in essentials; `list_sites` gains `customer` in the output and an
  optional `customer` filter (additive, contracts updated).
- Texts in admin-cs/de/es (+ de-du for the six that address the reader).
