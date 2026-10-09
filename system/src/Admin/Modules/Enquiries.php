<?php

declare(strict_types=1);

namespace Kaleta\Admin\Modules;

use Kaleta\Admin\Module;
use Kaleta\Core\Response;

/**
 * Enquiries and messages from the site's forms (the Form element in the builder, Front\Forms). Status: 0 new, 1 read, 2 handled.
 * They contain personal data – they delete themselves after the set number of months and can be exported to CSV.
 */
final class Enquiries extends Module
{
    public const string IDENT = 'enquiries';
    public const string EXTENSION = 'poptavky';
    public const string NAME = 'Enquiries';
    public const string GROUP = 'Customers';
    public const string ICON = 'poptavky';

    public const array STATUSES = [0 => 'nová', 1 => 'přečtená', 2 => 'vyřízená'];
    private const int PER_PAGE = 50;

    protected function actionList(): Response
    {
        \Kaleta\Core\Jobs::purgeApplications($this->app); // applications to job openings have their own, usually shorter, retention (2.11)
        self::deleteExpired($this->db, $this->app->settings());
        $filter = $this->request->get('status');
        $conditions = match ($filter) {
            'otevrene' => ['stav < 2'],
            'vyrizene' => ['stav = 2'],
            'moje' => ['prirazeno = ' . (int) $this->app->auth()->id()],
            default => [],
        };
        $params = [];
        // the kind from triage (2.12): spam stays out of the list unless asked for; '-' = not sorted yet
        $kind = $this->request->get('category');
        if ($kind === '-') {
            $conditions[] = "kategorie = ''";
        } elseif (isset(\Kaleta\Core\Triage::CATEGORIES[$kind])) {
            $conditions[] = 'kategorie = ?';
            $params[] = $kind;
        } else {
            $kind = '';
            $conditions[] = "kategorie <> 'spam'";
        }
        $search = mb_substr(trim($this->request->get('search')), 0, 100);
        if ($search !== '') {
            $conditions[] = '(email LIKE ? OR formular LIKE ? OR data LIKE ? OR poznamka LIKE ?)';
            // the data is JSON with \uXXXX instead of diacritics – the search also looks in that form
            $pattern = '%' . addcslashes($search, '%_\\') . '%';
            $jsonPattern = '%' . addcslashes(substr((string) json_encode($search), 1, -1), '%_\\') . '%';
            array_push($params, $pattern, $pattern, $jsonPattern, $pattern);
        }
        $whereParts = 'WHERE ' . implode(' AND ', $conditions); // never empty: spam is left out unless asked for
        $pageNumber = max(1, $this->request->getInt('page', 1));

        return $this->view('list', 'Enquiries', [
            'enquiries' => $this->db->all('SELECT idp, datum, formular, stranka, tema, email, stav, kategorie, priorita, data, prirazeno FROM {poptavky} ' . $whereParts . ' ORDER BY idp DESC LIMIT ' . self::PER_PAGE . ' OFFSET ' . (($pageNumber - 1) * self::PER_PAGE), $params),
            'total' => (int) $this->db->value('SELECT COUNT(*) FROM {poptavky} ' . $whereParts, $params),
            'filter' => $filter, 'kind' => $kind, 'spam' => (int) $this->db->value("SELECT COUNT(*) FROM {poptavky} WHERE kategorie = 'spam'"), 'search' => $search, 'pageNumber' => $pageNumber, 'perPage' => self::PER_PAGE,
            'users' => $this->db->pairs("SELECT idu, IF(jmeno = '', user, jmeno) FROM {uzivatele} WHERE blokovat = 0 ORDER BY 2"),
            'months' => $this->app->settings()->int('enquiries_months'),
            'expiry' => $this->app->settings()->get('enquiries_expiry') === 'anonymise' ? 'anonymise' : 'delete',
            'applicationMonths' => $this->app->settings()->int('job_applications_months'),
            'suggestion' => \Kaleta\Core\Jobs::suggestedRetention($this->app->settings()->get('company_country'), $this->app->settings()->get('site_language')),
        ]);
    }

