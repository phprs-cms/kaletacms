<?php

declare(strict_types=1);

namespace Kaleta\Admin\Modules;

use Kaleta\Admin\Module;
use Kaleta\Core\Response;

/**
 * News subscribers (the Newsletter extension): who subscribed with the Newsletter subscription element and whether they
 * confirmed the subscription. Newsletters go to them from the Newsletters module (Core\Mailing); confirmed addresses can
 * also go to a mailing service or be exported to CSV, with an unsubscribe link, for another tool.
 *
 * 3.51: the language version of each sign-up, a counts line (confirmed per language, waiting, the send register) and the
 * register's mark per row. A bounced address stays subscribed and is skipped until an administrator lifts the bounce
 * (lift); an address that refused comes back only by a new confirmed sign-up (Front\Subscription), never by a person here.
 */
final class Subscribers extends Module
{
    public const string IDENT = 'subscribers';
    public const string EXTENSION = 'newsletter';
    public const string NAME = 'Subscribers';
    public const string GROUP = 'Content';
    /** 3.25: a tab of the Newsletter hub (URLs unchanged); shown as its own item only to a person without Newsletters */
    public const string PARENT = 'newsletters';
    public const string HUB = 'newsletter';
    public const string ICON = 'newsletter';

    protected function actionList(): Response
    {
        $search = mb_substr($this->request->get('search'), 0, 100);
        $pageNumber = max(1, $this->request->getInt('page', 1));
        [$whereParts, $params] = \Kaleta\Core\Db::likeWords(['email'], $search);
        $whereParts = ' WHERE ' . $whereParts;
        $subscribers = $this->db->all('SELECT * FROM {odberatele}' . $whereParts . ' ORDER BY ido DESC LIMIT 100 OFFSET ' . (($pageNumber - 1) * 100), $params);
        $register = \Kaleta\Core\SendRegister::counts($this->db);
        // 3.51: the register's mark of the listed rows – asked only while the register holds anything
        $marks = [];
        if ($register['bounced'] + $register['refused'] > 0) {
            foreach ($subscribers as $o) {
                $kind = \Kaleta\Core\SendRegister::blocked($this->db, (string) $o['email']);
                if ($kind !== null) {
                    $marks[(int) $o['ido']] = $kind;
                }
            }
        }

        return $this->view('list', 'Subscribers', [
            'subscribers' => $subscribers,
            'languages' => $this->db->all('SELECT language, SUM(stav = 1) AS confirmed, SUM(stav = 0) AS waiting FROM {odberatele} GROUP BY language ORDER BY language'),
            'register' => $register, 'marks' => $marks,
            'total' => (int) $this->db->value('SELECT COUNT(*) FROM {odberatele}' . $whereParts, $params),
            'confirmed' => (int) $this->db->value('SELECT COUNT(*) FROM {odberatele} WHERE stav = 1'),
            'service' => \Kaleta\Core\Newsletter::isEnabled($this->app->settings()) ? $this->app->settings()->get('newsletter_service') : '',
            'queue' => $this->db->one('SELECT SUM(dalsi IS NOT NULL) AS ceka, SUM(dalsi IS NULL) AS chyby FROM {odber_fronta}') ?? ['ceka' => 0, 'chyby' => 0],
            'search' => $search, 'pageNumber' => $pageNumber,
        ]);
    }

    protected function actionDelete(): Response
    {
        $o = $this->request->isPost() ? $this->db->one('SELECT email FROM {odberatele} WHERE ido = ?', [$this->request->postInt('ido')]) : null;
        if ($o !== null) {
            \Kaleta\Front\Subscription::remove($this->app, (string) $o['email']); // the ledger and the mailing service too (3.46 N156)
        }

        return $this->back('The subscriber has been deleted.');
    }

    /**
     * 3.51: an administrator decides that a bounced address works again – it is no longer skipped by newsletters. Only a
     * bounce: a refusal is the person's own word and ends only with their new confirmed sign-up.
     */
    protected function actionLift(): Response
    {
        if (!$this->request->isPost() || !$this->app->auth()->isAdmin()) {
            return $this->back();
        }
        $o = $this->db->one('SELECT email FROM {odberatele} WHERE ido = ?', [$this->request->postInt('ido')]);
        if ($o === null || \Kaleta\Core\SendRegister::blocked($this->db, (string) $o['email']) !== 'bounced') {
            return $this->back('Only a bounced address can be lifted here – an address that refused comes back only by its own new confirmed sign-up.', '', [], 'chyba');
        }
        \Kaleta\Core\SendRegister::lift($this->db, 'bounced', (string) $o['email']);
        \Kaleta\Core\ChangeLog::write($this->app, 'subscribers', 'lift bounce', \Kaleta\Core\PersonalData::mask((string) $o['email']));

        return $this->back('The address is no longer skipped as bounced.');
    }

    /** After connecting the service: all confirmed subscribers who are not in it yet go to the queue – and the first batch right away. */
    protected function actionSync(): Response
    {
        if (!$this->request->isPost() || !$this->app->auth()->isAdmin()) {
            return $this->back();
        }
        $count = \Kaleta\Core\Newsletter::enqueueAll($this->app);
        \Kaleta\Core\Newsletter::processQueue($this->app, 20);

        return $this->back(t('%d subscribers are going to the mailing service; the site sends the rest gradually in the background.', $count));
    }

    /** Retry failed transfers right away. */
    protected function actionRetry(): Response
    {
        if (!$this->request->isPost() || !$this->app->auth()->isAdmin()) {
            return $this->back();
        }
        \Kaleta\Core\Newsletter::retry($this->app);
        \Kaleta\Core\Newsletter::processQueue($this->app, 20);

        return $this->back('The failed transfers were tried again – see the result in the Service column.');
    }

    /** Confirmed subscribers to CSV (UTF-8 with BOM, semicolon) with an unsubscribe link. */
    protected function actionCsv(): Response
    {
        $f = fopen('php://temp', 'w+');
        fwrite($f, "\xEF\xBB\xBF");
        fputcsv($f, [t('Email'), t('Subscribed'), t('Confirmed'), t('Unsubscribe link'), t('Language')], ';', '"', '');
        foreach ($this->db->all('SELECT * FROM {odberatele} WHERE stav = 1 ORDER BY ido') as $o) {
            $row = array_map(fn (string $v): string => preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v,
                [(string) $o['email'], (string) $o['datum'], (string) $o['potvrzeno'], \Kaleta\Front\Subscription::unsubscribeLink($this->app, (string) $o['token']),
                    (string) $o['language'] !== '' ? (string) $o['language'] : \Kaleta\Core\Language::defaults($this->app->settings())]); // 3.51
            fputcsv($f, $row, ';', '"', '');
        }
        rewind($f);
        $csv = (string) stream_get_contents($f);
        fclose($f);
        \Kaleta\Core\ChangeLog::write($this->app, 'subscribers', 'export CSV', '');

        return new Response($csv, 200, ['Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => 'attachment; filename="odberatele-' . date('Y-m-d') . '.csv"']);
    }
}
