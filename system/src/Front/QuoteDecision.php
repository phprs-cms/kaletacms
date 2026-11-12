<?php

declare(strict_types=1);

namespace Kaleta\Front;

use Kaleta\Core\Antispam;
use Kaleta\Core\App;
use Kaleta\Core\Language;
use Kaleta\Core\QuoteDelivery;
use Kaleta\Core\Quotes;
use Kaleta\Core\Response;

/**
 * The customer's answer on their quote page (3.40, design A6 and A12 "Release Q2"; both settings off by default, A-12):
 *
 *  - Accept / Decline (setting quote_customer_decision): under a sent quote that is still valid, two folded steps
 *    (<details>, no script): "Accept the quotation" → Confirm, "Decline" → an optional reason → Decline the quotation.
 *    The form posts to the quote's own address (POST /quote/<token>): no CSRF token and no cookie, but bound to the link's
 *    token – whoever may answer is whoever has the link, as with the document itself. One decision (Quotes::customerDecision
 *    under the row lock); the answer goes back to the page as a code in the address (decision=done|closed), never a text.
 *    Posts count per network like failed tokens do (Core\Antispam): at most MAX_POSTS an hour, then a wrong token is a
 *    404 as on the page and a right one simply returns to it.
 *  - "First opened on" (setting quote_track_opened): the first view of the page by someone who is not signed in to the
 *    administration stores the time only (Quotes::markOpened) – no address, no browser, no cookie.
 *
 * The status changes "by the customer", the responsible person is notified (the bell names the number only) and the deal
 * is only offered as won or lost in the admin – never moved here. The labels are visitor texts in the quote's language.
 */
final class QuoteDecision
{
    /** Posts of answers per network and hour (Antispam type TRIES). */
    public const int MAX_POSTS = 10;
    public const string TRIES = 'quote_decision';

    /** The first view of a sent quote by the customer, when the site records it. @param array<string, mixed> $quote as Quotes::get returns it */
    public static function opened(App $app, array $quote): void
    {
        if ($app->settings()->bool('quote_track_opened') && $quote['status'] !== 'draft' && $quote['first_opened_at'] === null && $app->auth()->user() === null) {
            Quotes::markOpened($app->db(), (int) $quote['id']);
        }
    }

    /**
     * POST /quote/<token>: the customer's decision. Null = the plain 404 (a wrong, withdrawn or expired token, or too many
     * tries from the network); otherwise back to the page (303) with the result code.
     */
    public static function response(App $app, string $token): ?Response
    {
        $db = $app->db();
        $ip = Antispam::network($app->request->ip());
        $antispam = new Antispam($db, $app->settings());
        if ($antispam->count($ip, QuoteDelivery::TRIES, 0, 60) >= QuoteDelivery::MAX_TRIES) {
            return null;
        }
        $id = QuoteDelivery::byToken($db, $token);
        $quote = $id !== null ? Quotes::get($db, $id) : null;
        if ($quote === null || $quote['snapshot'] === null) {
            $antispam->write($ip, QuoteDelivery::TRIES, 0);

            return null;
        }
        $page = $app->request->basePath() . '/quote/' . $token;
        if ($antispam->count($ip, self::TRIES, (int) $quote['id'], 60) >= self::MAX_POSTS) {
            return Response::redirect($page, 303);
        }
        $antispam->write($ip, self::TRIES, (int) $quote['id']);
        $decision = match ($app->request->post('decision')) {
            'accept' => 'accepted',
            'decline' => 'declined',
            default => '',
        };
        $done = $decision !== '' && Quotes::customerDecision($app, (int) $quote['id'], $decision, $app->request->post('reason'));

        return Response::redirect($page . '?decision=' . ($done ? 'done' : 'closed'), 303);
    }

    /**
     * The customer's document with the answer under it: the Accept / Decline steps while the quote may be decided, the thank
     * you right after an answer, or a short line when an answer came too late. $html as QuotePage::document returns it.
     *
     * @param array<string, mixed> $quote as Quotes::get returns it
     */
    public static function into(App $app, string $html, array $quote, string $token): string
    {
        $result = $app->request->get('decision');
        $s = $app->settings();
        if (!Quotes::customerMayDecide($s, $quote) && !in_array($result, ['done', 'closed'], true)) {
            return $html;
        }
        $language = Language::ofContent($s, (string) ($quote['snapshot']['language'] ?? ''));
        $panel = Language::runWith($language, fn (): string => self::panel($app, $quote, $token, $result), '', $s->get('german_register'));
        $at = strrpos($html, '</article>');

        return $at === false ? $html : substr($html, 0, $at) . $panel . substr($html, $at);
    }

    /** @param array<string, mixed> $quote */
    private static function panel(App $app, array $quote, string $token, string $result): string
    {
        if ($result === 'done' && in_array($quote['status'], ['accepted', 'declined'], true)) {
            return '<section class="quote-decision" role="status" data-quote-decision="done"><p>' . e($quote['status'] === 'accepted'
                ? t('Thank you – you accepted the quotation. We will be in touch about the next steps.') : t('Thank you for your answer – you declined the quotation.')) . '</p></section>';
        }
        if (!Quotes::customerMayDecide($app->settings(), $quote)) {
            return $result === 'closed' ? '<section class="quote-decision" role="status" data-quote-decision="closed"><p>'
                . e(t('This quotation can no longer be answered here – please contact us.')) . '</p></section>' : '';
        }
        $action = e($app->request->basePath() . '/quote/' . $token);
        $number = (string) ($quote['snapshot']['number'] ?? $quote['number'] ?? '');

        return '<section class="quote-decision" aria-labelledby="quote-decision-heading" data-quote-decision="open"><h2 id="quote-decision-heading">' . e(t('Your answer')) . '</h2>'
            . '<div class="quote-decision-steps">'
            . '<details class="quote-decision-step" data-quote-accept><summary class="quote-button quote-button-primary">' . e(t('Accept the quotation')) . '</summary>'
            . '<form method="post" action="' . $action . '"><input type="hidden" name="decision" value="accept">'
            . '<p>' . e(t('By confirming you accept quotation %s as it stands. We will be in touch about the next steps.', $number)) . '</p>'
            . '<button type="submit" class="quote-button quote-button-primary">' . e(t('Confirm acceptance')) . '</button></form></details>'
            . '<details class="quote-decision-step" data-quote-decline><summary class="quote-button">' . e(t('Decline')) . '</summary>'
            . '<form method="post" action="' . $action . '"><input type="hidden" name="decision" value="decline">'
            . '<label for="quote-decline-reason">' . e(t('Why not? (optional)')) . '</label>'
            . '<textarea id="quote-decline-reason" name="reason" rows="3" maxlength="' . Quotes::CUSTOMER_REASON_MAX . '"></textarea>'
            . '<button type="submit" class="quote-button">' . e(t('Decline the quotation')) . '</button></form></details>'
            . '</div></section>';
    }
}