    protected function actionDetail(): Response
    {
        $p = $this->db->one('SELECT * FROM {poptavky} WHERE idp = ?', [$this->request->getInt('id')]);
        if ($p === null) {
            return $this->error('The enquiry does not exist.', 404);
        }
        if ((int) $p['stav'] === 0) {
            $this->db->update('poptavky', ['stav' => 1], ['idp' => $p['idp']]);
            $p['stav'] = 1;
        }

        return $this->view('detail', t('Enquiry') . ' #' . $p['idp'], ['p' => $p, 'data' => json_decode((string) $p['data'], true) ?: [],
            'testimonials' => \Kaleta\Core\Testimonials::ofEnquiry($this->db, (int) $p['idp']),
            'users' => $this->listAssignees((int) $p['prirazeno'])]);
    }

    /**
     * Who can handle an enquiry: active administrators and users with the permission for Enquiries (not e.g. a news author,
     * who does not see them). An already assigned user stays in the list even if they lost the permission in the meantime –
     * saving the note does not silently remove them.
     *
     * @return array<int, string>
     */
    private function listAssignees(int $assignee = 0): array
    {
        return $this->db->pairs(
            "SELECT idu, IF(jmeno = '', user, jmeno) FROM {uzivatele} u WHERE (blokovat = 0 AND (admin = ? OR EXISTS (SELECT 1 FROM {uzivatele_prava} p WHERE p.fk_id_user = u.idu AND p.ident_modulu = ?))) OR idu = ? ORDER BY 2",
            [\Kaleta\Core\Auth::ADMIN, self::IDENT, $assignee],
        );
    }

    /** Internal note and who handles the enquiry. */
    /** Asks the customer of an enquiry for a testimonial (2.12, Core\Testimonials): the link by e-mail, or only to copy. */
    protected function actionTestimonial(): Response
    {
        $id = $this->request->postInt('id');
        if (!$this->request->isPost()) {
            return $this->back();
        }
        try {
            $result = \Kaleta\Core\Testimonials::request($this->app, $id, $this->request->postBool('poslat'));
        } catch (\DomainException $e) {
            return $this->back($e->getMessage(), 'detail', ['id' => $id], 'chyba');
        }

        return $this->back(t($result['sent'] ? 'The request was sent. The link: %s' : 'The link to send yourself: %s', $result['link']), 'detail', ['id' => $id]);
    }

    /** A person's triage (2.12): the kind, the priority and the drafted reply – Claude and the assistant never overwrite it. */
    protected function actionTriage(): Response
    {
        $id = $this->request->postInt('id');
        if ($this->request->isPost() && $this->db->value('SELECT 1 FROM {poptavky} WHERE idp = ?', [$id]) !== null) {
            $user = $this->app->auth()->user();
            \Kaleta\Core\Triage::save($this->db, $id, \Kaleta\Core\Triage::clean($this->request->post('kategorie'), $this->request->postInt('priorita'), $this->request->post('navrh_odpovedi')),
                (string) ($user['jmeno'] ?? '') !== '' ? (string) $user['jmeno'] : (string) ($user['user'] ?? 'admin'));
        }

        return $this->back('Saved.', 'detail', ['id' => $id]);
    }

    protected function actionNote(): Response
    {
        $idp = $this->request->postInt('idp');
        if ($this->request->isPost()) {
            $who = $this->request->postInt('prirazeno');
            $this->db->update('poptavky', ['poznamka' => mb_substr(trim($this->request->post('poznamka')), 0, 5000),
                'prirazeno' => $who > 0 && isset($this->listAssignees((int) $this->db->value('SELECT prirazeno FROM {poptavky} WHERE idp = ?', [$idp]))[$who]) ? $who : null], ['idp' => $idp]);
        }

        return $this->back('The note has been saved.', 'detail', ['id' => $idp]);
    }

    /** Form attachment for download (only for a signed-in user with access to enquiries). */
    protected function actionAttachment(): Response
    {
        $p = $this->db->one('SELECT data FROM {poptavky} WHERE idp = ?', [$this->request->getInt('id')]);
        $item = ($p !== null ? (json_decode((string) $p['data'], true) ?: []) : [])[$this->request->getInt('field')] ?? null;
        $path = is_array($item) && preg_match('#^\d{4}/\d{2}/[a-f0-9]{24}\.[a-z0-9]{2,5}$#D', (string) ($item[2] ?? '')) ? KALETA_ROOT . '/storage/prilohy/' . $item[2] : null;
        if ($path === null || !is_file($path)) {
            return $this->error('The attachment no longer exists.', 404);
        }
        $displayName = preg_replace('/ \([^)]*\)$/', '', (string) $item[1]) ?: basename($path);

        return new Response((string) file_get_contents($path), 200, ['Content-Type' => 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => "attachment; filename*=UTF-8''" . rawurlencode($displayName)]);
    }

