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
        // přihlášení vydrží 8 hodin nečinnosti (administrace ho navíc při otevřené stránce udržuje, image/admin.js)
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

    /** Nové ID session - volat při přihlášení i odhlášení. */
    public function regenerate(): void
    {
        $this->start();
        session_regenerate_id(true);
    }

    public function destroy(): void
    {
        $this->start();
        $_SESSION = [];
        session_destroy();
        $this->started = false;
    }

    /** Jednorázová hláška zobrazená po přesměrování. Typ: "ok" | "chyba" | "info". */
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
