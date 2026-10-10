<?php

declare(strict_types=1);

namespace Kaleta\Front;

use Kaleta\Builder\Collections;
use Kaleta\Builder\DesignSystem;
use Kaleta\Builder\Presets;
use Kaleta\Core\App;
use Kaleta\Core\Extensions;
use Kaleta\Core\Hours;
use Kaleta\Core\Language;
use Kaleta\Core\Response;
use Kaleta\Core\Settings;

/**
 * Screen mode (2.11): a kiosk address /screen/<secret> for a TV or a tablet in a reception, showroom or waiting room that
 * rotates slides by itself – the latest news, items of the chosen collections (a collection with a date field only the
 * upcoming ones) and today's opening hours with "Open now, until 17:00".
 *
 * The page stands alone in the site's design tokens: full viewport, large type, no header or footer, noindex, no cookies,
 * no analytics and no consent bar. A small inline script rotates the slides (no animation under prefers-reduced-motion)
 * and reloads the page every RELOAD_MINUTES to pick up changes; without JavaScript the slide from ?s= shows and a meta
 * refresh in <noscript> rotates. The secret part of the address is generated, so the screen is not found by guessing –
 * it is shown only in the administration, never over MCP.
 *
 * Settings: screen_mode, screen_seconds, screen_collections (addresses, comma-separated), screen_news, screen_hours,
 * screen_clock, screen_secret.
 */
final class Screen
{
    public const int MIN_SECONDS = 5;
    public const int MAX_SECONDS = 60;
    public const int DEFAULT_SECONDS = 10;
    public const int RELOAD_MINUTES = 15;
    public const int NEWS_LIMIT = 5;
    public const int ITEMS_LIMIT = 12;

    /** How long a slide's text may be – a screen is read from a distance. */
    private const int TEXT_LENGTH = 240;

    public const string SECRET_PATTERN = '/^[a-f0-9]{32}$/D';

    /** Seconds per slide within the limits (a value from the settings or a form). */
    public static function seconds(string|int $value): int
    {
        $n = (int) $value;

        return $n === 0 ? self::DEFAULT_SECONDS : max(self::MIN_SECONDS, min(self::MAX_SECONDS, $n));
    }

    /** Does /screen/<given> open? Only with the mode on, a secret set and the given one equal (in constant time). */
    public static function opens(bool $on, string $secret, string $given): bool
    {
        return $on && $secret !== '' && preg_match(self::SECRET_PATTERN, $given) === 1 && hash_equals($secret, $given);
    }

