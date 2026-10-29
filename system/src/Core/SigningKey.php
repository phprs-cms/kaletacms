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
        'share images get new addresses (/og/…) – social networks fetch them again',
        'today\'s limits per sender of the whistleblowing channel start again',
    ];

    /** What the confirmation adds below the list (N3191-11). */
    public const string NOTE = 'Restoring a backup made before the new key brings the old key back. Claude connections are not affected – their tokens do not depend on this key.';

    /** @return array{status: 'rotated'|'vault'|'error', count?: int, error?: string} vault = the encryption key is half moved or missing */
    public static function rotate(App $app): array
    {
        $settings = $app->settings();
        $state = Vault::state($settings);
        if ($state === 'pending' || $state === 'missing') {
            return ['status' => 'vault'];
        }
        (new Antispam($app->db(), $settings))->key(); // a site that never signed anything gets its first key now
        $new = Vault::newKey();
        try {
            $count = $app->db()->transaction(function (Db $db) use ($settings, $state, $new): int {
                // N3191-1: the key in force is read from the database under a lock, never from this request's cache – two
                // rotations at once (a double click) wait for each other, and the second one reseals with the first one's key
                $old = (string) $db->value("SELECT hodnota FROM {nastaveni} WHERE promenna = 'secret_key' FOR UPDATE");
                if ($old === '') {
                    throw new \RuntimeException('The signing key is missing.');
                }
                [$previous] = Vault::previousKey($settings);
                $count = 0;
                if ($state === 'database') {
                    // the stored secrets belong to secret_key until the encryption key is in config.php: encrypted again with the
                    // new one. A value the keys could read before must stay readable – otherwise nothing changes
                    $before = Vault::unreadable($db, $old, $previous);
                    [$count, $unreadable] = Vault::resealAll($db, $old, $new, $previous);
                    if ($unreadable > $before) {
                        throw new \RuntimeException(sprintf('%d stored values could not be encrypted again.', $unreadable - $before));
                    }
                }
                $settings->set('secret_key', $new);
                // N3191-2: a request that read the settings before this commit may still seal one value with the old key –
                // Vault::openFor() tries it while the key is in the database, and the next rotation reseals with it too
                $settings->set('secret_key_previous', $old . '|' . time());

                return $count;
            });
        } catch (\Throwable $e) {
            // the database rolled back; the value this request keeps in memory is never written back (another rotation may have won)
            return ['status' => 'error', 'error' => $e->getMessage()];
        }
        \Kaleta\Admin\ChangeLog::write($app, 'settings', 'new signing key', $count > 0 ? 'secrets encrypted again: ' . $count : '');
        Events::record($app->db(), 'security.signing_key_rotated', 'warning', t('A new signing key was made: shared preview links and other signed links of the site stopped working.'),
            ['user' => (int) ($app->auth()->user()['idu'] ?? 0), 'resealed' => $count]);

        return ['status' => 'rotated', 'count' => $count];
    }
}
