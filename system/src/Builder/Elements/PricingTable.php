<?php

declare(strict_types=1);

namespace Kaleta\Builder\Elements;

use Kaleta\Builder\Context;
use Kaleta\Builder\Element;

/**
 * Pricing table: plans as cards in a responsive grid – name, price with its period, a short description, features and a button.
 * One plan can be highlighted (primary border and background, a label such as "Most popular"). A feature line starting with "-"
 * is not included in the plan: shown crossed out and greyed, with a text for screen readers – the line-through alone says nothing to them.
 */
final class PricingTable extends Element
{
    public const string TYPE = 'cenik';
    public const string NAME = 'Pricing table';
    public const string DESCRIPTION = 'Plans side by side – name, price, features and a button; one plan can be highlighted.';
    public const string ICON = 'cenik';
    public const array HTML_TAGS = ['div'];

    public static function properties(): array
    {
        $plan = fn (string $name, string $price, string $description, string $features, bool $highlighted): array => [
            'nazev' => $name, 'cena' => $price, 'obdobi' => t('/ month'), 'popis' => $description, 'funkce' => $features,
            'tlacitko' => t('Choose'), 'odkaz' => '#', 'zvyraznit' => $highlighted, 'stitek' => t('Most popular'),
        ];

        return ['plany' => ['typ' => 'polozky', 'popisek' => 'Plans', 'max' => 6, 'pole' => [
            'nazev' => ['typ' => 'text', 'popisek' => 'Plan name', 'vychozi' => '', 'max' => 80],
            'cena' => ['typ' => 'text', 'popisek' => 'Price', 'vychozi' => '', 'max' => 40],
            'obdobi' => ['typ' => 'text', 'popisek' => 'Period (e.g. / month)', 'vychozi' => '', 'max' => 40],
            'popis' => ['typ' => 'text', 'popisek' => 'Short description', 'vychozi' => '', 'max' => 300],
            'funkce' => ['typ' => 'radky', 'popisek' => 'Features – one per line; a line starting with "-" is not included', 'vychozi' => '', 'max' => 2000],
            'tlacitko' => ['typ' => 'text', 'popisek' => 'Button text', 'vychozi' => '', 'max' => 80],
            'odkaz' => ['typ' => 'odkaz', 'popisek' => 'Button link', 'vychozi' => '#'],
            'zvyraznit' => ['typ' => 'prepinac', 'popisek' => 'Highlighted plan', 'vychozi' => false],
            'stitek' => ['typ' => 'text', 'popisek' => 'Highlight label', 'vychozi' => '', 'max' => 60],
        ], 'vychozi' => [
            $plan(t('Basic'), '9', t('For a start.'), t('First feature') . "\n" . t('Second feature') . "\n- " . t('Third feature') . "\n- " . t('Fourth feature'), false),
            $plan(t('Standard'), '29', t('For most customers.'), t('First feature') . "\n" . t('Second feature') . "\n" . t('Third feature') . "\n- " . t('Fourth feature'), true),
            $plan(t('Premium'), '79', t('Everything included.'), t('First feature') . "\n" . t('Second feature') . "\n" . t('Third feature') . "\n" . t('Fourth feature'), false),
        ]]];
    }

