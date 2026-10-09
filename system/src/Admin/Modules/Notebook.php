<?php

declare(strict_types=1);

namespace Kaleta\Admin\Modules;

use Kaleta\Admin\Module;
use Kaleta\Core\Notebook as Notes;
use Kaleta\Core\Response;

/**
 * Agent notebook (2.15, Core\Notebook): notes for whoever works on the site next – Claude in a new conversation or a
 * colleague. The list by topic with a search, pinned notes first; add, edit, pin and delete. Claude reads and writes the
 * same notes with read_notebook and write_notebook.
 */
final class Notebook extends Module
{
    public const string IDENT = 'notebook';
    public const string HUB = 'claude';
    public const string PARENT = 'claude_settings';
    public const string NAME = 'Notebook';
    public const string GROUP = 'Claude';
    public const string ICON = 'zapisnik';

    protected function actionList(): Response
    {
        $topic = Notes::topic($this->request->get('topic')) ?? '';
        $search = mb_substr(trim($this->request->get('search')), 0, 100);

        return $this->view('list', 'Notebook', ['notes' => Notes::all($this->db, $topic, $search), 'topic' => $topic, 'search' => $search,
            'counts' => $this->db->pairs('SELECT topic, COUNT(*) FROM {notebook} GROUP BY topic')]);
    }

    protected function actionEdit(): Response
    {
        $id = $this->request->getInt('id');
        $note = Notes::find($this->db, $id);
        if ($id > 0 && $note === null) {
            return $this->back('The note does not exist.', '', [], 'chyba');
        }

        return $this->view('edit', $note !== null ? $note['title'] : 'New note', ['note' => $note, 'topic' => Notes::topic($this->request->get('topic')) ?? 'other']);
    }

    protected function actionSave(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        $id = $this->request->postInt('id');
        $saved = Notes::save($this->app, ['topic' => $this->request->post('topic'), 'title' => $this->request->post('title'), 'text' => $this->request->post('text'),
            'pinned' => $this->request->postBool('pinned')], $id);
        if (is_string($saved)) {
            return $this->back($saved, 'edit', $id > 0 ? ['id' => $id] : [], 'chyba');
        }

        return $this->back('The note is saved.', '', ['topic' => (string) $saved['topic']]);
    }

    /** Pins a note or takes the pin off – one click in the list. */
    protected function actionPin(): Response
    {
        $id = $this->request->postInt('id');
        $note = $this->request->isPost() ? Notes::find($this->db, $id) : null;
        if ($note !== null) {
            Notes::save($this->app, ['pinned' => !$note['pinned']], $id);
        }

        return $this->back('', '', array_filter(['topic' => $this->request->post('tema'), 'search' => $this->request->post('hledat')]));
    }

    protected function actionDelete(): Response
    {
        if ($this->request->isPost() && Notes::delete($this->app, $this->request->postInt('id'))) {
            return $this->back('The note is deleted.');
        }

        return $this->back();
    }
}
