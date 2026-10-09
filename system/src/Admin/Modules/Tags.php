<?php

declare(strict_types=1);

namespace Kaleta\Admin\Modules;

use Kaleta\Admin\Module;
use Kaleta\Core\Response;

/**
 * Tags and topics. Tags are created automatically when writing news; here they can be renamed, merged and deleted.
 * A tag with a description and an image behaves on the site as a topic page (/novinky/stitek/<slug>).
 */
final class Tags extends Module
{
    public const string IDENT = 'tags';
    public const string EXTENSION = 'novinky';
    public const string NAME = 'Tags and topics';
    public const string GROUP = 'Content';
    public const string ICON = 'stitky';
    public const string PARENT = 'news';

    protected function actionList(): Response
    {
        $edit = $this->db->one('SELECT * FROM {stitky} WHERE ids = ?', [$this->request->getInt('edit')]);

        return $this->view('list', 'Tags and topics', [
            'tags' => $this->db->all('SELECT s.*, (SELECT COUNT(*) FROM {novinky_stitky} cs WHERE cs.ids = s.ids) AS pocet FROM {stitky} s ORDER BY (s.popis IS NOT NULL AND s.popis <> \'\') DESC, pocet DESC, s.nazev LIMIT 500'),
            'edit' => $edit,
        ]);
    }

    protected function actionSave(): Response
    {
        $tag = $this->db->one('SELECT * FROM {stitky} WHERE ids = ?', [$this->request->postInt('ids')]);
        $name = mb_substr(trim($this->request->post('nazev')), 0, 80);
        if (!$this->request->isPost() || $tag === null || $name === '') {
            return $this->back('Enter the tag name.', type: 'chyba');
        }
        try {
            $description = \Kaleta\Core\Html::forUserOrFail(trim($this->request->post('popis')), $this->app->auth(), 'popis');
        } catch (\Kaleta\Core\HtmlTooLarge $e) {
            return $this->back(t('Nothing was saved: %s', $e->localized()), '', ['edit' => $tag['ids']], 'chyba');
        }
        $this->db->update('stitky', ['nazev' => $name, 'popis' => $description, 'obrazek' => mb_substr($this->request->post('obrazek'), 0, 255)], ['ids' => $tag['ids']]);

        // merge: the news items get the target tag, this one ceases to exist and its slug is redirected
        $target = $this->db->one('SELECT * FROM {stitky} WHERE ids = ? AND ids <> ?', [$this->request->postInt('sloucit_do'), $tag['ids']]);
        if ($target !== null) {
            $this->db->run('INSERT IGNORE INTO {novinky_stitky} (idc, ids) SELECT idc, ? FROM {novinky_stitky} WHERE ids = ?', [$target['ids'], $tag['ids']]);
            $this->db->delete('novinky_stitky', ['ids' => $tag['ids']]);
            $this->db->delete('stitky', ['ids' => $tag['ids']]);
            Redirects::add($this->db, 'novinky/stitek/' . $tag['seo_link'], 'novinky/stitek/' . $target['seo_link']);

            return $this->back(t('Tag “%s” has been merged into “%s”.', $tag['nazev'], $target['nazev']));
        }

        return $this->back('Tag saved.');
    }

    protected function actionDelete(): Response
    {
        if ($this->request->isPost()) {
            $this->db->delete('novinky_stitky', ['ids' => $this->request->postInt('ids')]);
            $this->db->delete('stitky', ['ids' => $this->request->postInt('ids')]);
        }

        return $this->back('Tag deleted. The news items remain, they just no longer carry it.');
    }
}
