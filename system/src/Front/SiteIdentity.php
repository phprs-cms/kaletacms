<?php

declare(strict_types=1);

namespace Kaleta\Front;

use Kaleta\Core\Settings;

/**
 * Site identity ("Vzhled → Identita webu", Appearance → Site identity): the main color and fonts that flow into the page frame.
 *
 * The frame (image/sablona.css) uses the identity through the CSS custom properties --ka-akcent, --ka-pismo-titulky and
 * --ka-pismo-text, each with its own default value, e.g. --akcent: var(--ka-akcent, #326891).
 * Fonts are system fonts only (no downloads from third-party servers - speed and GDPR).
 */
final class SiteIdentity
{
    /** key => [name, description, CSS font-family] */
    public const array TITLE_FONTS = [
        'vychozi' => ['Default font', 'the font of the default site design', ''],
        'elegantni' => ['Elegant serif', 'Bodoni, Didot – elegance and fashion', '"Bodoni 72", Didot, "Bodoni MT", "Playfair Display", Georgia, serif'],
        'klasicke' => ['Classic serif', 'Georgia – serious and easy to read', 'Georgia, "Times New Roman", Times, serif'],
        'knizni' => ['Book', 'Charter, Cambria – calm and literary', 'Charter, "Bitstream Charter", "Sitka Text", Cambria, Georgia, serif'],
        'moderni' => ['Modern sans-serif', 'the device\'s system font – clean and neutral', 'system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif'],
        'grotesk' => ['Bold grotesque', 'Helvetica, Arial – strong and confident', '"Helvetica Neue", Helvetica, "Arial Nova", Arial, sans-serif'],
        'zaoblene' => ['Rounded', 'friendly, for services and family businesses', 'ui-rounded, "SF Pro Rounded", "Hiragino Maru Gothic ProN", Quicksand, Nunito, system-ui, sans-serif'],
        'strojove' => ['Typewriter', 'technology and development', 'ui-monospace, "SF Mono", Menlo, Consolas, "Courier New", monospace'],
        // 3.12: a site with an Arabic version (Builder\FontScripts) – the named families where the device has them, else its Arabic font
        'arabske' => ['Arabic sans-serif', 'Noto Sans Arabic, IBM Plex Sans Arabic – for Arabic text', '"Noto Sans Arabic", "IBM Plex Sans Arabic", "Geeza Pro", "Segoe UI", Tahoma, system-ui, sans-serif'],
    ];

    public const array TEXT_FONTS = [
        'vychozi' => ['Default font', '', ''],
        'patkove' => ['Serif', 'Georgia – comfortable for long reads', 'Georgia, "Times New Roman", Times, serif'],
        'knizni' => ['Book', 'Charter, Cambria', 'Charter, "Bitstream Charter", "Sitka Text", Cambria, Georgia, serif'],
        'moderni' => ['Sans-serif', 'the device\'s system font', 'system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif'],
        'grotesk' => ['Grotesque', 'Helvetica, Arial', '"Helvetica Neue", Helvetica, "Arial Nova", Arial, sans-serif'],
        'arabske' => ['Arabic sans-serif', 'Noto Sans Arabic, IBM Plex Sans Arabic – for Arabic text', '"Noto Sans Arabic", "IBM Plex Sans Arabic", "Geeza Pro", "Segoe UI", Tahoma, system-ui, sans-serif'],
    ];

    /**
     * Site icons, manifest and browser bar color for <head>. The site's colors and fonts are output by the design system
     * (Builder\DesignSystem). The PNG sizes (media/ikona-<n>.png) are prepared by Appearance when the icon is saved.
     */
    public static function head(Settings $siteSettings, string $base): string
    {
        $html = '';
        $icon = $siteSettings->get('favicon');
        if ($icon !== '') {
            $html .= '<link rel="icon" href="' . e((preg_match('#^(https?:)?/#', $icon) ? '' : $base . '/') . $icon) . "\">\n";
        }
        $png = KALETA_ROOT . '/media/ikona-180.png';
        if (is_file($png)) {
            $v = '?v=' . filemtime($png);
            $html .= '<link rel="icon" type="image/png" sizes="32x32" href="' . e($base . '/media/ikona-32.png' . $v) . "\">\n"
                . '<link rel="apple-touch-icon" href="' . e($base . '/media/ikona-180.png' . $v) . "\">\n";
        }
        $html .= '<link rel="manifest" href="' . e($base . '/manifest.webmanifest') . "\">\n";
        $colors = \Kaleta\Builder\DesignSystem::load($siteSettings)['barvy'];
        $html .= '<meta name="theme-color" content="' . e($colors['pozadi']) . '">' . "\n";

        return $html;
    }

    /** Site manifest: name, colors and icons – a phone then pins the site to the home screen with its own icon and name. */
    public static function manifest(Settings $siteSettings, string $base): string
    {
        $colors = \Kaleta\Builder\DesignSystem::load($siteSettings)['barvy'];
        $name = $siteSettings->get('site_name') ?: 'Website';
        $icons = [];
        foreach ([192, 512] as $n) {
            if (is_file(KALETA_ROOT . '/media/ikona-' . $n . '.png')) {
                $icons[] = ['src' => $base . '/media/ikona-' . $n . '.png', 'sizes' => $n . 'x' . $n, 'type' => 'image/png', 'purpose' => 'any'];
            }
        }

        return (string) json_encode(array_filter([
            'name' => $name, 'short_name' => mb_strimwidth($name, 0, 12, ''), 'start_url' => $base . '/', 'scope' => $base . '/',
            'display' => 'browser', 'background_color' => $colors['pozadi'], 'theme_color' => $colors['pozadi'], 'icons' => $icons,
        ]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }
}
