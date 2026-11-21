<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Files a person sends with a reply to an enquiry or with a quote e-mail (3.49 A, D6–D8): internal files they may open and
 * files uploaded with the form. Only a person chooses them in the administration – never Claude (an enquiry's text could
 * ask for a sealed company document). Nothing is stored: an upload is read once and sent, an internal file is opened from
 * its sealed store (InternalFiles::download, so the section and the folder's audience decide). Every collected file carries
 * 'queue' => false: it never waits in the mail queue (Mail::send), where it would lie unsealed in the database.
 *
 * The rules, in order: at most MAX_FILES files; an uploaded SVG is refused before anything else; an internal file must be
 * one the person sees (the same message for a missing and a hidden one), ready (not a draft) and no SVG; the sizes are added
 * up from filesize() of the uploads and the stored size of the internal files' current versions – over Mail::MAX_ATTACHMENTS
 * the refusal names them before a single file is read; then each upload passes InternalFiles::check (the allowed types,
 * Files::forbiddenContent). The name in the e-mail is ASCII with the extension kept (mailName – Mail would otherwise turn
 * "Ceník.pdf" into "Cen-k.pdf"); the history keeps the names as sent.
 */
final class ReplyAttachments
{
    /** The most files one e-mail carries. */
    public const int MAX_FILES = 5;

    /** The refusal for an internal file that does not exist or whose folder is not for the person – one message for both. */
    public const string NOT_YOURS = 'The file does not exist, or its folder is not for you.';

    /**
     * The files ready to go with Mail::send. Throws \DomainException (a translated message naming the files) when one is
     * refused – then nothing goes.
     *
     * @param list<int> $internalIds ids of internal files
     * @param list<array<string, mixed>> $uploads entries of Request::fileList (name, tmp_name, error, size)
     * @return list<array{name: string, type: string, data: string, file: int, queue: false}>
     */
    public static function collect(App $app, array $internalIds, array $uploads): array
    {
        $internalIds = array_values(array_unique(array_filter(array_map('intval', $internalIds), fn (int $id): bool => $id > 0)));
        if (count($internalIds) + count($uploads) > self::MAX_FILES) {
            throw new \DomainException(t('Up to %d files can go with one e-mail.', self::MAX_FILES));
        }
        $sizes = [];
        foreach ($uploads as $u) {
            $name = InternalFiles::safeName((string) ($u['name'] ?? '')) ?: t('File');
            if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'svg') {
                throw new \DomainException(t('An SVG drawing cannot be sent as an attachment: %s', $name));
            }
            $error = (int) ($u['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($error !== UPLOAD_ERR_OK) {
                throw new \DomainException(in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                    ? t('The file %1$s is larger than this server accepts (%2$s).', $name, Files::limitText()) : t('The file %s could not be uploaded.', $name));
            }
            $path = (string) ($u['tmp_name'] ?? '');
            if ($path === '' || !is_file($path)) {
                throw new \DomainException(t('The file %s could not be uploaded.', $name));
            }
            $sizes[] = [$name, (int) filesize($path)];
        }
        $files = [];
        foreach ($internalIds as $id) {
            $file = InternalFiles::file($app, $id);
            if ($file === null) {
                throw new \DomainException(t(self::NOT_YOURS));
            }
            if ($file['status'] !== 'ready') {
                throw new \DomainException(t('The file %s is a draft – mark it ready before it leaves the company.', (string) $file['name']));
            }
            if ($file['extension'] === 'svg') {
                throw new \DomainException(t('An SVG drawing cannot be sent as an attachment: %s', (string) $file['name']));
            }
            $files[] = $file;
            $sizes[] = [(string) $file['name'], (int) $file['size']];
        }
        $total = array_sum(array_column($sizes, 1));
        if ($total > Mail::MAX_ATTACHMENTS) {
            throw new \DomainException(t('The attachments are too large together (%1$s, at most %2$s): %3$s.', Files::size($total), Files::size(Mail::MAX_ATTACHMENTS),
                implode(', ', array_map(fn (array $s): string => $s[0] . ' (' . Files::size($s[1]) . ')', $sizes))));
        }
        $out = [];
        foreach ($uploads as $u) {
            $name = InternalFiles::safeName((string) ($u['name'] ?? '')) ?: t('File');
            $checked = InternalFiles::check((string) $u['tmp_name'], $name);
            if (is_string($checked)) {
                throw new \DomainException($name . ': ' . t($checked));
            }
            $data = (string) file_get_contents((string) $u['tmp_name']);
            if ($data === '') {
                throw new \DomainException(t('The file %s is empty.', $name));
            }
            $out[] = ['name' => self::mailName($name, $checked['extension']), 'type' => self::type($checked['extension']), 'data' => $data, 'file' => 0, 'queue' => false];
        }
        foreach ($files as $file) {
            $download = InternalFiles::download($app, (int) $file['id']);
            if ($download === null || $download['content'] === '') {
                throw new \DomainException(t('The file %s cannot be opened right now.', (string) $file['name']));
            }
            $out[] = ['name' => self::mailName($download['name'], (string) $file['extension']), 'type' => self::type((string) $file['extension']), 'data' => $download['content'],
                'file' => (int) $file['id'], 'queue' => false];
        }

        return $out;
    }

    /**
     * The file name an e-mail carries (pure): the letters without diacritics, anything else but letters, digits, dot, dash
     * and underscore as one dash, the extension of the checked file kept – "Ceník služeb.pdf" goes as "Cenik-sluzeb.pdf",
     * a name with nothing Latin in it as "attachment.pdf".
     */
    public static function mailName(string $displayName, string $extension): string
    {
        $extension = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '', $extension));
        $base = pathinfo(str_replace('\\', '/', $displayName), PATHINFO_FILENAME);
        if ($extension !== '' && strtolower(pathinfo($displayName, PATHINFO_EXTENSION)) !== $extension) {
            $base = basename(str_replace('\\', '/', $displayName)); // the shown name had another (or no) extension – keep it whole
        }
        $base = trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', remove_diacritics($base)), '-_');
        $base = $base !== '' ? substr($base, 0, 90) : 'attachment';

        return $base . ($extension !== '' ? '.' . $extension : '');
    }

    /**
     * What the history keeps of the files sent (pure): the name as sent, the size and the internal file's id (0 = an upload).
     *
     * @param list<array<string, mixed>> $files as collect() gives them
     * @return list<array{name: string, size: int, file: int}>
     */
    public static function history(array $files): array
    {
        return array_map(fn (array $f): array => ['name' => (string) $f['name'], 'size' => strlen((string) $f['data']), 'file' => (int) ($f['file'] ?? 0)], $files);
    }

    /**
     * A stored list of files cleaned (pure): each entry with its three keys, at most MAX_FILES.
     *
     * @return list<array{name: string, size: int, file: int}>
     */
    public static function cleanHistory(mixed $files): array
    {
        $out = [];
        foreach (is_array($files) ? array_slice(array_values($files), 0, self::MAX_FILES) : [] as $f) {
            if (is_array($f) && is_string($f['name'] ?? null) && $f['name'] !== '') {
                $out[] = ['name' => mb_substr($f['name'], 0, 120), 'size' => max(0, (int) ($f['size'] ?? 0)), 'file' => max(0, (int) ($f['file'] ?? 0))];
            }
        }

        return $out;
    }

    /**
     * One change-log line in the Internal files section per internal file that left with an e-mail ("Internal file #7 sent
     * with enquiry #12") – the file's id and the record, never an address.
     *
     * @param list<array<string, mixed>> $files as collect() gives them
     */
    public static function trace(App $app, array $files, string $with): void
    {
        foreach ($files as $f) {
            if ((int) ($f['file'] ?? 0) > 0) {
                ChangeLog::write($app, InternalFiles::SECTION, 'sent', t('Internal file #%d', (int) $f['file']) . ' ' . $with);
            }
        }
    }

    /** The plain media type of an extension (Mail accepts no parameters such as "; charset"). */
    private static function type(string $extension): string
    {
        return trim(explode(';', InternalFiles::contentType($extension))[0]);
    }
}
