-- 3.22 (C10): a reply can be e-mailed from the enquiry detail (and by Claude with reply_to_enquiry). Every reply is kept on
-- the enquiry as JSON (Core\EnquiryReply): who sent it and when, the subject, the text and the ka_posta row whose state the
-- detail shows. NULL = no reply yet. The code of 3.21 does not read the column. A column that already exists (an earlier
-- attempt) is skipped by the migration runner (error 1060).
ALTER TABLE ka_poptavky ADD COLUMN replies MEDIUMTEXT NULL AFTER anonymizovano;
