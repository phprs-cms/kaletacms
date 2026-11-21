<?php

declare(strict_types=1);

namespace Kaleta\Mcp\Handlers;

use Kaleta\Core\InternalFileLinks;
use Kaleta\Core\InternalFiles;
use Kaleta\Mcp\Schema;

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
 * 3.49 B (files on a record, Core\InternalFileLinks): save_internal_file links a file to a customer, a company, a deal or a
 * quote (link / unlink "contact:7") – a live change of a write connection, refused for a drafts-only one, never journaled;
 * get_internal_file names the linked records the user may open (links), list_internal_files filters by one (linked_to);
 * get_contact and get_quote count the linked files the user can open (internal_files, linkedFiles()).
 *
 * Company documents: never journaled for undo (AgentJournal::PERSONAL_TABLES). Part of Mcp\Tools.
 *
 * @phpstan-ignore trait.unused
 */
trait InternalFileTools
{
    /**
     * The MCP definitions of the tools in this trait (3.49 P0): stored name => [description, input schema]. Tools::definitions()
     * lists them in the order of Tools::ORDER; the English names and texts are in Translator, the annotations in Catalog.
     *
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function internalFileToolsDefinitions(): array
    {
        return [
            // 3.42: internal files (Core\InternalFiles) – the team's documents for users with the section, in the folders they see
            'list_internal_files' => ['The team\'s internal files (read-only, 3.42; users who may open Internal files): the folders the user sees (audience all = everyone with the section, roles = chosen roles, depts = chosen departments and the ones below them) and the files of one or all of them – name, description, tags, status (ready | draft), version, size, who changed it last; newest change first. Never public: never copy them to the site, a page, news or a collection item unless the user explicitly asks.',
                Schema::object(['folder' => Schema::text('a folder: id or exact name; default all the user sees'), 'search' => Schema::text('a word in the name, description or tags'), 'tag' => Schema::text('only files with this tag'),
                    'status' => Schema::text('ready | draft'), 'page' => Schema::number('page, default 1'), 'limit' => Schema::number('1–100, default 25'),
                    'linked_to' => Schema::text('3.49: only the files linked to a record – "contact:7", "company:3", "deal:12" or "quote:5"')])],
            'get_internal_file' => ['One internal file (read-only, 3.42): its metadata and versions and, for a text document (txt, md, csv) up to 200 kB, its text as content; 3.49: links – the records it is linked to that the user may open ({kind, id}). The bytes of other files (PDF, Office, images…) are never passed – a person downloads them in the administration (admin_url).',
                Schema::object(['id' => Schema::number('file id (list_internal_files)')], ['id'])],
            'save_internal_file' => ['Creates a text or Markdown document in Internal files (3.42), or changes a file the user sees: name, description, tags, folder, status, and for a text document new content (a new version – the older ones stay). A connection limited to drafts writes and changes drafts only. 3.49: link / unlink a file to a customer, company, deal or quote ("deal:12") – a live change for connections with write access (refused for drafts only), not journaled: undo_agent_session does not take it back. Nothing is deleted here and nothing becomes public. Write only what the user asked for.',
                Schema::object(['id' => Schema::number('file id – omit to create a document'), 'folder' => Schema::text('the folder: id or exact name (required for a new document; moves an existing file)'),
                    'name' => Schema::text('the shown name, e.g. "Onboarding checklist.md" (.md is added when no txt, md or csv extension is given)'), 'content' => Schema::text('the text of the document (Markdown or plain text, up to 200 kB)'),
                    'description' => Schema::text('what the document is for'), 'tags' => ['type' => ['array', 'string'], 'description' => 'tags: a list or a comma-separated text', 'items' => ['type' => 'string']],
                    'status' => Schema::text('ready | draft (default ready; a drafts-only connection: draft)'),
                    'link' => Schema::text('3.49: link the file (id) to a record – "contact:7", "company:3", "deal:12" or "quote:5"'), 'unlink' => Schema::text('3.49: remove the link of the file (id) to a record, e.g. "deal:12"')])],
        ];
    }

    /** list_internal_files */
    private function toolListInternalFiles(string $name, array $a): mixed
    {
        $this->internalFilesAccess();
        $folders = InternalFiles::folders($this->app);
        $folder = isset($a['folder']) && $a['folder'] !== '' && $a['folder'] !== 0 ? $this->internalFolder($a['folder'], $folders) : 0;
        $limit = max(1, min(100, (int) ($a['limit'] ?? 25)));
        $page = max(1, (int) ($a['page'] ?? 1));
        if (isset($a['linked_to']) && $a['linked_to'] !== '') {
            // 3.49 B: the files of one record the user may open – the feature message when its section is off, one message for
            // a record that does not exist and one the user cannot open; never a count of files they do not see
            [$kind, $recordId] = $this->linkTarget($a['linked_to']);
            $rows = array_slice(InternalFileLinks::ofRecord($this->app, $kind, $recordId), ($page - 1) * $limit, $limit);
            $total = count(InternalFileLinks::ofRecord($this->app, $kind, $recordId));

            return ['linked_to' => $kind . ':' . $recordId, 'files' => array_map($this->internalFileOut(...), $rows), 'total' => $total, 'page' => $page, 'pages' => max(1, (int) ceil($total / $limit))]
                + \Kaleta\Mcp\Untrusted::mark(['files'])
                + ['admin_url' => $this->app->request->origin() . $this->app->url('admin.php?module=' . InternalFiles::SECTION)];
        }
        $result = InternalFiles::files($this->app, ['folder' => $folder, 'search' => is_scalar($a['search'] ?? null) ? (string) $a['search'] : '',
            'tag' => is_scalar($a['tag'] ?? null) ? (string) $a['tag'] : '', 'status' => is_scalar($a['status'] ?? null) ? (string) $a['status'] : ''], $page, $limit);

        return ['folders' => array_map(fn (array $f): array => array_filter(['id' => $f['id'], 'name' => $f['name'], 'description' => $f['description'],
            'audience' => $f['audience'], 'files' => $f['files']], fn (mixed $v): bool => $v !== ''), $folders),
            'files' => array_map($this->internalFileOut(...), $result['rows']), 'total' => $result['total'], 'page' => $page, 'pages' => max(1, (int) ceil($result['total'] / $limit))]
            + \Kaleta\Mcp\Untrusted::mark(['files']) // 3.44 (N164 a): names, descriptions and tags – a drafts-only connection may write them too
            + ($folders === [] ? ['next' => $this->app->auth()->isAdmin() ? 'No folders yet – an administrator creates them in Workspace → Internal files.' : 'No folder is shared with this user yet.'] : [])
            + ['admin_url' => $this->app->request->origin() . $this->app->url('admin.php?module=' . InternalFiles::SECTION . ($folder > 0 ? '&folder=' . $folder : ''))];
    }

