<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * 3.50.1: the campaign of a lead as people read it – it lived in Front\Forms (UTM, campaignText), which forwards here; Core
 * (the statistics overview, Core\Report) calls it here, so Core never names Front for it (docs/design/code-layers.md).
 */
final class Campaign
{
    /** The address parameters a campaign is made of, in the order they are read. */
    public const array UTM = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];

    /** Human-readable campaign: „google / cpc / jarni-akce“ (source / medium / campaign, optionally keyword and content). Pure. */
    public static function text(string $campaign): string
    {
        parse_str($campaign, $utm);

        return implode(' / ', array_filter(array_map(fn (string $k): string => is_string($utm[$k] ?? null) ? $utm[$k] : '', self::UTM), fn (string $h): bool => $h !== ''));
    }
}
