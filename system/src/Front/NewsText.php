<?php

declare(strict_types=1);

namespace Kaleta\Front;

use Kaleta\Core\App;
use Kaleta\Core\Html;

/**
 * Additions to the news item text: video embedded by URL, outline from subheadings, author bio and share links.
 * The finished HTML is inserted into the text, so site templates need not know anything about the additions; the look
 * is in image/web.css.
 */
final class NewsText
{
    public function __construct(private readonly App $app)
    {
    }

    /**
     * @param array<string, mixed> $newsItem
     * @return array<string, mixed>
     */
    public function complete(array $newsItem): array
    {
        $newsItem['text'] = $this->withOutline($this->embedVideoUrls((string) $newsItem['text'])) . $this->authorHtml($newsItem) . $this->shareHtml($newsItem);

        return $newsItem;
    }

    /**
     * A paragraph containing only a video URL (YouTube, Vimeo) turns into a player on the site. Putting the URL on its own
     * line is enough.
     */
    public function embedVideoUrls(string $html): string
    {
        if (!preg_match('#youtu|vimeo\.com#i', $html)) {
            return $html;
        }
        // on the DOM, not with a regular expression over the stored markup (an attribute's text must never become a tag);
        // a replaced paragraph is marked by a comment with a random key and the player goes in after serialization
        $players = [];
        $key = 'ka-player-' . bin2hex(random_bytes(8)) . '-';
        $output = Html::transform($html, function (\Dom\HTMLElement $body, \Dom\HTMLDocument $doc) use (&$players, $key): void {
            foreach (iterator_to_array($body->querySelectorAll('p')) as $p) {
                $url = self::paragraphUrl($p);
                $player = $url !== null ? self::player($url, '', '', true) : '';
                if ($player !== '' && !str_contains($player, '<audio') && !str_contains($player, '<video')) {
                    $players['<!--' . $key . count($players) . '-->'] = $player;
                    $p->replaceWith($doc->createComment($key . (count($players) - 1)));
                }
            }
        });

        return $players === [] ? $html : strtr($output, $players);
    }

    /** The URL of a paragraph that holds only a URL – as text or as one link – and at most a line break after it. */
    private static function paragraphUrl(\Dom\Element $p): ?string
    {
        if ($p->attributes->length > 0) {
            return null;
        }
        $nodes = array_values(array_filter(iterator_to_array($p->childNodes), fn (\Dom\Node $n): bool => !($n instanceof \Dom\Text && trim($n->data) === '')));
        if (count($nodes) === 2 && $nodes[1] instanceof \Dom\Element && strtolower($nodes[1]->localName) === 'br') {
            array_pop($nodes);
        }
        if (count($nodes) !== 1) {
            return null;
        }
        $node = $nodes[0];
        if ($node instanceof \Dom\Text) {
            return preg_match('#^https?://\S+$#iD', trim($node->data)) ? trim($node->data) : null;
        }
        if ($node instanceof \Dom\Element && strtolower($node->localName) === 'a' && $node->firstElementChild === null) {
            $href = (string) $node->getAttribute('href');

            return preg_match('#^https?://\S+$#iD', $href) ? $href : null;
        }

        return null;
    }

    /**
     * Outline of a longer news item: from three H2 subheadings on, the headings get anchors and a table of contents is
     * inserted before the text. The anchors are useful on their own too - you can link to a specific part of the text.
     */
    public function withOutline(string $html): string
    {
        if (!$this->app->settings()->bool('article_outline') || substr_count($html, '<h2') < 3) {
            return $html;
        }
        $items = [];
        $used = [];
        // the anchors are set on the DOM, not with a regular expression over the stored markup
        $output = Html::transform($html, function (\Dom\HTMLElement $body) use (&$items, &$used): void {
            foreach (iterator_to_array($body->querySelectorAll('h2')) as $h2) {
                $text = trim((string) preg_replace('/\s+/u', ' ', $h2->textContent));
                if ($text === '' || $h2->hasAttribute('id')) {
                    continue;
                }
                $base = slugify($text, 60);
                $base = $base !== '' && !Html::isSafeId($base) ? 'h-' . $base : $base; // a heading "Obsah" must not take the id of the site's own hooks (3.15, N21)
                $id = $base;
                for ($i = 2; isset($used[$id]); $i++) {
                    $id = $base . '-' . $i;
                }
                $used[$id] = true;
                $items[] = '<li><a href="#' . e($id) . '">' . e($text) . '</a></li>';
                $h2->setAttribute('id', $id);
            }
        });
        $html = $items === [] ? $html : $output;

        return count($items) < 3 ? $html
            : '<nav class="ka-osnova" aria-label="' . e(t('Content')) . '"><strong>' . e(t('Content')) . '</strong><ol>' . implode('', $items) . '</ol></nav>' . $html;
    }

