<?php

declare(strict_types=1);

namespace Kaleta\Mcp\Handlers;

use Kaleta\Mcp\Schema;

/**
 * MCP tools: newsletter (one method per tool, see Mcp\Catalog). Part of Mcp\Tools.
 *
 * @phpstan-ignore trait.unused
 */
trait NewsletterTools
{
    /**
     * The MCP definitions of the tools in this trait (3.49 P0): stored name => [description, input schema]. Tools::definitions()
     * lists them in the order of Tools::ORDER; the English names and texts are in Translator, the annotations in Catalog.
     *
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function newsletterToolsDefinitions(): array
    {
        return [
            'list_newsletters' => ['Newsletters (Newsletter extension; users with the Newsletters section): drafts, scheduled, being sent, paused, sent and cancelled, with counts of recipients, sent, failed and skipped e-mails (recipients = sent + failed + skipped once it ends), the number of confirmed subscribers and sending_problem – why the site cannot send now (no SMTP server, cron not running, no company name and postal address of the sender). A paused one has paused_at, pause_reason (manual | brake – sending kept failing at the mail server) and cancel_on, the day it is cancelled unless a person resumes it in the admin. warnings: what may hurt delivery without stopping it (no SPF or DMARC record, a connected mailing service). Each newsletter has its audience (all | language) and customers_only; a draft or a scheduled one has recipients_now, one that started has results (unsubscribed through its links, visits from it – null without a campaign name or statistics); audiences lists what this site offers beyond all (language, customers).', Schema::object([])],
            'draft_newsletter' => ['Creates a newsletter draft (without id) or changes a draft or a scheduled one (with id). There is no e-mail builder: one template styled by the design system (colours, fonts, logo) with the subject, an introduction, news items, an optional button and the company footer with an unsubscribe link. Returns the plain-text version to check and recipients_now; the admin shows the HTML preview. Who gets it: audience and customers_only (an unknown value is refused, never read as everyone; not changeable while scheduled).',
                Schema::object(['id' => Schema::number('newsletter ID – only when changing it'), 'subject' => Schema::text('subject of the e-mail, also its heading'), 'preheader' => Schema::text('preview text next to the subject in the inbox (optional)'),
                    'intro' => Schema::text('introduction as plain text; an empty line starts a new paragraph, web addresses become links'),
                    'news_mode' => Schema::text('latest (the latest news_count items at the time of sending, default) | chosen (news_ids) | none'),
                    'news_count' => Schema::number('how many of the latest news items, 1–' . \Kaleta\Core\Mailing::MAX_NEWS . ', default 3'),
                    'news_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'chosen news item IDs in order (list_news), with news_mode chosen'],
                    'button_label' => Schema::text('button text (optional, with button_url)'), 'button_url' => Schema::text('button link: a path on the site (/contact) or https://…'),
                    'language' => Schema::text('language of the footer texts and the latest news on a multilingual site (code; empty = default)'),
                    'audience' => Schema::text('all (every confirmed subscriber, default) | language (the confirmed subscribers of its language)'),
                    'customers_only' => ['type' => 'boolean', 'description' => 'true = only customers of the audience (a won deal or a quote the customer accepted)']])],
            'send_test_newsletter' => ['Sends the newsletter as a test to the connected user\'s own e-mail address – subscribers get nothing. Use it before asking the user to send.', Schema::object(['id' => Schema::number('newsletter ID')], ['id'])],
            'send_newsletter' => ['Sends the newsletter to its audience now, or schedules it with at. It cannot be taken back: only with the publishing permission and ONLY when the user explicitly asks to send it. Needs an SMTP server, a running cron and the sender\'s company name and postal address (sending_problem in list_newsletters). Addresses that bounced or refused e-mail are skipped. unschedule: true turns a scheduled one back into a draft; pause: true stops a send under way at once (no confirmation needed – resuming or cancelling it is done by a person in the admin). Use one of at, unschedule and pause per call.',
                Schema::object(['id' => Schema::number('newsletter ID'), 'at' => Schema::text('YYYY-MM-DD HH:MM to schedule; empty = now'), 'unschedule' => ['type' => 'boolean', 'description' => 'true = cancel the scheduled sending'],
                    'pause' => ['type' => 'boolean', 'description' => 'true = stop a newsletter that is being sent, at once']], ['id'])],
            'delete_newsletter' => ['Deletes a newsletter – a draft, a scheduled, a sent or a cancelled one (not one being sent or paused). Only when the user explicitly asks.', Schema::object(['id' => Schema::number('newsletter ID')], ['id'])],
        ];
    }

    /** list_newsletters */
    private function toolListNewsletters(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();
        if (!\Kaleta\Core\Extensions::isEnabled($this->app->settings(), 'newsletter') || !$auth->hasModule('newsletters')) {
            throw new \DomainException('Newsletters need the Newsletter extension and a user with access to the Newsletters section.');
        }
        $mailing = \Kaleta\Core\Mailing::class;
        $rows = $mailing::all($db);
        $results = \Kaleta\Core\NewsletterResults::ofMany($this->app, $rows); // 3.54 (C): one grouped query for every visit count
        $s = $this->app->settings();
        // 3.54: what this site offers beyond "all" – the language audience on a multilingual site, customers with the pipeline or quotes
        $audiences = array_keys(array_filter(['language' => \Kaleta\Core\Language::additional($s) !== [], 'customers' => \Kaleta\Core\Audience::customersAvailable($s)]));

        return ['newsletters' => array_map(fn (array $n): array => $this->newsletterSending($n, $results[(int) $n['id']] ?? null), $rows),
            'confirmed_subscribers' => $mailing::confirmedCount($db), 'audiences' => $audiences, 'sending_problem' => $mailing::problem($this->app), 'warnings' => $mailing::warnings($s)];
    }

