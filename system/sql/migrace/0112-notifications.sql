-- 3.26: notifications in the administration (the bell and the inbox) and each person's choice how e-mail reaches them.
-- A notification says who did what where (a mention, an assignment, a comment on my page or item, a review date) – never the
-- text of an enquiry; it keeps the actor's name so it still reads after the account is removed. Kept 90 days. No foreign
-- keys (a removed account never cascades). notify_email: instant (the default, as before 3.26), daily (one digest in the
-- morning) or off. The code of 3.25 does not read the table or the column. A table or column that already exists (an earlier
-- attempt) is skipped by the migration runner (1050/1060).
CREATE TABLE ka_notifications (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     INT UNSIGNED NOT NULL,
    kind        VARCHAR(20) NOT NULL,
    target      VARCHAR(40) NOT NULL DEFAULT '',
    actor_id    INT UNSIGNED NULL,
    actor_name  VARCHAR(100) NOT NULL DEFAULT '',
    excerpt     VARCHAR(255) NOT NULL DEFAULT '',
    created_at  DATETIME NOT NULL,
    read_at     DATETIME NULL,
    emailed_at  DATETIME NULL,
    PRIMARY KEY (id),
    KEY ix_notifications_user (user_id, read_at, id),
    KEY ix_notifications_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
ALTER TABLE ka_uzivatele ADD COLUMN notify_email VARCHAR(10) CHARACTER SET ascii NOT NULL DEFAULT 'instant';
