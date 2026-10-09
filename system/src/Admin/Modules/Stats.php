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
        return $this->view('list', 'Statistics', ['report' => \Kaleta\Core\Report::build($this->db, $days)]);
    }
}
