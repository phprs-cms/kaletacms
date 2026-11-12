-- 3.38 review M3: when a Claude connection (MCP) last set the e-mail of a customer record – save_contact creating or
-- changing it, or a record made from an enquiry that import_enquiries brought in. Claude sends a quote (send_quote) only
-- to an address a person, a form or the customer gave (design A-5): an address Claude itself wrote is sent only by a person
-- from the administration. A person changing the e-mail in the administration clears it. NULL for every existing record.
-- A column that already exists (an earlier attempt) is skipped by the migration runner (1060).
ALTER TABLE ka_contacts ADD COLUMN email_by_agent_at DATETIME NULL;
