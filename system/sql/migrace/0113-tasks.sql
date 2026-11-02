-- 3.27: tasks and follow-ups (Core\Tasks): "call back on Monday", "review the price page by 1 Dec". A task belongs to one
-- person (assignee_id; NULL only after a leaver's work was handed to nobody), may name what it is about (target
-- 'enquiry:<idp>' | 'page:<ids>' | 'item:<idp>', NULL = nothing), and has a due moment and an optional reminder; the
-- background job "tasks" notifies once (notified_at). A task handed to Claude keeps the id of its request (request_id) and
-- is done. No subtasks, dependencies, time tracking or recurrence. No foreign keys (a removed account never cascades; the
-- target is text and follows its record: Core\Tasks::forgetTargets). The code of 3.26 does not read the table. A table that
-- already exists (an earlier attempt) is skipped by the migration runner (1050).
CREATE TABLE ka_tasks (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    title       VARCHAR(200) NOT NULL,
    target      VARCHAR(40) NULL,
    assignee_id INT UNSIGNED NULL,
    created_by  INT UNSIGNED NULL,
    due_at      DATETIME NULL,
    remind_at   DATETIME NULL,
    notified_at DATETIME NULL,
    done_at     DATETIME NULL,
    done_by     INT UNSIGNED NULL,
    request_id  INT UNSIGNED NULL,
    created_at  DATETIME NOT NULL,
    updated_at  DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY ix_tasks_assignee (assignee_id, done_at, due_at),
    KEY ix_tasks_target (target),
    KEY ix_tasks_due (done_at, notified_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
