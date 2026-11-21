<?php

declare(strict_types=1);

namespace Kaleta\Admin;

use Kaleta\Core\App;
use Kaleta\Core\Files;
use Kaleta\Core\InternalFiles;
use Kaleta\Core\Mail;
use Kaleta\Core\ReplyAttachments;

/**
 * The Attachments block of a reply to an enquiry and of a quote e-mail (3.49 A, views/admin/attachments.php): the internal
 * files already chosen (ticked), and – for a person with Internal files – the 50 most recently changed ready files they
 * see for the choice "Add another file" (a choice by search with the script, a select and "Add" without it), and the
 * upload field. What it offers is only what the person may open (InternalFiles::file and files() follow the folders).
 */
final class AttachmentBox
{
    /** How many of the most recently changed ready files the choice offers without searching. */
    public const int RECENT = 50;

    /**
     * @param list<int> $chosen ids of internal files ticked
     * @param array<string, int|string> $reload the query of the page the no-script "Add" reloads (module, action, id)
     * @return array{chosen: list<array{id: int, name: string, note: string, size: int}>, canFiles: bool, recent: list<array{id: int, name: string, size: int}>, searchUrl: string, maxBytes: int, maxFiles: int, reload: array<string, int|string>}
     */
    public static function data(App $app, array $chosen, array $reload): array
    {
        $canFiles = InternalFiles::mayOpen($app);
        $rows = [];
        foreach ($canFiles ? array_slice(array_values(array_unique(array_map('intval', $chosen))), 0, ReplyAttachments::MAX_FILES) : [] as $id) {
            $file = InternalFiles::file($app, $id);
            if ($file !== null && $file['status'] === 'ready' && $file['extension'] !== 'svg') {
                $rows[] = ['id' => (int) $file['id'], 'name' => (string) $file['name'], 'note' => strtoupper((string) $file['extension']) . ' · ' . Files::size((int) $file['size']), 'size' => (int) $file['size']];
            }
        }
        $recent = [];
        if ($canFiles) {
            foreach (InternalFiles::files($app, ['status' => 'ready'], 1, self::RECENT)['rows'] as $f) {
                if ($f['extension'] !== 'svg') {
                    $recent[] = ['id' => (int) $f['id'], 'name' => (string) $f['name'], 'size' => (int) $f['size']];
                }
            }
        }
        $post = Files::postLimit();

        return ['chosen' => $rows, 'canFiles' => $canFiles, 'recent' => $recent, 'searchUrl' => $app->url('admin.php?module=internal_files&action=search_json'),
            'maxBytes' => min(Mail::MAX_ATTACHMENTS, $post > 0 ? $post : PHP_INT_MAX), 'maxFiles' => ReplyAttachments::MAX_FILES, 'reload' => $reload];
    }
}
