<?php

declare(strict_types=1);

namespace Kaleta\Admin\Modules;

use Kaleta\Admin\Module;
use Kaleta\Core\Response;

/**
 * Broken links under Site care (3.20, C20): the check runs across published news items, pages and collection items, yet its
 * screen lived under News only. This entry in the menu opens the same screen (News → Broken links stays as a shortcut) and
 * keeps its availability and permission exactly as before – the News feature and whoever may open News.
 */
final class BrokenLinks extends Module
{
    public const string IDENT = 'broken_links';
    public const string EXTENSION = 'novinky';
    public const string NAME = 'Broken links';
    public const string GROUP = 'Site care';
    public const string ICON = 'odkazy';
    public const string SHARES_PERMISSION_OF = 'news';

    /** The screen of News → Broken links; "check again" posts to News as before. */
    protected function actionList(): Response
    {
        return (new News($this->kernel))->handle('links');
    }
}
