<?php

declare(strict_types=1);

namespace Kaleta\Admin\Modules;

use Kaleta\Admin\Module;
use Kaleta\Core\Response;
use Kaleta\Extension\Registry;

/**
 * Add-ons (3.0): code from other developers copied into extensions/<slug>/ – switched on and off here, with what each
 * needs and the error that switched it off, and the administration pages add-ons register (Extension\Api::adminPage).
 * Built-in parts of Kaleta stay in Extensions; nothing is uploaded or downloaded from here.
 */
final class Addons extends Module
{
    public const string IDENT = 'addons';
    public const string HUB = 'features';
    public const string PARENT = 'extensions';
    public const string NAME = 'Add-ons';
    public const string GROUP = 'Administration';
    public const string ICON = 'rozsireni';
    public const bool ADMIN_ONLY = true;

    protected function actionList(): Response
    {
        $s = $this->app->settings();

        return $this->view('list', 'Add-ons', ['addons' => Registry::discover(), 'enabled' => Registry::enabled($s),
            'errors' => array_map(fn (array $m): string => $s->get('addons_error.' . $m['slug']), Registry::discover()),
            'pages' => Registry::get()->adminPages(), 'safeMode' => ($this->app->config['addons'] ?? true) === false, 'demo' => \Kaleta\Core\Demo::active()]);
    }

    protected function actionToggle(): Response
    {
        $slug = $this->request->post('slug');
        if (!$this->request->isPost() || \Kaleta\Core\Demo::active()) {
            return $this->back();
        }
        if ($this->request->post('on') === '1') {
            if (!$this->request->postBool('trust')) {
                return $this->back('Tick that you trust the code of this add-on.', '', [], 'chyba');
            }
            try {
                Registry::enable($this->app, $slug);
            } catch (\InvalidArgumentException | \DomainException | \PDOException $e) {
                return $this->back($e->getMessage(), '', [], 'chyba');
            }
            // 3.19.1 (N87): which add-on, and which way (the event addon.enabled is recorded by Registry::enable)
            \Kaleta\Admin\ChangeLog::write($this->app, 'addons', 'enabled', self::label($slug));

            return $this->back('The add-on is switched on.');
        }
        if (in_array($slug, Registry::enabled($this->app->settings()), true)) {
            Registry::disable($this->app, $slug); // records addon.disabled
            \Kaleta\Admin\ChangeLog::write($this->app, 'addons', 'disabled', self::label($slug));
        }

        return $this->back('The add-on is switched off – its data stays.');
    }

    /** "Name (slug, version)" of an add-on for the change log; the slug alone when it is no longer in extensions/. */
    private static function label(string $slug): string
    {
        $manifest = Registry::discover()[$slug] ?? null;

        return $manifest === null ? $slug : $manifest['name'] . ' (' . $slug . ($manifest['version'] !== '' ? ', ' . $manifest['version'] : '') . ')';
    }

    /** A page an add-on registered: admin.php?module=addons&action=page&p=<slug>.<name> */
    protected function actionPage(): Response
    {
        $page = Registry::get()->adminPages()[$this->request->get('p')] ?? null;
        if ($page === null) {
            return $this->error('The add-on page does not exist or its add-on is switched off.', 404);
        }
        try {
            $html = ($page['render'])($this->request);
        } catch (\Throwable $e) {
            return $this->error(t('The add-on failed: %s', $e->getMessage()), 500);
        }

        return $this->view('page', $page['title'], ['html' => is_string($html) ? $html : '', 'addon' => $page['slug']]);
    }
}
