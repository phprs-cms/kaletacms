-- 3.42: the team hierarchy (Core\Departments, Core\Absences) – departments with a head and a parent, each account's
-- department and the person it reports to, absence periods with a deputy, the stand-in copy of a notification and the
-- marker of an escalated overdue task. The code of 3.41 reads none of it. A table or column that already exists (an
-- earlier attempt) is skipped by the runner.
CREATE TABLE IF NOT EXISTS ka_departments (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name          VARCHAR(100) NOT NULL,
    head_id       INT UNSIGNED NULL,
    parent_id     INT UNSIGNED NULL,
    enquiry_forms VARCHAR(1000) NOT NULL DEFAULT '',
    created_at    DATETIME NOT NULL,
    updated_at    DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY ix_departments_parent (parent_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
CREATE TABLE IF NOT EXISTS ka_absences (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     INT UNSIGNED NOT NULL,
    starts_on   DATE NOT NULL,
    ends_on     DATE NOT NULL,
    deputy_id   INT UNSIGNED NULL,
    reason      VARCHAR(20) NOT NULL DEFAULT 'away',
    created_by  INT UNSIGNED NULL,
    created_at  DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY ix_absences_user (user_id, ends_on),
    KEY ix_absences_ends (ends_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
ALTER TABLE ka_uzivatele ADD COLUMN department_id INT UNSIGNED NULL;
ALTER TABLE ka_uzivatele ADD COLUMN reports_to INT UNSIGNED NULL;
ALTER TABLE ka_notifications ADD COLUMN standing_in_for VARCHAR(100) NOT NULL DEFAULT '';
ALTER TABLE ka_tasks ADD COLUMN escalated_at DATETIME NULL;
