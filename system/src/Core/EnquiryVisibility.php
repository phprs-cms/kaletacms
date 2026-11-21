<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * 3.49: which enquiry rows a read may return – the condition every query on ka_poptavky that returns rows carries (3.34
 * second review N6; unit 2256 lists them). It lived in Admin\Modules\Enquiries, which keeps forwards under the old names;
 * Core calls it here, so Core never names the admin module for it (docs/design/code-layers.md).
 */
final class EnquiryVisibility
{
    /**
     * Spam is left out of the list unless asked for (2.12) – the menu badge, the Dashboard count, my work and the team
     * summary do the same (N315-10). Admin\Modules\Enquiries::NOT_SPAM before 3.50.1 (an alias of this one now).
     */
    public const string NOT_SPAM = "kategorie <> 'spam'";

    /**
     * 3.34 review M3: while migration 0120 still moves job enquiries to Applications, " AND <condition>" that leaves out the
     * ones still to move – for the counts everyone sees; with visible() (N6) for a person without Applications.
     */
    public static function stillMoving(\Kaleta\Core\Db $db, ?\Kaleta\Core\Settings $settings = null): string
    {
        return ' AND ' . self::visible($db, $settings, false);
    }

    /**
     * The one condition every read of enquiry rows uses (3.34 second review N6; a unit test lists every query on ka_poptavky
     * that returns rows and fails on one without it or an explicit exemption) – never empty:
     *  - an enquiry with an application twin (an application whose source_enquiry_id and received_at are its idp and date –
     *    a database restored from a backup taken around the move, N9) has moved: shown to nobody, and acted on by nobody;
     *  - while migration 0120 still moves, the job enquiries still to move (Applications::movingCondition) – for a person
     *    without Applications ($withApplications false), the retention and anything automatic.
     * $table: the name or alias the query gives ka_poptavky.
     */
    public static function visible(\Kaleta\Core\Db $db, ?\Kaleta\Core\Settings $settings, bool $withApplications, string $table = '{poptavky}'): string
    {
        $settings ??= new \Kaleta\Core\Settings($db);
        $parts = [];
        if ($settings->int('db_version') >= 119) {
            $parts[] = 'NOT EXISTS (SELECT 1 FROM {applications} twin WHERE twin.source_enquiry_id = ' . $table . '.idp AND twin.received_at = ' . $table . '.datum)';
        }
        if (!$withApplications && ($moving = \Kaleta\Core\Applications::movingCondition($db, $settings, $table)) !== null) {
            $parts[] = $moving;
        }

        return $parts === [] ? '1 = 1' : implode(' AND ', $parts);
    }
}
