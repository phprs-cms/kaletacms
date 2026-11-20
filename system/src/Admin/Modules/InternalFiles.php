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
 *  - upload: new files (3.47: several at once) with a description and tags.
 *  - file: the detail – name, description, tags, folder, status, the versions with a download each, Replace, Delete.
 *  - save, replace, delete: the changes (delete: an administrator or who uploaded it).
 *  - download: the file as an attachment with its Content-Type, no-store and nosniff; one change-log line each.
 *  - preview (3.47): a raster image (at most 10 MB) or a text document shown inline – never PDF or SVG; one change-log line.
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

    /**
     * New files (3.47 U66: several at once – files[], and the older single field "file"), each with the description and the
     * tags of the form and one change-log line. One file stored opens its page; several go back to the folder with
     * "Stored: N" and, for each refusal, the name and the reason.
     */
    protected function actionUpload(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        $folder = $this->request->postInt('folder_id');
        $uploads = [...self::uploadsOf($this->request->file('files')), ...self::uploadsOf($this->request->file('file'))];
        if ($uploads === []) {
            return $this->back(t('Choose a file.'), '', ['folder' => $folder], 'chyba');
        }
        $stored = [];
        $refused = [];
        foreach ($uploads as $upload) {
            $name = Library::safeName((string) ($upload['name'] ?? '')) ?: t('File');
            $problem = self::uploadProblem($upload);
            if ($problem !== null) {
                $refused[] = [$name, $problem];
                continue;
            }
            $saved = Library::store($this->app, $folder, (string) $upload['tmp_name'], (string) ($upload['name'] ?? ''),
                ['description' => $this->request->post('description'), 'tags' => $this->request->post('tags')]);
            if (is_string($saved)) {
                $refused[] = [$name, t($saved)];
                continue;
            }
            ChangeLog::write($this->app, Library::SECTION, 'upload', t('Internal file #%d uploaded', (int) $saved['id']));
            $stored[] = $saved;
        }
        if (count($uploads) === 1 && $stored !== []) {
            return $this->back(t('The file %s is stored.', (string) $stored[0]['name']), 'file', ['id' => (int) $stored[0]['id']]);
        }
        if (count($uploads) === 1) {
            return $this->back($refused[0][1], '', ['folder' => $folder], 'chyba');
        }

        $why = array_map(fn (array $r): string => $r[0] . ' – ' . rtrim($r[1], '.'), $refused);

        return $this->back(t('Stored: %d', count($stored)) . ($why !== [] ? '. ' . implode('. ', $why) . '.' : ''), '', ['folder' => $folder], $refused !== [] ? 'chyba' : 'ok');
    }

    /**
     * The uploads of one form field as a list of single files: a field with several files (files[] – PHP gives each key as a
     * list) becomes one entry per file; a single file stays one; a missing field is none (3.47 U66). Pure.
     *
     * @param array<string, mixed>|null $field the field as in $_FILES
     * @return list<array<string, mixed>>
     */
    public static function uploadsOf(?array $field): array
    {
        if ($field === null) {
            return [];
        }
        if (!is_array($field['name'] ?? null)) {
            return ($field['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE ? [] : [$field];
        }
        $out = [];
        foreach (array_keys($field['name']) as $i) {
            $one = [];
            foreach (['name', 'type', 'tmp_name', 'error', 'size', 'full_path'] as $key) {
                if (isset($field[$key]) && is_array($field[$key]) && array_key_exists($i, $field[$key])) {
                    $one[$key] = $field[$key][$i];
                }
            }
            if (($one['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE && !is_array($one['name'] ?? null)) {
                $out[] = $one;
            }
        }

        return $out;
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

    /** Raster images a preview shows (3.47 U66) – never SVG or PDF: on the admin's origin they could run code. */
    public const array PREVIEW_IMAGES = ['png', 'jpg', 'jpeg', 'webp', 'gif'];

    /** The largest image a preview opens (3.47 U66). */
    public const int PREVIEW_MAX = 10 * 1024 * 1024;

    /**
     * What a preview of the file shows (3.47 U66): 'image' for a raster image of at most PREVIEW_MAX that fits the memory of a
     * request, 'text' for a text document, null for anything else (PDF, SVG, office files, audio, video). Pure.
     *
     * @param array<string, mixed> $file Core\InternalFiles::file
     */
    public static function previewKind(array $file): ?string
    {
        $extension = strtolower((string) ($file['extension'] ?? ''));
        $size = (int) ($file['size'] ?? 0);

        return match (true) {
            in_array($extension, self::PREVIEW_IMAGES, true) && $size <= self::PREVIEW_MAX && \Kaleta\Core\SealedFiles::roomFor($size) => 'image',
            in_array($extension, Library::TEXT_EXTENSIONS, true) && $size <= Library::MAX_TEXT => 'text',
            default => null,
        };
    }

    /**
     * The current version shown in the browser (3.47 U66): a raster image with its own type, or a text document as plain
     * text – inline, nosniff, no-store, under a sandbox policy; one change-log line ("previewed"). 404 for any other type and
     * for a person who does not see the folder, as the download.
     */
    protected function actionPreview(): Response
    {
        $id = $this->request->getInt('id');
        $file = Library::file($this->app, $id);
        $kind = $file !== null ? self::previewKind($file) : null;
        if ($file === null || $kind === null) {
            return $this->error('The file does not exist, or its folder is not for you.', 404);
        }
        $content = $kind === 'text' ? Library::text($this->app->settings(), $file) : (Library::download($this->app, $id)['content'] ?? null);
        if ($content === null) {
            return $this->error('The file does not exist, or its folder is not for you.', 404);
        }
        ChangeLog::write($this->app, Library::SECTION, 'preview', t('Internal file #%d previewed', $id));

        return new Response($content, 200, ['Content-Type' => $kind === 'text' ? 'text/plain; charset=utf-8' : Library::contentType((string) $file['extension']),
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-store, private', 'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox",
            'Content-Disposition' => "inline; filename*=UTF-8''" . rawurlencode((string) $file['name'])]);
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

        return self::uploadProblem($file) ?? $file;
    }

    /**
     * Why one uploaded file cannot be taken (translated), or null when it can.
     *
     * @param array<string, mixed> $file
     */
    private static function uploadProblem(array $file): ?string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? Files::limitMessage() : t('The file could not be uploaded.');
        }
        if (!is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
            return t('The file could not be uploaded.');
        }

        return null;
    }

    /** The largest upload in words: the lower of the library's limit and the server's. */
    private static function uploadLimit(): string
    {
        $server = Files::limit();

        return $server > 0 && $server < Library::MAX_BYTES ? Files::limitText() : '100 MB';
    }
}
