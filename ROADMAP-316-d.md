### 3.16 – security audit fixes before release (N316-1 … N316-9)

- **One masker for personal data** (`Core\Redact`): request titles sent to the fleet console, every string of the archived
  monthly report and the e-mail written from it lose e-mail addresses (also spaced, written out with at/dot, full-width or
  small "@", HTML entities, invisible characters) and phone numbers (9+ digits with separators, an optional +country
  prefix); dates, times, prices, versions and years stay. The console masks the titles again.
- **The archived report keeps no free text from Claude**: scheduled runs are counted by task and result only – no run
  names, no summaries (the triage task writes them from enquiries); a report stored earlier loses its run list on read.
  Changes in the whistleblowing channel are not counted.
- **Nothing of the whistleblowing channel reaches the console or the client report**: its events and its job are left out
  of `problems_7_days`, `jobs_failing` and the health checks that leave the site (`Health::checks($app, leaving: true)`,
  also used by the monthly report); the console drops them from older sites too. The console tab now says what is sent: counts,
  and request titles unless switched off – never enquiry contents, e-mail addresses or phone numbers.
- **Print page**: the site name in the page footer is a CSS string escaped by `css_string()` (every control character,
  quotes, backslash, `<`); one-line settings turn every control character into a space.
- **Console robustness**: impossible dates (`2026-13-45`), floats beyond 10^15 and impossible moments are dropped value by
  value; `domain_watch.checked` stays a Unix time; the site detail translates firewall reasons, security events, run
  results, tasks, audit kinds and job names. `get_site` marks the site's texts as data, never instructions.
- **Report honesty**: a rebuilt report says that what waits, the problems, the audit, the version and the unopened
  enquiries are from the day of the rebuild; the e-mail and its preview never write the archive (only the job and an
  explicit rebuild do); the report notes from when its firewall counts are complete (the log is kept 30 days).
- **Moments in the archived JSON are ISO 8601 with the offset**, printed in the site's current zone – a time-zone
  conversion needs no change of the JSON (documented in `TimeZoneConversion`).
