<?php

declare(strict_types=1);

namespace Kaleta\Admin\Modules;

use Kaleta\Admin\ChangeLog;
use Kaleta\Admin\Module;
use Kaleta\Admin\TaskPanel;
use Kaleta\Core\App;
use Kaleta\Core\Assignment;
use Kaleta\Core\Clock;
use Kaleta\Core\Comments;
use Kaleta\Core\Contacts as Customers;
use Kaleta\Core\Response;
use Kaleta\Core\Tasks;

/**
 * Customers (3.28, Core\Contacts): the contacts and companies of the site – one record per customer across every enquiry,
 * booking and newsletter subscription of the same e-mail address. The CRM is core: no extension, no switch; the section
 * "contacts" is given to whoever has Enquiries (migration 0114). Nothing here ever changes the public site
 * (Kernel::SITE_NEUTRAL_MODULES) and the site never reads contacts.
 *
 *  - list (the Customers tab) and companies (the Companies tab): search, filters, sort, paging; cards on a phone
 *  - detail (a contact) and company: the record, its timeline, the team's log of calls, meetings, e-mails and notes
 *    (activity), the team's comments and tasks (Admin\CommentActions, Admin\TaskActions – targets contact:N and company:N)
 *  - merge: two contacts side by side and what moves – administrators only, a person's decision, never automatic
 *  - link: an enquiry or a booking from before 3.28 linked by hand (the job "contacts" links them by itself)
 *  - export and import (CSV, administrators): an import matches by the normalised e-mail address only – it never merges two
 *    different addresses; the change log keeps the counts, never personal data
 *  - search_json: the command palette (Ctrl K) finds customers by name, e-mail or company
 */
final class Contacts extends Module
{
    use \Kaleta\Admin\CommentActions;
    use \Kaleta\Admin\TaskActions;

    public const string IDENT = Customers::SECTION;
    public const string NAME = 'Customers';
    public const string GROUP = 'Workspace';
    public const string ICON = 'zakaznici';

    /** The last-activity filter of the list: key => [active (true) or quiet (false), days, label]. */
    public const array ACTIVITY = [
        'active7' => [true, 7, 'Active in the last 7 days'],
        'active30' => [true, 30, 'Active in the last 30 days'],
        'active90' => [true, 90, 'Active in the last 90 days'],
        'quiet90' => [false, 90, 'Quiet for 90 days or more'],
        'quiet365' => [false, 365, 'Quiet for a year or more'],
    ];

    /** The sort orders of the list (Core\Contacts::SORTS) => label. */
    public const array SORT_LABELS = ['activity' => 'Last activity', 'name' => 'Full name', 'created' => 'Newest'];

    /** The timeline kinds (Core\Contacts::timeline) => [icon of views/admin/icons.php, label]. */
    public const array KINDS = [
        'enquiry' => ['poptavky', 'Enquiry'], 'reply' => ['rozesilka', 'Reply sent'], 'booking' => ['rezervace', 'Booking'],
        'subscription' => ['newsletter', 'Newsletter subscription'], 'comment' => ['komentare', 'Comment'], 'task' => ['moje', 'Task'],
        'call' => ['telefon', 'Call'], 'meeting' => ['uzivatele', 'Meeting'], 'email' => ['newsletter', 'E-mail'], 'note' => ['zapisnik', 'Note'],
        'deal' => ['obchody', 'Deal'], // 3.31: a deal created, moved, won, lost or reopened (Core\Deals::timeline)
    ];

    /** The columns an import reads: field => the header names it recognises (lower case, letters only, no diacritics). */
    public const array IMPORT_COLUMNS = [
        'email' => ['email', 'mail', 'emailaddress', 'emailadresa', 'emailovaadresa', 'correo', 'correoelectronico', 'emailadresse'],
        'name' => ['name', 'fullname', 'contactname', 'contact', 'jmeno', 'jmenoaprijmeni', 'kontakt', 'nombre', 'nombrecompleto', 'vollername'],
        'phone' => ['phone', 'phonenumber', 'telephone', 'tel', 'mobile', 'telefon', 'telefonnicislo', 'mobil', 'telefono', 'movil', 'handy', 'telefonnummer'],
        'company' => ['company', 'companyname', 'organisation', 'organization', 'firma', 'spolecnost', 'organizace', 'empresa', 'unternehmen'],
    ];

    /** How many rows of an import the preview shows. */
    public const int IMPORT_SAMPLE = 30;

    /** An unconfirmed import file is removed after this many seconds. */
    private const int IMPORT_TTL = 86400;

    /** The contacts of a company its detail lists, and the entries of each in the company's merged timeline. */
    public const int COMPANY_CONTACTS = 100;
    public const int COMPANY_TIMELINE = 60;

    /* ---------- the lists ---------- */

