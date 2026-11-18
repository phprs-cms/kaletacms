<?php

declare(strict_types=1);

namespace Kaleta\Admin\Modules;

use Kaleta\Core\ChangeLog;
use Kaleta\Admin\Module;
use Kaleta\Core\Files;
use Kaleta\Core\InternalFiles as Library;
use Kaleta\Core\Response;

/**
 * Internal files (3.42, Core\InternalFiles): the team's document library in Workspace – never public. The section is never
 * a default of a level (GRANTED_EXPLICITLY): after the update only administrators open it and give it in Users or Roles.
 * Nothing here changes the public site (Kernel::SITE_NEUTRAL_MODULES).
 *
 *  - list: the folders the person sees (tabs), the files of one or all of them by a word (name, description, tags), a tag
 *    or a status; upload into the chosen folder; paging.
 *  - upload: a new file with a description and tags.
 *  - file: the detail – name, description, tags, folder, status, the versions with a download each, Replace, Delete.
 *  - save, replace, delete: the changes (delete: an administrator or who uploaded it).
 *  - download: the file as an attachment with its Content-Type, no-store and nosniff; one change-log line each.
 *  - folder, folder_save, folder_delete (administrators): a folder, its description and who sees it (everyone with the
 *    section, or chosen roles); only an empty folder is deleted.
 */
final class InternalFiles extends Module
{
    public const string IDENT = Library::SECTION;
    public const string NAME = 'Internal files';
    public const string GROUP = 'Workspace';
    public const string ICON = 'interni';
    public const bool GRANTED_EXPLICITLY = true;

    protected function actionList(): Response
    {
        $folders = Library::folders($this->app);
        $folder = $this->request->getInt('folder');
        $current = null;
        foreach ($folders as $f) {
            if ($f['id'] === $folder) {
                $current = $f;
            }
        }
        $query = ['folder' => $current['id'] ?? 0, 'search' => $this->request->get('search'), 'tag' => $this->request->get('tag'), 'status' => $this->request->get('status')];
        $page = max(1, $this->request->getInt('page', 1));
        $result = Library::files($this->app, $query, $page, Library::PAGE);

        return $this->view('list', 'Internal files', ['folders' => $folders, 'current' => $current, 'query' => $query, 'result' => $result, 'page' => $page,
            'pages' => max(1, (int) ceil($result['total'] / Library::PAGE)), 'admin' => $this->app->auth()->isAdmin(), 'limit' => self::uploadLimit()]);
    }

    protected function actionUpload(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        $folder = $this->request->postInt('folder_id');
        $upload = $this->uploaded('file');
        if (is_string($upload)) {
            return $this->back($upload, '', ['folder' => $folder], 'chyba');
        }
        $saved = Library::store($this->app, $folder, (string) $upload['tmp_name'], (string) ($upload['name'] ?? ''),
            ['description' => $this->request->post('description'), 'tags' => $this->request->post('tags')]);
        if (is_string($saved)) {
            return $this->back(t($saved), '', ['folder' => $folder], 'chyba');
        }
        ChangeLog::write($this->app, Library::SECTION, 'upload', t('Internal file #%d uploaded', (int) $saved['id']));

        return $this->back(t('The file %s is stored.', (string) $saved['name']), 'file', ['id' => (int) $saved['id']]);
    }

    protected function actionFile(): Response
    {
        $file = Library::file($this->app, $this->request->getInt('id'));
        if ($file === null) {
            return $this->error('The file does not exist, or its folder is not for you.', 404);
        }

        return $this->view('file', (string) $file['name'], ['file' => $file, 'folders' => Library::folders($this->app), 'mayDelete' => Library::mayDelete($this->app, $file),
            'limit' => self::uploadLimit()]);
    }

    protected function actionSave(): Response
    {
        $id = $this->request->postInt('id');
        if (!$this->request->isPost()) {
            return $this->back();
        }
        $saved = Library::update($this->app, $id, ['name' => $this->request->post('name'), 'description' => $this->request->post('description'),
            'tags' => $this->request->post('tags'), 'folder_id' => $this->request->postInt('folder_id'), 'status' => $this->request->post('status', 'ready')]);
        if (is_string($saved)) {
            return $this->back(t($saved), 'file', ['id' => $id], 'chyba');
        }

        return $this->back('The file is saved.', 'file', ['id' => $id]);
    }

