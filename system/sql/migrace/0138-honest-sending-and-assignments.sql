-- 3.51 (honest sending and team counts): every column of the release in one structure migration; 0139 fills two of them.
--  - ka_odberatele.language: the language version a subscriber signed up in ('' = the default language);
--  - ka_testimonial_requests.sent_at: the request was e-mailed (a testimonial request goes out once per enquiry);
--  - ka_newsletter_queue.tried_at / reply: the last delivery attempt and its SMTP answer "<stage>:<code>:<enhanced>"
--    (Core\Mail::lastResult) – the brake of a send and the bounce candidates read them;
--  - ka_newsletters.paused_at / pause_reason (manual | brake) / resumed_at / skipped_count (the remainder of the
--    recipients when a newsletter ends: recipients = sent + failed + skipped);
--  - assigned_by on enquiries, tasks, deals and quotes: who last changed the responsible person (NULL = a rule of the site
--    or before 3.51) – the team summary leaves out what the viewer put on someone else.
-- The code of 3.50 reads none of it. A column or index that already exists (an earlier attempt) is skipped by the runner.
ALTER TABLE ka_odberatele ADD COLUMN language VARCHAR(35) CHARACTER SET ascii NOT NULL DEFAULT '';
ALTER TABLE ka_testimonial_requests ADD COLUMN sent_at DATETIME NULL;
ALTER TABLE ka_newsletter_queue ADD COLUMN tried_at DATETIME NULL;
ALTER TABLE ka_newsletter_queue ADD COLUMN reply VARCHAR(32) CHARACTER SET ascii NOT NULL DEFAULT '';
ALTER TABLE ka_newsletter_queue ADD KEY ix_newsletter_queue_tried (newsletter_id, tried_at);
ALTER TABLE ka_newsletters ADD COLUMN paused_at DATETIME NULL;
ALTER TABLE ka_newsletters ADD COLUMN pause_reason VARCHAR(10) CHARACTER SET ascii NOT NULL DEFAULT '';
ALTER TABLE ka_newsletters ADD COLUMN resumed_at DATETIME NULL;
ALTER TABLE ka_newsletters ADD COLUMN skipped_count INT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE ka_poptavky ADD COLUMN assigned_by INT UNSIGNED NULL;
ALTER TABLE ka_tasks ADD COLUMN assigned_by INT UNSIGNED NULL;
ALTER TABLE ka_deals ADD COLUMN assigned_by INT UNSIGNED NULL;
ALTER TABLE ka_quotes ADD COLUMN assigned_by INT UNSIGNED NULL;