    protected function actionList(): Response
    {
        $me = $this->app->auth()->id();
        $search = mb_substr(trim($this->request->get('search')), 0, 100);
        $ownerKey = $this->request->get('owner');
        $owner = match (true) {
            $ownerKey === 'mine' => $me,
            $ownerKey === 'nobody' => -1,
            ctype_digit($ownerKey) && (int) $ownerKey > 0 => (int) $ownerKey,
            default => 0,
        };
        if ($owner === 0) {
            $ownerKey = '';
        }
        $activity = isset(self::ACTIVITY[$this->request->get('activity')]) ? $this->request->get('activity') : '';
        $sort = isset(Customers::SORTS[$this->request->get('sort')]) ? $this->request->get('sort') : 'activity';
        $company = max(0, $this->request->getInt('company'));
        $filter = ['search' => $search, 'owner' => $owner, 'company' => $company, 'sort' => $sort] + self::activityFilter($activity);
        $result = Customers::list($this->db, $filter, max(1, $this->request->getInt('page', 1)));

        return $this->view('list', 'Customers', ['result' => $result, 'search' => $search, 'ownerKey' => $ownerKey, 'activity' => $activity, 'sort' => $sort,
            'company' => $company, 'companyName' => $company > 0 ? (string) (Customers::findCompany($this->db, $company)['name'] ?? '') : '',
            'companies' => Customers::companies($this->db, [], 1, 200)['rows'], 'people' => Assignment::candidates($this->db, 'contact', $owner > 0 && $owner !== $me ? $owner : 0),
            'owners' => Assignment::labels($this->db, array_values(array_filter(array_column($result['rows'], 'owner_id')))),
            'query' => array_filter(['search' => $search, 'owner' => $ownerKey, 'activity' => $activity, 'sort' => $sort !== 'activity' ? $sort : '', 'company' => $company ?: ''],
                fn (mixed $v): bool => $v !== '')]);
    }

    /**
     * The filter keys of Core\Contacts::list for a last-activity choice.
     *
     * @return array<string, string>
     */
    public static function activityFilter(string $key, ?string $now = null): array
    {
        if (!isset(self::ACTIVITY[$key])) {
            return [];
        }
        [$active, $days] = self::ACTIVITY[$key];
        $at = (new \DateTimeImmutable($now ?? Clock::now()))->modify('-' . $days . ' days')->format(Clock::FORMAT);

        return $active ? ['active_since' => $at] : ['inactive_before' => $at];
    }

    protected function actionCompanies(): Response
    {
        $search = mb_substr(trim($this->request->get('search')), 0, 100);
        $ownerKey = $this->request->get('owner');
        $owner = match ($ownerKey) {
            'mine' => $this->app->auth()->id(),
            'nobody' => -1,
            default => 0,
        };
        $result = Customers::companies($this->db, ['search' => $search, 'owner' => $owner], max(1, $this->request->getInt('page', 1)));

        return $this->view('companies', 'Customers', ['result' => $result, 'search' => $search, 'ownerKey' => $owner !== 0 ? $ownerKey : '',
            'owners' => Assignment::labels($this->db, array_values(array_filter(array_column($result['rows'], 'owner_id'))))]);
    }

    /** The command palette (Ctrl K): customers by name, e-mail or company. */
    protected function actionSearchJson(): Response
    {
        $q = mb_substr(trim($this->request->get('q')), 0, 80);
        if (mb_strlen($q) < 2) {
            return Response::json(['contacts' => []]);
        }
        $rows = Customers::list($this->db, ['search' => $q, 'sort' => 'activity'], 1, 6)['rows'];

        return Response::json(['contacts' => array_map(fn (array $c): array => ['name' => Customers::displayName($c),
            'note' => implode(' · ', array_filter([(string) ($c['company_name'] ?? ''), $c['name'] !== '' ? $c['email'] : ''])), 'url' => $this->url('detail', ['id' => $c['id']])], $rows)]);
    }

    /* ---------- a contact ---------- */

    protected function actionNew(): Response
    {
        $draft = $this->takeDraft('contact_draft', 0);

        return $this->view('new', t('New customer'), ['draft' => $draft + ['company_id' => max(0, $this->request->getInt('company'))],
            'companies' => Customers::companies($this->db, [], 1, 500)['rows'], 'people' => Assignment::candidates($this->db, 'contact')]);
    }

    protected function actionDetail(): Response
    {
        $contact = Customers::find($this->db, $this->request->getInt('id'));
        if ($contact === null) {
            return $this->error('The contact does not exist.', 404);
        }
        $id = $contact['id'];
        $target = Comments::contact($id);
        $byIds = [];
        foreach (Customers::activities($this->db, $id, 1000) as $activity) {
            $byIds[$activity['id']] = $activity['by_id'];
        }

        return $this->view('detail', Customers::displayName($contact), ['c' => $contact,
            'company' => $contact['company_id'] !== null ? Customers::findCompany($this->db, $contact['company_id']) : null,
            'counts' => Customers::linkCounts($this->db, [$id])[$id] ?? ['enquiries' => 0, 'bookings' => 0, 'subscribed' => false],
            'timeline' => $this->dealsShown(Customers::timeline($this->db, $id, Customers::TIMELINE, isset($this->kernel->modules()[\Kaleta\Core\Applications::SECTION]))), 'authors' => $byIds,
            'suggestion' => $contact['company_id'] === null && $contact['anonymised_at'] === null ? Customers::suggestCompany($this->db, $contact['email']) : null,
            'people' => Assignment::candidates($this->db, 'contact', (int) $contact['owner_id']),
            'companies' => Customers::companies($this->db, [], 1, 500)['rows'],
            'draft' => $this->takeDraft('contact_draft', $id), 'activityDraft' => $this->takeDraft('activity_draft', $id),
            'thread' => $this->commentThread($target, ['id' => $id]),
            'tasks' => $this->taskPanel($target, ['id' => $id]),
            'deals' => $contact['anonymised_at'] === null ? Pipeline::deals($this->app, $this->kernel->modules(), 'contact', $id) : null, // 3.31
            'admin' => $this->app->auth()->isAdmin(), 'me' => $this->app->auth()->id()]);
    }

