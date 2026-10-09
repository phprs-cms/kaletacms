<?php

declare(strict_types=1);

namespace Kaleta\Admin;

use Kaleta\Core\App;
use Kaleta\Core\Db;
use Kaleta\Core\Request;
use Kaleta\Core\Response;

/**
 * Base class of admin modules.
 *
 * The action from the URL (admin.php?module=news&action=edit) calls the method actionEdit().
 * The default action is "list". A new module = one class + templates in views/admin/<ident>/.
 */
abstract class Module
{
    /** Identifier in the URL and in the permissions table. */
    public const string IDENT = '';

    /** Title in the menu. */
    public const string NAME = '';

    /** Group in the menu: Obsah | Vzhled | Správa (Content | Appearance | Administration). */
    public const string GROUP = 'Content';

    /** Icon in the menu (key into the set in views/admin/icons.php). */
    public const string ICON = 'clanek';

    /** Key of the extension (Core\Extensions) the module belongs to; empty = core, cannot be disabled. */
    public const string EXTENSION = '';

    /** Only the administrator sees the module (authors, configuration...). */
    public const bool ADMIN_ONLY = false;

    /** The module belongs under another one (ident): it is not shown separately in the menu, the parent is highlighted (Categories and Tags under News). */
    public const string PARENT = '';

    /** The module is available to every signed-in user without setting permissions. */
    public const bool FOR_ALL_USERS = false;

    /**
     * A screen of a hub shares the hub's permission (3.2): Facts open for whoever may open Business details, so a role
     * has one checkbox for the hub, not one per screen. Empty = the module's own permission.
     */
    public const string SHARES_PERMISSION_OF = '';

    /** The hub (Admin\Hubs) whose tabs the module's screens show at the top (3.2); empty = none. */
    public const string HUB = '';

    /**
     * A last check of whom the module is for, after the permission (3.1.1): a module can narrow it further, so the menu
     * never offers what then answers 403 (the whistleblowing channel only for its readers and administrators).
     */
    public static function availableTo(App $app): bool
    {
        return true;
    }

    protected readonly App $app;
    protected readonly Db $db;
    protected readonly Request $request;

    public function __construct(protected readonly Kernel $kernel)
    {
        $this->app = $kernel->app;
        $this->db = $this->app->db();
        $this->request = $this->app->request;
    }

    public function handle(string $action): Response
    {
        $method = 'action' . str_replace('_', '', ucwords($action, '_'));
        if (!preg_match('/^[a-z][a-z_]*$/D', $action) || !method_exists($this, $method)) {
            return $this->error('Unknown action.', 404);
        }

        return $this->$method();
    }

    /** @param array<string, mixed> $data */
    protected function view(string $template, string $heading, array $data = []): Response
    {
        $data += ['app' => $this->app, 'module' => $this, 'csrf' => $this->app->session->csrfField()];

        return $this->kernel->page($heading, $this->hubTabs() . $this->app->view->render('admin/' . static::IDENT . '/' . $template, $data));
    }

    /** The tabs of the module's hub for the current screen, or '' (3.2). */
    protected function hubTabs(): string
    {
        return static::HUB === '' ? '' : Hubs::tabs(static::HUB, $this->kernel->modules(), static::IDENT, $this->request->get('action'), $this->app->url(...));
    }

    /**
     * Sends a file straight from the disk in chunks and ends the request – a backup or an export can have hundreds of MB,
     * so it never goes through Response (which holds the whole body in memory).
     */
    protected function sendFile(string $path, string $name, string $type = 'application/octet-stream'): never
    {
        session_write_close();
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: ' . $type);
        header('Content-Disposition: attachment; filename="' . str_replace(['"', "\r", "\n"], '', $name) . '"');
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: no-store, private');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }

    protected function error(string $text, int $status = 400): Response
    {
        return $this->kernel->page('Error', $this->app->view->render('admin/error', ['text' => $text]), $status);
    }

    public function app(): App
    {
        return $this->app;
    }

    /** @param array<string, scalar> $params */
    public function url(string $action = '', array $params = []): string
    {
        $query = ['module' => static::IDENT] + ($action !== '' ? ['action' => $action] : []) + $params;

        return $this->app->url('admin.php?' . http_build_query($query));
    }

    /** Redirect back to the module with a message (Post/Redirect/Get pattern). */
    /** Return to the site page after editing "directly on the site": only a local path under the site root, never a foreign URL. */
    /**
     * Language version filter in lists (pages, categories, collection items) by ?language=code.
     *
     * @return array{0: list<string>, 1: string, 2: ?string} site languages (empty = single language), selected code, value of the jazyk column (null = all)
     */
    protected function readLanguageFilter(): array
    {
        $siteSettings = $this->app->settings();
        $additional = \Kaleta\Core\Language::additional($siteSettings);
        $languages = $additional === [] ? [] : [\Kaleta\Core\Language::defaults($siteSettings), ...$additional];
        $code = in_array($this->request->get('language'), $languages, true) ? $this->request->get('language') : '';

        return [$languages, $code, $code === '' ? null : \Kaleta\Core\Language::column($siteSettings, $code)];
    }

    protected function redirectToSite(string $target, string $suffix = ''): Response
    {
        $root = $this->app->request->basePath() . '/';
        $isLocal = str_starts_with($target, $root) && !str_starts_with($target, '//') && !str_contains($target, '\\') && !preg_match('#[\r\n]|^/[/\\\\]#', $target);

        return Response::redirect(($isLocal ? strtok($target, '?#') : $root) . ($isLocal ? $suffix : ''), 303);
    }

    protected function back(string $message = '', string $action = '', array $params = [], string $type = 'ok'): Response
    {
        if ($message !== '') {
            $this->app->session->flash($type, $message);
        }

        return Response::redirect($this->url($action, $params));
    }
}
