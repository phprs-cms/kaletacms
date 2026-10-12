<?php

declare(strict_types=1);

namespace Kaleta\Builder\Elements;

use Kaleta\Builder\Context;
use Kaleta\Builder\Element;

/** Site logo from Appearance (without it, the site name) as a link to the home page. The height is changed by the „Výška“ (height) style. */
final class Logo extends Element
{
    public const string TYPE = 'logo';
    public const string NAME = 'Logo';
    public const string DESCRIPTION = 'The site logo from Appearance, otherwise the site name – a link to the home page.';
    public const string ICON = 'logo';
    public const string GROUP = 'Site parts';
    public const array HTML_TAGS = ['a'];
    public const bool PARTS_ONLY = true;

    public static function properties(): array
    {
        return ['nazev' => ['typ' => 'prepinac', 'popisek' => 'Site name next to the logo', 'vychozi' => false]];
    }

    public static function baseCss(): string
    {
        return '.ka-logo { display: inline-flex; align-items: center; gap: var(--ka-mezera-xs); height: 2.75rem; color: inherit; font-family: var(--ka-pismo-titulky); font-size: var(--ka-krok-1); font-weight: 800; line-height: 1.1; text-decoration: none; }
.ka-logo img { display: block; width: auto; height: 100%; max-width: none; }';
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        $siteSettings = $k->app->settings();
        $name = $siteSettings->get('site_name');
        $logo = \Kaleta\Core\Look::identity($siteSettings, 'logo'); // the draft logo in a preview of the draft look (3.12)
        $home = $k->url('');
        $content = $logo !== ''
            ? '<img src="' . e($k->image($logo)) . '" alt="' . e($p['obsah']['nazev'] ? '' : $name) . '"' . \Kaleta\Front\ImageHtml::logoSize($logo) . '>' . ($p['obsah']['nazev'] ? '<span>' . e($name) . '</span>' : '')
            : e($name);

        return '<a' . Text::withClass($a, 'ka-logo') . ' href="' . e($home) . '"' . ($k->path === $home ? ' aria-current="page"' : '') . '>' . $content . '</a>';
    }
}