    public static function baseCss(): string
    {
        return '.ka-cenik { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(16rem, 100%), 1fr)); gap: var(--ka-mezera-m); align-items: stretch; }
.ka-cenik-plan { position: relative; display: flex; flex-direction: column; gap: var(--ka-mezera-s); padding: var(--ka-mezera-l); border: 1px solid var(--ka-barva-linka); border-radius: var(--ka-zaobleni); background: var(--ka-barva-pozadi); }
.ka-cenik-plan--zvyrazneny { border: 2px solid var(--ka-barva-primarni); background: var(--ka-barva-primarni-jemna); box-shadow: var(--ka-stin-m); }
.ka-cenik-stitek { position: absolute; inset-block-start: 0; inset-inline-start: 50%; margin: 0; padding: 0.25em 0.9em; translate: -50% -50%; border-radius: 999px; background: var(--ka-barva-primarni); color: var(--ka-barva-na-primarni); font-size: var(--ka-krok--1); font-weight: 600; white-space: nowrap; }
.ka-cenik-plan h3 { margin: 0; font-size: var(--ka-krok-1); }
.ka-cenik-cena { display: flex; flex-wrap: wrap; align-items: baseline; gap: 0.35em; margin: 0; }
.ka-cenik-cena strong { font: 800 var(--ka-krok-4) / 1 var(--ka-pismo-titulky); }
.ka-cenik-cena span { color: var(--ka-barva-tlumeny); }
.ka-cenik-popis { margin: 0; color: var(--ka-barva-tlumeny); }
.ka-cenik-funkce { display: grid; flex: 1; gap: var(--ka-mezera-2xs); margin: 0; padding: 0; list-style: none; }
.ka-cenik-funkce li { display: flex; align-items: flex-start; gap: 0.6em; }
.ka-cenik-funkce li::before { content: "✓"; content: "✓" / ""; flex: none; color: var(--ka-barva-primarni); font-weight: 700; }
.ka-cenik-funkce .ka-cenik-ne { color: var(--ka-barva-tlumeny); text-decoration: line-through; }
.ka-cenik-funkce .ka-cenik-ne::before { content: "–"; content: "–" / ""; color: var(--ka-barva-tlumeny); }
.ka-cenik-plan .ka-tlacitko { justify-content: center; margin-block-start: auto; }
.ka-cenik-sr { position: absolute; width: 1px; height: 1px; margin: -1px; overflow: hidden; clip-path: inset(50%); white-space: nowrap; }';
    }

    /** @return list<array{0: bool, 1: string}> features as [included, text] – a line starting with "-" is not included */
    public static function features(string $lines): array
    {
        $features = [];
        foreach (array_filter(array_map('trim', explode("\n", $lines)), fn (string $l): bool => $l !== '') as $line) {
            $excluded = str_starts_with($line, '-');
            $text = trim($excluded ? substr($line, 1) : $line);
            if ($text !== '') {
                $features[] = [!$excluded, $text];
            }
        }

        return $features;
    }

    public static function rtlCss(): string
    {
        return '.ka-cenik-stitek { translate: 50% -50%; }';
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        $plans = array_values(array_filter($p['obsah']['plany'], fn (array $x): bool => $x['nazev'] !== ''));
        if ($plans === []) {
            return $k->editor ? '<div' . $a . ' style="padding:2rem;text-align:center;background:var(--ka-barva-plocha)">' . e(t('Add plans in the Content panel.')) . '</div>' : '';
        }
        $k->types['tlacitko'] = true; // the plan buttons are Button elements in appearance – their CSS goes to the page too
        $html = '';
        foreach ($plans as $i => $plan) {
            $highlighted = !empty($plan['zvyraznit']);
            $heading = 'cn-' . $p['id'] . '-' . $i;
            $features = '';
            foreach (self::features((string) $plan['funkce']) as [$included, $text]) {
                $features .= '<li' . ($included ? '' : ' class="ka-cenik-ne"') . '><span class="ka-cenik-sr">' . e($included ? t('Included:') : t('Not included:')) . ' </span>' . e($text) . '</li>';
            }
            $html .= '<article class="ka-cenik-plan' . ($highlighted ? ' ka-cenik-plan--zvyrazneny' : '') . '" aria-labelledby="' . $heading . '">'
                . ($highlighted && $plan['stitek'] !== '' ? '<p class="ka-cenik-stitek">' . e($plan['stitek']) . '</p>' : '')
                . '<h3 id="' . $heading . '">' . e($plan['nazev']) . '</h3>'
                . ($plan['cena'] !== '' ? '<p class="ka-cenik-cena"><strong>' . e($plan['cena']) . '</strong>' . ($plan['obdobi'] !== '' ? ' <span>' . e($plan['obdobi']) . '</span>' : '') . '</p>' : '')
                . ($plan['popis'] !== '' ? '<p class="ka-cenik-popis">' . e($plan['popis']) . '</p>' : '')
                . ($features !== '' ? '<ul class="ka-cenik-funkce">' . $features . '</ul>' : '')
                . ($plan['tlacitko'] !== '' ? '<a class="ka-tlacitko ka-tlacitko--' . ($highlighted ? 'primarni' : 'obrys') . '" href="' . e($plan['odkaz'] !== '' ? $plan['odkaz'] : '#') . '">' . e($plan['tlacitko']) . '</a>' : '')
                . '</article>';
        }

        return '<div' . Text::withClass($a, 'ka-cenik') . '>' . $html . '</div>';
    }
}