    /** draft_newsletter */
    private function toolDraftNewsletter(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();
        if (!\Kaleta\Core\Extensions::isEnabled($this->app->settings(), 'newsletter') || !$auth->hasModule('newsletters')) {
            throw new \DomainException('Newsletters need the Newsletter extension and a user with access to the Newsletters section.');
        }
        $mailing = \Kaleta\Core\Mailing::class;
        $byId = fn (): array => $mailing::byId($db, (int) ($a['id'] ?? 0)) ?? throw new \InvalidArgumentException('The newsletter does not exist. Use list_newsletters.');

        $current = isset($a['id']) && (int) $a['id'] > 0 ? $byId() : null;
        $id = $mailing::save($this->app, array_intersect_key($a, array_flip(['subject', 'preheader', 'intro', 'news_mode', 'news_count', 'news_ids', 'button_label', 'button_url', 'language', 'audience', 'customers_only'])), (int) ($current['id'] ?? 0));
        $n = (array) $mailing::byId($db, $id);

        return $this->newsletter($n) + ['text' => str_replace($mailing::UNSUBSCRIBE, '(unsubscribe link)', $mailing::render($this->app, $n)[1]),
            'sending_problem' => $mailing::problem($this->app)];
    }

    /** send_test_newsletter */
    private function toolSendTestNewsletter(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();
        if (!\Kaleta\Core\Extensions::isEnabled($this->app->settings(), 'newsletter') || !$auth->hasModule('newsletters')) {
            throw new \DomainException('Newsletters need the Newsletter extension and a user with access to the Newsletters section.');
        }
        $mailing = \Kaleta\Core\Mailing::class;
        $byId = fn (): array => $mailing::byId($db, (int) ($a['id'] ?? 0)) ?? throw new \InvalidArgumentException('The newsletter does not exist. Use list_newsletters.');

        $n = $byId();
        $email = (string) ($auth->user()['email'] ?? '');
        if ($email === '') {
            throw new \DomainException('The connected user has no e-mail address – add one under My account in the admin.');
        }
        if (!$mailing::sendTest($this->app, $n, $email)) {
            throw new \DomainException('The test e-mail could not be sent: ' . \Kaleta\Core\Mail::$error);
        }

        return ['sent_to' => $email];
    }