    /**
     * Creates a contact (no id) or changes the fields a form posts (the edit form, the responsible person of the header,
     * the suggested company): only the keys present change. A company may be named instead of chosen (company_new – an
     * existing one by name, otherwise a new one, with company_domain when given).
     */
    protected function actionSave(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        $id = $this->request->postInt('id');
        $me = $this->app->auth()->id();
        $fields = [];
        foreach (['email', 'name', 'phone', 'language', 'summary'] as $key) {
            if ($this->request->hasPost($key)) {
                $fields[$key] = $this->request->post($key);
            }
        }
        foreach (['company_id', 'owner_id'] as $key) {
            if ($this->request->hasPost($key)) {
                $fields[$key] = $this->request->postInt($key);
            }
        }
        try {
            if ($id > 0 && Customers::find($this->db, $id) === null) {
                return $this->error('The contact does not exist.', 404);
            }
            $newCompany = trim($this->request->post('company_new'));
            if ($newCompany !== '') {
                $existing = Customers::companyByName($this->db, $newCompany);
                $fields['company_id'] = $existing !== null ? $existing['id']
                    : Customers::saveCompany($this->app, null, ['name' => $newCompany] + ($this->request->post('company_domain') !== '' ? ['domain' => $this->request->post('company_domain')] : []), $me);
            }
            if ($id > 0) {
                Customers::update($this->app, $id, $fields, $me);
            } else {
                $id = Customers::create($this->app, $fields + ['source' => 'manual'], $me);
            }
        } catch (\DomainException $e) {
            $this->app->session->set('contact_draft', ['id' => $id] + array_map(fn (mixed $v): string => mb_substr((string) $v, 0, Customers::MAX_TEXT), $fields));

            return $this->back($e->getMessage(), $id > 0 ? 'detail' : 'new', $id > 0 ? ['id' => $id] : [], 'chyba');
        }
        // the change log names the contact by its number only – never its name or address
        ChangeLog::write($this->app, self::IDENT, $this->request->postInt('id') > 0 ? 'contact saved' : 'contact created', '#' . $id);

        return $this->back($this->request->postInt('id') > 0 ? 'The contact was saved.' : 'The contact was created.', 'detail', ['id' => $id]);
    }

    /** Logs a call, a meeting, an e-mail or a note (op=add), or deletes an entry (op=delete: its author, or an administrator). */
    protected function actionActivity(): Response
    {
        $id = $this->request->postInt('id');
        if (!$this->request->isPost()) {
            return $this->back('', 'detail', ['id' => $id]);
        }
        if (Customers::find($this->db, $id) === null) {
            return $this->error('The contact does not exist.', 404);
        }
        $user = $this->app->auth()->user() ?? [];
        $back = $this->url('detail', ['id' => $id]) . '#timeline';
        try {
            if ($this->request->post('op') === 'delete') {
                $entry = $this->request->postInt('activity');
                Customers::deleteActivity($this->db, $id, $entry, $user, $this->app->auth()->isAdmin());
                ChangeLog::write($this->app, self::IDENT, 'activity deleted', '#' . $id . ' (activity #' . $entry . ')');
                $this->app->session->flash('ok', t('The entry was deleted.'));

                return Response::redirect($back);
            }
            $at = TaskPanel::due($this->request->post('date'), $this->request->post('time'));
            if ($at !== '' && Tasks::parseMoment($at) === null) {
                throw new \DomainException(t('The date “%s” is not understood.', $this->request->post('date')));
            }
            $entry = Customers::logActivity($this->app, $id, $this->request->post('kind'), $this->request->post('text'), $user, $at);
        } catch (\DomainException $e) {
            $this->app->session->set('activity_draft', ['id' => $id, 'kind' => $this->request->post('kind'), 'text' => mb_substr($this->request->post('text'), 0, Customers::MAX_TEXT),
                'date' => mb_substr($this->request->post('date'), 0, 10), 'time' => mb_substr($this->request->post('time'), 0, 5)]);
            $this->app->session->flash('chyba', $e->getMessage());

            return Response::redirect($back);
        }
        ChangeLog::write($this->app, self::IDENT, 'activity logged', '#' . $id . ' (' . $this->request->post('kind') . ' #' . $entry . ')');
        $this->app->session->flash('ok', t('Logged.'));

        return Response::redirect($back);
    }

    /** Deletes a contact for good – administrators only; its enquiries, bookings and subscription stay, unlinked. */
    protected function actionDelete(): Response
    {
        if (!$this->app->auth()->isAdmin()) {
            return $this->error('Only an administrator can delete a customer.', 403);
        }
        $id = $this->request->postInt('id');
        if ($this->request->isPost() && Customers::delete($this->app, $id)) {
            ChangeLog::write($this->app, self::IDENT, 'delete', '#' . $id);

            return $this->back('The contact was deleted. Its enquiries, bookings and subscription stay.');
        }

        return $this->back();
    }

