<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Notifications about a published news item: webhook and IndexNow. Called right after publishing in the admin and also after
 * visits to the site (at most once per minute) - thanks to that, news items scheduled for the future and news items published
 * through Claude (MCP) are announced as soon as their time comes. In the background the mail queue is also sent and links are checked.
 */
final class Notifications
{
    /** After the page is sent to the visitor (index.php). */
    public static function runInBackground(App $app): void
    {
        $s = $app->settings();
        if (time() - $s->int('notification_check') < 60) {
            return;
        }
        $s->set('notification_check', (string) time());
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        ignore_user_abort(true);
        try {
            Scheduler::run($app, 'visit', 8.0); // 2.8: the same job list as cron, only the jobs that may run on a visit
        } catch (\Throwable) {
            // background work must not break the site; the next attempt happens on the next visit
        }
    }

    /**
     * Once a day: enquiries past the configured retention period and unconfirmed subscription sign-ups older than 30 days
     * (GDPR – without consent the address is not kept). Runs regardless of whether the extension is enabled: data from the
     * time when it was enabled is deleted too.
     */
    public static function purgePersonalData(App $app, bool $immediately = false): void
    {
        $s = $app->settings();
        if (!$immediately && time() - $s->int('data_cleanup') < 86400) {
            return;
        }
        $s->set('data_cleanup', (string) time());
        Jobs::purgeApplications($app); // applications to job openings first: they usually have a shorter retention (2.11)
        \Kaleta\Admin\Modules\Enquiries::deleteExpired($app->db(), $s);
        Booking::purge($app); // bookings follow the same retention (3.0)
        if ($s->int('cookies_log_months') > 0) {
            // records of cookie consents should not be kept forever
            $app->db()->run('DELETE FROM {souhlasy} WHERE cas < ?', [Clock::monthsAgo($s->int('cookies_log_months'))]);
        }
        $app->db()->run('DELETE FROM {odberatele} WHERE stav = 0 AND datum < ?', [Clock::at('-30 days')]);
        // the states of imports and migration reports, the rows of item imports (3.7, N37-25) – never an uploaded export
        try {
            WpFile::purgeOld(WpFile::folder(), time());
        } catch (\RuntimeException) {
            // no storage/import folder and none can be made: nothing to clean
        }
    }

    /** Announces all published and not yet announced news items (at most 2 days old, so that the archive is not sent out after an outage). */
    public static function process(App $app): void
    {
        $db = $app->db();
        // scheduled pages: a hidden page publishes itself at the given time
        // scheduled collection items (1.9) likewise
        $now = Clock::now();
        $items = $db->run('UPDATE {kolekce_polozky} SET zobrazit = 1, zverejnit_od = NULL WHERE zverejnit_od IS NOT NULL AND zverejnit_od <= ? AND smazano IS NULL', [$now])->rowCount();
        if ($db->run('UPDATE {stranky} SET zobrazit = 1, zverejnit_od = NULL WHERE zverejnit_od IS NOT NULL AND zverejnit_od <= ? AND smazano IS NULL', [$now])->rowCount() + $items > 0) {
            \Kaleta\Front\Cache::clear();
        }
        $newsItems = $db->all('SELECT idc, seo_link, jazyk, noindex FROM {novinky} WHERE visible = 1 AND datum <= ? AND oznameno IS NULL ORDER BY datum LIMIT 5', [$now]);
        foreach ($newsItems as $c) {
            // mark first: if the notification fails, it must not repeat forever
            if ($db->run('UPDATE {novinky} SET oznameno = ? WHERE idc = ? AND oznameno IS NULL', [Clock::now(), $c['idc']])->rowCount() === 0) {
                continue;
            }
            \Kaleta\Front\Cache::clear(); // a scheduled news item has just gone out - the cached listing does not know it yet
            SocialDrafts::prepare($app, (int) $c['idc']); // post drafts for the chosen networks (2.13) – a person posts them
            if ($c['noindex'] || (int) $db->value('SELECT datum < ? FROM {novinky} WHERE idc = ?', [Clock::at('-2 days'), $c['idc']]) === 1) {
                continue;
            }
            Webhook::articlePublished($app, (int) $c['idc']);
            GoogleBusiness::newsPublished($app, (int) $c['idc']); // a post on the Business Profile when the administrator opted in (2.13)
            (new \Kaleta\Front\Seo($app))->indexNow($app->newsItemUrl($c['seo_link'], $c['jazyk']));
        }
    }
}
