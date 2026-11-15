<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Internal files (3.42): a document library for the team that is never public – price sheets, contracts, manuals,
 * templates, the onboarding pack. Folders, files with a description and tags, versions (replacing a file keeps the
 * older ones), a search by name, description and tags.
 *
 *  - Never public: the files live in storage/internal-files/YYYY/MM/<random>.bin (deny-all .htaccess there and in
 *    storage/, nginx.example.conf denies storage/), never under media/, and the rows in ka_internal_folders,
 *    ka_internal_files and ka_internal_file_versions. No page, search, sitemap, llms.txt, site export, blueprint, page
 *    package, heartbeat, monthly report or media picker reads them – a unit test keeps the tables inside the allowed
 *    classes. The only way out is the admin action download (signed in, with the section and the folder: attachment,
 *    no-store, nosniff) and the MCP tools, which never pass the bytes – only the text of a small text document.
 *  - Who: the section internal_files (granted explicitly – after the update only administrators have it, others get it in
 *    Users or Roles). A folder is for everyone with the section (audience all), only for chosen custom roles (audience
 *    roles) or only for chosen departments and the departments below them (audience depts, Core\Departments; the ids are
 *    stored as d<id> in the roles column); administrators always see every folder – audienceMatches() is the one rule.
 *    Folders are created, changed and deleted by administrators; files are uploaded, replaced and described by anyone who
 *    sees the folder; a file is deleted by an administrator or the person who uploaded it.
 *  - Uploads: the attachments of Media (Files::FILE_EXTENSIONS without fonts), images, Markdown and SVG (cleaned by
 *    Svg::sanitize, refused when it is no drawing); content that says HTML, PHP, a script or a program is refused whatever
 *    the extension (Files::forbiddenContent); at most MAX_BYTES and the server's upload limit.
 *  - Status draft: a document a Claude connection limited to drafts wrote; a person marks it ready.
 *  - Company documents, not personal data: they are not part of the personal data search or the retentions; never
 *    journaled for undo (AgentJournal::PERSONAL_TABLES).
 */
final class InternalFiles
{
    /** The admin section (Admin\Modules\InternalFiles) and the permission row. */
    public const string SECTION = 'internal_files';

    /** Where the files are on disk – outside the web root's public paths. */
    public const string FOLDER = KALETA_ROOT . '/storage/internal-files';

    /** The largest file (bytes); the server's upload limit may be lower (Files::limit). */
    public const int MAX_BYTES = 100 * 1024 * 1024;

    /** The most text get_internal_file returns of a text document (bytes). */
    public const int MAX_TEXT = 200_000;

    /** Rows per page of the list. */
    public const int PAGE = 50;

    public const array AUDIENCES = ['all' => 'Everyone with Internal files', 'roles' => 'Only chosen roles', 'depts' => 'Only chosen departments'];

    public const array STATUSES = ['ready' => 'Ready', 'draft' => 'Draft'];

    /** Text documents: their content may be read over MCP and written as a new document or version. */
    public const array TEXT_EXTENSIONS = ['txt', 'md', 'csv'];

    /** Allowed extensions => the Content-Type of the download. */
    public const array TYPES = [
        'pdf' => 'application/pdf', 'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint', 'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'odt' => 'application/vnd.oasis.opendocument.text', 'ods' => 'application/vnd.oasis.opendocument.spreadsheet', 'odp' => 'application/vnd.oasis.opendocument.presentation',
        'rtf' => 'application/rtf', 'txt' => 'text/plain; charset=utf-8', 'md' => 'text/markdown; charset=utf-8', 'csv' => 'text/csv; charset=utf-8',
        'zip' => 'application/zip', 'epub' => 'application/epub+zip', 'gpx' => 'application/gpx+xml', 'ics' => 'text/calendar; charset=utf-8',
        'mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'ogg' => 'audio/ogg', 'wav' => 'audio/wav', 'mp4' => 'video/mp4', 'webm' => 'video/webm',
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif', 'svg' => 'image/svg+xml',
    ];

