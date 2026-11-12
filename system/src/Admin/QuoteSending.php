<?php

declare(strict_types=1);

namespace Kaleta\Admin;

use Kaleta\Core\QuoteDelivery;
use Kaleta\Core\Quotes;
use Kaleta\Core\Response;
use Kaleta\Front\QuotePage;

/**
 * Sending a quote and its customer's link (3.38, design A5 5–6, A7) – actions of Admin\Modules\Quotes kept apart from
 * the quote screens:
 *
 *  - send (GET): the send dialog – the recipient (the quote's customer e-mail, read only), the subject (with {number})
 *    and the message in the quote's language, the sender's signature, "Set the deal value to the net total" when a deal
 *    is linked (ticked by quote_set_deal_value). Without SMTP it offers to finalise the quote and hand over the link
 *    (copy it, or the mail-program link), as replies do. For a quote already sent it shows the link and its state.
 *    (POST): sends it (Core\QuoteDelivery::send) or, with mode=finalise, finalises it and makes the link.
 *  - preview: the document as the customer sees it (Front\QuotePage), a draft from its live data and marked as a draft.
 *  - link (POST): a new link for a sent quote (the old one stops working); link_withdraw (POST): withdraws it at once.
 *    Both go back to the send dialog, or to the quote's detail with back=detail.
 *  - texts_save (POST, administrators): the default texts of new quotes – intro, terms and the document's footer – for
 *    the default language and each additional language (QuoteDelivery::defaultText).
 *
 * The module's section is checked by the admin kernel; every write is a POST with the CSRF token.
 */
trait QuoteSending
{
    protected function actionSend(): Response
    {
        $id = $this->request->isPost() ? $this->request->postInt('id') : $this->request->getInt('id');
        $quote = Quotes::get($this->db, $id);
        if ($quote === null) {
            return $this->error('The quote does not exist.', 404);
        }
        if (!$this->request->isPost()) {
            $user = $this->app->auth()->user() ?? [];

            return $this->view('send', 'Send the quote', ['quote' => $quote, 'draft' => QuoteDelivery::draft($this->app, $quote), 'link' => QuoteDelivery::linkUrl($this->app, $quote),
                'signature' => \Kaleta\Core\EnquiryReply::signature($this->app, $user) !== null, 'replyTo' => (string) ($user['email'] ?? '')]);
        }
        try {
            if ($this->request->post('mode') === 'finalise') {
                $done = Quotes::finalise($this->app, $id, $this->app->auth()->id());
                QuoteDelivery::remember($this->app, $id, QuoteDelivery::issueLink($this->app, $id, $this->app->auth()->id())); // 3.38 review L1: shown in this session only
                $this->app->session->flash('ok', t('Quote %s was finalised – hand over the link below or print the quote.', (string) $done['number']));
            } else {
                $done = QuoteDelivery::send($this->app, $id, $this->request->post('subject'), $this->request->post('message'), $this->request->postBool('set_deal_value'));
                QuoteDelivery::remember($this->app, $id, $done['link']);
                $this->app->session->flash($done['status'] === 'sent' ? 'ok' : 'chyba', ($done['status'] === 'sent'
                    ? t('Quote %1$s was sent to %2$s.', $done['number'], $done['to'])
                    : t('Quote %1$s is sent, but the mail server refused the e-mail for now (%2$s) – the site tries again from its mail queue.', $done['number'], $done['error']))
                    . ($done['deal_value_set'] ? ' ' . t('The deal’s value is now the quote’s net total.') : '') . ($done['warnings'] !== [] ? ' ' . implode(' ', $done['warnings']) : ''));
            }
        } catch (\DomainException $e) {
            return $this->back($e->getMessage(), 'send', ['id' => $id], 'chyba');
        }

        return $this->back('', 'send', ['id' => $id]);
    }

    protected function actionPreview(): Response
    {
        $quote = Quotes::get($this->db, $this->request->getInt('id'));
        if ($quote === null) {
            return $this->error('The quote does not exist.', 404);
        }
        $snapshot = $quote['snapshot'] ?? (Quotes::snapshot($this->app, $quote) + ['sent_at' => null]);

        return QuotePage::html(QuotePage::document($this->app, $snapshot, QuotePage::state($this->app, $quote)));
    }

    protected function actionLink(): Response
    {
        $id = $this->request->postInt('id');
        if (!$this->request->isPost()) {
            return $this->back('', 'send', ['id' => $this->request->getInt('id')]);
        }
        try {
            QuoteDelivery::remember($this->app, $id, QuoteDelivery::issueLink($this->app, $id, $this->app->auth()->id()));
        } catch (\DomainException $e) {
            return $this->linkBack($id, $e->getMessage(), 'chyba');
        }

        return $this->linkBack($id, t('The quote has a new link; the previous one no longer works.'));
    }

    protected function actionLinkWithdraw(): Response
    {
        $id = $this->request->postInt('id');
        if (!$this->request->isPost()) {
            return $this->back('', 'send', ['id' => $this->request->getInt('id')]);
        }

        return $this->linkBack($id, QuoteDelivery::withdrawLink($this->app, $id, $this->app->auth()->id())
            ? t('The customer’s link was withdrawn – it shows “page not found” now.') : t('The quote has no link to withdraw.'));
    }

    protected function actionTextsSave(): Response
    {
        if (!$this->app->auth()->isAdmin()) {
            return $this->error('Only an administrator can change the settings of quotes.', 403);
        }
        if (!$this->request->isPost()) {
            return $this->back('', 'settings');
        }
        $s = $this->app->settings();
        foreach (QuoteDelivery::textLanguages($s) as $language) {
            foreach (QuoteDelivery::TEXTS as $key => $setting) {
                $name = $setting . ($language !== '' ? '_' . $language : '');
                $value = trim(mb_substr(str_replace("\r\n", "\n", strip_tags($this->request->post($name))), 0, Quotes::TEXT_MAX));
                if ($value !== $s->get($name) || $language === '') {
                    $s->set($name, $value);
                }
            }
        }
        ChangeLog::write($this->app, Quotes::SECTION, 'settings', t('Default texts of quotes'));

        return $this->back('The default texts of quotes were saved.', 'settings');
    }

    /** Back to the send dialog, or to the quote's detail when the form came from there (back=detail). */
    private function linkBack(int $id, string $message, string $type = 'ok'): Response
    {
        if ($this->request->post('back') === 'detail') {
            $this->app->session->flash($type, $message);

            return Response::redirect($this->app->url(Quotes::adminPath($id)));
        }

        return $this->back($message, 'send', ['id' => $id], $type);
    }
}
