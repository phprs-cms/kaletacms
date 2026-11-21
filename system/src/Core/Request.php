<?php

declare(strict_types=1);

namespace Kaleta\Core;

final class Request
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     * @param array<string, mixed> $server
     * @param array<string, mixed> $files
     */
    public function __construct(
        private readonly array $query,
        private readonly array $post,
        private readonly array $server,
        private readonly array $files = [],
    ) {
    }

    /** Path without the language version prefix ("/en/novinky/x" -> "/novinky/x"); set by Front\Kernel. */
    private ?string $path = null;

    /**
     * Site URL from Settings (site_url). The Host header cannot be trusted - whoever forges it would get their domain
     * into links in e-mails (new password!), into the webhook and into notifications. Both kernels set it right after start.
     */
    private ?string $origin = null;

    public function setOrigin(string $url): void
    {
        if (preg_match('#^https?://[a-z0-9.-]+(:\d+)?$#iD', $url)) {
            $this->origin = $url;
        }
    }

    public function setPath(string $path): void
    {
        $this->path = '/' . trim($path, '/');
    }

    public static function fromGlobals(): self
    {
        return new self($_GET, $_POST, $_SERVER, $_FILES);
    }

    public function isPost(): bool
    {
        return ($this->server['REQUEST_METHOD'] ?? 'GET') === 'POST';
    }

    /**
     * Query parameters are English; these are the Czech names they had before this change (new name => former names). Links that
     * already exist (e-mails sent, bookmarks, shared previews, List-Unsubscribe) keep working: get*() and has() fall back to the
     * former name when the English one is missing. Kaleta itself only writes the English names. Never remove an entry.
     */
    public const LEGACY_QUERY = [
        'preview_key' => ['nahled_klic'], 'preview_end' => ['nahled_konec'], 'preview' => ['nahled'], 'build' => ['stavba'],
        'item' => ['polozka'], 'variant' => ['varianta'], 'result' => ['vysledek'], 'edit' => ['upravit', 'uprava', 'uprav'],
        'page' => ['strana'], 'search' => ['hledat'], 'sort' => ['razeni'], 'file' => ['soubor'], 'translation_of' => ['preklad_z'],
        'section' => ['sekce'], 'view' => ['pohled'], 'comment' => ['komentar'], 'unsubscribe' => ['odhlasit'], 'confirm' => ['potvrdit'],
        'category' => ['kategorie', 'tema'], 'topic' => ['tema'], 'type' => ['typ'], 'key' => ['klic'], 'status' => ['stav'],
        'language' => ['jazyk'], 'part' => ['cast'], 'location' => ['umisteni'], 'field' => ['pole'], 'new' => ['nova'],
        'unused' => ['nepouzite'], 'staff' => ['osoba'], 'service' => ['sluzba'], 'user' => ['kdo'], 'area' => ['kde'],
        'article' => ['clanek'], 'password' => ['heslo'], 'revision' => ['idr'], 'days' => ['dni'], 'parent' => ['nadrazena'],
        'subscription' => ['odber'], 'quantity' => ['mnozstvi'], 'booking' => ['rezervace'], 'form' => ['formular'],
        'error' => ['chyba'], 'product' => ['produkt'], 'sent' => ['odeslano'], 'from' => ['z'], 'path' => ['cesta'], 'template' => ['sablona'],
    ];

    /** The raw query value under the English name, or else under a former Czech one. */
    private function raw(string $key): mixed
    {
        foreach ([$key, ...(self::LEGACY_QUERY[$key] ?? [])] as $name) {
            if (array_key_exists($name, $this->query)) {
                return $this->query[$name];
            }
        }

        return null;
    }

    /** Whether the address has the query parameter at all (also with an empty value: ?variant). */
    public function has(string $key): bool
    {
        return $this->raw($key) !== null;
    }

    /** Text value from GET; an array or a missing key returns the default value. */
    public function get(string $key, string $default = ''): string
    {
        $value = $this->raw($key);

        return is_string($value) ? trim($value) : $default;
    }

    public function getInt(string $key, int $default = 0): int
    {
        $value = $this->raw($key);

        return is_string($value) && preg_match('/^-?\d{1,18}$/', $value) ? (int) $value : $default;
    }

    /** Whether the POST body has the field at all (also empty) – two forms posting to one action tell themselves apart (3.25). */
    public function hasPost(string $key): bool
    {
        return array_key_exists($key, $this->post);
    }

    public function post(string $key, string $default = ''): string
    {
        $value = $this->post[$key] ?? null;

        return is_string($value) ? trim($value) : $default;
    }

    public function postInt(string $key, int $default = 0): int
    {
        $value = $this->post[$key] ?? null;

        return is_string($value) && preg_match('/^-?\d{1,18}$/', $value) ? (int) $value : $default;
    }

    public function postBool(string $key): bool
    {
        return !empty($this->post[$key]);
    }

    /** @return list<string> */
    public function postList(string $key): array
    {
        $value = $this->post[$key] ?? [];

        return is_array($value) ? array_values(array_filter($value, is_string(...))) : [];
    }

    /**
     * 3.49 A: the rows of a repeated group of fields (name="replies[3][text]"): a list of rows, each with its string values
     * only (anything nested deeper is dropped).
     *
     * @return list<array<string, string>>
     */
    public function postRows(string $key): array
    {
        $value = $this->post[$key] ?? [];
        $rows = [];
        foreach (is_array($value) ? $value : [] as $row) {
            if (is_array($row)) {
                $rows[] = array_filter($row, is_string(...));
            }
        }

        return $rows;
    }

    /** @return array<string, mixed>|null */
    public function file(string $key): ?array
    {
        $file = $this->files[$key] ?? null;

        return is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE ? $file : null;
    }

    /**
     * 3.49 A: the files of a multiple field (name="files[]"): PHP's nested $_FILES arrays turned into a list of entries
     * (name, type, tmp_name, error, size), each with its own UPLOAD_ERR_* code; empty slots (no file chosen) are left out.
     * A single field gives a list of one.
     *
     * @return list<array{name: string, type: string, tmp_name: string, error: int, size: int}>
     */
    public function fileList(string $key): array
    {
        $field = $this->files[$key] ?? null;
        if (!is_array($field) || !isset($field['error'])) {
            return [];
        }
        $columns = ['name', 'type', 'tmp_name', 'error', 'size'];
        $rows = [];
        if (!is_array($field['error'])) {
            $rows[] = array_combine($columns, array_map(fn (string $c): mixed => $field[$c] ?? null, $columns));
        } else {
            foreach (array_keys($field['error']) as $i) {
                $rows[] = array_combine($columns, array_map(fn (string $c): mixed => is_array($field[$c] ?? null) ? ($field[$c][$i] ?? null) : null, $columns));
            }
        }
        $out = [];
        foreach ($rows as $r) {
            if (is_array($r['error']) || is_array($r['name']) || (int) $r['error'] === UPLOAD_ERR_NO_FILE) {
                continue; // a deeper nesting (files[a][b]) is no file of this form; an empty slot is none either
            }
            $out[] = ['name' => is_string($r['name']) ? $r['name'] : '', 'type' => is_string($r['type']) ? $r['type'] : '', 'tmp_name' => is_string($r['tmp_name']) ? $r['tmp_name'] : '',
                'error' => (int) $r['error'], 'size' => (int) $r['size']];
        }

        return $out;
    }

    /**
     * 3.49 A (finding 9): a POST whose body was over post_max_size – PHP then empties $_POST and $_FILES, so the form looks as
     * if nothing was sent (in the administration it would end as an expired form). Files::overPostLimit decides.
     */
    public function bodyTooLarge(): bool
    {
        return $this->isPost() && $this->post === [] && $this->files === [] && Files::overPostLimit((int) ($this->server['CONTENT_LENGTH'] ?? 0), Files::postLimit());
    }

    /**
     * The server values of the request (the firewall reads the CDN's headers from them, 2.8).
     *
     * @return array<string, mixed>
     */
    public function serverValues(): array
    {
        return $this->server;
    }

    public function ip(): string
    {
        return (string) ($this->server['REMOTE_ADDR'] ?? '');
    }

    /** URL of the page the request came from (the Referer header; the browser may omit it). */
    public function referer(): string
    {
        return (string) ($this->server['HTTP_REFERER'] ?? '');
    }

    public function isHttps(): bool
    {
        return (!empty($this->server['HTTPS']) && $this->server['HTTPS'] !== 'off')
            || ($this->server['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    }

    /** Scheme and domain without a trailing slash: "https://www.example.cz". */
    public function origin(): string
    {
        if ($this->origin !== null) {
            return $this->origin;
        }
        $host = (string) ($this->server['HTTP_HOST'] ?? 'localhost');
        if (!preg_match('/^[a-z0-9.\-]+(:\d+)?$/iD', $host)) {
            $host = 'localhost';
        }

        return ($this->isHttps() ? 'https://' : 'http://') . $host;
    }

    /** Path to the installation relative to the domain root, without a trailing slash ("" or "/magazin"). */
    public function basePath(): string
    {
        $dir = str_replace('\\', '/', dirname((string) ($this->server['SCRIPT_NAME'] ?? '/')));

        return $dir === '/' || $dir === '.' ? '' : rtrim($dir, '/');
    }

    /**
     * Request path inside the installation, always starts with a slash: "/novinky/muj-titulek".
     * Without mod_rewrite the form index.php?path=/novinky/muj-titulek works too.
     */
    public function path(): string
    {
        if ($this->path !== null) {
            return $this->path;
        }
        $fallback = $this->get('path');
        if ($fallback !== '') {
            return '/' . trim($fallback, '/');
        }
        $uri = (string) parse_url((string) ($this->server['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $base = $this->basePath();
        if ($base !== '' && str_starts_with($uri, $base)) {
            $uri = substr($uri, strlen($base));
        }
        $uri = rawurldecode($uri);

        return '/' . trim($uri, '/');
    }

    /** Name of the running script: "index.php", "admin.php"... */
    public function script(): string
    {
        return basename((string) ($this->server['SCRIPT_NAME'] ?? 'index.php'));
    }
}
