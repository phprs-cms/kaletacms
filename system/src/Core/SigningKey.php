<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * The site's signing key (`secret_key` in the settings, created by Antispam::key()) and its replacement (3.19.1, N84).
 *
 * The key signs what the site hands out and later trusts: shared preview links of drafts (Preview), the look preview and
 * the preview of staged media, gated download links (Documents), unlocked password-protected pages (PageLock), the
 * builder clipboard's Custom HTML signature (ElementClipboard), the time stamp of visitors' forms (Antispam) and share
 * images (ShareImage); it also salts the daily visitor and conversion hashes of the statistics and the whistleblowing
 * address buckets. A new key withdraws all of it at once – the only way to call back a preview link that went to the
 * wrong person before it expires. The administrator's action in Settings → Backups and updates, written to the change log.
 *
 * Until the encryption key has been moved to config.php (Vault, 3.15), `secret_key` is also the base of the encrypted
 * connector secrets and whistleblowing reports: the stored values are then encrypted again under the new key in the same
 * transaction. While the move is half done (pending) or the data belongs to another key (missing), nothing changes.
 */
final class SigningKey
{
    /** What stops working – the confirmation text (Backups tab) and the documentation say the same. */
    public const array CONSEQUENCES = [
        'every shared preview link of a draft, and the preview of the whole site',
        'download links of gated documents already sent by e-mail',
        'unlocked password-protected pages – visitors type the password again',
        'forms open in visitors\' browsers – they reload the page once',
        'Custom HTML in elements copied to the builder clipboard before – copy them again',
        'today\'s visitor count of the statistics starts again (returning visitors count once more)',
    ];

    /** @return array{status: 'rotated'|'vault'|'error', count?: int, error?: string} vault = the encryption key is half moved or missing */
    public static function rotate(App $app): array
    {
        $settings = $app->settings();
        $state = Vault::state($settings);
        if ($state === 'pending' || $state === 'missing') {
            return ['status' => 'vault'];
        }
        $old = (new Antispam($app->db(), $settings))->key();
        $new = Vault::newKey();
        try {
            $count = $app->db()->transaction(function (Db $db) use ($settings, $state, $old, $new): int {
                // the stored secrets belong to secret_key until the encryption key is in config.php: encrypted again with the new one
                $count = $state === 'database' ? Vault::resealAll($db, $old, $new)[0] : 0;
                $settings->set('secret_key', $new);

                return $count;
            });
        } catch (\Throwable $e) {
            try {
                $settings->set('secret_key', $old); // the database rolled back; the value this request keeps in memory too
            } catch (\Throwable) {
                // the database is gone – nothing was changed in it
            }

            return ['status' => 'error', 'error' => $e->getMessage()];
        }
        \Kaleta\Admin\ChangeLog::write($app, 'settings', 'new signing key', $count > 0 ? 'secrets encrypted again: ' . $count : '');
        Events::record($app->db(), 'security.signing_key_rotated', 'warning', t('A new signing key was made: shared preview links and other signed links of the site stopped working.'),
            ['user' => (int) ($app->auth()->user()['idu'] ?? 0), 'resealed' => $count]);

        return ['status' => 'rotated', 'count' => $count];
    }
}
