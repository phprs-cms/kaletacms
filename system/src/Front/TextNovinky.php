<?php

declare(strict_types=1);

namespace Kaleta\Front;

use Kaleta\Core\App;

/**
 * Doplňky textu novinky: video vložené adresou, osnova z mezititulků, medailonek autora a odkazy pro sdílení.
 * Hotové HTML se vkládá do textu, takže šablony webu o doplňcích nemusí nic vědět; vzhled je v image/web.css.
 */
final class TextNovinky
{
    public function __construct(private readonly App $app)
    {
    }

    /**
     * @param array<string, mixed> $clanek
     * @return array<string, mixed>
     */
    public function dopln(array $clanek): array
    {
        $clanek['text'] = $this->sOsnovou($this->vlozeneAdresy((string) $clanek['text'])) . $this->autorHtml($clanek) . $this->sdileniHtml($clanek);

        return $clanek;
    }

    /**
     * Odstavec, ve kterém je jen adresa videa (YouTube, Vimeo), se na webu promění v přehrávač. Stačí vložit adresu na samostatný řádek.
     */
    public function vlozeneAdresy(string $html): string
    {
        if (!preg_match('#youtu|vimeo\.com#i', $html)) {
            return $html;
        }

        return preg_replace_callback('#<p>\s*(?:<a\b[^>]*href="(https?://[^"]+)"[^>]*>[^<]*</a>|(https?://[^\s<]+))\s*(?:<br\s*/?>)?\s*</p>#i', function (array $m): string {
            $prehravac = self::prehravac(html_entity_decode($m[1] !== '' ? $m[1] : $m[2]), '', '', true);

            return $prehravac !== '' && !str_contains($prehravac, '<audio') && !str_contains($prehravac, '<video') ? $prehravac : $m[0];
        }, $html) ?? $html;
    }

    /**
     * Osnova delší novinky: od tří mezititulků H2 dostanou nadpisy kotvy a před text se vloží obsah.
     * Kotvy se hodí i samy o sobě - jde odkázat na konkrétní část textu.
     */
    public function sOsnovou(string $html): string
    {
        if (!$this->app->settings()->bool('osnova_clanku') || substr_count($html, '<h2') < 3) {
            return $html;
        }
        $polozky = [];
        $pouzite = [];
        $html = preg_replace_callback('#<h2\b([^>]*)>(.*?)</h2>#is', function (array $m) use (&$polozky, &$pouzite): string {
            $text = trim(html_entity_decode(strip_tags($m[2]), ENT_QUOTES | ENT_HTML5));
            if ($text === '' || str_contains($m[1], ' id=')) {
                return $m[0];
            }
            $id = $zaklad = slugify($text, 60);
            for ($i = 2; isset($pouzite[$id]); $i++) {
                $id = $zaklad . '-' . $i;
            }
            $pouzite[$id] = true;
            $polozky[] = '<li><a href="#' . e($id) . '">' . e($text) . '</a></li>';

            return '<h2' . $m[1] . ' id="' . e($id) . '">' . $m[2] . '</h2>';
        }, $html) ?? $html;

        return count($polozky) < 3 ? $html
            : '<nav class="ka-osnova" aria-label="' . e(t('Obsah')) . '"><strong>' . e(t('Obsah')) . '</strong><ol>' . implode('', $polozky) . '</ol></nav>' . $html;
    }

    /**
     * Sdílení novinky: obyčejné odkazy bez cizích skriptů; na telefonu tlačítko systémového sdílení (image/web.js).
     *
     * @param array<string, mixed> $clanek
     */
    public function sdileniHtml(array $clanek): string
    {
        if (!$this->app->settings()->bool('sdileni')) {
            return '';
        }
        $adresa = $this->app->request->origin() . $this->app->url('novinky/' . $clanek['seo_link']);
        $u = rawurlencode($adresa);
        $t = rawurlencode((string) $clanek['titulek']);
        $site = [
            'Facebook' => 'https://www.facebook.com/sharer/sharer.php?u=' . $u,
            'X' => 'https://x.com/intent/post?url=' . $u . '&text=' . $t,
            'LinkedIn' => 'https://www.linkedin.com/sharing/share-offsite/?url=' . $u,
            'WhatsApp' => 'https://wa.me/?text=' . $t . '%20' . $u,
            'E-mail' => 'mailto:?subject=' . $t . '&body=' . $u,
        ];
        $html = '<aside class="ka-sdileni" aria-label="' . e(t('Sdílet')) . '"><span>' . e(t('Sdílet')) . '</span>'
            . '<button type="button" data-sdilet data-adresa="' . e($adresa) . '" data-titulek="' . e((string) $clanek['titulek']) . '" hidden>' . e(t('Sdílet…')) . '</button>';
        foreach ($site as $nazev => $odkaz) {
            $html .= '<a href="' . e($odkaz) . '"' . ($nazev === 'E-mail' ? '' : ' target="_blank" rel="noopener nofollow"') . '>' . e($nazev) . '</a>';
        }

        return $html . '<button type="button" data-kopirovat="' . e($adresa) . '" data-hotovo="' . e(t('Zkopírováno')) . '">' . e(t('Kopírovat odkaz')) . '</button></aside>';
    }

