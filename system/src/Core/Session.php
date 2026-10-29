<?php

declare(strict_types=1);

namespace Kaleta\Core;

final class Session
{
    private bool $started = false;

    public function __construct(
        private readonly bool $secure,
        private readonly string $cookiePath = '/',
    ) {
    }

    public function start(): void
    {
        if ($this->started) {
            return;
        }
        session_name('kaleta');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => $this->cookiePath,
            'secure' => $this->secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        ini_set('session.use_strict_mode', '1');
        // a login survives 8 hours of inactivity (the admin also keeps it alive while a page is open, image/admin.js)
        ini_set('session.gc_maxlifetime', '28800');
        session_start();
        $this->started = true;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->start();

        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->start();
        $_SESSION[$key] = $value;
    }

    public function remove(string $key): void
    {
        $this->start();
        unset($_SESSION[$key]);
    }

    /**
     * New session ID - call on both login and logout. $newFormToken (3.19.1, N75): each step of a sign-in also starts a new
     * form token – the token of the anonymous session must not carry over, or whoever planted the session cookie (a sibling
     * subdomain) would know it. A password change keeps the token, so forms open in other tabs still save.
     */
    public function regenerate(bool $newFormToken = false): void
    {
        $this->start();
        session_regenerate_id(true);
        if ($newFormToken) {
            unset($_SESSION['_csrf']);
        }
    }

    public function destroy(): void
    {
        $this->start();
        $_SESSION = [];
        session_destroy();
        $this->started = false;
    }

    /** One-time message shown after a redirect. Type: "ok" | "chyba" | "info". */
    public function flash(string $type, string $message): void
    {
        $this->start();
        $_SESSION['_flash'][] = ['typ' => $type, 'text' => $message];
    }

    /** @return list<array{typ:string, text:string}> */
    public function takeFlashes(): array
    {
        $this->start();
        $flashes = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);

        return $flashes;
    }

    public function csrfToken(): string
    {
        $token = $this->get('_csrf');
        if (!is_string($token)) {
            $token = bin2hex(random_bytes(32));
            $this->set('_csrf', $token);
        }

        return $token;
    }

    public function csrfField(): string
    {
        return '<input type="hidden" name="_csrf" value="' . e($this->csrfToken()) . '">';
    }

    public function csrfValid(Request $request): bool
    {
        return hash_equals($this->csrfToken(), $request->post('_csrf'));
    }
}