    /**
     * Two contacts side by side and what moves (GET), then the merge on confirmation (POST) – administrators only, never
     * automatic: the one kept is "id", the one merged into it and deleted is "with" (Core\Contacts::merge).
     */
    protected function actionMerge(): Response
    {
        if (!$this->app->auth()->isAdmin()) {
            return $this->error('Only an administrator can merge customers.', 403);
        }
        if ($this->request->isPost()) {
            $into = $this->request->postInt('id');
            $from = $this->request->postInt('with');
            if (!$this->request->postBool('confirmed')) {
                return $this->back('Tick that you want to merge the two contacts.', 'merge', ['id' => $into, 'with' => $from], 'chyba');
            }
            try {
                $result = Customers::merge($this->app, $into, $from, $this->app->auth()->id());
            } catch (\DomainException $e) {
                return $this->back($e->getMessage(), 'merge', ['id' => $into], 'chyba');
            }
            $moved = $result['moved'];

            return $this->back(t('Merged. Moved here: %d enquiries, %d bookings, %d subscriptions, %d log entries, %d comments, %d tasks.', $moved['enquiries'], $moved['bookings'],
                $moved['subscriptions'], $moved['activities'], $moved['comments'], $moved['tasks']), 'detail', ['id' => $into]);
        }
        $keep = Customers::find($this->db, $this->request->getInt('id'));
        if ($keep === null) {
            return $this->error('The contact does not exist.', 404);
        }
        $other = $this->request->getInt('with') > 0 && $this->request->getInt('with') !== $keep['id'] ? Customers::find($this->db, $this->request->getInt('with')) : null;
        $search = mb_substr(trim($this->request->get('search')), 0, 100);
        $found = [];
        if ($other === null && $search !== '') {
            $found = array_values(array_filter(Customers::list($this->db, ['search' => $search], 1, 20)['rows'], fn (array $c): bool => $c['id'] !== $keep['id']));
        }

        return $this->view('merge', t('Merge contacts'), ['keep' => $keep, 'other' => $other, 'search' => $search, 'found' => $found,
            'sides' => $other !== null ? [$keep['id'] => $this->mergeSide($keep), $other['id'] => $this->mergeSide($other)] : []]);
    }

    /**
     * What a contact brings to a merge: its links, log entries, comments and tasks, its company and responsible person.
     *
     * @param array<string, mixed> $c
     * @return array<string, mixed>
     */
    private function mergeSide(array $c): array
    {
        $target = Comments::contact($c['id']);

        return (Customers::linkCounts($this->db, [$c['id']])[$c['id']] ?? ['enquiries' => 0, 'bookings' => 0, 'subscribed' => false]) + [
            'activities' => count(Customers::activities($this->db, $c['id'], 1000)),
            'comments' => count(Comments::of($this->db, $target)),
            'tasks' => count(Tasks::forTarget($this->db, $target)),
            'owner' => $c['owner_id'] !== null ? Assignment::label($this->db, $c['owner_id']) : '',
        ];
    }

    /**
     * Links an enquiry or a booking to the contact of its address (Core\Contacts::link) – for a row from before 3.28 the job
     * "contacts" has not reached yet. Who may open the record's section only; back to the record.
     */
    protected function actionLink(): Response
    {
        $kind = $this->request->post('kind');
        $id = $this->request->postInt('id');
        $section = ['enquiry' => 'enquiries', 'booking' => 'bookings'][$kind] ?? null;
        if (!$this->request->isPost() || $section === null) {
            return $this->back();
        }
        if (!isset($this->kernel->modules()[$section])) {
            return $this->error('You do not have access to this module.', 403);
        }
        $back = $this->app->url('admin.php?module=' . $section . '&action=detail&id=' . $id);
        try {
            $contactId = Customers::link($this->app, $kind, $id);
        } catch (\PDOException $e) {
            \Kaleta\Core\Db::rethrowUnlessMissing($e);
            $contactId = null;
        }
        if ($contactId === null) {
            $this->app->session->flash('chyba', t('It cannot be linked – it has no valid e-mail address, or it was anonymised.'));
        } else {
            ChangeLog::write($this->app, self::IDENT, 'link', $kind . ' #' . $id . ' → #' . $contactId);
            $this->app->session->flash('ok', t('Linked to the contact.'));
        }

        return Response::redirect($back . '#contact');
    }

    /* ---------- companies ---------- */