    protected function actionReplace(): Response
    {
        $id = $this->request->postInt('id');
        if (!$this->request->isPost()) {
            return $this->back();
        }
        $upload = $this->uploaded('file');
        if (is_string($upload)) {
            return $this->back($upload, 'file', ['id' => $id], 'chyba');
        }
        $saved = Library::replace($this->app, $id, (string) $upload['tmp_name'], (string) ($upload['name'] ?? ''));
        if (is_string($saved)) {
            return $this->back(t($saved), 'file', ['id' => $id], 'chyba');
        }

        return $this->back(t('Version %d is stored; the older versions stay below.', (int) $saved['version']), 'file', ['id' => $id]);
    }

    protected function actionDownload(): Response
    {
        $id = $this->request->getInt('id');
        $file = Library::download($this->app, $id, $this->request->getInt('version'));
        if ($file === null) {
            $listed = Library::file($this->app, $id);
            if ($listed !== null && !\Kaleta\Core\SealedFiles::roomFor((int) $listed['size'])) { // review H1: a message, never a fatal error
                return $this->error('The file is too large to open with the memory this server allows a request (memory_limit). Ask your hosting to raise it.', 503);
            }

            return $this->error('The file does not exist, or its folder is not for you.', 404);
        }
        ChangeLog::write($this->app, Library::SECTION, 'download', t('Internal file #%d downloaded', $id));

        // 3.46 (N165): sealed at rest – the content is opened in memory, never served from the disk
        return new Response($file['content'], 200, ['Content-Type' => $file['type'], 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-store, private',
            'Content-Disposition' => 'attachment; filename="' . str_replace(['"', "\r", "\n"], '', $file['name']) . '"; filename*=UTF-8\'\'' . rawurlencode($file['name'])]);
    }

    protected function actionDelete(): Response
    {
        $id = $this->request->postInt('id');
        if (!$this->request->isPost()) {
            return $this->back();
        }
        $file = Library::file($this->app, $id);
        $refused = Library::delete($this->app, $id);
        if ($refused !== null) {
            return $this->back(t($refused), $file !== null ? 'file' : '', $file !== null ? ['id' => $id] : [], 'chyba');
        }

        return $this->back('The file is deleted with all its versions.', '', ['folder' => (int) ($file['folder_id'] ?? 0)]);
    }

    protected function actionFolder(): Response
    {
        if (!$this->app->auth()->isAdmin()) {
            return $this->error('Only an administrator creates and changes folders – they decide who sees them.', 403);
        }
        $id = $this->request->getInt('id');
        $folder = $id > 0 ? Library::folder($this->app, $id) : null;
        if ($id > 0 && $folder === null) {
            return $this->error('The folder does not exist.', 404);
        }

        return $this->view('folder', $folder !== null ? $folder['name'] : 'New folder', ['folder' => $folder, 'roles' => Library::roles($this->db), 'departments' => Library::departments($this->db)]);
    }

    protected function actionFolderSave(): Response
    {
        $id = $this->request->postInt('id');
        if (!$this->request->isPost()) {
            return $this->back();
        }
        $saved = Library::saveFolder($this->app, ['name' => $this->request->post('name'), 'description' => $this->request->post('description'),
            'audience' => $this->request->post('audience'), 'roles' => $this->request->postList('roles'),
            'departments' => $this->request->postList('departments')], $id);
        if (is_string($saved)) {
            return $this->back(t($saved), 'folder', $id > 0 ? ['id' => $id] : [], 'chyba');
        }

        return $this->back('The folder is saved.', '', ['folder' => $saved['id']]);
    }

    protected function actionFolderDelete(): Response
    {
        $id = $this->request->postInt('id');
        if (!$this->request->isPost()) {
            return $this->back();
        }
        $refused = Library::deleteFolder($this->app, $id);
        if ($refused !== null) {
            return $this->back(t($refused), '', ['folder' => $id], 'chyba');
        }

        return $this->back('The folder is deleted.');
    }

    /**
     * The uploaded file of a form field, or a translated refusal.
     *
     * @return array<string, mixed>|string
     */
    private function uploaded(string $field): array|string
    {
        $file = $this->request->file($field);
        if ($file === null) {
            return t('Choose a file.');
        }
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? Files::limitMessage() : t('The file could not be uploaded.');
        }
        if (!is_uploaded_file((string) $file['tmp_name'])) {
            return t('The file could not be uploaded.');
        }

        return $file;
    }

    /** The largest upload in words: the lower of the library's limit and the server's. */
    private static function uploadLimit(): string
    {
        $server = Files::limit();

        return $server > 0 && $server < Library::MAX_BYTES ? Files::limitText() : '100 MB';
    }
}
