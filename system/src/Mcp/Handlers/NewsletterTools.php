<?php

declare(strict_types=1);

namespace Kaleta\Mcp\Handlers;

use Kaleta\Admin\Modules\Media;
use Kaleta\Admin\Modules\Categories;
use Kaleta\Admin\Modules\Pages;
use Kaleta\Core\App;
use Kaleta\Core\Language;
use Kaleta\Front\SiteIdentity;
use Kaleta\Builder\SiteParts;
use Kaleta\Builder\DesignSystem;
use Kaleta\Builder\Library;
use Kaleta\Builder\Collections;
use Kaleta\Builder\Publisher;
use Kaleta\Builder\Build;
use Kaleta\Builder\HtmlConverter;
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
            'list_newsletters' => ['Newsletters (Newsletter extension; users with the Newsletters section): drafts, scheduled, being sent and sent, with counts of recipients, sent and failed e-mails, the number of confirmed subscribers and sending_problem – why the site cannot send now (no SMTP server, cron not running).', Schema::object([])],
            'draft_newsletter' => ['Creates a newsletter draft (without id) or changes a draft or a scheduled one (with id). There is no e-mail builder: one template styled by the design system (colours, fonts, logo) with the subject, an introduction, news items, an optional button and the company footer with an unsubscribe link. Returns the plain-text version to check; the admin shows the HTML preview.',
                Schema::object(['id' => Schema::number('newsletter ID – only when changing it'), 'subject' => Schema::text('subject of the e-mail, also its heading'), 'preheader' => Schema::text('preview text next to the subject in the inbox (optional)'),
                    'intro' => Schema::text('introduction as plain text; an empty line starts a new paragraph, web addresses become links'),
                    'news_mode' => Schema::text('latest (the latest news_count items at the time of sending, default) | chosen (news_ids) | none'),
                    'news_count' => Schema::number('how many of the latest news items, 1–' . \Kaleta\Core\Mailing::MAX_NEWS . ', default 3'),
                    'news_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'chosen news item IDs in order (list_news), with news_mode chosen'],
                    'button_label' => Schema::text('button text (optional, with button_url)'), 'button_url' => Schema::text('button link: a path on the site (/contact) or https://…'),
                    'language' => Schema::text('language of the footer texts and the latest news on a multilingual site (code; empty = default)')])],
            'send_test_newsletter' => ['Sends the newsletter as a test to the connected user\'s own e-mail address – subscribers get nothing. Use it before asking the user to send.', Schema::object(['id' => Schema::number('newsletter ID')], ['id'])],
            'send_newsletter' => ['Sends the newsletter to all confirmed subscribers now, or schedules it with at. It cannot be taken back: only with the publishing permission and ONLY when the user explicitly asks to send it. Needs an SMTP server and a running cron (sending_problem in list_newsletters). unschedule: true turns a scheduled one back into a draft.',
                Schema::object(['id' => Schema::number('newsletter ID'), 'at' => Schema::text('YYYY-MM-DD HH:MM to schedule; empty = now'), 'unschedule' => ['type' => 'boolean', 'description' => 'true = cancel the scheduled sending']], ['id'])],
            'delete_newsletter' => ['Deletes a newsletter – a draft, a scheduled or a sent one (not one being sent). Only when the user explicitly asks.', Schema::object(['id' => Schema::number('newsletter ID')], ['id'])],
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

        return ['newsletters' => array_map(fn (array $n): array => $this->newsletter($n), $mailing::all($db)),
            'confirmed_subscribers' => $mailing::confirmedCount($db), 'sending_problem' => $mailing::problem($this->app)];
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
        $id = $mailing::save($this->app, array_intersect_key($a, array_flip(['subject', 'preheader', 'intro', 'news_mode', 'news_count', 'news_ids', 'button_label', 'button_url', 'language'])), (int) ($current['id'] ?? 0));
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
        if (!empty($a['unschedule'])) {
            $mailing::unschedule($this->app, (int) $n['id']);
        } else {
            $mailing::send($this->app, (int) $n['id'], isset($a['at']) ? (string) $a['at'] : null);
        }

        return $this->newsletter((array) $mailing::byId($db, (int) $n['id']));
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
