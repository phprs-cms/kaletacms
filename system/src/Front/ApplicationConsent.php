<?php

declare(strict_types=1);

namespace Kaleta\Front;

use Kaleta\Core\Antispam;
use Kaleta\Core\App;
use Kaleta\Core\Applications;
use Kaleta\Core\Language;
use Kaleta\Core\TalentPool;

/**
 * The page of the link in the renewal e-mail of the talent pool (3.40, Core\TalentPool): /application-consent/<token>.
 * Opening the link (GET) only shows the choice – keep the application for another applications_pool_months, or delete it
 * now – with the chosen one first (?choice=keep|delete); mail scanners open links, so nothing changes until a button posts
 * the form (POST to the same address). The token works once and only until the consent lapses; a wrong, used or expired
 * one gets the same answer. Failed attempts count per network (Core\Antispam, 3.40 review L1, as /quote/ does): after
 * TalentPool::MAX_TRIES within an hour every link from it gets that answer. The texts are in the language of the
 * application; the page is never cached (Cache-Control: no-store) or indexed.
 */
final class ApplicationConsent
{
    /**
     * The page: [heading, HTML content].
     *
     * @return array{0: string, 1: string}
     */
    public static function page(App $app, string $token): array
    {
        $r = $app->request;
        $ip = Antispam::network($r->ip());
        $antispam = new Antispam($app->db(), $app->settings());
        $invalid = [t('The link is no longer valid'), '<p>' . e(t('The link is invalid, has already been used or has expired. If you have a question about your application, reply to the e-mail you received.')) . '</p>'];
        if ($antispam->count($ip, TalentPool::TRIES, 0, 60) >= TalentPool::MAX_TRIES) {
            return $invalid; // too many wrong links from this network: no lookup at all
        }
        $row = TalentPool::byToken($app->db(), $token);
        if ($row === null) {
            $antispam->write($ip, TalentPool::TRIES, 0);

            return $invalid;
        }

        return Language::runWith(Applications::languageOf($app->settings(), $row), function () use ($app, $r, $row, $token): array {
            $company = $app->settings()->get('company_name') !== '' ? $app->settings()->get('company_name') : $app->settings()->get('site_name');
            if ($r->isPost()) {
                if ($r->post('choice') === 'delete') {
                    return TalentPool::remove($app, $token)
                        ? [t('Your application was deleted'), '<p>' . e(t('We have deleted your application and everything you sent with it. Thank you for your interest in %s.', $company)) . '</p>']
                        : [t('The link is no longer valid'), '<p>' . e(t('The link is invalid, has already been used or has expired. If you have a question about your application, reply to the e-mail you received.')) . '</p>'];
                }
                if ($r->post('choice') === 'keep') {
                    $until = TalentPool::renew($app, $token);

                    return $until !== null
                        ? [t('Thank you – we keep your application'), '<p>' . e(t('We keep your application until %s and will contact you when a suitable opening comes up.', format_date($until))) . '</p>']
                        : [t('The link is no longer valid'), '<p>' . e(t('The link is invalid, has already been used or has expired. If you have a question about your application, reply to the e-mail you received.')) . '</p>'];
                }
            }
            $position = (string) $row['job_title'] !== '' ? (string) $row['job_title'] : t('a position with us');
            $months = max(1, $app->settings()->int('applications_pool_months'));
            $action = e($app->url(TalentPool::PATH . '/' . $token));
            $keep = '<button class="tlacitko" type="submit" name="choice" value="keep">' . e(t('Keep my application for another %d months', $months)) . '</button>';
            $delete = '<button class="tlacitko" type="submit" name="choice" value="delete">' . e(t('Delete my application now')) . '</button>';
            $buttons = $r->get('choice') === 'delete' ? $delete . ' ' . $keep : $keep . ' ' . $delete;

            return [t('Your application with %s', $company), '<p>' . e(t('You applied for %s and agreed that we keep your application until %s for future openings.', $position, format_date(substr((string) $row['pool_until'], 0, 10)))) . '</p>'
                . '<form method="post" action="' . $action . '"><p>' . $buttons . '</p></form>'
                . '<p>' . e(t('If you do nothing, we delete your application after %s.', format_date(substr((string) $row['pool_until'], 0, 10)))) . '</p>'];
        });
    }
}
