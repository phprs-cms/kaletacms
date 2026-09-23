<?php

declare(strict_types=1);

namespace MiroCMS\Front;

use MiroCMS\Core\App;

/**
 * Typy obsahu nad běžným článkem: přehrávač zvuku či videa, živá reportáž a recenze s hodnocením.
 * Hotové HTML se vkládá do textu článku (přehrávač a reportáž před text, hodnocení za něj), takže
 * šablony webu o typech obsahu nemusí nic vědět; vzhled je v image/web.css.
 */
final class TypyObsahu
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
        if (!empty($clanek['zamceno'])) {
            $clanek['text'] .= $this->sdileniHtml($clanek);

            return $clanek;
        }
        if (($clanek['sablona_soubor'] ?? '') === 'rozhovor') {
            // šablona Rozhovor: odstavec, který je celý tučně, je otázka
            $clanek['text'] = preg_replace('#<p>(\s*<(strong|b)>(?:(?!</?(?:strong|b|p)\b).)*</\2>\s*)</p>#is', '<p class="mc-otazka">$1</p>', (string) $clanek['text']) ?? $clanek['text'];
        }
        $slov = count(preg_split('/\s+/u', trim(strip_tags((string) $clanek['text'])), -1, PREG_SPLIT_NO_EMPTY) ?: []);
        $clanek['text'] = $this->sOsnovou($this->vlozeneAdresy((string) $clanek['text']));
        $pred = self::prehravac((string) $clanek['medium_url'], $this->app->request->basePath(), (string) $clanek['titulek']);
        if ((int) $clanek['zive'] > 0) {
            $pred .= $this->ziveHtml($clanek);
        }
        if ($slov >= 400 && $this->app->settings()->bool('doba_cteni')) {
            // od dvou minut čtení výš; 200 slov za minutu je běžné tempo čtení na obrazovce
            // div, ne odstavec: šablony dávají prvnímu odstavci textu iniciálu
            $pred = '<div class="mc-cteni" data-prubeh>' . e(t('Čtení na %s min', (int) round($slov / 200))) . '</div>' . $pred;
        }
        $clanek['text'] = $pred . $clanek['text'] . self::recenzeHtml($clanek) . $this->autorHtml($clanek) . $this->sdileniHtml($clanek);

        return $clanek;
    }

    /**
     * Odstavec, ve kterém je jen adresa videa, podcastu (YouTube, Vimeo, Spotify) nebo příspěvku ze sítí (X, Instagram,
     * Facebook, TikTok, Mastodon), se na webu promění v přehrávač či vložený příspěvek. Redaktor jen vloží adresu na samostatný řádek.
     */
    public function vlozeneAdresy(string $html): string
    {
        if (!preg_match('#youtu|vimeo\.com|spotify\.com|twitter\.com|//x\.com|instagram\.com|facebook\.com|tiktok\.com|/@[^/"<\s]+/\d{10}#i', $html)) {
            return $html;
        }

        return preg_replace_callback('#<p>\s*(?:<a\b[^>]*href="(https?://[^"]+)"[^>]*>[^<]*</a>|(https?://[^\s<]+))\s*(?:<br\s*/?>)?\s*</p>#i', function (array $m): string {
            $adresa = html_entity_decode($m[1] !== '' ? $m[1] : $m[2]);
            if (($prispevek = self::prispevek($adresa)) !== '') {
                return $prispevek;
            }
            $prehravac = self::prehravac($adresa, '', '', true);

            return $prehravac !== '' && !str_contains($prehravac, '<audio') && !str_contains($prehravac, '<video') ? $prehravac : $m[0];
        }, $html) ?? $html;
    }

    /**
     * Osnova dlouhého článku: od tří mezititulků H2 dostanou nadpisy kotvy a před text se vloží obsah.
     * Kotvy se hodí i samy o sobě - jde odkázat na konkrétní část článku.
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
            : '<nav class="mc-osnova" aria-label="' . e(t('Obsah článku')) . '"><strong>' . e(t('Obsah článku')) . '</strong><ol>' . implode('', $polozky) . '</ol></nav>' . $html;
    }

    /**
     * Sdílení článku: obyčejné odkazy bez cizích skriptů; na telefonu tlačítko systémového sdílení (image/web.js).
     *
     * @param array<string, mixed> $clanek
     */
    public function sdileniHtml(array $clanek): string
    {
        if (!$this->app->settings()->bool('sdileni')) {
            return '';
        }
        $adresa = $this->app->request->origin() . $this->app->url('clanek/' . $clanek['seo_link']);
        $u = rawurlencode($adresa);
        $t = rawurlencode((string) $clanek['titulek']);
        $site = [
            'Facebook' => 'https://www.facebook.com/sharer/sharer.php?u=' . $u,
            'X' => 'https://x.com/intent/post?url=' . $u . '&text=' . $t,
            'LinkedIn' => 'https://www.linkedin.com/sharing/share-offsite/?url=' . $u,
            'WhatsApp' => 'https://wa.me/?text=' . $t . '%20' . $u,
            'E-mail' => 'mailto:?subject=' . $t . '&body=' . $u,
        ];
        $html = '<aside class="mc-sdileni" aria-label="' . e(t('Sdílet článek')) . '"><span>' . e(t('Sdílet')) . '</span>'
            . '<button type="button" data-sdilet data-adresa="' . e($adresa) . '" data-titulek="' . e((string) $clanek['titulek']) . '" hidden>' . e(t('Sdílet…')) . '</button>';
        foreach ($site as $nazev => $odkaz) {
            $html .= '<a href="' . e($odkaz) . '"' . ($nazev === 'E-mail' ? '' : ' target="_blank" rel="noopener nofollow"') . '>' . e($nazev) . '</a>';
        }

        return $html . '<button type="button" data-kopirovat="' . e($adresa) . '" data-hotovo="' . e(t('Zkopírováno')) . '">' . e(t('Kopírovat odkaz')) . '</button></aside>';
    }

    /**
     * Medailonek autora pod článkem - jen když má autor vyplněných pár vět o sobě (Můj účet).
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

        return '<aside class="mc-autor" aria-label="' . e(t('O autorovi')) . '">'
            . ($foto !== '' ? '<img src="' . e($foto) . '" alt="" width="72" height="72" loading="lazy">' : '')
            . '<div><a class="mc-autor-jmeno" href="' . e($this->app->url('autor/' . (int) $clanek['autor'])) . '" rel="author">' . e($clanek['autor_jm']) . '</a>'
            . ($clanek['autor_pozice'] !== '' ? '<span>' . e($clanek['autor_pozice']) . '</span>' : '')
            . '<p>' . nl2br(e(trim((string) $clanek['autor_bio']))) . '</p></div></aside>';
    }

    /**
     * Příspěvek ze sociální sítě podle adresy. Vkládá se jako rámec dané služby (žádný cizí skript ve stránce) a až po kliknutí;
     * do té doby je vidět jen odkaz na originál, který zůstává i pro čtečky a RSS.
     */
    public static function prispevek(string $url): string
    {
        [$sit, $vlozit] = match (true) {
            (bool) preg_match('#^https://(?:www\.|mobile\.)?(?:twitter|x)\.com/[A-Za-z0-9_]{1,15}/status/(\d{5,25})#', $url, $m) => ['X', 'https://platform.twitter.com/embed/Tweet.html?dnt=true&id=' . $m[1]],
            (bool) preg_match('#^https://(?:www\.)?instagram\.com/(p|reel|tv)/([A-Za-z0-9_-]{5,20})#', $url, $m) => ['Instagram', 'https://www.instagram.com/' . $m[1] . '/' . $m[2] . '/embed/'],
            (bool) preg_match('#^https://(?:www\.|m\.)?facebook\.com/[^\s"<>]+/(?:posts|videos|photos)/[^\s"<>]+$#', $url) => ['Facebook', 'https://www.facebook.com/plugins/post.php?show_text=true&width=500&href=' . rawurlencode($url)],
            (bool) preg_match('#^https://(?:www\.)?tiktok\.com/@[A-Za-z0-9_.]{1,30}/video/(\d{10,25})#', $url, $m) => ['TikTok', 'https://www.tiktok.com/embed/v2/' . $m[1]],
            (bool) preg_match('#^https://([a-z0-9][a-z0-9.-]{2,80}\.[a-z]{2,20})/@([A-Za-z0-9_]{1,40}(?:@[a-z0-9.-]{3,80})?)/(\d{10,25})/?$#', $url, $m) => ['Mastodon', 'https://' . $m[1] . '/@' . $m[2] . '/' . $m[3] . '/embed'],
            default => ['', ''],
        };
        if ($vlozit === '') {
            return '';
        }

        return '<figure class="mc-medium mc-prispevek"><button type="button" class="mc-medium-spustit" data-vlozit="' . e($vlozit) . '" data-titulek="' . e(t('Příspěvek ze sítě') . ' ' . $sit) . '">'
            . e(t('Zobrazit příspěvek ze sítě')) . ' ' . e($sit) . '<small>' . e(t('Obsah se načte ze služby')) . ' ' . e((string) parse_url($vlozit, PHP_URL_HOST)) . '</small></button>'
            . '<figcaption><a href="' . e($url) . '" rel="noopener nofollow">' . e(t('Otevřít původní příspěvek')) . '</a></figcaption></figure>';
    }

    /** Přehrávač podle adresy: soubor (audio/video), YouTube, Vimeo, Spotify. Cizí přehrávače se načtou až po kliknutí. */
    public static function prehravac(string $url, string $zaklad, string $titulek, bool $jenZname = false): string
    {
        if ($url === '') {
            return '';
        }
        $adresa = preg_match('#^(https?:)?/#i', $url) ? $url : $zaklad . '/' . $url;
        $pripona = strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
        if (in_array($pripona, ['mp3', 'm4a', 'ogg', 'oga', 'wav', 'aac'], true)) {
            return '<figure class="mc-medium mc-medium-zvuk"><audio controls preload="none" src="' . e($adresa) . '"></audio></figure>';
        }
        if (in_array($pripona, ['mp4', 'webm', 'm4v'], true)) {
            return '<figure class="mc-medium"><video controls preload="metadata" playsinline src="' . e($adresa) . '"></video></figure>';
        }
        $vlozit = match (true) {
            (bool) preg_match('#(?:youtube\.com/(?:watch\?(?:.*&)?v=|shorts/|live/|embed/)|youtu\.be/)([A-Za-z0-9_-]{11})#', $url, $m) => 'https://www.youtube-nocookie.com/embed/' . $m[1] . '?autoplay=1',
            (bool) preg_match('#vimeo\.com/(?:video/)?(\d+)#', $url, $m) => 'https://player.vimeo.com/video/' . $m[1] . '?autoplay=1&dnt=1',
            (bool) preg_match('#open\.spotify\.com/(episode|show|track)/([A-Za-z0-9]+)#', $url, $m) => 'https://open.spotify.com/embed/' . $m[1] . '/' . $m[2],
            default => '',
        };
        if ($vlozit === '' && $jenZname) {
            return '';
        }
        if ($vlozit === '') {
            return '<div class="mc-medium-odkaz"><a class="mc-tl" href="' . e($adresa) . '" rel="noopener">▶ ' . e(t('Přehrát')) . '</a></div>';
        }
        $zvuk = str_contains($vlozit, 'spotify');

        // přehrávač cizí služby se vloží až po kliknutí: do té doby se k ní nic neposílá (soukromí, rychlost)
        return '<figure class="mc-medium' . ($zvuk ? ' mc-medium-zvuk' : '') . '"><button type="button" class="mc-medium-spustit" data-vlozit="' . e($vlozit) . '" data-titulek="' . e($titulek) . '">'
            . '<span aria-hidden="true">▶</span> ' . e(t($zvuk ? 'Přehrát zvuk' : 'Přehrát video')) . '<small>' . e(t('Obsah se načte ze služby')) . ' ' . e((string) parse_url($vlozit, PHP_URL_HOST)) . '</small></button></figure>';
    }

    /**
     * Zápisy živé reportáže, nejnovější nahoře.
     *
     * @param array<string, mixed> $clanek
     */
    public function ziveHtml(array $clanek, int $odId = 0): string
    {
        $zapisy = $this->app->db()->all('SELECT * FROM {zive} WHERE idc = ? AND idz > ? ORDER BY cas DESC, idz DESC LIMIT 300', [$clanek['idc'], $odId]);
        $html = '';
        foreach ($zapisy as $z) {
            $html .= '<article class="mc-zapis' . ($z['dulezite'] ? ' mc-zapis-dulezity' : '') . '" data-zapis="' . (int) $z['idz'] . '"><time datetime="' . e(date('c', strtotime($z['cas']))) . '">'
                . e(date('G:i', strtotime($z['cas']))) . (date('Y-m-d', strtotime($z['cas'])) !== date('Y-m-d') ? ' <small>' . e(datum($z['cas'])) . '</small>' : '') . '</time><div>' . $z['text'] . '</div></article>';
        }
        if ($odId > 0) {
            return $html; // jen nové zápisy pro průběžné načítání
        }
        $bezi = (int) $clanek['zive'] === 1;

        return '<section class="mc-zive" aria-label="' . e(t('Živá reportáž')) . '"' . ($bezi ? ' data-zive="' . e($this->app->url('zive/' . (int) $clanek['idc'] . '.json')) . '"' : '') . '>'
            . '<p class="mc-zive-stav">' . ($bezi ? '<span class="mc-zive-tecka" aria-hidden="true"></span> ' . e(t('Živě')) . ' · ' . e(t('stránka se doplňuje sama')) : e(t('Reportáž skončila'))) . '</p>'
            . '<div class="mc-zive-zapisy" aria-live="polite">' . $html . '</div></section>';
    }

    /** @param array<string, mixed> $clanek */
    public static function recenzeHtml(array $clanek): string
    {
        if ($clanek['recenze_hodnoceni'] === null) {
            return '';
        }
        $procent = max(0, min(100, (int) $clanek['recenze_hodnoceni']));

        return '<aside class="mc-recenze" aria-label="' . e(t('Hodnocení')) . '"><div class="mc-recenze-cislo"><strong>' . $procent . '</strong><span>%</span></div><div class="mc-recenze-text">'
            . '<span>' . e(t('Hodnocení redakce')) . '</span>' . ($clanek['recenze_predmet'] !== '' ? '<strong>' . e($clanek['recenze_predmet']) . '</strong>' : '')
            . '<div class="mc-recenze-pruh" role="img" aria-label="' . $procent . ' %"><i style="width:' . $procent . '%"></i></div></div></aside>';
    }
}
