<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Requests to Claude (2.15): staff write in the administration what they need changed on the site – "change the opening
 * hours on Monday", "add this PDF to the price list page" – instead of e-mailing the agency. Claude reads them over MCP
 * (list_requests), does the work as drafts, and answers with a note and links to the drafts (update_request); the
 * requester reads the notes in the detail and replies there; a person publishes.
 *
 *  - A request's text was written by a staff member: for Claude it is a job to do as drafts the user will review, never
 *    permission to publish or to skip a confirmation (the tool descriptions and the work_requests prompt say so).
 *  - Attachments (3.46, owner decision 5): kept private and sealed at rest (Core\SealedFiles, the Vault purpose "requests")
 *    in storage/requests/, never under the public media/ – the column files holds the sealed list with each file's own key.
 *    Claude reads them over MCP (list_requests, files) and puts one into Media only when it places it on the site
 *    (update_request place_files – placeFile()); the request then names the Media file too. Requests from before 3.46 keep
 *    their Media uploads (the column attachments, ids of ka_media).
 *  - Administrators hear about a new request by e-mail – the subject and the title, nothing else; the requester hears
 *    by e-mail when it is marked done, with Claude's note. The event request.created is recorded.
 *  - Not in the site export: a request is work for the team, not content of the site.
 */
final class Requests
{
    /** Status => label (admin texts, translated with t()). */
    public const array STATUSES = ['new' => 'New', 'in_progress' => 'In progress', 'done' => 'Done', 'declined' => 'Declined'];

    /** From a status => the statuses it may move to. A done or declined request is reopened into "in progress". */
    public const array TRANSITIONS = ['new' => ['in_progress', 'done', 'declined'], 'in_progress' => ['done', 'declined'], 'done' => ['in_progress'], 'declined' => ['in_progress']];

    /** Open statuses first in the list, newest first within a status. */
    public const array ORDER = ['new' => 0, 'in_progress' => 1, 'done' => 2, 'declined' => 3];

    public const int MAX_ATTACHMENTS = 5;
    public const int MAX_TEXT = 10000;
    public const int MAX_MESSAGE = 10000;
    public const int MAX_LINKS = 20;

    /** Where the private attachments are (3.46) – outside the web root's public paths, deny-all. */
    public const string FOLDER = KALETA_ROOT . '/storage/requests';

    /** The Vault purpose of the sealed attachments and of their list in the column files (3.46). */
    public const string FILE_PURPOSE = 'requests';

    /** The extensions of a private attachment that Media shows as an image. */
    private const array IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg'];