    /** Image extensions: the content must be an image of that kind. */
    private const array IMAGE_TYPES = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'];

    /** May the signed-in user open Internal files (administrators always)? */
    public static function mayOpen(App $app): bool
    {
        return $app->auth()->hasModule(self::SECTION);
    }

    /**
     * Does a folder's audience include the user? The one rule of who sees a folder: an administrator always; audience
     * all = everyone with the section; audience roles = the user's custom role (ka_uzivatele.role) is one of the folder's;
     * audience depts = the user belongs to one of the folder's departments or a department below it (needs the database –
     * without it nobody but an administrator matches).
     *
     * @param array<string, mixed> $folder a row of ka_internal_folders
     * @param array<string, mixed>|null $user a row of ka_uzivatele
     */
    public static function audienceMatches(array $folder, ?array $user, ?Db $db = null): bool
    {
        if ($user === null) {
            return false;
        }
        if ((int) ($user['admin'] ?? -1) === Auth::ADMIN) {
            return true;
        }
        $audience = (string) ($folder['audience'] ?? 'all');
        if ($audience === 'depts') {
            $userId = (int) ($user['idu'] ?? 0);
            if ($db === null || $userId <= 0) {
                return false;
            }
            foreach (self::departmentIds((string) ($folder['roles'] ?? '')) as $department) {
                if (Departments::userIn($db, $userId, $department)) {
                    return true;
                }
            }

            return false;
        }
        if ($audience !== 'roles') {
            return true;
        }
        $role = (int) ($user['role'] ?? 0);

        return $role > 0 && in_array($role, self::roleIds((string) ($folder['roles'] ?? '')), true);
    }

    /**
     * The role ids of a folder's roles column (pure).
     *
     * @return list<int>
     */
    public static function roleIds(string $roles): array
    {
        return array_values(array_unique(array_filter(array_map('intval', explode(',', $roles)), fn (int $id): bool => $id > 0)));
    }

    /**
     * The department ids of a folder's roles column – the d<id> items (pure).
     *
     * @return list<int>
     */
    public static function departmentIds(string $roles): array
    {
        $out = [];
        foreach (explode(',', $roles) as $item) {
            if (preg_match('/^d([1-9][0-9]*)$/', trim($item), $m) === 1 && !in_array((int) $m[1], $out, true)) {
                $out[] = (int) $m[1];
            }
        }

        return $out;
    }

    /**
     * Tags as stored: trimmed, without duplicates (case aside), at most 20 of at most 40 characters (pure).
     *
     * @return list<string>
     */
    public static function tags(string|array $tags): array
    {
        $out = [];
        foreach (is_array($tags) ? $tags : explode(',', $tags) as $tag) {
            $tag = mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F,]+/u', ' ', is_scalar($tag) ? (string) $tag : '')), 0, 40);
            if ($tag !== '' && !in_array(mb_strtolower($tag), array_map('mb_strtolower', $out), true)) {
                $out[] = $tag;
            }
        }