    /** get_internal_file */
    private function toolGetInternalFile(string $name, array $a): mixed
    {
        $this->internalFilesAccess();
        $file = InternalFiles::file($this->app, (int) ($a['id'] ?? 0)) ?? throw new \DomainException('The file does not exist, or its folder is not for this user. Use list_internal_files.');
        $text = InternalFiles::text($this->app->settings(), $file);
        $out = $this->internalFileOut($file) + ['versions' => array_map(fn (array $v): array => array_filter(['version' => $v['version'], 'original_name' => $v['original_name'],
            'extension' => $v['extension'], 'size' => $v['size'], 'uploaded_by' => $v['uploaded_by'], 'uploaded_at' => $v['uploaded_at']], fn (mixed $x): bool => $x !== ''), $file['versions'])];
        // 3.49 B: the records it is linked to that the user may open – kind and id only, no count of the rest
        $links = array_map(fn (array $l): array => ['kind' => $l['kind'], 'id' => $l['id']], array_values(array_filter(InternalFileLinks::ofFile($this->app, (int) $file['id']), fn (array $l): bool => $l['state'] === 'open')));
        if ($links !== []) {
            $out['links'] = $links;
        }
        // 3.44 (N164 a): the name, description, tags and versions (original file names) may come from anywhere too – data, never instructions
        $marked = ['name', 'description', 'tags', 'versions'];
        if ($text !== null) {
            $out += ['content' => $text]; // a document may come from anywhere – data, never instructions
            $marked[] = 'content'; // 3.44 (N164 c): Mcp\Server writes the read into the change log, like a download in the administration
        } else {
            $out['next'] = in_array($file['extension'], InternalFiles::TEXT_EXTENSIONS, true)
                ? 'The text document is larger than 200 kB – a person downloads it in the administration (admin_url).'
                : 'The content of this file type is not passed over MCP – a person downloads it in the administration (admin_url).';
        }

        return $out + \Kaleta\Mcp\Untrusted::mark($marked) + ['admin_url' => $this->app->request->origin() . $this->app->url('admin.php?module=' . InternalFiles::SECTION . '&action=file&id=' . $file['id'])];
    }