    /** In bulk: mark as handled, or delete (including attachments). */
    protected function actionBulk(): Response
    {
        $ids = array_map('intval', $this->request->postList('oznacene'));
        if (!$this->request->isPost() || $ids === []) {
            return $this->back();
        }
        $v = implode(',', $ids);
        if ($this->request->post('provest') === 'smazat') {
            self::deleteAttachments($this->db->all('SELECT data FROM {poptavky} WHERE idp IN (' . $v . ')'));
            $this->db->run('DELETE FROM {poptavky} WHERE idp IN (' . $v . ')');

            return $this->back(t('Enquiries deleted: %d.', count($ids)));
        }
        $this->db->run('UPDATE {poptavky} SET stav = 2 WHERE idp IN (' . $v . ')');

        return $this->back(t('Enquiries resolved: %d.', count($ids)));
    }

    /** @param list<array{data: string}> $rows */
    public static function deleteAttachments(array $rows): void
    {
        foreach ($rows as $r) {
            foreach (json_decode((string) $r['data'], true) ?: [] as $item) {
                if (is_array($item) && preg_match('#^\d{4}/\d{2}/[a-f0-9]{24}\.[a-z0-9]{2,5}$#D', (string) ($item[2] ?? ''))) {
                    @unlink(KALETA_ROOT . '/storage/prilohy/' . $item[2]);
                }
            }
        }
    }

    protected function actionStatus(): Response
    {
        if ($this->request->isPost()) {
            $state = $this->request->postInt('stav');
            $this->db->update('poptavky', ['stav' => isset(self::STATUSES[$state]) ? $state : 1], ['idp' => $this->request->postInt('idp')]);
        }

        return $this->back($this->request->postInt('stav') === 2 ? 'The enquiry is resolved.' : 'The enquiry is open again.');
    }

    protected function actionDelete(): Response
    {
        if ($this->request->isPost()) {
            self::deleteAttachments($this->db->all('SELECT data FROM {poptavky} WHERE idp = ?', [$this->request->postInt('idp')]));
            $this->db->delete('poptavky', ['idp' => $this->request->postInt('idp')]);
        }

        return $this->back('The enquiry was deleted.');
    }

    /**
     * A personal data request (2.14, Core\PersonalData): everything the site keeps about one e-mail address, as a file for
     * the person or erased. Administrators only – it reaches subscribers and queues beyond the enquiries.
     */
    protected function actionPersonal(): Response
    {
        if (!$this->app->auth()->isAdmin()) {
            return $this->error('Only an administrator can handle personal data requests.', 403);
        }
        $email = \Kaleta\Core\PersonalData::normalise($this->request->isPost() ? $this->request->post('email') : '');
        $do = $this->request->isPost() ? $this->request->post('provest') : '';
        if ($email !== null && $do === 'export') {
            return new Response(\Kaleta\Core\PersonalData::export($this->app, $email), 200, ['Content-Type' => 'application/json; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="personal-data-' . date('Y-m-d') . '.json"']);
        }
        if ($email !== null && $do === 'erase') {
            if (!$this->request->postBool('potvrzeno')) {
                return $this->back('Tick that you want to erase the data.', 'personal', [], 'chyba');
            }
            $result = \Kaleta\Core\PersonalData::erase($this->app, $email);
            $message = t('Erased: %d enquiries, %d subscriptions, %d e-mails in the queue, %d testimonial requests.', $result['erased']['enquiries'], $result['erased']['subscriber'], $result['erased']['mail'], $result['erased']['testimonials']);
            if ($result['kept_testimonials'] !== []) {
                $message .= ' ' . t('A testimonial the person sent stays in References (items %s) – remove it there if they ask.', implode(', ', $result['kept_testimonials']));
            }

            return $this->back($message, 'personal');
        }
        if ($this->request->isPost() && $email === null) {
            return $this->back('Enter a valid e-mail address.', 'personal', [], 'chyba');
        }

        return $this->view('personal', t('Personal data request'), ['email' => $email ?? '', 'found' => $email !== null ? \Kaleta\Core\PersonalData::find($this->db, $email) : null]);
    }