    /**
     * Sharing a news item: plain links without third-party scripts; on a phone a system share button (image/web.js).
     *
     * @param array<string, mixed> $newsItem
     */
    public function shareHtml(array $newsItem): string
    {
        if (!$this->app->settings()->bool('share_buttons')) {
            return '';
        }
        $url = $this->app->request->origin() . $this->app->url('novinky/' . $newsItem['seo_link']);
        $u = rawurlencode($url);
        $t = rawurlencode((string) $newsItem['titulek']);
        $networks = [
            'Facebook' => 'https://www.facebook.com/sharer/sharer.php?u=' . $u,
            'X' => 'https://x.com/intent/post?url=' . $u . '&text=' . $t,
            'LinkedIn' => 'https://www.linkedin.com/sharing/share-offsite/?url=' . $u,
            'WhatsApp' => 'https://wa.me/?text=' . $t . '%20' . $u,
            'Email' => 'mailto:?subject=' . $t . '&body=' . $u,
        ];
        $html = '<aside class="ka-sdileni" aria-label="' . e(t('Share')) . '"><span>' . e(t('Share')) . '</span>'
            . '<button type="button" data-sdilet data-adresa="' . e($url) . '" data-titulek="' . e((string) $newsItem['titulek']) . '" hidden>' . e(t('Share…')) . '</button>';
        foreach ($networks as $name => $link) {
            $html .= '<a href="' . e($link) . '"' . ($name === 'Email' ? '' : ' target="_blank" rel="noopener nofollow"') . '>' . e($name) . '</a>';
        }

        return $html . '<button type="button" data-kopirovat="' . e($url) . '" data-hotovo="' . e(t('Copied')) . '">' . e(t('Copy link')) . '</button></aside>';
    }

    /**
     * Author bio below the news item - only when the author has filled in a few sentences about themselves ("Můj účet", My account).
     *
     * @param array<string, mixed> $newsItem
     */
    public function authorHtml(array $newsItem): string
    {
        if (trim((string) ($newsItem['autor_bio'] ?? '')) === '' || ($newsItem['autor_jm'] ?? null) === null) {
            return '';
        }
        $photo = (string) $newsItem['autor_foto'];
        $photo = $photo === '' ? '' : (preg_match('#^(https?:)?/#i', $photo) ? $photo : $this->app->request->basePath() . '/' . $photo);

        return '<aside class="ka-autor" aria-label="' . e(t('About the author')) . '">'
            . ($photo !== '' ? '<img src="' . e($photo) . '" alt="" width="72" height="72" loading="lazy">' : '')
            . '<div><strong class="ka-autor-jmeno">' . e($newsItem['autor_jm']) . '</strong>'
            . ($newsItem['autor_pozice'] !== '' ? '<span>' . e($newsItem['autor_pozice']) . '</span>' : '')
            . '<p>' . nl2br(e(trim((string) $newsItem['autor_bio']))) . '</p></div></aside>';
    }

    /** Player by URL: file (audio/video), YouTube, Vimeo. Third-party players load only after a click. */
    public static function player(string $url, string $base, string $title, bool $onlyKnown = false): string
    {
        if ($url === '') {
            return '';
        }
        $mediaUrl = preg_match('#^(https?:)?/#i', $url) ? $url : $base . '/' . $url;
        $extension = strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
        if (in_array($extension, ['mp3', 'm4a', 'ogg', 'oga', 'wav', 'aac'], true)) {
            return '<figure class="ka-medium ka-medium-zvuk"><audio controls preload="none" src="' . e($mediaUrl) . '"></audio></figure>';
        }
        if (in_array($extension, ['mp4', 'webm', 'm4v'], true)) {
            return '<figure class="ka-medium"><video controls preload="metadata" playsinline src="' . e($mediaUrl) . '"></video></figure>';
        }
        $embedUrl = match (true) {
            (bool) preg_match('#(?:youtube\.com/(?:watch\?(?:.*&)?v=|shorts/|live/|embed/)|youtu\.be/)([A-Za-z0-9_-]{11})#', $url, $m) => 'https://www.youtube-nocookie.com/embed/' . $m[1] . '?autoplay=1',
            (bool) preg_match('#vimeo\.com/(?:video/)?(\d+)#', $url, $m) => 'https://player.vimeo.com/video/' . $m[1] . '?autoplay=1&dnt=1',
            default => '',
        };
        if ($embedUrl === '' && $onlyKnown) {
            return '';
        }
        if ($embedUrl === '') {
            return '<div class="ka-medium-odkaz"><a class="ka-tl" href="' . e($mediaUrl) . '" rel="noopener">▶ ' . e(t('Play')) . '</a></div>';
        }
        // a third-party player is embedded only after a click: until then nothing is sent to that service (privacy, speed)
        return '<figure class="ka-medium"><button type="button" class="ka-medium-spustit" data-vlozit="' . e($embedUrl) . '" data-titulek="' . e($title) . '">'
            . '<span aria-hidden="true">▶</span> ' . e(t('Play video')) . '<small>' . e(t('Content will load from')) . ' ' . e((string) parse_url($embedUrl, PHP_URL_HOST)) . '</small></button></figure>';
    }
}