    protected function actionCompany(): Response
    {
        $company = Customers::findCompany($this->db, $this->request->getInt('id'));
        if ($company === null) {
            return $this->error('The company does not exist.', 404);
        }
        $id = $company['id'];
        $contacts = Customers::list($this->db, ['company' => $id, 'sort' => 'name'], 1, self::COMPANY_CONTACTS);
        // the timeline of its contacts, merged and bounded: the latest entries of each, newest first
        $timeline = [];
        foreach (array_slice($contacts['rows'], 0, 30) as $c) {
            foreach (Customers::timeline($this->db, $c['id'], 20, isset($this->kernel->modules()[\Kaleta\Core\Applications::SECTION])) as $entry) {
                $timeline[] = $entry + ['contact' => Customers::displayName($c), 'contact_id' => $c['id']];
            }
        }
        // 3.31: the moves of the company's deals its contacts' timelines do not carry (a deal without a contact, or of a
        // contact beyond the first 30) – each move once
        $seen = array_flip(array_map(fn (array $e): string => $e['ref'] . '|' . $e['at'] . '|' . ($e['status'] ?? ''), $timeline));
        foreach (\Kaleta\Core\Deals::timeline($this->db, 'company', $id, self::COMPANY_TIMELINE) as $entry) {
            if (!isset($seen[$entry['ref'] . '|' . $entry['at'] . '|' . $entry['status']])) {
                $timeline[] = $entry;
            }
        }
        usort($timeline, fn (array $x, array $y): int => [$y['at'], $y['ref']] <=> [$x['at'], $x['ref']]);
        $target = Comments::company($id);

        return $this->view('company', $company['name'], ['co' => $company, 'contacts' => $contacts, 'timeline' => $this->dealsShown(array_slice($timeline, 0, self::COMPANY_TIMELINE)),
            'people' => Assignment::candidates($this->db, 'company', (int) $company['owner_id']),
            'owners' => Assignment::labels($this->db, array_values(array_filter([$company['owner_id'], ...array_column($contacts['rows'], 'owner_id')]))),
            'draft' => $this->takeDraft('company_draft', $id),
            'thread' => $this->commentThread($target, ['company' => $id]),
            'tasks' => $this->taskPanel($target, ['company' => $id]),
            'deals' => Pipeline::deals($this->app, $this->kernel->modules(), 'company', $id), // 3.31
            'admin' => $this->app->auth()->isAdmin()]);
    }

    /**
     * The timeline as this user may see it (3.31.1): without the Pipeline a deal's entry is only "a deal" – no title,
     * stage, status or link (Deals::neutralTimeline).
     *
     * @param list<array<string, mixed>> $timeline
     * @return list<array<string, mixed>>
     */
    private function dealsShown(array $timeline): array
    {
        return isset($this->kernel->modules()[Pipeline::IDENT]) ? $timeline : \Kaleta\Core\Deals::neutralTimeline($timeline);
    }

    protected function actionCompanyNew(): Response
    {
        return $this->view('company_new', t('New company'), ['draft' => $this->takeDraft('company_draft', 0), 'people' => Assignment::candidates($this->db, 'company')]);
    }

    /** Creates a company (no "company" field) or changes the fields its form posts. */
    protected function actionCompanySave(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back('', 'companies');
        }
        $id = $this->request->postInt('company');
        $fields = [];
        foreach (['name', 'domain', 'registration_no', 'vat_id', 'note'] as $key) {
            if ($this->request->hasPost($key)) {
                $fields[$key] = $this->request->post($key);
            }
        }
        if ($this->request->hasPost('owner_id')) {
            $fields['owner_id'] = $this->request->postInt('owner_id');
        }
        try {
            $saved = Customers::saveCompany($this->app, $id > 0 ? $id : null, $fields, $this->app->auth()->id());
        } catch (\DomainException $e) {
            $this->app->session->set('company_draft', ['id' => $id] + array_map(fn (mixed $v): string => mb_substr((string) $v, 0, Customers::MAX_TEXT), $fields));

            return $this->back($e->getMessage(), $id > 0 ? 'company' : 'company_new', $id > 0 ? ['id' => $id] : [], 'chyba');
        }
        ChangeLog::write($this->app, self::IDENT, $id > 0 ? 'company saved' : 'company created', mb_substr('#' . $saved . ' ' . ($fields['name'] ?? ''), 0, 255));

