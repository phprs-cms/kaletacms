<?php

declare(strict_types=1);

namespace Kaleta\Admin;

use Kaleta\Core\App;
use Kaleta\Core\Files;
use Kaleta\Core\InternalFileLinks;
use Kaleta\Core\InternalFiles;
use Kaleta\Core\Mail;
use Kaleta\Core\ReplyAttachments;

/**
 * The Attachments block of a reply to an enquiry and of a quote e-mail (3.49 A, views/admin/attachments.php): the internal
 * files already chosen (ticked), and – for a person with Internal files – the 50 most recently changed ready files they
 * see for the choice "Add another file" (a choice by search with the script, a select and "Add" without it), and the
 * upload field. What it offers is only what the person may open (InternalFiles::file and files() follow the folders).
 * 3.49 (A meets B): the internal files linked to the record the e-mail is about – the enquiry's customer, the quote – are
 * listed too, unticked (only a person attaches, D6): through InternalFileLinks::ofRecords, so only files of folders the
 * person sees, and only for a record kind the person may open; ready files, never an SVG, each once.
 */
final class AttachmentBox
{
    /** How many of the most recently changed ready files the choice offers without searching. */
    public const int RECENT = 50;

    /**
     * @param list<int> $chosen ids of internal files ticked
     * @param array<string, int|string> $reload the query of the page the no-script "Add" reloads (module, action, id)
     * @param list<array{0: string, 1: int}> $records the records the e-mail is about (kind, id) whose linked files are offered unticked
     * @return array{chosen: list<array{id: int, name: string, note: string, size: int}>, linked: list<array{id: int, name: string, note: string, size: int}>, canFiles: bool, recent: list<array{id: int, name: string, size: int}>, searchUrl: string, maxBytes: int, maxFiles: int, reload: array<string, int|string>}
     */
    public static function data(App $app, array $chosen, array $reload, array $records = []): array
    {
        $canFiles = InternalFiles::mayOpen($app);
        $rows = [];
        foreach ($canFiles ? array_slice(array_values(array_unique(array_map('intval', $chosen))), 0, ReplyAttachments::MAX_FILES) : [] as $id) {
            $file = InternalFiles::file($app, $id);
            if ($file !== null && $file['status'] === 'ready' && $file['extension'] !== 'svg') {
                $rows[] = ['id' => (int) $file['id'], 'name' => (string) $file['name'], 'note' => strtoupper((string) $file['extension']) . ' · ' . Files::size((int) $file['size']), 'size' => (int) $file['size']];
            }
        }
        $records = $canFiles ? array_values(array_filter($records, fn (array $r): bool => (int) $r[1] > 0 && InternalFileLinks::mayOpenKind($app, (string) $r[0]))) : [];
        $linked = $records !== [] ? self::linkedRows(InternalFileLinks::ofRecords($app, $records), array_column($rows, 'id')) : [];
        $recent = [];
        if ($canFiles) {
            foreach (InternalFiles::files($app, ['status' => 'ready'], 1, self::RECENT)['rows'] as $f) {
                if ($f['extension'] !== 'svg') {
                    $recent[] = ['id' => (int) $f['id'], 'name' => (string) $f['name'], 'size' => (int) $f['size']];
                }
            }
        }
        $post = Files::postLimit();

        return ['chosen' => $rows, 'linked' => $linked, 'canFiles' => $canFiles, 'recent' => $recent, 'searchUrl' => $app->url('admin.php?module=internal_files&action=search_json'),
            'maxBytes' => min(Mail::MAX_ATTACHMENTS, $post > 0 ? $post : PHP_INT_MAX), 'maxFiles' => ReplyAttachments::MAX_FILES, 'reload' => $reload];
    }

    /**
     * The rows offered unticked from the files linked to the records (InternalFileLinks::ofRecords, already limited to the
     * folders the person sees): ready files only, never an SVG, none already chosen, each once, at most RECENT. Pure.
     *
     * @param list<array<string, mixed>> $files
     * @param list<int> $taken ids already ticked
     * @return list<array{id: int, name: string, note: string, size: int}>
     */
    public static function linkedRows(array $files, array $taken): array
    {
        $out = [];
        foreach ($files as $file) {
            $id = (int) ($file['id'] ?? 0);
            if ($id > 0 && ($file['status'] ?? '') === 'ready' && ($file['extension'] ?? '') !== 'svg' && !in_array($id, $taken, true) && !isset($out[$id])) {
                $out[$id] = ['id' => $id, 'name' => (string) $file['name'], 'note' => strtoupper((string) $file['extension']) . ' · ' . Files::size((int) $file['size']), 'size' => (int) $file['size']];
            }
        }

        return array_slice(array_values($out), 0, self::RECENT);
    }
}