    /** send_newsletter */
    private function toolSendNewsletter(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();
        if (!\Kaleta\Core\Extensions::isEnabled($this->app->settings(), 'newsletter') || !$auth->hasModule('newsletters')) {
            throw new \DomainException('Newsletters need the Newsletter extension and a user with access to the Newsletters section.');
        }
        $mailing = \Kaleta\Core\Mailing::class;
        $byId = fn (): array => $mailing::byId($db, (int) ($a['id'] ?? 0)) ?? throw new \InvalidArgumentException('The newsletter does not exist. Use list_newsletters.');

        $n = $byId();
        if (!$auth->canPublish()) {
            throw new \DomainException('Sending to subscribers needs the publishing permission.');
        }
        // 3.51: one action per call – a mixed call is refused before anything happens (only a pure stop goes without a code)
        $at = isset($a['at']) ? trim((string) $a['at']) : '';
        if (count(array_filter([!empty($a['pause']), !empty($a['unschedule']), $at !== ''])) > 1) {
            throw new \InvalidArgumentException('Choose one: at (schedule), unschedule or pause – not several in one call.');
        }
        if (!empty($a['pause'])) {
            $mailing::pause($this->app, (int) $n['id']);
        } elseif (!empty($a['unschedule'])) {
            $mailing::unschedule($this->app, (int) $n['id']);
        } else {
            // 3.54 (A2): the number the user confirmed is the one the server showed in the summary the gate validated – a
            // different number at the start sends nothing and asks again (RecipientCountChanged names the new number)
            $confirmed = \Kaleta\Mcp\Confirmation::confirmed('send_newsletter', (int) $n['id']);
            $mailing::send($this->app, (int) $n['id'], $at !== '' ? $at : null, $at === '' && isset($confirmed['recipients']) ? (int) $confirmed['recipients'] : null);
        }

        return $this->newsletterSending((array) $mailing::byId($db, (int) $n['id']));
    }

    /**
     * A newsletter as list_newsletters shows it: the keys of Tools::newsletter() and, 3.51, the counts of a paused or
     * cancelled one, skipped for an ended one, paused_at, pause_reason and cancel_on for a paused one (additive keys);
     * 3.54 (C): results – unsubscribed through its links and visits from it – for one that started.
     *
     * @param array<string, mixed> $n
     * @param array{sent: int, failed: int, skipped: int, unsubscribed: int, visits: ?int}|null $results computed for a list
     * @return array<string, mixed>
     */
    private function newsletterSending(array $n, ?array $results = null): array
    {
        $output = $this->newsletter($n);
        $status = (string) $n['status'];
        if (in_array($status, ['paused', 'cancelled'], true)) {
            $output += ['recipients' => (int) $n['recipients'], 'sent' => (int) $n['sent_count'], 'failed' => (int) $n['failed_count'],
                'started_at' => substr((string) $n['started_at'], 0, 16), 'finished_at' => !empty($n['finished_at']) ? substr((string) $n['finished_at'], 0, 16) : null];
        }
        if (in_array($status, ['sent', 'cancelled'], true)) {
            $output['skipped'] = (int) ($n['skipped_count'] ?? 0);
        }
        if ($status === 'paused') {
            $output += ['paused_at' => substr((string) $n['paused_at'], 0, 16), 'pause_reason' => (string) $n['pause_reason'],
                'cancel_on' => substr((string) \Kaleta\Core\Mailing::cancelOn($n), 0, 16)];
        }
        if (in_array($status, ['sending', 'paused', 'sent', 'cancelled'], true)) {
            $results ??= \Kaleta\Core\Mailing::results($this->app, $n);
            $output['results'] = ['unsubscribed' => $results['unsubscribed'], 'visits' => $results['visits']];
        }

        return $output;
    }

    /** delete_newsletter */
    private function toolDeleteNewsletter(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();
        if (!\Kaleta\Core\Extensions::isEnabled($this->app->settings(), 'newsletter') || !$auth->hasModule('newsletters')) {
            throw new \DomainException('Newsletters need the Newsletter extension and a user with access to the Newsletters section.');
        }
        $mailing = \Kaleta\Core\Mailing::class;
        $byId = fn (): array => $mailing::byId($db, (int) ($a['id'] ?? 0)) ?? throw new \InvalidArgumentException('The newsletter does not exist. Use list_newsletters.');

        $n = $byId();
        $mailing::delete($this->app, (int) $n['id']);

        return ['deleted' => (int) $n['id']];
    }
}
