<?php

declare(strict_types=1);

namespace Kaleta\Admin\Modules;

use Kaleta\Admin\Module;
use Kaleta\Core\Response;

/**
 * Change log - overview of actions in the admin (administrator only), and the Claude sessions that can be undone as a
 * whole (2.17, Core\AgentJournal).
 */
final class ChangeLog extends Module
{
    public const string IDENT = 'changelog';
    public const string NAME = 'Change log';
    public const string GROUP = 'Site care';
    public const string ICON = 'protokol';
    public const bool ADMIN_ONLY = true;

    private const int PER_PAGE = 100;

    protected function actionList(): Response
    {
        $who = $this->request->getInt('user');
        $whereParts = $this->request->get('area');
        $search = mb_substr(trim($this->request->get('search')), 0, 100);
        $by = in_array($this->request->get('by'), ['people', 'claude'], true) ? $this->request->get('by') : '';
        $conditions = [];
        $params = [];
        if ($by !== '') {
            $conditions[] = $by === 'claude' ? "via <> ''" : "via = ''"; // made through a Claude connection or by a person in the admin (2.2)
        }
        if ($who > 0) {
            $conditions[] = 'kdo = ?';
            $params[] = $who;
        }
        if ($whereParts !== '' && preg_match('/^[a-z_]{2,30}$/D', $whereParts)) {
            $conditions[] = 'modul = ?';
            $params[] = $whereParts;
        }
        if ($search !== '') {
            $conditions[] = 'popis LIKE ?';
            $params[] = '%' . addcslashes($search, '%_\\') . '%';
        }
        $sql = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);
        $total = (int) $this->db->value('SELECT COUNT(*) FROM {protokol}' . $sql, $params);
        $pageCount = max(1, (int) ceil($total / self::PER_PAGE));
        $pageNumber = max(1, min($pageCount, $this->request->getInt('page', 1)));

        return $this->view('list', 'Change log', [
            'records' => $this->db->all('SELECT * FROM {protokol}' . $sql . ' ORDER BY idp DESC LIMIT ' . self::PER_PAGE . ' OFFSET ' . (($pageNumber - 1) * self::PER_PAGE), $params),
            'users' => $this->db->pairs("SELECT idu, IF(jmeno = '', user, jmeno) FROM {uzivatele} ORDER BY 2"),
            'modules' => array_column($this->db->all('SELECT DISTINCT modul FROM {protokol} ORDER BY modul'), 'modul'),
            'who' => $who, 'by' => $by, 'whereParts' => $whereParts, 'search' => $search, 'pageNumber' => $pageNumber, 'pageCount' => $pageCount, 'total' => $total,
        ]);
    }

    /** Claude sessions: the changes one connection made in a row, each undoable as a whole (2.17). */
    protected function actionSessions(): Response
    {
        $sessions = [];
        try {
            $sessions = \Kaleta\Core\AgentJournal::sessions($this->db, 100);
        } catch (\PDOException) {
            // before the migration
        }
        $result = $this->app->session->get('undo_result');
        $this->app->session->set('undo_result', null);

        return $this->view('sessions', 'Claude sessions', ['sessions' => $sessions, 'result' => is_array($result) ? $result : null]);
    }

    /** Undo one session (POST): rows changed since by someone else are left alone unless "force" is ticked. */
    protected function actionUndo(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back('', 'sessions');
        }
        try {
            $result = \Kaleta\Core\AgentJournal::undo($this->app, $this->request->postInt('id'), $this->request->postBool('force'));
        } catch (\InvalidArgumentException | \DomainException $e) {
            return $this->back($e->getMessage(), 'sessions', [], 'chyba');
        }
        $this->app->session->set('undo_result', $result + ['id' => $this->request->postInt('id')]);

        return $this->back(t('The session is undone: %d rows restored, %d removed.', $result['restored'], $result['removed']), 'sessions');
    }
}
