<?php

declare(strict_types=1);

namespace Kaleta\Admin\Modules;

use Kaleta\Admin\Module;
use Kaleta\Core\Response;

/**
 * My work (3.24, Core\MyWork): what waits for the signed-in person – the enquiries they handle, the pages and items they
 * are responsible for that need a look, and their requests Claude answered. Every signed-in user has it; it is the first
 * menu item for everyone but administrators, who keep the Dashboard first (and get a tile there). Read only: every row
 * links to the place where the work is done.
 */
final class MyWork extends Module
{
    public const string IDENT = 'my_work';
    public const string NAME = 'My work';
    public const string GROUP = 'Content';
    public const string ICON = 'moje';
    public const bool FOR_ALL_USERS = true;

    /** The enquiries assigned to me that are still open, spam left out like the "Assigned to me" tile (N324-5) – one COUNT over the key (prirazeno, stav) of migration 0110. */
    public static function badge(\Kaleta\Core\App $app): int
    {
        if (!\Kaleta\Core\Extensions::isEnabled($app->settings(), Enquiries::EXTENSION) || !$app->auth()->hasModule(Enquiries::IDENT)) {
            return 0;
        }

        return Enquiries::countAssigned($app->db(), $app->auth()->id());
    }

    protected function actionList(): Response
    {
        $modules = $this->kernel->modules();

        return $this->view('list', 'My work', ['lists' => \Kaleta\Core\MyWork::lists($this->app, $modules, $this->app->auth()->id()), 'modules' => $modules]);
    }
}
