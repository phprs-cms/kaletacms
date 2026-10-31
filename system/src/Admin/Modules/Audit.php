<?php

declare(strict_types=1);

namespace Kaleta\Admin\Modules;

use Kaleta\Admin\Module;
use Kaleta\Core\Response;

/** Site audit (1.9): broken links, missing descriptions, duplicate titles, the menu, builder checks and frequent 404s (Core\Audit). */
final class Audit extends Module
{
    public const string IDENT = 'audit';
    public const string NAME = 'Site audit';
    public const string GROUP = 'Site care';
    public const string ICON = 'audit';
    public const bool ADMIN_ONLY = true;

    protected function actionList(): Response
    {
        $findings = (new \Kaleta\Core\Audit($this->app))->run();
        $groups = [];
        foreach ($findings as $f) {
            $groups[$f['kind']][] = $f;
        }

        // the questions for an agency (3.11) are not counted as things to fix on a site nobody hands over
        return $this->view('list', 'Site audit', ['groups' => $groups, 'total' => count(array_filter($findings, fn (array $f): bool => empty($f['for_client']))),
            // 3.20 (C22): before handing over, the decisions belong in the Notebook – linked from here, not only from the Claude hub
            'notebookUrl' => isset($this->kernel->modules()['notebook']) ? $this->app->url('admin.php?module=notebook') : null]);
    }
}
