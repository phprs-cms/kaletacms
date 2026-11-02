<?php

declare(strict_types=1);

namespace Kaleta\Admin;

use Kaleta\Core\App;
use Kaleta\Core\Assignment;
use Kaleta\Core\Clock;
use Kaleta\Core\Request;
use Kaleta\Core\Tasks;

/**
 * The tasks of a record in the admin (3.27, Core\Tasks): what the form "Add a task" (views/admin/task_form.php) posts,
 * turned into the fields of Tasks::create, the default titles of the quick buttons and who may get a task. Pure apart from
 * people() – the action is Admin\TaskActions, the panel views/admin/tasks_panel.php. My work can post the same form.
 */
final class TaskPanel
{
    /** The quick due days of "Add a task" (they add at once): key => days from today. */
    public const array QUICK = ['today' => 0, 'tomorrow' => 1, '3days' => 3, 'week' => 7];

    /** The follow-up buttons of an enquiry: key => days from today. A follow-up is the signed-in person's own task. */
    public const array FOLLOW_UP = ['tomorrow' => 1, '3days' => 3, 'week' => 7];

    /** The reminder choices: key => hours before the due moment; '' = when it is due (the task notifies then anyway). */
    public const array REMIND = ['' => 0, '1h' => 1, '1d' => 24];

    /** The labels of QUICK and FOLLOW_UP (English keys of the dictionaries). */
    public const array DAY_LABELS = ['today' => 'Today', 'tomorrow' => 'Tomorrow', '3days' => 'In 3 days', 'week' => 'In a week'];

    /** The labels of REMIND. */
    public const array REMIND_LABELS = ['' => 'When it is due', '1h' => '1 hour before', '1d' => '1 day before'];

    /**
     * The fields of Tasks::create or Tasks::update from the posted form: title, assignee_id (0 = me), due_at and remind_at
     * as DATETIME (null = none). A quick day (due_quick) wins over the date field; the time field applies to either.
     *
     * @return array{title: string, assignee_id: int, due_at: ?string, remind_at: ?string}
     * @throws \DomainException a date or a time that is not understood (translated)
     */
    public static function fromForm(Request $request, ?string $today = null): array
    {
        $due = Tasks::parseMoment(self::due($request->post('due_date'), $request->post('due_time'), $request->post('due_quick'), $today));

        return ['title' => $request->post('title'), 'assignee_id' => max(0, $request->postInt('assignee_id')), 'due_at' => $due,
            'remind_at' => self::remindAt($due, $request->post('remind'))];
    }

    /**
     * The due moment as Tasks::parseMoment takes it: "YYYY-MM-DD" (DEFAULT_TIME), "YYYY-MM-DD HH:MM", or '' for none. A
     * quick day (a key of QUICK) counts from $today (the site's day). A time without a date is today's.
     *
     * @throws \DomainException a time that is not HH:MM
     */
    public static function due(string $date, string $time, string $quick = '', ?string $today = null): string
    {
        $today ??= Clock::today();
        $date = trim($date);
        $time = trim($time);
        if (isset(self::QUICK[$quick])) {
            $date = (new \DateTimeImmutable($today . ' +' . self::QUICK[$quick] . ' days'))->format('Y-m-d');
        }
        if ($time !== '' && preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/D', $time) !== 1) {
            throw new \DomainException(t('The time “%s” is not understood – use HH:MM.', $time));
        }
        if ($date === '') {
            return $time === '' ? '' : $today . ' ' . substr($time, 0, 5);
        }

        return $time === '' ? $date : $date . ' ' . substr($time, 0, 5);
    }

    /** The reminder for a REMIND choice: that many hours before the due moment; null without a due moment or for ''. */
    public static function remindAt(?string $dueAt, string $choice): ?string
    {
        $hours = self::REMIND[$choice] ?? 0;
        if ($dueAt === null || $hours === 0) {
            return null;
        }

        return (new \DateTimeImmutable($dueAt))->modify('-' . $hours . ' hours')->format(Clock::FORMAT);
    }

    /** Which REMIND choice a stored reminder is: '' (none), '1h', '1d' – or 'keep' for one set otherwise (My work, Claude). */
    public static function remindChoice(?string $dueAt, ?string $remindAt): string
    {
        if ($remindAt === null) {
            return '';
        }
        foreach (self::REMIND as $key => $hours) {
            if ($hours > 0 && $dueAt !== null && self::remindAt($dueAt, $key) === $remindAt) {
                return $key;
            }
        }

        return 'keep';
    }

    /**
     * The title of a follow-up on an enquiry: the form's name, never what the customer wrote (a task title goes into
     * notifications, e-mails and My work).
     */
    public static function followUpTitle(string $formName): string
    {
        $formName = trim($formName);

        return mb_substr(t('Follow up: %s', $formName !== '' ? $formName : t('Enquiry')), 0, Tasks::TITLE);
    }

    /** The title of the task for the review date of a page or an item. */
    public static function reviewTitle(string $title): string
    {
        return mb_substr(t('Review: %s', trim($title)), 0, Tasks::TITLE);
    }

    /**
     * Who may get a task about $target from $me: the people who may be assigned to its kind (Core\Assignment), each passing
     * Tasks::mayAssign; for a task about nothing ($target null – My work) every active colleague. $keep (a task's current
     * person) stays in the list even when they no longer pass – saving the edit form does not silently move the task.
     *
     * @return array<int, string> user id => name
     */
    public static function people(App $app, ?string $target, int $me, int $keep = 0): array
    {
        if ($target === null) {
            $out = \Kaleta\Core\Handover::eligible($app->db(), $app->settings(), []);

            return $keep > 0 && !isset($out[$keep]) ? $out + [$keep => Assignment::label($app->db(), $keep)] : $out;
        }
        $kind = explode(':', $target)[0];
        if (!isset(Assignment::KINDS[$kind])) {
            return [];
        }
        $out = [];
        foreach (Assignment::candidates($app->db(), $kind, $keep) as $id => $name) {
            if ($id === $keep || Tasks::mayAssign($app, $me, $id, $target)) {
                $out[$id] = $name;
            }
        }

        return $out;
    }
}