        return $this->back($id > 0 ? 'The company was saved.' : 'The company was created.', 'company', ['id' => $saved]);
    }

    /** Deletes a company – administrators only; its contacts stay without a company. */
    protected function actionCompanyDelete(): Response
    {
        if (!$this->app->auth()->isAdmin()) {
            return $this->error('Only an administrator can delete a company.', 403);
        }
        $id = $this->request->postInt('company');
        if ($this->request->isPost() && Customers::deleteCompany($this->app, $id)) {
            ChangeLog::write($this->app, self::IDENT, 'company deleted', '#' . $id);

            return $this->back('The company was deleted. Its contacts stay, without a company.', 'companies');
        }

        return $this->back('', 'companies');
    }

    /* ---------- export and import (administrators) ---------- */

    /** The contacts to CSV (UTF-8 with BOM, semicolons – opens directly in Excel): the company and the responsible person too. */
    protected function actionExport(): Response
    {
        if (!$this->app->auth()->isAdmin()) {
            return $this->error('Only an administrator can export or import customers.', 403);
        }
        $f = fopen('php://temp', 'w+');
        fwrite($f, "\xEF\xBB\xBF");
        fputcsv($f, [t('Number'), t('Full name'), t('E-mail'), t('Phone'), t('Company'), t('Responsible person'), t('Language'), t('Source'), t('First contact'),
            t('Last activity'), t('Enquiries'), t('Bookings'), t('Subscribed')], ';', '"', '');
        $last = 0;
        do {
            $rows = $this->db->all('SELECT c.*, co.name AS company_name FROM {contacts} c LEFT JOIN {companies} co ON co.id = c.company_id WHERE c.anonymised_at IS NULL AND c.id > ? ORDER BY c.id LIMIT 500', [$last]);
            $counts = Customers::linkCounts($this->db, array_map('intval', array_column($rows, 'id')));
            $owners = Assignment::labels($this->db, array_values(array_filter(array_map(fn (array $r): int => (int) $r['owner_id'], $rows))));
            foreach ($rows as $r) {
                $last = (int) $r['id'];
                $n = $counts[$last] ?? ['enquiries' => 0, 'bookings' => 0, 'subscribed' => false];
                fputcsv($f, self::csvSafe([(string) $last, (string) $r['name'], (string) $r['email'], (string) $r['phone'], (string) ($r['company_name'] ?? ''),
                    $r['owner_id'] !== null ? ($owners[(int) $r['owner_id']] ?? '') : '', (string) $r['language'], (string) $r['source'], (string) ($r['first_seen_at'] ?? ''),
                    (string) ($r['last_activity_at'] ?? ''), (string) $n['enquiries'], (string) $n['bookings'], $n['subscribed'] ? t('yes') : '']), ';', '"', '');
            }
        } while (count($rows) === 500);
        rewind($f);
        $csv = (string) stream_get_contents($f);
        fclose($f);
        ChangeLog::write($this->app, self::IDENT, 'export CSV', '');

        return new Response($csv, 200, ['Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => 'attachment; filename="customers-' . date('Y-m-d') . '.csv"',
            'Cache-Control' => 'no-store, private']);
    }

    /**
     * Cells of a CSV row: a cell starting with = + - @ (or a tab or a carriage return) would run as a formula in a
     * spreadsheet – it gets an apostrophe. Pure.
     *
     * @param list<string> $cells
     * @return list<string>
     */
    public static function csvSafe(array $cells): array
    {
        return array_map(fn (string $v): string => preg_match('/^[=+\-@\t\r]/', $v) === 1 ? "'" . $v : $v, $cells);
    }

    /** The import: the upload form, or the preview of an uploaded file (?file=) with what saving would do. */
    protected function actionImport(): Response
    {
        if (!$this->app->auth()->isAdmin()) {
            return $this->error('Only an administrator can export or import customers.', 403);
        }
        self::sweepImports();
        $token = $this->request->get('file');
        $stored = $token !== '' ? $this->loadImport($token) : null;
        if ($token !== '' && $stored === null) {
            return $this->back('The import does not exist any more.', 'import', [], 'chyba');
        }
        $plan = $stored !== null ? self::plan($this->db, $stored['rows']) : null;

        return $this->view('import', t('Import customers'), ['token' => $stored !== null ? $token : '', 'file' => $stored['name'] ?? '', 'plan' => $plan]);
    }

    /** Reads an uploaded or pasted CSV and keeps its rows (storage/import, for a day) for the preview. Nothing is saved yet. */
    protected function actionImportUpload(): Response
    {
        if (!$this->app->auth()->isAdmin()) {
            return $this->error('Only an administrator can export or import customers.', 403);
        }
        if (!$this->request->isPost()) {
            return $this->back('', 'import');
        }
        $file = $this->request->file('file');
        $tmp = is_string($file['tmp_name'] ?? null) ? $file['tmp_name'] : '';
        $text = $this->request->post('text');
        $name = 'pasted.csv';
        if ($tmp !== '' && ($file['error'] ?? null) === UPLOAD_ERR_OK && is_uploaded_file($tmp)) {
            if (filesize($tmp) > \Kaleta\Builder\ItemImport::MAX_BYTES) {
                return $this->back(t('The file is larger than %d MB.', \Kaleta\Builder\ItemImport::MAX_BYTES >> 20), 'import', [], 'chyba');
            }
            $text = (string) file_get_contents($tmp);
            $name = mb_substr(basename((string) ($file['name'] ?? 'customers.csv')), 0, 100);
        }
        $parsed = \Kaleta\Builder\ItemImport::parse(Redirects::toUtf8($text), str_ends_with(strtolower($name), '.json') ? 'customers.csv' : $name); // CSV only
        if (is_string($parsed)) {
            return $this->back(t($parsed), 'import', [], 'chyba');
        }
        $columns = self::importColumns($parsed[0]);
        if (!isset($columns['email'])) {
            return $this->back('The file has no e-mail column – the first row must name the columns: e-mail, name, phone, company.', 'import', [], 'chyba');
        }
        $rows = array_map(fn (array $cells): array => self::importRow($cells, $columns), $parsed[1]);
        $token = bin2hex(random_bytes(12));
        $dir = KALETA_ROOT . '/storage/import';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        file_put_contents($dir . '/contacts-' . $token . '.json', (string) json_encode(['user' => $this->app->auth()->id(), 'name' => $name, 'rows' => $rows], JSON_UNESCAPED_UNICODE));

        return $this->back('', 'import', ['file' => $token]);
    }

    /** Saves a previewed import (op=run) or drops it (op=cancel); the file goes either way. */
    protected function actionImportRun(): Response
    {
        if (!$this->app->auth()->isAdmin()) {
            return $this->error('Only an administrator can export or import customers.', 403);
        }
        $token = $this->request->post('file');
        $stored = $this->request->isPost() ? $this->loadImport($token) : null;
        if ($stored === null) {
            return $this->back('The import does not exist any more.', 'import', [], 'chyba');
        }
        @unlink(self::importPath($token));
        if ($this->request->post('op') !== 'run') {
            return $this->back('The import was cancelled – nothing was saved.', 'import');
        }
        @set_time_limit(300);
        $done = self::apply($this->app, $stored['rows'], $this->app->auth()->id());
        // the change log keeps the counts – never an address or a name from the file
        ChangeLog::write($this->app, self::IDENT, 'import', t('new: %d, updated: %d, skipped: %d', $done['new'], $done['update'], $done['skip']));

        return $this->back(t('Imported: %d new, %d updated, %d skipped.', $done['new'], $done['update'], $done['skip']));
    }

    /**
     * Which column of the file is which field (IMPORT_COLUMNS): field => the index of the first header that names it. Pure.
     *
     * @param list<string> $header
     * @return array<string, int>
     */
    public static function importColumns(array $header): array
    {
        $out = [];
        foreach ($header as $i => $title) {
            $key = (string) preg_replace('/[^a-z]/', '', strtolower(remove_diacritics(trim($title))));
            foreach (self::IMPORT_COLUMNS as $field => $names) {
                if (!isset($out[$field]) && in_array($key, $names, true)) {
                    $out[$field] = (int) $i;
                }
            }
        }

        return $out;
    }

    /**
     * One row of the file as the import's fields (trimmed, single line). The apostrophe our export puts before a cell starting
     * with = + - @ (csvSafe) is taken off again, so an exported file imports as it was – "'+420 …" is a phone (N328-4). Pure.
     *
     * @param list<string> $cells
     * @param array<string, int> $columns
     * @return array{email: string, name: string, phone: string, company: string}
     */
    public static function importRow(array $cells, array $columns): array
    {
        $cell = fn (string $field, int $max): string => isset($columns[$field])
            ? mb_substr((string) preg_replace("/^'(?=[=+\-@])/", '', trim((string) preg_replace('/\s+/u', ' ', (string) ($cells[$columns[$field]] ?? '')))), 0, $max) : '';

        return ['email' => $cell('email', 190), 'name' => $cell('name', 150), 'phone' => $cell('phone', 40), 'company' => $cell('company', 150)];
    }

    /**
     * What an import would do with each row, without saving anything: new (an address with no contact), update (a contact
     * of that address gets a name, phone or company it does not have yet, or a different one), skip (no valid address, the
     * same address again in the file, a phone that is not a phone number, nothing new). A row is matched by its normalised
     * address only – never by a name or a phone, so two different addresses are never merged. The company of a new contact
     * is the one named in the file (found by name or created), else an existing company of its e-mail domain – never for a
     * free-mail domain (Core\Contacts::companyDomain).
     *
     * @param list<array{email: string, name: string, phone: string, company: string}> $rows
     * @return array{rows: list<array<string, mixed>>, new: int, update: int, skip: int}
     */
    public static function plan(\Kaleta\Core\Db $db, array $rows): array
    {
        $out = ['rows' => [], 'new' => 0, 'update' => 0, 'skip' => 0];
        $seen = [];
        foreach ($rows as $row) {
            $norm = Customers::normalise($row['email']);
            $entry = $row + ['norm' => $norm, 'action' => 'skip', 'reason' => '', 'contact' => null, 'changes' => []];
            if ($norm === null) {
                $entry['reason'] = $row['email'] === '' ? 'no e-mail address' : 'not an e-mail address';
            } elseif (isset($seen[$norm])) {
                $entry['reason'] = 'the same address is higher in the file';
            } elseif ($row['phone'] !== '' && preg_match('/^[+()\d\s\/.-]{3,40}$/', $row['phone']) !== 1) {
                $entry['reason'] = 'the phone is not a phone number';
            } else {
                $seen[$norm] = true;
                $contact = Customers::findByEmail($db, $norm);
                if ($contact === null) {
                    $entry['action'] = 'new';
                } elseif ($contact['anonymised_at'] !== null) {
                    $entry['reason'] = 'the contact was anonymised';
                } else {
                    $entry['contact'] = $contact['id'];
                    foreach (['name' => 'name', 'phone' => 'phone'] as $field => $column) {
                        if ($row[$field] !== '' && $row[$field] !== $contact[$column]) {
                            $entry['changes'][] = $field;
                        }
                    }
                    if ($row['company'] !== '' && mb_strtolower($row['company']) !== mb_strtolower((string) ($contact['company_name'] ?? ''))) {
                        $entry['changes'][] = 'company';
                    }
                    $entry['action'] = $entry['changes'] !== [] ? 'update' : 'skip';
                    $entry['reason'] = $entry['changes'] === [] ? 'nothing new' : '';
                }
            }
            $out[$entry['action']]++;
            $out['rows'][] = $entry;
        }

        return $out;
    }

    /**
     * Saves an import as plan() describes it (source "import"); a row the save refuses counts as skipped. Returns the counts.
     *
     * @param list<array{email: string, name: string, phone: string, company: string}> $rows
     * @return array{new: int, update: int, skip: int}
     */
    public static function apply(App $app, array $rows, int $byUserId): array
    {
        $db = $app->db();
        $plan = self::plan($db, $rows);
        $done = ['new' => 0, 'update' => 0, 'skip' => $plan['skip']];
        $companies = [];
        foreach ($plan['rows'] as $row) {
            if ($row['action'] === 'skip') {
                continue;
            }
            try {
                $companyId = null;
                if ($row['company'] !== '' && ($row['action'] === 'new' || in_array('company', $row['changes'], true))) {
                    $key = mb_strtolower($row['company']);
                    $companyId = $companies[$key] ??= (Customers::companyByName($db, $row['company'])['id']
                        ?? Customers::saveCompany($app, null, ['name' => $row['company'], 'domain' => Customers::companyDomain($row['email'])], $byUserId));
                } elseif ($row['action'] === 'new') {
                    $companyId = Customers::companyByDomain($db, Customers::companyDomain($row['email']))['id'] ?? null; // never for free-mail
                }
                if ($row['action'] === 'new') {
                    Customers::create($app, ['email' => $row['email'], 'name' => $row['name'], 'phone' => $row['phone'], 'company_id' => $companyId ?? 0, 'source' => 'import'], $byUserId);
                    $done['new']++;
                } else {
                    $fields = array_intersect_key(['name' => $row['name'], 'phone' => $row['phone']], array_flip($row['changes']));
                    if ($companyId !== null) {
                        $fields['company_id'] = $companyId;
                    }
                    Customers::update($app, (int) $row['contact'], $fields, $byUserId);
                    $done['update']++;
                }
            } catch (\DomainException) {
                $done['skip']++;
            }
        }

        return $done;
    }

    /** @return array{user: int, name: string, rows: list<array{email: string, name: string, phone: string, company: string}>}|null the import of this user */
    private function loadImport(string $token): ?array
    {
        $path = self::importPath($token);
        $data = $path !== '' && is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        return is_array($data) && (int) ($data['user'] ?? 0) === $this->app->auth()->id() && is_array($data['rows'] ?? null)
            ? ['user' => (int) $data['user'], 'name' => (string) ($data['name'] ?? ''), 'rows' => array_values(array_filter($data['rows'], 'is_array'))] : null;
    }

    /** The file of an import token ('' for anything but 24 hex digits). */
    private static function importPath(string $token): string
    {
        return preg_match('/^[a-f0-9]{24}$/D', $token) === 1 ? KALETA_ROOT . '/storage/import/contacts-' . $token . '.json' : '';
    }

    /**
     * Removes import files nobody confirmed within a day (they hold personal data): when Import is opened, and by the daily
     * clean-up of personal data (Notifications::purgePersonalData – N328-5). Returns how many went.
     */
    public static function sweepImports(?int $now = null): int
    {
        $removed = 0;
        foreach (glob(KALETA_ROOT . '/storage/import/contacts-*.json') ?: [] as $file) {
            if ((int) @filemtime($file) < ($now ?? time()) - self::IMPORT_TTL && @unlink($file)) {
                $removed++;
            }
        }

        return $removed;
    }

    /* ---------- the box on an enquiry and a booking ---------- */

    /**
     * What views/admin/contact_box.php shows on the detail of an enquiry or a booking: the linked contact with its company,
     * responsible person and counts, or the "Link to a contact" button for a row from before 3.28. Null when the person may
     * not open Customers.
     *
     * @param array<string, class-string<Module>> $modules the modules the signed-in person may open (Kernel::modules())
     * @return array<string, mixed>|null
     */
    public static function box(App $app, array $modules, string $kind, int $id, ?int $contactId, bool $canLink): ?array
    {
        if (!isset($modules[self::IDENT])) {
            return null;
        }
        $db = $app->db();
        $contact = $contactId !== null && $contactId > 0 ? Customers::find($db, $contactId) : null;

        return ['kind' => $kind, 'id' => $id, 'contact' => $contact, 'canLink' => $canLink,
            'counts' => $contact !== null ? (Customers::linkCounts($db, [$contact['id']])[$contact['id']] ?? null) : null,
            'owner' => $contact !== null && $contact['owner_id'] !== null ? Assignment::label($db, $contact['owner_id']) : '',
            'url' => $contact !== null ? $app->url(Customers::adminPath($contact['id'])) : '', 'linkUrl' => $app->url('admin.php?module=' . self::IDENT . '&action=link')];
    }

    /* ---------- comments and tasks (Admin\CommentActions, Admin\TaskActions) ---------- */

    /**
     * The contact or the company a comment or task form is about: "company" names a company, "id" a contact. A contact's
     * change-log rows carry its number only, never its name.
     */
    protected function commentTarget(): ?array
    {
        $companyId = $this->request->postInt('company');
        if ($companyId > 0) {
            $company = Customers::findCompany($this->db, $companyId);

            return $company === null ? null : ['target' => Comments::company($companyId), 'back' => ['company', ['id' => $companyId]], 'title' => $company['name']];
        }
        $contact = Customers::find($this->db, $this->request->postInt('id'));

        return $contact === null || $contact['anonymised_at'] !== null ? null : ['target' => Comments::contact($contact['id']), 'back' => ['detail', ['id' => $contact['id']]], 'title' => 'contact'];
    }

    protected function taskTarget(): ?array
    {
        $record = $this->commentTarget();

        return $record === null ? null : $record + ['review' => null];
    }

    /**
     * A refused form's fields, once, for the same record (0 = a new one).
     *
     * @return array<string, string>
     */
    private function takeDraft(string $key, int $id): array
    {
        $draft = $this->app->session->get($key);
        if (!is_array($draft)) {
            return [];
        }
        $this->app->session->remove($key);
        if ((int) ($draft['id'] ?? -1) !== $id) {
            return [];
        }
        unset($draft['id']);

        return array_map('strval', $draft);
    }
}
