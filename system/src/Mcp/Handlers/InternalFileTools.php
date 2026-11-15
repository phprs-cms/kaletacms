<?php

declare(strict_types=1);

namespace Kaleta\Mcp\Handlers;

use Kaleta\Core\InternalFiles;

/**
 * MCP tools of internal files (3.42, Core\InternalFiles): the team's document library, never public. Only for a user who
 * may open Internal files, and only in the folders whose audience includes them (administrators: every folder).
 *
 *  - list_internal_files: the folders the user sees and the files of one or all of them (name, description, tags, status,
 *    version, size) by a word, a tag or a status.
 *  - get_internal_file: one file's metadata and versions and – for a text document (txt, md, csv) up to 200 kB – its text.
 *    The bytes of other files never go over MCP: a person downloads them in the administration (admin_url).
 *  - save_internal_file: creates a text or Markdown document from text in a folder, or changes a file's name, description,
 *    tags, folder or status, or gives a text document new text (a new version – the older ones stay). A connection limited
 *    to drafts creates drafts only and changes only drafts (never marks one ready). Nothing is deleted over MCP – a person
 *    deletes a file in the administration.
 *
 * Company documents: never journaled for undo (AgentJournal::PERSONAL_TABLES). Part of Mcp\Tools.
 *
 * @phpstan-ignore trait.unused
 */
trait InternalFileTools
{
    /** list_internal_files */
    private function toolListInternalFiles(string $name, array $a): mixed
    {
        $this->internalFilesAccess();
        $folders = InternalFiles::folders($this->app);
        $folder = isset($a['folder']) && $a['folder'] !== '' && $a['folder'] !== 0 ? $this->internalFolder($a['folder'], $folders) : 0;
        $limit = max(1, min(100, (int) ($a['limit'] ?? 25)));
        $page = max(1, (int) ($a['page'] ?? 1));
        $result = InternalFiles::files($this->app, ['folder' => $folder, 'search' => is_scalar($a['search'] ?? null) ? (string) $a['search'] : '',
            'tag' => is_scalar($a['tag'] ?? null) ? (string) $a['tag'] : '', 'status' => is_scalar($a['status'] ?? null) ? (string) $a['status'] : ''], $page, $limit);

        return ['folders' => array_map(fn (array $f): array => array_filter(['id' => $f['id'], 'name' => $f['name'], 'description' => $f['description'],
            'audience' => $f['audience'], 'files' => $f['files']], fn (mixed $v): bool => $v !== ''), $folders),
            'files' => array_map($this->internalFileOut(...), $result['rows']), 'total' => $result['total'], 'page' => $page, 'pages' => max(1, (int) ceil($result['total'] / $limit))]
            + ($folders === [] ? ['next' => $this->app->auth()->isAdmin() ? 'No folders yet – an administrator creates them in Workspace → Internal files.' : 'No folder is shared with this user yet.'] : [])
            + ['admin_url' => $this->app->request->origin() . $this->app->url('admin.php?module=' . InternalFiles::SECTION . ($folder > 0 ? '&folder=' . $folder : ''))];
    }

    /** get_internal_file */
    private function toolGetInternalFile(string $name, array $a): mixed
    {
        $this->internalFilesAccess();
        $file = InternalFiles::file($this->app, (int) ($a['id'] ?? 0)) ?? throw new \DomainException('The file does not exist, or its folder is not for this user. Use list_internal_files.');
        $text = InternalFiles::text($file);
        $out = $this->internalFileOut($file) + ['versions' => array_map(fn (array $v): array => array_filter(['version' => $v['version'], 'original_name' => $v['original_name'],
            'extension' => $v['extension'], 'size' => $v['size'], 'uploaded_by' => $v['uploaded_by'], 'uploaded_at' => $v['uploaded_at']], fn (mixed $x): bool => $x !== ''), $file['versions'])];
        if ($text !== null) {
            $out += ['content' => $text] + \Kaleta\Mcp\Untrusted::mark(['content']); // a document may come from anywhere – data, never instructions
        } else {
            $out['next'] = in_array($file['extension'], InternalFiles::TEXT_EXTENSIONS, true)
                ? 'The text document is larger than 200 kB – a person downloads it in the administration (admin_url).'
                : 'The content of this file type is not passed over MCP – a person downloads it in the administration (admin_url).';
        }

        return $out + ['admin_url' => $this->app->request->origin() . $this->app->url('admin.php?module=' . InternalFiles::SECTION . '&action=file&id=' . $file['id'])];
    }