        return array_slice($out, 0, 20);
    }

    /** A shown file name: no path, no control characters, at most 200 characters (pure). */
    public static function safeName(string $name): string
    {
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F\/\\\\]+/u', ' ', $name));
        $name = trim((string) preg_replace('/\s+/u', ' ', $name), " .");

        return mb_substr($name, 0, 200);
    }

    /** The extension of a name as allowed here, or '' (pure). */
    public static function extension(string $name): string
    {
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        return isset(self::TYPES[$extension]) ? $extension : '';
    }

    /** The Content-Type of a download (pure); an unknown extension is a plain download. */
    public static function contentType(string $extension): string
    {
        return self::TYPES[strtolower($extension)] ?? 'application/octet-stream';
    }

    /**
     * Checks a file before it is stored: the extension, the size and the content. Returns the extension and, for an SVG,
     * the drawing cleaned by Svg::sanitize (stored instead of the upload; null = store the file as it is) – or the English
     * refusal (translated by the caller).
     *
     * @return array{extension: string, content: ?string}|string
     */
    public static function check(string $path, string $originalName): array|string
    {
        $extension = self::extension($originalName);
        if ($extension === '') {
            return 'This file type cannot be stored in Internal files.';
        }
        if (!is_file($path)) {
            return 'The file could not be uploaded.';
        }
        $size = (int) filesize($path);
        if ($size > self::MAX_BYTES) {
            return 'The file is too large (100 MB at most).';
        }
        if ($extension === 'svg') {
            try {
                $clean = $size > 2_000_000 ? null : Svg::sanitize((string) file_get_contents($path));
            } catch (HtmlTooLarge) {
                $clean = null;
            }

            return $clean === null ? 'The SVG file is not a drawing – it cannot be stored.' : ['extension' => 'svg', 'content' => $clean];
        }
        if (Files::forbiddenContent($path)) {
            return 'The file content does not match its extension.';
        }
        if (isset(self::IMAGE_TYPES[$extension]) && strtolower((string) (new \finfo(FILEINFO_MIME_TYPE))->file($path)) !== self::IMAGE_TYPES[$extension]) {
            return 'The file content does not match its extension.';
        }
        if (in_array($extension, self::TEXT_EXTENSIONS, true) && $size <= 5_000_000 && !mb_check_encoding((string) file_get_contents($path), 'UTF-8')) {
            return 'A text document must be in UTF-8.';
        }

        return ['extension' => $extension, 'content' => null];
    }

    /**
     * The folders the signed-in user sees, A–Z, with the number of files in each.
     *
     * @return list<array{id: int, name: string, description: string, audience: string, roles: list<int>, departments: list<int>, files: int}>
     */
    public static function folders(App $app): array
    {
        if (!self::mayOpen($app)) {
            return [];
        }
        $user = $app->auth()->user();
        $out = [];
        foreach ($app->db()->all('SELECT f.*, (SELECT COUNT(*) FROM {internal_files} x WHERE x.folder_id = f.id) AS files FROM {internal_folders} f ORDER BY f.name') as $row) {
            if (self::audienceMatches($row, $user, $app->db())) {
                $out[] = self::folderOut($row);
            }
        }

        return $out;
    }

    /**
     * One folder the user sees, or null (not there, or not for them).
     *
     * @return array{id: int, name: string, description: string, audience: string, roles: list<int>, departments: list<int>, files: int}|null
     */
    public static function folder(App $app, int $id): ?array
    {
        if ($id <= 0 || !self::mayOpen($app)) {
            return null;
        }
        $row = $app->db()->one('SELECT f.*, (SELECT COUNT(*) FROM {internal_files} x WHERE x.folder_id = f.id) AS files FROM {internal_folders} f WHERE f.id = ?', [$id]);

        return $row !== null && self::audienceMatches($row, $app->auth()->user(), $app->db()) ? self::folderOut($row) : null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, name: string, description: string, audience: string, roles: list<int>, departments: list<int>, files: int}
     */
    private static function folderOut(array $row): array
    {
        return ['id' => (int) $row['id'], 'name' => (string) $row['name'], 'description' => (string) $row['description'],
            'audience' => in_array((string) $row['audience'], ['roles', 'depts'], true) ? (string) $row['audience'] : 'all', 'roles' => self::roleIds((string) $row['roles']),
            'departments' => self::departmentIds((string) $row['roles']), 'files' => (int) ($row['files'] ?? 0)];
    }

    /**
     * The custom roles a folder may be limited to (ka_role): id => name, A–Z.
     *
     * @return array<int, string>
     */
    public static function roles(Db $db): array
    {
        $out = [];
        foreach ($db->all('SELECT idr, nazev FROM {role} ORDER BY nazev') as $r) {
            $out[(int) $r['idr']] = (string) $r['nazev'];
        }

        return $out;
    }

    /**
     * The departments a folder may be limited to (Core\Departments, a tree): id => name indented by its depth.
     *
     * @return array<int, string>
     */
    public static function departments(Db $db): array
    {
        $out = [];
        foreach (Departments::tree($db) as $d) {
            $out[$d['id']] = str_repeat('– ', $d['depth']) . $d['name'];
        }

        return $out;
    }

    /**
     * Creates or changes a folder (administrators only). Returns the folder or the English refusal.
     *
     * @param array<string, mixed> $data name, description, audience, roles and departments (lists of ids or comma-separated texts)
     * @return array{id: int, name: string, description: string, audience: string, roles: list<int>, departments: list<int>, files: int}|string
     */
    public static function saveFolder(App $app, array $data, int $id = 0): array|string
    {
        if (!$app->auth()->isAdmin()) {
            return 'Only an administrator creates and changes folders – they decide who sees them.';
        }
        $db = $app->db();
        if ($id > 0 && $db->value('SELECT 1 FROM {internal_folders} WHERE id = ?', [$id]) === null) {
            return 'The folder does not exist.';
        }
        $name = mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) ($data['name'] ?? ''))), 0, 120);
        if ($name === '') {
            return 'Give the folder a name.';
        }
        if ($db->value('SELECT 1 FROM {internal_folders} WHERE name = ? AND id <> ?', [$name, $id]) !== null) {
            return 'A folder of that name already exists.';
        }
        $audience = in_array((string) ($data['audience'] ?? 'all'), ['roles', 'depts'], true) ? (string) $data['audience'] : 'all';
        $list = fn (mixed $value): string => is_array($value) ? implode(',', array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $value)) : (is_scalar($value) ? (string) $value : '');
        $roles = array_values(array_intersect(self::roleIds($list($data['roles'] ?? '')), array_keys(self::roles($db))));
        if ($audience === 'roles' && $roles === []) {
            return 'Choose at least one role for a folder limited to roles.';
        }
        $departments = array_values(array_intersect(self::roleIds($list($data['departments'] ?? '')), array_keys(self::departments($db))));
        if ($audience === 'depts' && $departments === []) {
            return 'Choose at least one department for a folder limited to departments.';
        }
        $stored = match ($audience) {
            'roles' => implode(',', $roles),
            'depts' => implode(',', array_map(fn (int $id): string => 'd' . $id, $departments)),
            default => '',
        };
        $row = ['name' => $name, 'description' => mb_substr(trim((string) ($data['description'] ?? '')), 0, 500), 'audience' => $audience,
            'roles' => $stored, 'updated_at' => Clock::now()];
        if ($id > 0) {
            $db->update('internal_folders', $row, ['id' => $id]);
        } else {
            $id = $db->insert('internal_folders', $row + ['created_by' => $app->auth()->id() ?: null, 'created_at' => Clock::now()]);
        }

        return self::folder($app, $id) ?? 'The folder does not exist.';
    }

    /** Deletes an empty folder (administrators only); returns the English refusal or null. */
    public static function deleteFolder(App $app, int $id): ?string
    {
        if (!$app->auth()->isAdmin()) {
            return 'Only an administrator deletes folders.';
        }
        $db = $app->db();
        if ($db->value('SELECT 1 FROM {internal_folders} WHERE id = ?', [$id]) === null) {
            return 'The folder does not exist.';
        }
        if ($db->value('SELECT 1 FROM {internal_files} WHERE folder_id = ? LIMIT 1', [$id]) !== null) {
            return 'The folder is not empty – move or delete its files first.';
        }
        $db->delete('internal_folders', ['id' => $id]);

        return null;
    }

    /**
     * The files of the folders the user sees: by a folder, a word (name, description, tags) and a tag; newest change first.
     *
     * @param array{folder?: int, search?: string, tag?: string, status?: string} $query
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public static function files(App $app, array $query, int $page = 1, int $perPage = self::PAGE): array
    {
        $folders = array_column(self::folders($app), 'id');
        $folder = (int) ($query['folder'] ?? 0);
        if ($folder > 0) {
            $folders = in_array($folder, $folders, true) ? [$folder] : [];
        }
        if ($folders === []) {
            return ['rows' => [], 'total' => 0];
        }
        $where = ['f.folder_id IN (' . implode(',', array_map('intval', $folders)) . ')'];
        $params = [];
        $search = mb_substr(trim((string) ($query['search'] ?? '')), 0, 100);
        if ($search !== '') {
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $where[] = '(f.name LIKE ? OR f.description LIKE ? OR f.tags LIKE ?)';
            array_push($params, $like, $like, $like);
        }
        $tag = trim((string) ($query['tag'] ?? ''));
        if ($tag !== '') {
            $where[] = 'CONCAT(\',\', f.tags, \',\') LIKE ?';
            $params[] = '%,' . addcslashes($tag, '%_\\') . ',%';
        }
        if (isset(self::STATUSES[(string) ($query['status'] ?? '')])) {
            $where[] = 'f.status = ?';
            $params[] = (string) $query['status'];
        }
        $condition = ' WHERE ' . implode(' AND ', $where);
        $db = $app->db();
        $total = (int) $db->value('SELECT COUNT(*) FROM {internal_files} f' . $condition, $params);
        $perPage = max(1, min(200, $perPage));
        $rows = $db->all('SELECT f.*, d.name AS folder_name, u.jmeno AS updated_by_name FROM {internal_files} f JOIN {internal_folders} d ON d.id = f.folder_id LEFT JOIN {uzivatele} u ON u.idu = f.updated_by' . $condition
            . ' ORDER BY f.updated_at DESC, f.id DESC LIMIT ' . $perPage . ' OFFSET ' . ((max(1, $page) - 1) * $perPage), $params);

        return ['rows' => array_map(self::fileOut(...), $rows), 'total' => $total];
    }

    /**
     * One file the user may see (its folder is for them), with its versions newest first, or null.
     *
     * @return array<string, mixed>|null
     */
    public static function file(App $app, int $id): ?array
    {
        if ($id <= 0 || !self::mayOpen($app)) {
            return null;
        }
        $db = $app->db();
        $row = $db->one('SELECT f.*, d.name AS folder_name, d.audience, d.roles, u.jmeno AS updated_by_name, c.jmeno AS created_by_name FROM {internal_files} f JOIN {internal_folders} d ON d.id = f.folder_id'
            . ' LEFT JOIN {uzivatele} u ON u.idu = f.updated_by LEFT JOIN {uzivatele} c ON c.idu = f.created_by WHERE f.id = ?', [$id]);
        if ($row === null || !self::audienceMatches($row, $app->auth()->user(), $app->db())) {
            return null;
        }
        $file = self::fileOut($row);
        $file['created_by_name'] = (string) ($row['created_by_name'] ?? '');
        $file['versions'] = array_map(fn (array $v): array => ['version' => (int) $v['version'], 'original_name' => (string) $v['original_name'], 'extension' => (string) $v['extension'],
            'size' => (int) $v['size'], 'sha256' => (string) $v['sha256'], 'uploaded_by' => (string) ($v['uploaded_by_name'] ?? ''), 'uploaded_at' => (string) $v['uploaded_at'], 'path' => (string) $v['path']],
            $db->all('SELECT v.*, u.jmeno AS uploaded_by_name FROM {internal_file_versions} v LEFT JOIN {uzivatele} u ON u.idu = v.uploaded_by WHERE v.file_id = ? ORDER BY v.version DESC', [$id]));

        return $file;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function fileOut(array $row): array
    {
        return ['id' => (int) $row['id'], 'folder_id' => (int) $row['folder_id'], 'folder_name' => (string) ($row['folder_name'] ?? ''), 'name' => (string) $row['name'],
            'description' => (string) ($row['description'] ?? ''), 'tags' => self::tags((string) $row['tags']), 'status' => (string) $row['status'] === 'draft' ? 'draft' : 'ready',
            'extension' => (string) $row['extension'], 'size' => (int) $row['size'], 'version' => (int) $row['version'], 'created_by' => (int) ($row['created_by'] ?? 0),
            'created_at' => (string) $row['created_at'], 'updated_by_name' => (string) ($row['updated_by_name'] ?? ''), 'updated_at' => (string) $row['updated_at']];
    }

    /** May the user delete the file: an administrator, or the person who uploaded it? */
    public static function mayDelete(App $app, array $file): bool
    {
        return $app->auth()->isAdmin() || ($app->auth()->id() > 0 && (int) $file['created_by'] === $app->auth()->id());
    }

    /**
     * Stores a new file in a folder the user sees: checked (check()), written under a random name, version 1.
     *
     * @param array<string, mixed> $meta name (default the original name), description, tags, status
     * @return array<string, mixed>|string the file (file()) or the English refusal
     */
    public static function store(App $app, int $folderId, string $path, string $originalName, array $meta = []): array|string
    {
        if (self::folder($app, $folderId) === null) {
            return 'Choose a folder you can see.';
        }
        $checked = self::check($path, $originalName);
        if (is_string($checked)) {
            return $checked;
        }
        $name = self::nameWithExtension(self::safeName((string) ($meta['name'] ?? '')) ?: self::safeName($originalName), $checked['extension']);
        $disk = self::write($checked['content'], $path);
        if ($disk === null) {
            return 'The file could not be saved.';
        }
        $size = (int) filesize(self::FOLDER . '/' . $disk);
        $sha = (string) hash_file('sha256', self::FOLDER . '/' . $disk);
        $me = $app->auth()->id() ?: null;
        $now = Clock::now();
        $id = $app->db()->transaction(function (Db $db) use ($folderId, $name, $meta, $checked, $disk, $size, $sha, $me, $now, $originalName): int {
            $id = $db->insert('internal_files', ['folder_id' => $folderId, 'name' => $name, 'description' => mb_substr(trim((string) ($meta['description'] ?? '')), 0, 5000),
                'tags' => implode(',', self::tags($meta['tags'] ?? '')), 'status' => ($meta['status'] ?? 'ready') === 'draft' ? 'draft' : 'ready', 'extension' => $checked['extension'],
                'size' => $size, 'version' => 1, 'created_by' => $me, 'created_at' => $now, 'updated_by' => $me, 'updated_at' => $now]);
            $db->insert('internal_file_versions', ['file_id' => $id, 'version' => 1, 'path' => $disk, 'original_name' => self::safeName($originalName), 'extension' => $checked['extension'],
                'size' => $size, 'sha256' => $sha, 'uploaded_by' => $me, 'uploaded_at' => $now]);

            return $id;
        });

        return self::file($app, $id) ?? 'The file could not be saved.';
    }

    /**
     * Replaces a file with a new version; the older versions stay. A different extension renames the file's extension.
     *
     * @return array<string, mixed>|string the file or the English refusal
     */
    public static function replace(App $app, int $id, string $path, string $originalName): array|string
    {
        $file = self::file($app, $id);
        if ($file === null) {
            return 'The file does not exist.';
        }
        $checked = self::check($path, $originalName);
        if (is_string($checked)) {
            return $checked;
        }
        $disk = self::write($checked['content'], $path);
        if ($disk === null) {
            return 'The file could not be saved.';
        }
        $size = (int) filesize(self::FOLDER . '/' . $disk);
        $sha = (string) hash_file('sha256', self::FOLDER . '/' . $disk);
        $me = $app->auth()->id() ?: null;
        $now = Clock::now();
        $app->db()->transaction(function (Db $db) use ($file, $checked, $disk, $size, $sha, $me, $now, $originalName): void {
            $version = (int) $db->value('SELECT COALESCE(MAX(version), 0) FROM {internal_file_versions} WHERE file_id = ?', [$file['id']]) + 1;
            $db->insert('internal_file_versions', ['file_id' => $file['id'], 'version' => $version, 'path' => $disk, 'original_name' => self::safeName($originalName),
                'extension' => $checked['extension'], 'size' => $size, 'sha256' => $sha, 'uploaded_by' => $me, 'uploaded_at' => $now]);
            $db->update('internal_files', ['version' => $version, 'extension' => $checked['extension'], 'size' => $size,
                'name' => self::nameWithExtension((string) $file['name'], $checked['extension']), 'updated_by' => $me, 'updated_at' => $now], ['id' => $file['id']]);
        });

        return self::file($app, $id) ?? 'The file does not exist.';
    }

    /**
     * A text document from text (MCP save_internal_file): a new file, or a new version of an existing text document.
     *
     * @param array<string, mixed> $meta name, description, tags, status
     * @return array<string, mixed>|string
     */
    public static function writeText(App $app, int $folderId, int $fileId, string $text, array $meta = []): array|string
    {
        if (strlen($text) > self::MAX_TEXT) {
            return 'The text is too long (200 kB at most).';
        }
        if (!mb_check_encoding($text, 'UTF-8')) {
            return 'A text document must be in UTF-8.';
        }
        $name = self::safeName((string) ($meta['name'] ?? ''));
        if ($fileId > 0) {
            $file = self::file($app, $fileId);
            if ($file === null) {
                return 'The file does not exist.';
            }
            if (!in_array($file['extension'], self::TEXT_EXTENSIONS, true)) {
                return 'Only a text document (txt, md, csv) gets new text – replace other files in the administration.';
            }
            $name = (string) $file['name'];
        } elseif ($name === '') {
            return 'Give the document a name.';
        }
        $extension = self::extension($name);
        if (!in_array($extension, self::TEXT_EXTENSIONS, true)) {
            $name .= '.md';
        }
        $temp = tempnam(sys_get_temp_dir(), 'kif');
        if ($temp === false || file_put_contents($temp, $text) === false) {
            return 'The file could not be saved.';
        }
        try {
            return $fileId > 0 ? self::replace($app, $fileId, $temp, $name) : self::store($app, $folderId, $temp, $name, ['name' => $name] + $meta);
        } finally {
            @unlink($temp);
        }
    }

    /**
     * Changes the name, description, tags, folder or status of a file the user sees (the target folder too).
     *
     * @param array<string, mixed> $data only the keys given change
     * @return array<string, mixed>|string the file or the English refusal
     */
    public static function update(App $app, int $id, array $data): array|string
    {
        $file = self::file($app, $id);
        if ($file === null) {
            return 'The file does not exist.';
        }
        $row = [];
        if (array_key_exists('name', $data)) {
            $name = self::safeName((string) $data['name']);
            if ($name === '') {
                return 'Give the file a name.';
            }
            $row['name'] = self::nameWithExtension($name, (string) $file['extension']);
        }
        if (array_key_exists('description', $data)) {
            $row['description'] = mb_substr(trim((string) $data['description']), 0, 5000);
        }
        if (array_key_exists('tags', $data)) {
            $row['tags'] = implode(',', self::tags(is_array($data['tags']) ? $data['tags'] : (string) $data['tags']));
        }
        if (array_key_exists('status', $data)) {
            if (!isset(self::STATUSES[(string) $data['status']])) {
                return 'The status must be ready or draft.';
            }
            $row['status'] = (string) $data['status'];
        }
        if (array_key_exists('folder_id', $data) && (int) $data['folder_id'] !== $file['folder_id']) {
            if (self::folder($app, (int) $data['folder_id']) === null) {
                return 'Choose a folder you can see.';
            }
            $row['folder_id'] = (int) $data['folder_id'];
        }
        if ($row !== []) {
            $app->db()->update('internal_files', $row + ['updated_by' => $app->auth()->id() ?: null, 'updated_at' => Clock::now()], ['id' => $id]);
        }

        return self::file($app, $id) ?? 'The file does not exist.';
    }

    /** Deletes a file with every version and its files on disk (an administrator or who uploaded it). */
    public static function delete(App $app, int $id): ?string
    {
        $file = self::file($app, $id);
        if ($file === null) {
            return 'The file does not exist.';
        }
        if (!self::mayDelete($app, $file)) {
            return 'Only an administrator or the person who uploaded the file can delete it.';
        }
        $db = $app->db();
        $db->transaction(function (Db $db) use ($id): void {
            $db->delete('internal_file_versions', ['file_id' => $id]);
            $db->delete('internal_files', ['id' => $id]);
        });
        foreach ($file['versions'] as $version) {
            $disk = self::diskPath((string) $version['path']);
            if ($disk !== null && is_file($disk)) {
                @unlink($disk);
            }
        }

        return null;
    }

    /**
     * A version of a file the user sees, for the download: the disk path, the Content-Type and the name; null when the
     * file, the version or its bytes are not there. $version 0 = the current one.
     *
     * @return array{path: string, type: string, name: string}|null
     */
    public static function download(App $app, int $id, int $version = 0): ?array
    {
        $file = self::file($app, $id);
        if ($file === null) {
            return null;
        }
        foreach ($file['versions'] as $v) {
            if ($v['version'] === ($version > 0 ? $version : $file['version'])) {
                $disk = self::diskPath($v['path']);
                if ($disk === null || !is_file($disk)) {
                    return null;
                }
                $base = pathinfo((string) $file['name'], PATHINFO_FILENAME);

                return ['path' => $disk, 'type' => self::contentType($v['extension']), 'name' => ($v['version'] === $file['version'] ? $base : $base . ' (v' . $v['version'] . ')') . '.' . $v['extension']];
            }
        }

        return null;
    }

    /** The text of a file's current version when it is a text document of at most MAX_TEXT bytes, else null. */
    public static function text(array $file): ?string
    {
        if (!in_array((string) $file['extension'], self::TEXT_EXTENSIONS, true) || (int) $file['size'] > self::MAX_TEXT) {
            return null;
        }
        foreach ($file['versions'] ?? [] as $v) {
            if ($v['version'] === $file['version']) {
                $disk = self::diskPath((string) $v['path']);
                $text = $disk !== null && is_file($disk) ? file_get_contents($disk) : false;

                return is_string($text) && mb_check_encoding($text, 'UTF-8') ? $text : null;
            }
        }

        return null;
    }

    /** The disk path of a stored path, or null when it is not one this class writes (pure apart from the constant). */
    public static function diskPath(string $path): ?string
    {
        return preg_match('#^\d{4}/\d{2}/[a-f0-9]{32}\.bin$#D', $path) === 1 ? self::FOLDER . '/' . $path : null;
    }

    /** The name with the given extension (the old one replaced when it is an allowed one; pure). */
    public static function nameWithExtension(string $name, string $extension): string
    {
        $base = self::extension($name) !== '' ? pathinfo($name, PATHINFO_FILENAME) : $name;

        return mb_substr($base === '' ? 'file' : $base, 0, 190) . '.' . $extension;
    }

    /**
     * Writes a file under a new random name in this month's folder – the cleaned content when given, else a copy of the
     * source (written beside and renamed, so a half-written file never has the final name); returns the stored path or null.
     */
    private static function write(?string $content, string $source): ?string
    {
        $month = date('Y/m');
        $dir = self::FOLDER . '/' . $month;
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return null;
        }
        if (!is_file(self::FOLDER . '/.htaccess')) {
            @file_put_contents(self::FOLDER . '/.htaccess', "Require all denied\nDeny from all\n"); // storage/ has one too – a second line of defence
        }
        $path = $month . '/' . bin2hex(random_bytes(16)) . '.bin';
        $part = self::FOLDER . '/' . $path . '.part';
        $written = $content !== null ? file_put_contents($part, $content) === strlen($content) : @copy($source, $part);
        if (!$written || !rename($part, self::FOLDER . '/' . $path)) {
            @unlink($part);

            return null;
        }

        return $path;
    }
}
