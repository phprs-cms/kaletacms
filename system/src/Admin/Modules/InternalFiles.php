<?php

declare(strict_types=1);

namespace Kaleta\Admin\Modules;

use Kaleta\Core\ChangeLog;
use Kaleta\Admin\Module;
use Kaleta\Core\Files;
use Kaleta\Core\InternalFileLinks;
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
 *  - search_json (3.49 C): ready files by a word, for a choice by search – GET, read-only.
 *  - link, unlink (3.49 B, Core\InternalFileLinks): a file linked to a customer, a company, a deal or a quote from the box
 *    on the record's page (box() – views/admin/internal_files/box.php); the file's page lists its records.
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
     * The choice of an internal file (3.49 C, for the attachments of replies and the files of a record): ready files of the
     * folders the person sees by a word in the name, description or tags – never a draft. GET, read-only, no-store.
     */
    protected function actionSearchJson(): Response
    {
        $q = $this->searchWord();
        $rows = $q === '' ? [] : Library::files($this->app, ['search' => $q, 'status' => 'ready'], 1, self::PICK_ROWS)['rows'];

        // size: the bytes, for the attachments' size check before anything is uploaded (review C10)
        return Response::json(['files' => array_map(fn (array $f): array => ['id' => (int) $f['id'], 'name' => (string) $f['name'], 'size' => (int) $f['size'],
            'note' => implode(' · ', array_filter([(string) $f['folder_name'], strtoupper((string) $f['extension']), Files::size((int) $f['size'])]))], $rows)], 200, Response::NO_STORE);
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
            'limit' => self::uploadLimit(), 'links' => InternalFileLinks::ofFile($this->app, (int) $file['id'])]); // 3.49 B: the records it is linked to
    }

    /** The rows "Link a file" offers without script: the files most recently changed in the folders the person sees. */
    public const int BOX_CHOICES = 50;

    /**
     * 3.49 B: what views/admin/internal_files/box.php shows on a customer's, a company's, a deal's or a quote's page – the
     * files linked to it that the viewer can open, the choice of a file to link (the most recently changed ready files of
     * the folders they see; the script searches the rest) and the addresses of the actions. Null without Internal files
     * (nothing is shown, not even a count) or when the viewer may not link to this kind of record.
     *
     * @param array<string, mixed> $modules Admin\Kernel::modules() of the viewer
     * @return array{kind: string, id: int, files: list<array<string, mixed>>, choices: array<int, string>, linkUrl: string, unlinkUrl: string, searchUrl: string, fileUrl: string, downloadUrl: string}|null
     */
    public static function box(\Kaleta\Core\App $app, array $modules, string $kind, int $id): ?array
    {
        if (!isset($modules[self::IDENT]) || $id <= 0 || !InternalFileLinks::mayLink($app, $kind)) {
            return null;
        }
        $files = InternalFileLinks::ofRecord($app, $kind, $id);
        $linked = array_column($files, 'id');
        $choices = [];
        foreach (Library::files($app, ['status' => 'ready'], 1, self::BOX_CHOICES)['rows'] as $f) {
            if (!in_array($f['id'], $linked, true)) {
                $choices[(int) $f['id']] = (string) $f['name'] . ' (' . (string) $f['folder_name'] . ')';
            }
        }
        $base = 'admin.php?module=' . self::IDENT . '&action=';

        return ['kind' => $kind, 'id' => $id, 'files' => $files, 'choices' => $choices, 'linkUrl' => $app->url($base . 'link'), 'unlinkUrl' => $app->url($base . 'unlink'),
            'searchUrl' => $app->url($base . 'search_json'), 'fileUrl' => $app->url($base . 'file&id='), 'downloadUrl' => $app->url($base . 'download&id=')];
    }

    /**
     * 3.49 B: links a file to a record from the record's box (POST file_id, kind, record_id, back = the record's admin path).
     * The rule is Core\InternalFileLinks: the file seen, the record's section open with its feature on, the record there –
     * an unknown record and a forbidden one get the same message. Back to the record's page.
     */
    protected function actionLink(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        $kind = $this->request->post('kind');
        $recordId = $this->request->postInt('record_id');
        $refused = InternalFileLinks::link($this->app, $this->request->postInt('file_id'), $kind, $recordId);

        return $this->toRecord($kind, $recordId, $refused === null ? t('The file is linked.') : t($refused), $refused === null ? 'ok' : 'chyba');
    }

    /** 3.49 B: unlinks a file from a record (the record's box or the file's page – back where it came from). */
    protected function actionUnlink(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        $kind = $this->request->post('kind');
        $recordId = $this->request->postInt('record_id');
        $fileId = $this->request->postInt('file_id');
        $done = InternalFileLinks::unlink($this->app, $fileId, $kind, $recordId);
        $message = $done ? t('The file is unlinked.') : t(InternalFileLinks::NO_RECORD);
        if ($this->request->post('from') === 'file') {
            return $this->back($message, 'file', ['id' => $fileId], $done ? 'ok' : 'chyba');
        }

        return $this->toRecord($kind, $recordId, $message, $done ? 'ok' : 'chyba');
    }

    /**
     * Back to a record's page (its admin path through Inbox::places) – only when the person may open it; otherwise (an
     * unknown or forbidden record, the same message) to the list, so the answer tells nothing about the record.
     */
    private function toRecord(string $kind, int $recordId, string $message, string $type): Response
    {
        $this->app->session->flash($type, $message);
        $place = InternalFileLinks::mayOpenKind($this->app, $kind) ? (\Kaleta\Core\Inbox::places($this->db, [$kind . ':' . $recordId])[$kind . ':' . $recordId] ?? null) : null;

        return Response::redirect($place !== null ? $this->app->url((string) $place['path']) . '#internal-files' : $this->url());
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
