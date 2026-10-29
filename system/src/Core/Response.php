<?php

declare(strict_types=1);

namespace Kaleta\Core;

final class Response
{
    /**
     * @param array<string, string> $headers
     * @param string|null $file a file on disk sent instead of the body (3.15: a staged media file, Core\MediaStaging)
     */
    public function __construct(
        public readonly string $body = '',
        public readonly int $status = 200,
        public readonly array $headers = ['Content-Type' => 'text/html; charset=utf-8'],
        public readonly ?string $file = null,
    ) {
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status);
    }

    public static function redirect(string $url, int $status = 302): self
    {
        // 3.19.1 (N76): the browser's URL parser drops ASCII tab and newline anywhere and C0 controls and spaces at the ends
        // before it reads the address – so "/<TAB>/evil.example" would be "//evil.example" to it; they go here first (the
        // other control characters too: no header may carry them)
        $url = trim((string) preg_replace('/[\x00-\x1F\x7F]/', '', $url), ' ');
        // a path is never protocol-relative: "//evil.example" or "/\\evil.example" would leave the site (3.4.2, N34-1)
        if (preg_match('#^[/\\\\]{2}#', $url)) {
            $url = '/' . ltrim($url, '/\\');
        }

        return new self('', $status, ['Location' => $url]);
    }

    public static function json(mixed $data, int $status = 200): self
    {
        return new self(
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            $status,
            ['Content-Type' => 'application/json; charset=utf-8'],
        );
    }

    public function send(): void
    {
        http_response_code($this->status);
        // basic security headers; a specific response may override them
        $defaults = ['X-Content-Type-Options' => 'nosniff', 'Referrer-Policy' => 'strict-origin-when-cross-origin', 'X-Frame-Options' => 'SAMEORIGIN']
            + (Demo::active() ? ['X-Robots-Tag' => 'noindex, nofollow'] : []); // the public demo stays out of search engines
        foreach ($this->headers + $defaults as $name => $value) {
            header($name . ': ' . $value);
        }
        if ($this->file !== null) {
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
                readfile($this->file);
            }

            return;
        }
        echo $this->body;
    }
}
