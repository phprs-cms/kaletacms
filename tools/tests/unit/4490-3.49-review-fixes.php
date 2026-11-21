<?php

declare(strict_types=1);

/* ---------- 3.49 review: the palette for authors, saved replies of a language switched off, filters of a record's files, the parity of Link by e-mail ---------- */
use Kaleta\Admin\Palette as R449Palette;
use Kaleta\Core\InternalFiles as R449Files;
use Kaleta\Core\SavedReplies as R449Saved;

// C3: "Saved replies" answers 403 to an author – the palette offers it only to those who publish (and to administrators)
$r449Labels = fn (bool $admin, bool $publisher): array => array_column(R449Palette::quick(['enquiries'], $admin, $publisher), 0);
check('3.49 review C3: the palette offers Saved replies to an editor and an administrator, never to an author', [
    $r449Labels(false, true), $r449Labels(false, false), $r449Labels(true, false), $r449Labels(true, true)],
    [['Saved replies'], [], ['Saved replies'], ['Saved replies']]);

// C5: saved replies are offered only in the languages switched on – '' and the additional ones; a language switched off
// (or made the default) is offered nowhere, its rows stay for the admin
$r449Settings = function (array $values): Kaleta\Core\Settings {
    $s = (new ReflectionClass(Kaleta\Core\Settings::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(Kaleta\Core\Settings::class, 'values'))->setValue($s, $values);

    return $s;
};
check('3.49 review C5: activeLanguages – the default and the additional languages; none with the languages feature off; the new default is not an additional one', [
    R449Saved::activeLanguages($r449Settings(['site_language' => 'cs', 'additional_languages' => 'en,de', 'extensions' => 'jazyky'])),
    R449Saved::activeLanguages($r449Settings(['site_language' => 'cs', 'additional_languages' => 'en,de', 'extensions' => ''])),
    R449Saved::activeLanguages($r449Settings(['site_language' => 'de', 'additional_languages' => 'en,de', 'extensions' => 'jazyky']))],
    [['', 'en', 'de'], [''], ['', 'en']]);

// C7: a record's files take the filters of a folder's list – folder, status, tag, a word (name, description, tags; case aside)
$r449File = fn (int $id, int $folder, string $status, string $name, string $description = '', array $tags = []): array => ['id' => $id, 'folder_id' => $folder,
    'status' => $status, 'name' => $name, 'description' => $description, 'tags' => $tags];
$r449Rows = [$r449File(1, 10, 'ready', 'Price list.pdf', '', ['Sales']), $r449File(2, 10, 'draft', 'Contract draft.docx', 'for Acme'),
    $r449File(3, 20, 'ready', 'Terms.pdf', 'general terms', ['legal', 'sales']), $r449File(4, 20, 'draft', 'Notes.md')];
$r449Ids = fn (array $query): array => array_column(R449Files::matching($r449Rows, $query), 'id');
check('3.49 review C7: InternalFiles::matching filters by status, folder, tag and word, in the given order; an unknown status filters nothing', [
    $r449Ids([]), $r449Ids(['status' => 'ready']), $r449Ids(['status' => 'draft', 'folder' => 20]), $r449Ids(['tag' => 'SALES']),
    $r449Ids(['search' => 'acme']), $r449Ids(['search' => 'legal', 'status' => 'ready']), $r449Ids(['status' => 'bogus']), $r449Ids(['folder' => 99])],
    [[1, 2, 3, 4], [1, 3], [4], [1, 3], [2], [3], [1, 2, 3, 4], []]);

// C8: the account of a booking person is not in the published save_booking_staff schema – so the parity of "Link by e-mail"
// names the administration, not that tool
$r449StaffSchema = Kaleta\Mcp\Tools::bookingToolsDefinitions()['save_booking_staff'][1]['properties'] ?? [];
check('3.49 review C8: Link by e-mail is an admin action as long as save_booking_staff does not publish the account (user_id)', [
    isset($r449StaffSchema['user_id']), str_starts_with((string) ($parity['bookings']['staff_link'] ?? ''), 'admin:')], [false, true]);
