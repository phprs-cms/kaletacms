<?php

declare(strict_types=1);

namespace Kaleta\Admin\Modules;

use Kaleta\Admin\Module;
use Kaleta\Core\Response;

/**
 * Stats: own measurement without cookies (visits, views, pages, campaigns, devices, sources) and the leads they bring
 * (Core\Report, 2.3) – the same report Claude reads with get_stats.
 */
final class Stats extends Module
{
    public const string IDENT = 'stats';
    public const string NAME = 'Statistics';
    public const string GROUP = 'Customers';
    public const string ICON = 'statistika';
    public const string EXTENSION = 'statistika';

    protected function actionList(): Response
    {
        $days = in_array($this->request->getInt('days'), \Kaleta\Core\Report::PERIODS, true) ? $this->request->getInt('days') : 30;

        // the module is there only while the Statistics feature is on – the one switch since 3.2, so no "turned off" notice
        // 3.19.2: menu paths in the empty texts link only where this person may go
        $report = \Kaleta\Core\Report::build($this->db, $days);
        // 3.20 (C2): the pages that bring leads get an "edit" link into the administration next to the public one – only for
        // paths that are a page or a collection item the signed-in user may edit
        $editLinks = [];
        foreach ([...$report['pages'], ...$report['landing_pages']] as $row) {
            $editLinks[$row['path']] ??= \Kaleta\Admin\EnquiryOrigin::editLink($this->app, (string) $row['path']);
        }

        return $this->view('list', 'Statistics', ['report' => $report, 'modules' => $this->kernel->modules(), 'editLinks' => array_filter($editLinks)]);
    }
}