    /** May a request move from one status to another? The same status is not a move. */
    public static function canMove(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /**
     * What a request is about, as stored: page:<ids>, news:<idc>, item:<idp> (chosen from the select), or a pasted address –
     * a path on the site or an http(s) URL. Anything else is dropped.
     */
    public static function cleanAbout(string $about): string
    {
        $about = trim($about);
        if (preg_match('/^(page|news|item):[1-9]\d{0,9}$/D', $about)) {
            return $about;
        }
        if ($about !== '' && mb_strlen($about) <= 500 && !preg_match('/[\s<>"\'\\\\]/', $about) && (str_starts_with($about, '/') || preg_match('#^https?://[^/]+#i', $about))) {
            return $about;
        }

        return '';
    }

    /**
     * The stored "about" explained: its kind, id, title and address on the site.
     *
     * @return array{type: string, id: ?int, title: string, url: string}|null
     */
    public static function describeAbout(App $app, string $about): ?array
    {
        if ($about === '') {
            return null;
        }
        $db = $app->db();
        $site = rtrim($app->settings()->get('site_url') ?: $app->request->origin(), '/');
        if (preg_match('/^(page|news|item):(\d+)$/D', $about, $m)) {
            $id = (int) $m[2];
            [$title, $url] = match ($m[1]) {
                'page' => (($r = $db->one('SELECT titulek, seo_link FROM {stranky} WHERE ids = ? AND smazano IS NULL', [$id])) !== null ? [(string) $r['titulek'], $app->url((string) $r['seo_link'])] : ['', '']),
                'news' => (($r = $db->one('SELECT titulek, seo_link, jazyk FROM {novinky} WHERE idc = ? AND smazano IS NULL', [$id])) !== null ? [(string) $r['titulek'], $app->newsItemUrl((string) $r['seo_link'], (string) $r['jazyk'])] : ['', '']),
                default => (($r = $db->one('SELECT p.nazev, p.seo_link, k.seo_link AS kolekce FROM {kolekce_polozky} p JOIN {kolekce} k ON k.idk = p.idk WHERE p.idp = ? AND p.smazano IS NULL', [$id])) !== null
                    ? [(string) $r['nazev'], $app->url((string) $r['kolekce'] . '/' . (string) $r['seo_link'])] : ['', '']),
            };

            return ['type' => $m[1], 'id' => $id, 'title' => $title !== '' ? $title : t('(no longer exists)'), 'url' => $url !== '' ? $site . $url : ''];
        }

        return ['type' => 'url', 'id' => null, 'title' => $about, 'url' => str_starts_with($about, '/') ? $site . $about : $about];
    }

    /**
     * Saves a new request, notifies the administrators and records the event. Returns its id.
     *
     * @param list<int> $attachments ids of Media uploads (at most MAX_ATTACHMENTS; unknown ids are dropped)
     * @param list<array<string, mixed>> $files private attachments from storeFile() (3.46; at most MAX_ATTACHMENTS)
     * @throws \DomainException with the reason the request is not saved (an admin text)
     */
    public static function create(App $app, int $authorId, string $title, string $text, string $about, array $attachments, array $files = []): int
    {
        $title = mb_substr(trim(preg_replace('/\s+/', ' ', $title) ?? ''), 0, 190);
        $text = trim(str_replace("\r\n", "\n", $text));
        if ($title === '') {
            throw new \DomainException('Give the request a title.');
        }
        if ($text === '') {
            throw new \DomainException('Write what should change.');
        }
        $db = $app->db();
        $ids = array_values(array_unique(array_filter(array_map('intval', $attachments), fn (int $id): bool => $id > 0)));
        $ids = $ids === [] ? [] : array_map('intval', array_column($db->all('SELECT ido FROM {media} WHERE ido IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY ido', $ids), 'ido'));
        $now = Clock::now();
        $id = $db->insert('requests', ['created_at' => $now, 'updated_at' => $now, 'author_id' => $authorId, 'title' => $title, 'text' => mb_substr($text, 0, self::MAX_TEXT),
            'about' => self::cleanAbout($about), 'attachments' => (string) json_encode(array_slice($ids, 0, self::MAX_ATTACHMENTS)), 'status' => 'new',
            'files' => self::encodeFiles($app->settings(), array_slice($files, 0, self::MAX_ATTACHMENTS))]);
        // the event and the e-mail carry the id and the title – the text waits in the administration
        Events::record($db, 'request.created', 'info', t('A new request for Claude: %s', $title), ['id' => $id, 'title' => $title]);
        self::notifyAdministrators($app, $id, $title, $authorId);

        return $id;
    }

    /**
     * Requests, open first (new, in progress, done, declined), newest first within a status.
     *
     * @return list<array<string, mixed>> rows with author (name) and attachment ids decoded
     */
    public static function all(App $app, string $status = '', int $limit = 100): array
    {
        $where = isset(self::STATUSES[$status]) ? 'WHERE r.status = ?' : '';
        $rows = $app->db()->all("SELECT r.*, IF(u.jmeno = '' OR u.jmeno IS NULL, IFNULL(u.user, ''), u.jmeno) AS author FROM {requests} r LEFT JOIN {uzivatele} u ON u.idu = r.author_id {$where}"
            . ' ORDER BY FIELD(r.status, ' . implode(', ', array_map(fn (string $s): string => "'" . $s . "'", array_keys(self::ORDER))) . '), r.id DESC LIMIT ' . max(1, min(500, $limit)), isset(self::STATUSES[$status]) ? [$status] : []);

        return array_map(fn (array $row): array => self::decode($app->settings(), $row), $rows);
    }

    /** @return array<string, mixed>|null the request with its author's name */
    public static function get(App $app, int $id): ?array
    {
        $row = $app->db()->one("SELECT r.*, IF(u.jmeno = '' OR u.jmeno IS NULL, IFNULL(u.user, ''), u.jmeno) AS author, u.email AS author_email, u.jazyk AS author_language, u.register AS author_register FROM {requests} r LEFT JOIN {uzivatele} u ON u.idu = r.author_id WHERE r.id = ?", [$id]);

        return $row === null ? null : self::decode($app->settings(), $row);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function decode(Settings $s, array $row): array
    {
        $ids = json_decode((string) $row['attachments'], true);
        $row['attachments'] = is_array($ids) ? array_values(array_filter(array_map('intval', $ids), fn (int $id): bool => $id > 0)) : [];
        $row['files_stored'] = $row['files'] ?? null; // the sealed list as stored – placeFile() writes only over the same one
        $row['files'] = self::files($s, $row['files'] ?? null);
        $row['author'] = (string) ($row['author'] ?? '') !== '' ? (string) $row['author'] : t('(account removed)');

        return $row;
    }

    /**
     * The attachments of a request as Media files: id, name, address and size – what Claude needs to put a file on the site.
     *
     * @param list<int> $ids
     * @return list<array{id: int, name: string, url: string, size: string, image: bool}>
     */
    public static function attachments(App $app, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $site = rtrim($app->settings()->get('site_url') ?: $app->request->origin(), '/');
        $rows = $app->db()->all('SELECT ido, nazev, obr_poloha, nahl_poloha, obr_vel FROM {media} WHERE ido IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY FIELD(ido, ' . implode(',', array_fill(0, count($ids), '?')) . ')', [...$ids, ...$ids]);

        return array_map(fn (array $m): array => ['id' => (int) $m['ido'], 'name' => $m['nazev'] !== '' ? (string) $m['nazev'] : basename((string) $m['obr_poloha']),
            'url' => $site . $app->url((string) $m['obr_poloha']), 'size' => Files::size((int) $m['obr_vel']), 'image' => $m['nahl_poloha'] !== ''], $rows);
    }

    /* ---------- private attachments (3.46, owner decision 5) ---------- */

    /**
     * Seals one uploaded file into FOLDER (checked as Internal files check theirs: the allowed types, the content must fit
     * the extension and never be HTML, PHP, a script or a program, an SVG is cleaned). Returns the entry for create(), or
     * the English reason it was refused.
     *
     * @param array<string, mixed> $upload one entry of Admin\Modules\Media::uploadedFiles() (name, tmp_name, error, size)
     * @return array<string, mixed>|string
     */
    public static function storeFile(Settings $s, array $upload): array|string
    {
        $tmp = (string) ($upload['tmp_name'] ?? '');
        $name = InternalFiles::safeName(basename((string) ($upload['name'] ?? '')));
        if ((int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp)) {
            return 'The file could not be uploaded.';
        }
        $checked = InternalFiles::check($tmp, $name);
        if (is_string($checked)) {
            return $checked;
        }
        $content = $checked['content'] ?? @file_get_contents($tmp);
        if (!is_string($content) || !SealedFiles::roomFor(strlen($content))) {
            return 'The file could not be saved.';
        }
        $path = date('Y/m') . '/' . bin2hex(random_bytes(16)) . '.bin';
        $key = SealedFiles::write($s, self::FILE_PURPOSE, $content, self::FOLDER . '/' . $path);
        if ($key === null) {
            return 'The file could not be saved.';
        }
        SealedFiles::protect(self::FOLDER);

        return ['name' => InternalFiles::nameWithExtension($name, $checked['extension']), 'ext' => $checked['extension'], 'size' => strlen($content), 'path' => $path, 'key' => $key];
    }

    /**
     * The private attachments of a request from the column files (sealed list): name, ext, size, path, key, and media (the
     * Media id once Claude placed it on the site). Unreadable (another key) = none.
     *
     * @return list<array<string, mixed>>
     */
    public static function files(Settings $s, mixed $stored): array
    {
        $json = is_string($stored) && $stored !== '' ? Vault::openFor($s, self::FILE_PURPOSE, $stored) : null;
        $list = $json !== null ? json_decode($json, true) : null;

        return is_array($list) ? array_values(array_filter($list, fn (mixed $f): bool => is_array($f) && is_string($f['path'] ?? null) && is_string($f['name'] ?? null))) : [];
    }

    /** @param list<array<string, mixed>> $files */
    private static function encodeFiles(Settings $s, array $files): ?string
    {
        return $files === [] ? null : Vault::seal(Vault::key($s, self::FILE_PURPOSE), (string) json_encode($files, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** The disk path of a private attachment's stored path, or null when it is not one this class writes (pure apart from the constant). */
    public static function filePath(string $path): ?string
    {
        return preg_match('#^\d{4}/\d{2}/[a-f0-9]{32}\.bin$#D', $path) === 1 ? self::FOLDER . '/' . $path : null;
    }

    /**
     * The content of a private attachment, decrypted in memory – for the download of a person with the section and for
     * placeFile(); never for a public address. Null when it is gone or does not open.
     *
     * @param array<string, mixed> $file an entry of files()
     */
    public static function fileContent(Settings $s, array $file): ?string
    {
        $disk = self::filePath((string) ($file['path'] ?? ''));
        if ($disk === null || !SealedFiles::roomFor((int) @filesize($disk))) {
            return null; // review H1: too large for the memory this request may use – refused, never a fatal error
        }

        return SealedFiles::open($s, self::FILE_PURPOSE, $disk, is_string($file['key'] ?? null) ? $file['key'] : null);
    }

    /** The private attachments for MCP and the screens: index, name, size, image – never a path or a key. @return list<array{index: int, name: string, size: string, image: bool, media: int}> */
    public static function filesOut(array $files): array
    {
        $out = [];
        foreach ($files as $i => $f) {
            $out[] = ['index' => $i, 'name' => (string) $f['name'], 'size' => Files::size((int) ($f['size'] ?? 0)), 'image' => in_array((string) ($f['ext'] ?? ''), self::IMAGE_EXTENSIONS, true), 'media' => (int) ($f['media'] ?? 0)];
        }

        return $out;
    }

    /**
     * Claude places a private attachment on the site (3.46): $save puts the content into Media (the MCP upload – a
     * drafts-only connection's file waits for approval, Core\MediaStaging) and returns the Media id; the request remembers
     * it in the list (media) and among its Media attachments. A file placed already returns its Media id again. Null when
     * the attachment does not exist or does not open.
     *
     * @param callable(string $name, string $content): int $save
     */
    public static function placeFile(App $app, int $id, int $index, callable $save): ?int
    {
        $db = $app->db();
        $s = $app->settings();
        $request = self::get($app, $id);
        $file = $request !== null ? ($request['files'][$index] ?? null) : null;
        if ($request === null || $file === null) {
            return null;
        }
        if ((int) ($file['media'] ?? 0) > 0 && $db->value('SELECT 1 FROM {media} WHERE ido = ?', [(int) $file['media']]) !== null) {
            return (int) $file['media'];
        }
        // review H1: the MCP upload adds a base64 copy and its decoded twin on top of the content – budgeted as two files
        $disk = self::filePath((string) ($file['path'] ?? ''));
        $content = $disk !== null && SealedFiles::roomFor(2 * (int) @filesize($disk)) ? self::fileContent($s, $file) : null;
        if ($content === null) {
            return null;
        }
        $media = $save((string) $file['name'], $content);
        $files = $request['files'];
        $files[$index]['media'] = $media;
        $attachments = array_values(array_unique([...$request['attachments'], $media]));
        // written only over the list this run read – two placements at once never lose each other's Media id silently
        $db->run('UPDATE {requests} SET files = ?, attachments = ? WHERE id = ? AND files <=> ?', [self::encodeFiles($s, $files), (string) json_encode($attachments), $id, $request['files_stored']]);

        return $media;
    }

    /** @return list<array{id: int, sender: string, sender_name: string, text: string, links: list<array{label: string, url: string}>, created_at: string}> oldest first */
    public static function messages(Db $db, int $requestId): array
    {
        return array_map(fn (array $m): array => ['id' => (int) $m['id'], 'sender' => (string) $m['sender'], 'sender_name' => (string) $m['sender_name'], 'text' => (string) $m['text'],
            'links' => self::cleanLinks(json_decode((string) ($m['links'] ?? ''), true)), 'created_at' => (string) $m['created_at']],
            $db->all('SELECT id, sender, sender_name, text, links, created_at FROM {request_messages} WHERE request_id = ? ORDER BY id', [$requestId]));
    }

    /**
     * Links to the drafts Claude made, as given over MCP: a list of {label, url} objects or plain strings (an http(s) URL
     * becomes the url, anything else the label – e.g. "page 12, draft build"). At most MAX_LINKS, nothing but http(s).
     *
     * @return list<array{label: string, url: string}>
     */
    public static function cleanLinks(mixed $links): array
    {
        if (!is_array($links)) {
            return [];
        }
        $out = [];
        foreach ($links as $link) {
            if (is_string($link)) {
                $link = preg_match('#^https?://#i', trim($link)) ? ['url' => trim($link)] : ['label' => $link];
            }
            if (!is_array($link)) {
                continue;
            }
            $url = trim((string) ($link['url'] ?? ''));
            $label = mb_substr(trim(preg_replace('/\s+/', ' ', (string) ($link['label'] ?? '')) ?? ''), 0, 190);
            if ($url !== '' && (!preg_match('#^https?://[^\s<>"\']+$#iD', $url) || mb_strlen($url) > 500)) {
                continue; // only web addresses: a javascript: or data: link must never reach the requester's browser
            }
            if ($url === '' && $label === '') {
                continue;
            }
            $out[] = ['label' => $label, 'url' => $url];
            if (count($out) >= self::MAX_LINKS) {
                break;
            }
        }

        return $out;
    }

    /**
     * Adds a note (Claude) or a reply (a person) to the conversation. False when there is nothing to add.
     *
     * @param 'claude'|'person' $sender
     * @param list<array{label: string, url: string}> $links already cleaned (cleanLinks)
     */
    public static function addMessage(App $app, int $requestId, string $sender, string $senderName, string $text, array $links = []): bool
    {
        $text = trim(str_replace("\r\n", "\n", $text));
        if (($text === '' && $links === []) || !in_array($sender, ['claude', 'person'], true)) {
            return false;
        }
        $now = Clock::now();
        $app->db()->insert('request_messages', ['request_id' => $requestId, 'sender' => $sender, 'sender_name' => mb_substr($senderName, 0, 190), 'text' => mb_substr($text, 0, self::MAX_MESSAGE),
            'links' => $links === [] ? null : (string) json_encode($links, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'created_at' => $now]);
        $app->db()->update('requests', ['updated_at' => $now], ['id' => $requestId]);

        return true;
    }

    /**
     * Moves a request to another status (TRANSITIONS); the same status is left alone. False when the move is not allowed.
     * Marking it done e-mails the requester the note given (or Claude's last one) – unless the requester did it themselves.
     * The change log entry comes from the caller (the admin kernel logs every POST, the MCP server every write tool).
     *
     * @param array<string, mixed> $request from get()
     */
    public static function setStatus(App $app, array $request, string $status, string $note = '', bool $notify = true): bool
    {
        if ($status === $request['status']) {
            return true;
        }
        if (!isset(self::STATUSES[$status]) || !self::canMove((string) $request['status'], $status)) {
            return false;
        }
        $now = Clock::now();
        $app->db()->update('requests', ['status' => $status, 'updated_at' => $now, 'done_at' => in_array($status, ['done', 'declined'], true) ? $now : null], ['id' => $request['id']]);
        if ($status === 'done' && $notify) {
            $notes = array_filter(self::messages($app->db(), (int) $request['id']), fn (array $m): bool => $m['sender'] === 'claude' && $m['text'] !== '');
            self::notifyRequester($app, $request, $note !== '' ? $note : (string) (end($notes)['text'] ?? ''));
        }

        return true;
    }

    /** The administration address of a request, absolute – for e-mails. */
    public static function adminUrl(App $app, int $id): string
    {
        return rtrim($app->settings()->get('site_url') ?: $app->request->origin(), '/') . $app->url('admin.php?module=requests&action=detail&id=' . $id);
    }

    /** "New request for Claude: <title>" to every administrator with an e-mail address – except the requester, who knows. */
    private static function notifyAdministrators(App $app, int $id, string $title, int $authorId): void
    {
        $s = $app->settings();
        $url = self::adminUrl($app, $id);
        foreach ($app->db()->all("SELECT email, jazyk, register FROM {uzivatele} WHERE admin = ? AND blokovat = 0 AND email <> '' AND idu <> ?", [Auth::ADMIN, $authorId]) as $admin) {
            Language::runWith((string) $admin['jazyk'] ?: Language::defaults($s), function () use ($s, $admin, $title, $url): void {
                Mail::send($s, (string) $admin['email'], t('New request for Claude: %s', $title), t('A colleague wrote a new request for Claude: %s', $title) . "\n\n" . $url . "\n");
            }, 'admin-', Language::normalizeRegister((string) $admin['register']));
        }
    }

    /**
     * "Your request is done" to the requester, with Claude's note – in the language of their administration.
     *
     * @param array<string, mixed> $request from get() (author_email, author_language)
     */
    public static function notifyRequester(App $app, array $request, string $note): void
    {
        $email = (string) ($request['author_email'] ?? '');
        if ($email === '') {
            return;
        }
        $s = $app->settings();
        $url = self::adminUrl($app, (int) $request['id']);
        Language::runWith((string) ($request['author_language'] ?? '') ?: Language::defaults($s), function () use ($s, $email, $request, $note, $url): void {
            Mail::send($s, $email, t('Your request is done: %s', (string) $request['title']),
                t('Your request “%s” is marked done.', (string) $request['title']) . ($note !== '' ? "\n\n" . $note : '') . "\n\n" . t('The drafts wait for your review in the administration – nothing was published by itself.') . "\n" . $url . "\n");
        }, 'admin-', Language::normalizeRegister((string) ($request['author_register'] ?? '')));
    }
}
