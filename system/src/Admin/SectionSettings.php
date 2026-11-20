<?php

declare(strict_types=1);

namespace Kaleta\Admin;

/**
 * 3.48 B (U70): "Settings of sections" – a tab of Settings that lists the settings living in the sections themselves
 * (Quotes, Applications, the Pipeline's stages, Bookings, the enquiry assistant…), so an administrator finds them from one
 * place. Only the sections the person may open (Kernel::modules() already follows the features and the rights). Pure.
 */
final class SectionSettings
{
    /**
     * section => [the section's name, list of [label, query]].
     *
     * @var array<string, array{0: string, 1: list<array{0: string, 1: string}>}>
     */
    public const array ROWS = [
        'quotes' => ['Quotes', [['Settings', 'module=quotes&action=settings'], ['Price list set-up', 'module=quotes&action=setup']]],
        'applications' => ['Applications', [['Settings', 'module=applications&action=settings']]],
        'pipeline' => ['Pipeline', [['Stages', 'module=pipeline&action=stages']]],
        'bookings' => ['Bookings', [['Settings', 'module=bookings&action=settings'], ['Services', 'module=bookings&action=services'], ['People', 'module=bookings&action=staff']]],
        'enquiries' => ['Enquiries', [['Writing assistant', 'module=enquiries#triage_assistant']]],
        'whistleblowing' => ['Whistleblowing', [['Setup of the channel', 'module=whistleblowing#wb-setup']]],
        'claude_settings' => ['Claude settings', [['Instructions and guardrails', 'module=claude_settings']]],
        'extensions' => ['Features', [['Features', 'module=extensions'], ['Newsletter service', 'module=extensions#newsletter']]],
        'business' => ['Business details', [['Business details', 'module=business']]],
        'users' => ['Users', [['Departments', 'module=users&action=departments']]],
        'roles' => ['Roles', [['Roles', 'module=roles']]],
        'redirects' => ['Addresses', [['Settings', 'module=redirects&action=settings']]],
        'appearance' => ['Site appearance', [['Site appearance', 'module=appearance']]],
    ];

    /**
     * The rows for the sections the person may open: section => [name, list of [label, query]], in the order of ROWS.
     *
     * @param list<string> $modules the sections the person may open (the idents of Kernel::modules())
     * @return array<string, array{0: string, 1: list<array{0: string, 1: string}>}>
     */
    public static function links(array $modules): array
    {
        return array_intersect_key(self::ROWS, array_flip($modules));
    }
}
