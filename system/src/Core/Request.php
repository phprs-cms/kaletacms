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

    /** @return array<string, mixed>|null */
    public function file(string $key): ?array
    {
        $file = $this->files[$key] ?? null;

        return is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE ? $file : null;
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