    /** @return list<string> addresses of the collections chosen for the screen */
    public static function collections(Settings $s): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $s->get('screen_collections'))), fn (string $slug): bool => preg_match(Collections::ITEM_LINK_PATTERN, $slug) === 1));
    }

    /**
     * The settings for the administration and for Claude: whether the screen is on and what it shows – never the secret.
     *
     * @return array{on: bool, seconds: int, collections: list<string>, news: bool, hours: bool, clock: bool}
     */
    public static function settings(Settings $s): array
    {
        return ['on' => $s->bool('screen_mode'), 'seconds' => self::seconds($s->get('screen_seconds')), 'collections' => self::collections($s),
            'news' => $s->bool('screen_news'), 'hours' => $s->bool('screen_hours'), 'clock' => $s->bool('screen_clock')];
    }

    /** The secret part of the address: created when there is none (or anew when asked), so the mode never runs without one. */
    public static function ensureSecret(Settings $s, bool $renew = false): string
    {
        $secret = $s->get('screen_secret');
        if ($renew || preg_match(self::SECRET_PATTERN, $secret) !== 1) {
            $secret = bin2hex(random_bytes(16));
            $s->set('screen_secret', $secret);
        }

        return $secret;
    }

    /** The full address of the screen for the administrator; '' until a secret exists. */
    public static function url(App $app): string
    {
        $secret = $app->settings()->get('screen_secret');

        return preg_match(self::SECRET_PATTERN, $secret) === 1 ? $app->request->origin() . $app->url('screen/' . $secret) : '';
    }

    /**
     * The date fields of a collection: the first date-and-time field is the start, the second (if any) the end – such a
     * collection shows only its upcoming items. Null without one.
     *
     * @param list<array{klic: string, typ: string}> $fields
     * @return array{0: string, 1: string}|null
     */
    public static function dateFields(array $fields): ?array
    {
        $dates = array_values(array_filter(array_map(fn (array $f): string => $f['typ'] === 'termin' ? (string) $f['klic'] : '', $fields)));

        return $dates === [] ? null : [$dates[0], $dates[1] ?? ''];
    }

    /**
     * One slide of a collection item from its placeholder values (Collections::values): the first image, the name and the
     * date or the card fields – the preset's card fields, or the first three short fields of a collection without a preset.
     * A number is shown with its label, a date and time as the date, a longer text shortened.
     *
     * @param array<string, mixed> $collection with decoded "pole"
     * @param array<string, array{0: string, 1: string}> $values
     * @return array{kind: string, label: string, title: string, date: string, text: string, lines: list<string>, image: string}
     */
    public static function card(array $collection, array $values): array
    {
        $fields = array_column((array) $collection['pole'], null, 'klic');
        $types = array_column((array) $collection['pole'], 'typ', 'klic');
        $preset = Presets::of($collection);
        $keys = $preset !== null ? (array) $preset['card'] : array_slice(array_keys(array_filter($types, fn (string $t): bool => in_array($t, ['text', 'radky', 'cislo', 'termin', 'datum'], true))), 0, 3);
        $image = array_search('obrazek', $types, true);
        $slide = ['kind' => 'item', 'label' => (string) $collection['nazev'], 'title' => $values['nazev'][0] ?? '', 'date' => '', 'text' => '', 'lines' => [], 'image' => is_string($image) ? ($values[$image][0] ?? '') : ''];
        foreach ($keys as $key) {
            $value = trim((string) ($values[$key][0] ?? ''));
            if (!isset($types[$key]) || $value === '') {
                continue;
            }
            $type = $types[$key];
            if ($type === 'termin' || $type === 'datum') {
                $slide['date'] = $slide['date'] === '' ? ($type === 'datum' ? format_date($value) : $value) : $slide['date'] . ' – ' . ($type === 'datum' ? format_date($value) : $value);
            } elseif ($type === 'html' || $type === 'radky') {
                $slide['text'] .= ($slide['text'] === '' ? '' : ' ') . self::plain($value);
            } elseif ($type === 'cislo') {
                $slide['lines'][] = $fields[$key]['popisek'] . ': ' . $value;
            } elseif (!in_array($type, ['obrazek', 'odkaz', 'soubor'], true)) {
                $slide['lines'][] = $value;
            }
        }
        $slide['text'] = mb_strimwidth($slide['text'], 0, self::TEXT_LENGTH, '…');

        return $slide;
    }

    /** Formatted text as one line for a screen (the HTML of an answer or a news lead). */
    public static function plain(string $html): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5)));
    }

    /**
     * All slides of the screen in the site language: news, the chosen collections, the opening hours.
     *
     * @return list<array{kind: string, label: string, title: string, date: string, text: string, lines: list<string>, image: string}>
     */
    public static function slides(App $app, ?\DateTimeImmutable $now = null): array
    {
        $s = $app->settings();
        $db = $app->db();
        $now ??= new \DateTimeImmutable();
        $slides = [];
        if ($s->bool('screen_news') && Extensions::isEnabled($s, 'novinky')) {
            foreach ((new NewsRepository($db, $s, $app->request->basePath()))->listPublished(1, self::NEWS_LIMIT)[0] as $n) {
                $slides[] = ['kind' => 'news', 'label' => t('News'), 'title' => (string) $n['titulek'], 'date' => format_date((string) $n['datum']),
                    'text' => mb_strimwidth(self::plain((string) $n['uvod']), 0, self::TEXT_LENGTH, '…'), 'lines' => [], 'image' => (string) $n['obrazek']];
            }
        }
        foreach (self::collections($s) as $slug) {
            $collection = Collections::bySlug($db, $slug);
            if ($collection === null) {
                continue; // deleted since it was chosen
            }
            $dates = self::dateFields($collection['pole']);
            // a collection with a date field shows what is still to come, the nearest first (Collections::periodCondition)
            [$items] = $dates === null
                ? Collections::items($db, (int) $collection['idk'], Language::siteColumn(), self::ITEMS_LIMIT)
                : Collections::items($db, (int) $collection['idk'], Language::siteColumn(), self::ITEMS_LIMIT, 'pole', null, 1, $dates[0], ['nadchazejici', $dates[0], $dates[1]]);
            foreach ($items as $item) {
                $slide = self::card($collection, Collections::values($collection, $item, $app->url(...), $db));
                $slide['image'] = $slide['image'] === '' || preg_match('#^(https?:)?//|^/#', $slide['image']) ? $slide['image'] : $app->request->basePath() . '/' . $slide['image'];
                $slides[] = $slide;
            }
        }
        if ($s->bool('screen_hours') && ($status = Hours::statusText($app, $now)) !== '') {
            $slides[] = ['kind' => 'hours', 'label' => t('Opening hours'), 'title' => $status, 'date' => '', 'text' => '', 'lines' => [t('Today') . ': ' . Hours::todayText($app, $now)], 'image' => ''];
        }

        return $slides;
    }

    /** The screen page: slides, the clock and the rotation; the slide ?s= is shown first (and alone without JavaScript). */
    public static function response(App $app): Response
    {
        $s = $app->settings();
        $slides = self::slides($app);
        $count = max(1, count($slides));
        $active = max(0, $app->request->getInt('s')) % $count;
        $seconds = self::seconds($s->get('screen_seconds'));
        $path = $app->url('screen/' . $s->get('screen_secret'));
        $dark = in_array($s->get('dark_mode'), ['auto', 'tmavy'], true);
        $ds = DesignSystem::load($s);
        $base = $app->request->basePath();
        $siteName = $s->get('site_name');
        $logo = $s->get('logo');
        $logoHtml = $logo !== '' ? '<img class="obrazovka-logo" src="' . e($base . '/' . ltrim($logo, '/')) . '" alt="' . e($siteName) . '">' : '<span class="obrazovka-nazev">' . e($siteName) . '</span>';
        $html = '<!doctype html><html lang="' . e(Language::tag(Language::code())) . '"' . ($dark ? ' data-tmavy' : '') . ($s->get('dark_mode') === 'tmavy' ? ' data-tema="tmavy"' : '') . '><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow"><title>' . e($siteName) . '</title>'
            . ($count > 1 ? '<noscript><meta http-equiv="refresh" content="' . $seconds . '; url=' . e($path . '?s=' . (($active + 1) % $count)) . '"></noscript>' : '')
            . DesignSystem::fontPreloads($ds, $base) . '<style>' . DesignSystem::css($ds, $base) . self::css() . '</style></head><body class="obrazovka">'
            . '<header class="obrazovka-hlava">' . $logoHtml . ($s->bool('screen_clock') ? '<time class="obrazovka-hodiny" id="obrazovka-hodiny">' . e(date('H:i')) . '</time>' : '') . '</header><main class="obrazovka-slidy">';
        if ($slides === []) {
            $html .= '<section class="obrazovka-slide aktivni"><div class="obrazovka-obsah"><h1>' . e($siteName) . '</h1></div></section>';
        }
        foreach ($slides as $i => $slide) {
            $html .= '<section class="obrazovka-slide obrazovka-' . e($slide['kind']) . ($slide['image'] !== '' ? ' s-obrazkem' : '') . ($i === $active ? ' aktivni' : '') . '" aria-hidden="' . ($i === $active ? 'false' : 'true') . '">'
                . ($slide['image'] !== '' ? '<figure class="obrazovka-obrazek"><img src="' . e($slide['image']) . '" alt="" loading="' . ($i === $active ? 'eager' : 'lazy') . '"></figure>' : '')
                . '<div class="obrazovka-obsah"><p class="obrazovka-stitek">' . e($slide['label']) . '</p><h1>' . e($slide['title']) . '</h1>'
                . ($slide['date'] !== '' ? '<p class="obrazovka-datum">' . e($slide['date']) . '</p>' : '')
                . ($slide['text'] !== '' ? '<p class="obrazovka-text">' . e($slide['text']) . '</p>' : '')
                . ($slide['lines'] !== [] ? '<ul class="obrazovka-radky">' . implode('', array_map(fn (string $line): string => '<li>' . e($line) . '</li>', $slide['lines'])) . '</ul>' : '')
                . '</div></section>';
        }
        $html .= '</main><script>' . self::script($active, $seconds) . '</script></body></html>';

        return new Response($html, 200, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store', 'X-Robots-Tag' => 'noindex, nofollow']);
    }

    /** The rotation: the next slide every few seconds, a clock, and a reload every RELOAD_MINUTES to pick up changes. */
    private static function script(int $active, int $seconds): string
    {
        return '(function(){var s=document.querySelectorAll(".obrazovka-slide"),i=' . $active . ',n=s.length;'
            . 'function show(k){for(var j=0;j<n;j++){s[j].classList.toggle("aktivni",j===k);s[j].setAttribute("aria-hidden",j===k?"false":"true")}}'
            . 'if(n>1){setInterval(function(){i=(i+1)%n;show(i)},' . ($seconds * 1000) . ')}'
            . 'setTimeout(function(){location.replace(location.pathname)},' . (self::RELOAD_MINUTES * 60000) . ');'
            . 'var c=document.getElementById("obrazovka-hodiny");if(c){var tick=function(){var d=new Date();c.textContent=("0"+d.getHours()).slice(-2)+":"+("0"+d.getMinutes()).slice(-2)};tick();setInterval(tick,10000)}})();';
    }

    /** The look of the screen from the site's tokens: colours and fonts, large type, one slide filling the viewport. */
    private static function css(): string
    {
        return '
html, body { margin: 0; height: 100%; background: var(--ka-barva-pozadi); color: var(--ka-barva-text); font-family: var(--ka-pismo-text); overflow: hidden; }
.obrazovka-hlava { position: fixed; inset: 0 0 auto 0; z-index: 2; display: flex; justify-content: space-between; align-items: center; padding: 2vmin 3vmin; font-size: clamp(1.2rem, 2.2vw, 2rem); }
.obrazovka-logo { max-height: 7vmin; width: auto; }
.obrazovka-nazev { font-family: var(--ka-pismo-titulky); font-weight: 700; }
.obrazovka-hodiny { font-variant-numeric: tabular-nums; color: var(--ka-barva-tlumeny); }
.obrazovka-slidy { position: relative; height: 100vh; height: 100dvh; }
.obrazovka-slide { position: absolute; inset: 0; display: grid; grid-template-columns: 1fr; align-items: center; padding: 12vmin 6vmin 6vmin; box-sizing: border-box; opacity: 0; visibility: hidden; transition: opacity .7s ease, visibility .7s; }
.obrazovka-slide.aktivni { opacity: 1; visibility: visible; }
.obrazovka-slide.s-obrazkem { grid-template-columns: minmax(0, 5fr) minmax(0, 6fr); gap: 5vmin; }
.obrazovka-obrazek { margin: 0; height: 100%; max-height: 76vh; }
.obrazovka-obrazek img { width: 100%; height: 100%; object-fit: cover; border-radius: var(--ka-zaobleni); display: block; }
.obrazovka-obsah { min-width: 0; }
.obrazovka-stitek { margin: 0 0 1.5vmin; font-size: clamp(1.1rem, 1.8vw, 1.8rem); font-weight: 600; letter-spacing: .04em; text-transform: uppercase; color: var(--ka-barva-primarni); }
.obrazovka-slide h1 { margin: 0; font-family: var(--ka-pismo-titulky); font-weight: 700; font-size: clamp(2.4rem, 5.6vw, 5.5rem); line-height: 1.1; overflow-wrap: anywhere; }
.obrazovka-datum, .obrazovka-text, .obrazovka-radky { font-size: clamp(1.4rem, 2.6vw, 2.6rem); line-height: 1.45; }
.obrazovka-datum { margin: 2vmin 0 0; font-weight: 600; }
.obrazovka-text { margin: 3vmin 0 0; color: var(--ka-barva-tlumeny); }
.obrazovka-radky { margin: 3vmin 0 0; padding: 0; list-style: none; }
.obrazovka-radky li { margin: 0 0 1vmin; }
.obrazovka-hours h1 { color: var(--ka-barva-primarni); }
@media (orientation: portrait) {
  .obrazovka-slide.s-obrazkem { grid-template-columns: 1fr; grid-template-rows: minmax(0, 42vh) auto; align-content: center; }
  .obrazovka-obrazek { max-height: 42vh; }
  .obrazovka-slide h1 { font-size: clamp(2.2rem, 8vw, 4.5rem); }
  .obrazovka-datum, .obrazovka-text, .obrazovka-radky { font-size: clamp(1.3rem, 3.6vw, 2.2rem); }
}
@media (prefers-reduced-motion: reduce) { .obrazovka-slide { transition: none; } }
';
    }
}