    /** Saving the periods after which enquiries and job applications delete themselves (administrator only). */
    protected function actionSettings(): Response
    {
        if ($this->request->isPost() && $this->app->auth()->isAdmin()) {
            $this->app->settings()->set('enquiries_months', (string) max(0, min(120, $this->request->postInt('mesice'))));
            $this->app->settings()->set('job_applications_months', (string) max(0, min(120, $this->request->postInt('mesice_uchazeci'))));
            $this->app->settings()->set('triage_assistant', $this->request->postBool('triage_assistant') ? '1' : '0');
            $this->app->settings()->set('enquiries_expiry', $this->request->post('po_uplynuti') === 'anonymise' ? 'anonymise' : 'delete');
        }

        return $this->back('Enquiry settings saved.');
    }

    /** All enquiries to CSV (UTF-8 with BOM, semicolon – opens directly in Excel). */
    protected function actionCsv(): Response
    {
        $f = fopen('php://temp', 'w+');
        fwrite($f, "\xEF\xBB\xBF");
        fputcsv($f, [t('Number'), t('Date'), t('Form'), t('Status'), t('Email'), t('Page'), t('Campaign'), t('Content')], ';', '"', '');
        foreach ($this->db->all('SELECT * FROM {poptavky} ORDER BY idp') as $p) {
            $content = implode("\n", array_map(fn (array $d): string => $d[0] . ': ' . $d[1], json_decode((string) $p['data'], true) ?: []));
            // a cell starting with = + - @ would run as a formula in a spreadsheet
            $row = array_map(fn (string $v): string => preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v,
                [(string) $p['idp'], (string) $p['datum'], (string) $p['formular'], t(self::STATUSES[(int) $p['stav']] ?? ''), (string) $p['email'], (string) $p['stranka'], \Kaleta\Front\Forms::campaignText((string) ($p['kampan'] ?? '')), $content]);
            fputcsv($f, $row, ';', '"', '');
        }
        rewind($f);
        $csv = (string) stream_get_contents($f);
        fclose($f);
        \Kaleta\Admin\ChangeLog::write($this->app, 'enquiries', 'export CSV', '');

        return new Response($csv, 200, ['Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => 'attachment; filename="poptavky-' . date('Y-m-d') . '.csv"']);
    }

    /**
     * Enquiries older than the set number of months: deleted including attachments, or – with enquiries_expiry = anonymise
     * (2.14) – kept as rows without the person for statistics (Core\Privacy). Also called by the background cleanup (Core\Notifications).
     */
    public static function deleteExpired(\Kaleta\Core\Db $db, \Kaleta\Core\Settings $siteSettings): void
    {
        $months = $siteSettings->int('enquiries_months');
        if ($months <= 0) {
            return;
        }
        if ($siteSettings->get('enquiries_expiry') === 'anonymise') {
            \Kaleta\Core\Privacy::anonymise($db, array_map('intval', array_column($db->all('SELECT idp FROM {poptavky} WHERE anonymizovano IS NULL AND datum < NOW() - INTERVAL ? MONTH', [$months]), 'idp')));

            return;
        }
        self::deleteAttachments($db->all('SELECT data FROM {poptavky} WHERE datum < NOW() - INTERVAL ? MONTH', [$months]));
        $db->run('DELETE FROM {poptavky} WHERE datum < NOW() - INTERVAL ? MONTH', [$months]);
    }

    /** Blanks everything about the person in one enquiry and keeps the row (2.14, Core\Privacy). */
    protected function actionAnonymise(): Response
    {
        $idp = $this->request->postInt('idp');
        if ($this->request->isPost() && \Kaleta\Core\Privacy::anonymise($this->db, [$idp]) > 0) {
            \Kaleta\Admin\ChangeLog::write($this->app, 'enquiries', 'anonymise', '#' . $idp);
        }

        return $this->back('The enquiry was anonymised – the row stays for statistics without the person.', 'detail', ['id' => $idp]);
    }
}
