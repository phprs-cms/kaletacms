<?php

declare(strict_types=1);

namespace Kaleta\Admin;

use Kaleta\Core\Response;

/**
 * The guide to the office part (3.45, CPO 5): admin.php?action=guide – the chapters of Admin\Guide::CHAPTERS for every
 * signed-in user, like My account and Notifications no item of the left menu (no section, no permission). The index lists
 * the chapters of the sections the person may open; &chapter=<key> opens one chapter (any chapter – it is documentation,
 * the link to the section itself appears only for whoever may open it). GET only; nothing here changes anything.
 */
final class GuidePage
{
    public function __construct(private readonly Kernel $kernel)
    {
    }

    public function handle(): Response
    {
        $app = $this->kernel->app;
        $modules = $this->kernel->modules();
        $key = $app->request->get('chapter');
        if ($key === '') {
            return $this->kernel->page('Guide to the office', $app->view->render('admin/guide', ['app' => $app, 'chapters' => Guide::chaptersFor(array_keys($modules)), 'chapter' => null, 'modules' => $modules]));
        }
        $chapter = Guide::CHAPTERS[$key] ?? null;
        if ($chapter === null) {
            return $this->kernel->page('Error', $app->view->render('admin/error', ['text' => 'This page does not exist.']), 404);
        }

        return $this->kernel->page($chapter['title'], $app->view->render('admin/guide', ['app' => $app, 'chapters' => Guide::chaptersFor(array_keys($modules)), 'chapter' => $key, 'modules' => $modules]));
    }
}