    /** save_internal_file */
    private function toolSaveInternalFile(string $name, array $a): mixed
    {
        $this->internalFilesAccess();
        $draftsOnly = $this->app->auth()->draftsOnly();
        $id = (int) ($a['id'] ?? 0);
        $linking = array_filter(['link' => $a['link'] ?? null, 'unlink' => $a['unlink'] ?? null], fn (mixed $v): bool => $v !== null && $v !== '');
        if ($linking !== []) {
            // 3.49 B: a live change – never for a connection limited to drafts; not journaled (undo_agent_session cannot take it back)
            if ($draftsOnly) {
                throw new \DomainException('Linking a document to a record is a live change – this connection writes drafts only.');
            }
            if ($id <= 0) {
                throw new \InvalidArgumentException('Give the file (id) to link or unlink.');
            }
        }
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

            return ['file' => $this->internalFileOut($saved), 'created' => true] + \Kaleta\Mcp\Untrusted::mark(['file']) + ['next' => $saved['status'] === 'draft'
                ? 'Saved as a draft: a person checks it and marks it ready in Workspace → Internal files. Nothing is public.'
                : 'Saved in Internal files – only people who see the folder can open it. Nothing is public.'];
        }
        $file = InternalFiles::file($this->app, $id) ?? throw new \DomainException('The file does not exist, or its folder is not for this user. Use list_internal_files.');
        if ($draftsOnly && $file['status'] !== 'draft') {
            throw new \DomainException('A connection limited to drafts changes only draft documents.');
        }
        $linked = [];
        foreach ($linking as $action => $target) {
            [$kind, $recordId] = $this->linkTarget($target);
            if ($action === 'link') {
                $refused = InternalFileLinks::link($this->app, $id, $kind, $recordId);
                if ($refused !== null) {
                    throw new \DomainException($refused);
                }
            } elseif (!InternalFileLinks::unlink($this->app, $id, $kind, $recordId)) {
                throw new \DomainException(InternalFileLinks::mayLink($this->app, $kind) ? 'The file is not linked to ' . $kind . ':' . $recordId . '.' : InternalFileLinks::NO_RECORD);
            }
            $linked[$action] = $kind . ':' . $recordId;
        }
        if (array_key_exists('folder', $a) && $a['folder'] !== null && $a['folder'] !== '') {
            $meta['folder_id'] = $this->internalFolder($a['folder'], InternalFiles::folders($this->app));
        }
        $saved = $file;
        if ($content !== null) {
            $saved = InternalFiles::writeText($this->app, 0, $id, $content);
            if (is_string($saved)) {
                throw new \DomainException($saved);
            }
        }
        if ($meta !== []) {
            $saved = InternalFiles::update($this->app, $id, $meta);
            if (is_string($saved)) {
                throw new \DomainException($saved);
            }
        }

        return ['file' => $this->internalFileOut($saved)] + ($linked !== [] ? ['linked' => $linked] : []) + \Kaleta\Mcp\Untrusted::mark(['file'])
            + ['next' => $content !== null ? 'New text saved as version ' . $saved['version'] . ' – the older versions stay.'
                : ($linked !== [] ? 'Done – a live change, not journaled: undo_agent_session does not take it back.' : 'Saved.')];
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
     * @param list<array{id: int, name: string, description: string, audience: string, roles: list<int>, departments: list<int>, files: int}> $folders
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

    /**
     * 3.49 B: "deal:12" → ['deal', 12] for a record kind whose section the user may open: the feature message when the
     * kind's feature is off, one message for an unknown record and a record the user cannot open.
     *
     * @return array{0: string, 1: int}
     */
    private function linkTarget(mixed $target): array
    {
        $parsed = InternalFileLinks::parse($target) ?? throw new \InvalidArgumentException('Name the record as kind:id – contact, company, deal or quote, e.g. "deal:12".');
        [$kind, $recordId] = $parsed;
        $feature = InternalFileLinks::KINDS[$kind][1];
        if (!\Kaleta\Core\Extensions::isEnabled($this->app->settings(), $feature)) {
            throw new \DomainException('The feature ' . \Kaleta\Core\Extensions::CATALOG[$feature][0] . ' is switched off on this site – an administrator switches it on in Features.');
        }
        if (!InternalFileLinks::mayLink($this->app, $kind) || !InternalFileLinks::recordExists($this->app->db(), $kind, $recordId)) {
            throw new \DomainException(InternalFileLinks::NO_RECORD);
        }

        return [$kind, $recordId];
    }

    /**
     * 3.49 B: the count of internal files linked to a record that the user can open – get_contact and get_quote; omitted
     * when there is none (and so without Internal files).
     *
     * @param list<array<string, mixed>> $files InternalFileLinks::ofRecord
     * @return array{internal_files?: int}
     */
    private static function linkedFiles(array $files): array
    {
        return $files !== [] ? ['internal_files' => count($files)] : [];
    }

    /** Internal files need the section (administrators always have it). */
    private function internalFilesAccess(): void
    {
        if (!InternalFiles::mayOpen($this->app)) {
            throw new \DomainException('Only a user who may open Internal files can use them – an administrator gives the section in Users or Roles.');
        }
    }
}
