<?php

declare(strict_types=1);

namespace Kaleta\Admin\Modules;

use Kaleta\Admin\Module;
use Kaleta\Core\Db;
use Kaleta\Core\Response;
use Kaleta\Core\Slug;

/**
 * Tags and topics. Tags are created automatically when writing news; here they can be renamed, merged and deleted.
 * A tag with a description and an image behaves on the site as a topic page (/novinky/stitek/<slug>).
 *
 * Language versions (3.14): a tag belongs to the version of the news item that created it (jazyk, '' = the default
 * language). Without slugs per language a slug is still one tag for every version, so an English news item reuses the
 * Czech tag of the same name, as before; with slugs per language (Core\Slug) each version has its own. 3.19: that is every
 * site that could switch – migration 0106 split the tags used in several versions; merging stays within one version.
 */
final class Tags extends Module
{
    public const string IDENT = 'tags';
    public const string EXTENSION = 'novinky';
    public const string NAME = 'Tags and topics';
    public const string GROUP = 'Content';
    public const string ICON = 'stitky';
    public const string PARENT = 'news';

    /**
     * The tag a news item of the language version $language gets for $name: an existing one with the same slug (in that
     * version when slugs are per language, otherwise in any – the one of that version first), or a new one in that version.
     * Admin, MCP and the importers. Returns [ids, created].
     *
     * @return array{0: int, 1: bool}
     */
    public static function findOrCreate(Db $db, string $name, string $language): array
    {
        $seo = slugify($name, 90);
        [$sameLanguage, $languageParams] = Slug::scope($db, $language);
        // N314-4: per language, a tag from before 3.14 ('' = shared) that already tags a news item of this language stays
        // that item's tag – re-saving the item must not split it into a second tag with the same slug
        $legacy = $sameLanguage !== '' && $language !== '' ? " OR (jazyk = '' AND EXISTS (SELECT 1 FROM {novinky_stitky} ns JOIN {novinky} n ON n.idc = ns.idc WHERE ns.ids = {stitky}.ids AND n.jazyk = ?))" : '';
        $ids = $legacy === ''
            ? $db->value('SELECT ids FROM {stitky} WHERE seo_link = ?' . $sameLanguage . ' ORDER BY jazyk = ? DESC LIMIT 1', [$seo, ...$languageParams, $language])
            : $db->value('SELECT ids FROM {stitky} WHERE seo_link = ? AND (jazyk = ?' . $legacy . ') ORDER BY jazyk = ? DESC LIMIT 1', [$seo, $language, $language, $language]);

        return $ids !== null ? [(int) $ids, false] : [$db->insert('stitky', ['nazev' => $name, 'seo_link' => $seo, 'jazyk' => $language]), true];
    }

    protected function actionList(): Response
    {
        $edit = $this->db->one('SELECT * FROM {stitky} WHERE ids = ?', [$this->request->getInt('edit')]);

        return $this->view('list', 'Tags and topics', [
            'tags' => $this->db->all('SELECT s.*, (SELECT COUNT(*) FROM {novinky_stitky} cs WHERE cs.ids = s.ids) AS pocet FROM {stitky} s ORDER BY (s.popis IS NOT NULL AND s.popis <> \'\') DESC, pocet DESC, ' . $this->db->sortText('s.nazev') . ' LIMIT 500'),
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

        // merge: the news items get the target tag, this one ceases to exist and its slug is redirected; 3.19: with tags per
        // language only into a tag of the same version (the news items of one language never get another language's tag)
        [$sameLanguage, $languageParams] = Slug::scope($this->db, (string) $tag['jazyk']);
        $target = $this->db->one('SELECT * FROM {stitky} WHERE ids = ? AND ids <> ?' . $sameLanguage, [$this->request->postInt('sloucit_do'), $tag['ids'], ...$languageParams]);
        if ($target !== null) {
            $this->db->run('INSERT IGNORE INTO {novinky_stitky} (idc, ids) SELECT idc, ? FROM {novinky_stitky} WHERE ids = ?', [$target['ids'], $tag['ids']]);
            $this->db->delete('novinky_stitky', ['ids' => $tag['ids']]);
            $this->db->delete('stitky', ['ids' => $tag['ids']]);
            Redirects::add($this->db, Slug::redirectPath($this->db, 'novinky/stitek/' . $tag['seo_link'], (string) $tag['jazyk']),
                Slug::redirectPath($this->db, 'novinky/stitek/' . $target['seo_link'], (string) $target['jazyk']));

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
