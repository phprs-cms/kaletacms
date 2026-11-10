-- T1 (Q5): the retention clean-up (Enquiries::deleteExpired – SELECT/DELETE … WHERE datum < ?) and the notification
-- clean-up (Inbox::forgetTargets – WHERE target IN (…)) scanned the whole table each time. An index that already exists
-- (an earlier attempt) is skipped by the migration runner (1061).
ALTER TABLE ka_poptavky ADD KEY ix_poptavky_datum (datum);
ALTER TABLE ka_notifications ADD KEY ix_notifications_target (target);