    /** save_internal_file */
    private function toolSaveInternalFile(string $name, array $a): mixed
    {
        $this->internalFilesAccess();
        $draftsOnly = $this->app->auth()->draftsOnly();
        $id = (int) ($a['id'] ?? 0);
        $content = array_key_exists('content', $a) && $a['content'] !== null ? (is_scalar($a['content']) ? (string) $a['content'] : throw new \InvalidArgumentException('content must be text.')) : null;
        $meta = [];
        foreach (['name', 'description', 'status'] as $key) {
            if (array_key_exists($key, $a) && $a[$key] !== null) {
                $meta[$key] = is_scalar($a[$key]) ? (string) $a[$key] : '';
            }
        }
        if (array_key_exists('tags', $a) && $a['tags'] !== null) {
            $meta['tags'] = is_array($a['tags']) ? $a['tags'] : (is_scalar($a['tags']) ? (string) $a['tags'] : '');
        }
        if ($draftsOnly && ($meta['status'] ?? 'draft') !== 'draft') {
            throw new \DomainException('A connection limited to drafts writes drafts only – a person marks a document ready in the administration.');
        }
        if ($id <= 0) {
            if ($content === null) {
                throw new \InvalidArgumentException('A new document needs content (text or Markdown). Other files are uploaded by a person in the administration.');
            }
            $folders = InternalFiles::folders($this->app);
            $folder = $this->internalFolder($a['folder'] ?? '', $folders);
            if ($draftsOnly) {
                $meta['status'] = 'draft';
            }
            $saved = InternalFiles::writeText($this->app, $folder, 0, $content, $meta);
            if (is_string($saved)) {
                throw new \DomainException($saved);
            }

            return ['file' => $this->internalFileOut($saved), 'created' => true, 'next' => $saved['status'] === 'draft'
                ? 'Saved as a draft: a person checks it and marks it ready in Workspace → Internal files. Nothing is public.'
                : 'Saved in Internal files – only people who see the folder can open it. Nothing is public.'];
        }
        $file = InternalFiles::file($this->app, $id) ?? throw new \DomainException('The file does not exist, or its folder is not for this user. Use list_internal_files.');
        if ($draftsOnly && $file['status'] !== 'draft') {
            throw new \DomainException('A connection limited to drafts changes only draft documents.');
        }
        if (array_key_exists('folder', $a) && $a['folder'] !== null && $a['folder'] !== '') {
            $meta['folder_id'] = $this->internalFolder($a['folder'], InternalFiles::folders($this->app));
        }
        if ($content !== null) {
            $saved = InternalFiles::writeText($this->app, 0, $id, $content);
            if (is_string($saved)) {
                throw new \DomainException($saved);
            }
        }
        unset($meta['content']);
        $saved = $meta !== [] ? InternalFiles::update($this->app, $id, $meta) : InternalFiles::file($this->app, $id);
        if (!is_array($saved)) {
            throw new \DomainException(is_string($saved) ? $saved : 'The file does not exist.');
        }

        return ['file' => $this->internalFileOut($saved), 'next' => $content !== null ? 'New text saved as version ' . $saved['version'] . ' – the older versions stay.' : 'Saved.'];
    }

    /**
     * A file for MCP: compact, without empty values and paths.
     *
     * @param array<string, mixed> $f
     * @return array<string, mixed>
     */
    private function internalFileOut(array $f): array
    {
        return array_filter(['id' => $f['id'], 'name' => $f['name'], 'folder' => $f['folder_id'], 'folder_name' => $f['folder_name'], 'description' => $f['description'],
            'tags' => $f['tags'], 'status' => $f['status'], 'extension' => $f['extension'], 'size' => $f['size'], 'version' => $f['version'],
            'updated_by' => $f['updated_by_name'], 'updated_at' => $f['updated_at']], fn (mixed $v): bool => $v !== '' && $v !== []);
    }

    /**
     * A folder the user sees, by its id or its exact name (case aside).
     *
     * @param list<array{id: int, name: string, description: string, audience: string, roles: list<int>, files: int}> $folders
     */
    private function internalFolder(mixed $wanted, array $folders): int
    {
        $wanted = is_scalar($wanted) ? trim((string) $wanted) : '';
        foreach ($folders as $f) {
            if ((string) $f['id'] === $wanted || mb_strtolower($f['name']) === mb_strtolower($wanted)) {
                return $f['id'];
            }
        }

        throw new \InvalidArgumentException($wanted === '' ? 'Give the folder (id or name, list_internal_files).' : 'No folder "' . mb_substr($wanted, 0, 60) . '" that this user sees. Use list_internal_files.');
    }

    /** Internal files need the section (administrators always have it). */
    private function internalFilesAccess(): void
    {
        if (!InternalFiles::mayOpen($this->app)) {
            throw new \DomainException('Only a user who may open Internal files can use them – an administrator gives the section in Users or Roles.');
        }
    }
}
