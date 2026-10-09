<?php

declare(strict_types=1);

namespace Kaleta\Front;

use Kaleta\Core\App;
use Kaleta\Core\DraftComments as Comments;
use Kaleta\Core\Preview;
use Kaleta\Core\Response;

/**
 * Comment mode of a shared draft preview (2.15, Core\DraftComments): the small "Comment" widget on the page and the POST it
 * sends. The signed preview key with the comments flag is the only permission – no account, no cookie; a key without the
 * flag or an expired one gets 403, as if the mode did not exist.
 */
final class DraftComments
{
    public function __construct(private readonly App $app)
    {
    }

    /** POST /_comment (or /_komentar) from the widget: back to the preview with ?comment=ok | chyba | limit, 403 when the key does not allow it. */
    public function post(): Response
    {
        $r = $this->app->request;
        $target = $r->post('cil');
        $parsed = Comments::parseTarget($target);
        if (!$r->isPost() || $parsed === null || !Preview::allowsComments($this->app->db(), $this->app->settings(), $target, $r->post('klic'))) {
            return new Response(e(t('This preview link does not allow comments.')), 403, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store']);
        }
        $back = $r->post('zpet');
        $back = preg_match('~^/[^\s\\\\#]*$~D', $back) && !str_starts_with($back, '//') ? $back : $this->app->url('') . '?build=koncept&preview_key=' . rawurlencode($r->post('klic'));
        $redirect = fn (string $result): Response => Response::redirect($back . (str_contains($back, '?') ? '&' : '?') . 'comment=' . $result . '#ka-komentar', 303);
        if ($r->post('web_adresa') !== '') {
            return $redirect('ok'); // a bot filled the hidden field – it gets a thank-you and nothing is stored
        }
        if (Comments::tooMany($this->app, $parsed['id'])) {
            return $redirect('limit');
        }
        Comments::count($this->app, $parsed['id']);
        $id = Comments::add($this->app, $target, $r->post('prvek') !== '' ? $r->post('prvek') : null, $r->post('citace'), $r->post('jmeno'), $r->post('text'));

        return $redirect($id > 0 ? 'ok' : 'chyba');
    }

    /** The widget for a page draft shown through a key that allows comments (appended to the page by Front\Kernel). */
    public function widget(string $target, string $key, string $path): string
    {
        return $this->app->view->render('front/komentare', [
            'cil' => $target, 'klic' => $key,
            'zpet' => $this->app->url($path) . '?build=koncept&preview_key=' . rawurlencode($key),
            'akce' => $this->app->url('_komentar'),
            'vysledek' => in_array($this->app->request->get('comment'), ['ok', 'chyba', 'limit'], true) ? $this->app->request->get('comment') : '',
        ]);
    }
}
