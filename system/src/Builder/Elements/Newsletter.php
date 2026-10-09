<?php

declare(strict_types=1);

namespace Kaleta\Builder\Elements;

use Kaleta\Core\Antispam;
use Kaleta\Builder\Context;
use Kaleta\Builder\Element;

/**
 * Subscription to news by e-mail (the Newsletter extension). The address is saved only after confirmation via the link in the e-mail
 * (double opt-in); the subscriber list can be exported from the admin to a mailing tool.
 */
final class Newsletter extends Element
{
    public const string TYPE = 'newsletter';
    public const string NAME = 'Odběr novinek';
    public const string DESCRIPTION = 'An e-mail field with subscription confirmation – you will find the addresses under Subscribers in the administration.';
    public const string ICON = 'newsletter';
    public const string GROUP = 'Dynamic';
    public const string EXTENSION = 'newsletter';
    public const array HTML_TAGS = ['form'];

    public static function properties(): array
    {
        return [
            'tlacitko' => ['typ' => 'text', 'popisek' => 'Button', 'vychozi' => t('Subscribe'), 'max' => 40],
            'souhlas' => ['typ' => 'text', 'popisek' => 'Text below the field', 'vychozi' => t('We only send news and offers. You can unsubscribe with one click in every e-mail.'), 'max' => 300],
        ];
    }

    public static function baseCss(): string
    {
        return '.ka-newsletter { display: grid; gap: var(--ka-mezera-xs); max-width: 32rem; }
.ka-newsletter-radek { display: flex; flex-wrap: wrap; gap: var(--ka-mezera-xs); }
.ka-newsletter input[type="email"] { flex: 1 1 14rem; min-width: 0; padding: 0.65em 0.9em; border: 1px solid var(--ka-barva-linka); border-radius: var(--ka-zaobleni); background: var(--ka-barva-pozadi); color: inherit; font: inherit; }
.ka-newsletter button { padding: 0.65em 1.2em; border: 0; border-radius: var(--ka-zaobleni); background: var(--ka-barva-primarni); color: var(--ka-barva-na-primarni); font: inherit; font-weight: 600; cursor: pointer; }
.ka-newsletter small { color: var(--ka-barva-tlumeny); }
.ka-newsletter-hlaska { margin: 0; font-weight: 600; }';
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        $o = $p['obsah'];
        $r = $k->app->request;
        $id = 'nl-' . $p['id'];
        $result = $r->get('subscription');
        $message = match ($result) {
            'ok' => t('Thank you! We have sent you an e-mail with a link – click it to confirm your subscription.'),
            'chyba' => t('Please check the e-mail address.'),
            'limit' => t('Too many attempts in a row. Please try again in a moment.'),
            'captcha' => t('Please confirm that you are not a robot and send the form again.'),
            default => '',
        };
        $antispam = new Antispam($k->app->db(), $k->app->settings());
        // anchor for the return after sending: the element id (anchor or style), otherwise its own
        $anchor = preg_match('/ id="([^"]*)"/', $a, $m) ? $m[1] : $id;
        if ($anchor === $id) {
            $a = ' id="' . e($id) . '"' . $a;
        }

        return '<form' . Text::withClass($a, 'ka-newsletter') . ' method="post" action="' . e($k->url('odber')) . '">'
            . ($message !== '' ? '<p class="ka-newsletter-hlaska" role="status">' . e($message) . '</p>' : '')
            . '<label class="ka-jen-ctecka" for="' . e($id) . '-email">' . e(t('Your e-mail')) . '</label>'
            . '<div class="ka-newsletter-radek"><input type="email" id="' . e($id) . '-email" name="email" autocomplete="email" required maxlength="190" placeholder="' . e(t('you@example.com')) . '">'
            . '<button type="submit">' . e($o['tlacitko']) . '</button></div>'
            . Form::captcha($k)
            . ($o['souhlas'] !== '' ? '<small>' . e($o['souhlas']) . '</small>' : '')
            . '<input type="hidden" name="zpet" value="' . e($k->app->url($r->path())) . '"><input type="hidden" name="kotva" value="' . e($anchor) . '">' . \Kaleta\Front\Forms::ATTRIBUTION_FIELDS
            . $antispam->fields('odber') . '</form>';
    }
}