    /**
     * Medailonek autora pod novinkou - jen když má autor vyplněných pár vět o sobě (Můj účet).
     *
     * @param array<string, mixed> $clanek
     */
    public function autorHtml(array $clanek): string
    {
        if (trim((string) ($clanek['autor_bio'] ?? '')) === '' || ($clanek['autor_jm'] ?? null) === null) {
            return '';
        }
        $foto = (string) $clanek['autor_foto'];
        $foto = $foto === '' ? '' : (preg_match('#^(https?:)?/#i', $foto) ? $foto : $this->app->request->basePath() . '/' . $foto);

        return '<aside class="ka-autor" aria-label="' . e(t('O autorovi')) . '">'
            . ($foto !== '' ? '<img src="' . e($foto) . '" alt="" width="72" height="72" loading="lazy">' : '')
            . '<div><strong class="ka-autor-jmeno">' . e($clanek['autor_jm']) . '</strong>'
            . ($clanek['autor_pozice'] !== '' ? '<span>' . e($clanek['autor_pozice']) . '</span>' : '')
            . '<p>' . nl2br(e(trim((string) $clanek['autor_bio']))) . '</p></div></aside>';
    }

    /** Přehrávač podle adresy: soubor (audio/video), YouTube, Vimeo. Cizí přehrávače se načtou až po kliknutí. */
    public static function prehravac(string $url, string $zaklad, string $titulek, bool $jenZname = false): string
    {
        if ($url === '') {
            return '';
        }
        $adresa = preg_match('#^(https?:)?/#i', $url) ? $url : $zaklad . '/' . $url;
        $pripona = strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
        if (in_array($pripona, ['mp3', 'm4a', 'ogg', 'oga', 'wav', 'aac'], true)) {
            return '<figure class="ka-medium ka-medium-zvuk"><audio controls preload="none" src="' . e($adresa) . '"></audio></figure>';
        }
        if (in_array($pripona, ['mp4', 'webm', 'm4v'], true)) {
            return '<figure class="ka-medium"><video controls preload="metadata" playsinline src="' . e($adresa) . '"></video></figure>';
        }
        $vlozit = match (true) {
            (bool) preg_match('#(?:youtube\.com/(?:watch\?(?:.*&)?v=|shorts/|live/|embed/)|youtu\.be/)([A-Za-z0-9_-]{11})#', $url, $m) => 'https://www.youtube-nocookie.com/embed/' . $m[1] . '?autoplay=1',
            (bool) preg_match('#vimeo\.com/(?:video/)?(\d+)#', $url, $m) => 'https://player.vimeo.com/video/' . $m[1] . '?autoplay=1&dnt=1',
            default => '',
        };
        if ($vlozit === '' && $jenZname) {
            return '';
        }
        if ($vlozit === '') {
            return '<div class="ka-medium-odkaz"><a class="ka-tl" href="' . e($adresa) . '" rel="noopener">▶ ' . e(t('Přehrát')) . '</a></div>';
        }
        // přehrávač cizí služby se vloží až po kliknutí: do té doby se k ní nic neposílá (soukromí, rychlost)
        return '<figure class="ka-medium"><button type="button" class="ka-medium-spustit" data-vlozit="' . e($vlozit) . '" data-titulek="' . e($titulek) . '">'
            . '<span aria-hidden="true">▶</span> ' . e(t('Přehrát video')) . '<small>' . e(t('Obsah se načte ze služby')) . ' ' . e((string) parse_url($vlozit, PHP_URL_HOST)) . '</small></button></figure>';
    }
}
