<?php

declare(strict_types=1);

namespace MiroCMS\Core;

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

    /** Cesta bez předpony jazykové verze ("/en/novinky/x" -> "/novinky/x"); nastavuje Front\Kernel. */
    private ?string $cesta = null;

    /**
     * Adresa webu z Nastavení (adresa_webu). Hlavičce Host se nedá věřit - kdo ji podvrhne, dostal by svou doménu
     * do odkazů v e-mailech (nové heslo!), do webhooku i do oznámení. Nastavují oba kernely hned po startu.
     */
    private ?string $origin = null;

    public function setOrigin(string $adresa): void
    {
        if (preg_match('#^https?://[a-z0-9.-]+(:\d+)?$#i', $adresa)) {
            $this->origin = $adresa;
        }
    }

    public function setPath(string $cesta): void
    {
        $this->cesta = '/' . trim($cesta, '/');
    }

    public static function fromGlobals(): self
    {
        return new self($_GET, $_POST, $_SERVER, $_FILES);
    }

    public function isPost(): bool
    {
        return ($this->server['REQUEST_METHOD'] ?? 'GET') === 'POST';
    }

    /** Textová hodnota z GET; pole a chybějící klíč vrací výchozí hodnotu. */
    public function get(string $key, string $default = ''): string
    {
        $value = $this->query[$key] ?? null;

        return is_string($value) ? trim($value) : $default;
    }

    public function getInt(string $key, int $default = 0): int
    {
        $value = $this->query[$key] ?? null;

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

    public function ip(): string
    {
        return (string) ($this->server['REMOTE_ADDR'] ?? '');
    }

    public function isHttps(): bool
    {
        return (!empty($this->server['HTTPS']) && $this->server['HTTPS'] !== 'off')
            || ($this->server['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    }

    /** Schéma a doména bez koncového lomítka: "https://www.example.cz". */
    public function origin(): string
    {
        if ($this->origin !== null) {
            return $this->origin;
        }
        $host = (string) ($this->server['HTTP_HOST'] ?? 'localhost');
        if (!preg_match('/^[a-z0-9.\-]+(:\d+)?$/i', $host)) {
            $host = 'localhost';
        }

        return ($this->isHttps() ? 'https://' : 'http://') . $host;
    }

    /** Cesta k instalaci vůči kořeni domény, bez koncového lomítka ("" nebo "/magazin"). */
    public function basePath(): string
    {
        $dir = str_replace('\\', '/', dirname((string) ($this->server['SCRIPT_NAME'] ?? '/')));

        return $dir === '/' || $dir === '.' ? '' : rtrim($dir, '/');
    }

    /**
     * Cesta požadavku uvnitř instalace, vždy začíná lomítkem: "/novinky/muj-titulek".
     * Bez mod_rewrite funguje i tvar index.php?cesta=/novinky/muj-titulek.
     */
    public function path(): string
    {
        if ($this->cesta !== null) {
            return $this->cesta;
        }
        $fallback = $this->get('cesta');
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

    /** Název spuštěného skriptu: "index.php", "admin.php"... */
    public function script(): string
    {
        return basename((string) ($this->server['SCRIPT_NAME'] ?? 'index.php'));
    }
}
