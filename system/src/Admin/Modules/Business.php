<?php

declare(strict_types=1);

namespace Kaleta\Admin\Modules;

use Kaleta\Core\Response;

/**
 * Business details (3.2): the hub for what is true about the business – the company and its opening hours (formerly
 * Business details, the same settings keys), the facts, the claims and the blueprints (Admin\Hubs).
 *
 * Editors may change it (owner decision of 4 October 2026); the legal identifiers – the company ID, VAT ID, register entry
 * and who represents the company – stay with administrators: an editor neither sees nor saves them.
 */
final class Business extends Settings
{
    public const string IDENT = 'business';
    public const string NAME = 'Business details';
    public const string GROUP = 'Content'; // 3.25: Business details moved from the Company group (gone) to Content
    public const string ICON = 'fakta';
    public const bool ADMIN_ONLY = false;
    public const string HUB = 'business';

    /** The fields only an administrator sees and saves. */
    public const array LEGAL = ['company_id', 'company_vat_id', 'company_register', 'company_representative'];

    /** The only Settings actions this screen offers – an editor must never reach backups, updates or the firewall through it. */
    public const array ACTIONS = ['list', 'save', 'hours_add', 'hours_delete', 'hours_sign', 'hours_apply', 'hours_discard'];

    public function handle(string $action): Response
    {
        return in_array($action, self::ACTIONS, true) ? parent::handle($action) : $this->error('Unknown action.', 404);
    }

    protected function tab(string $tab): string
    {
        return 'company';
    }

    protected function fields(string $tab): array
    {
        $fields = parent::fields($tab);

        return $this->app->auth()->isAdmin() ? $fields : array_diff_key($fields, array_flip(self::LEGAL));
    }
}
