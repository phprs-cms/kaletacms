<?php

declare(strict_types=1);

namespace Kaleta\Core;

use Kaleta\Admin\ChangeLog;

/**
 * The instructions for Claude (claude_instructions, 3.15 N44): every connection gets them, so a change made once – by a
 * person in Claude settings or by a Claude connection with update_settings – reaches every later conversation. Each change
 * is written to the change log with the old and the new text, and the dashboard of the administrators says so until one of
 * them opens Claude settings or dismisses the notice (the setting claude_instructions_notice).
 */
final class ClaudeInstructions
{
    /** The change log keeps 255 characters: this much of each text, the full texts stay in the notice. */
    private const int LOG_CHARS = 110;

    /** Records a change of the instructions; nothing when the text stayed the same. */
    public static function changed(App $app, string $old, string $new): void
    {
        if ($old === $new) {
            return;
        }
        $user = $app->auth()->user();
        $via = (string) ($app->auth()->connection()['name'] ?? '');
        ChangeLog::write($app, 'claude_settings', 'instructions changed', self::logText($old, $new));
        $app->settings()->set('claude_instructions_notice', (string) json_encode(['at' => Clock::now(), 'by' => (string) ($user['jmeno'] ?? '') ?: (string) ($user['user'] ?? ''),
            'via' => $via, 'old' => $old, 'new' => $new], JSON_UNESCAPED_UNICODE));
    }

    /** "before: «…» → now: «…»", each text shortened so both fit in a change log row. */
    public static function logText(string $old, string $new): string
    {
        $short = fn (string $text): string => $text === '' ? '(empty)' : '«' . (mb_strlen($text) > self::LOG_CHARS ? mb_substr($text, 0, self::LOG_CHARS - 1) . '…' : $text) . '»';
        $flat = fn (string $text): string => trim((string) preg_replace('/\s+/u', ' ', $text));

        return 'before: ' . $short($flat($old)) . ' → now: ' . $short($flat($new));
    }

    /**
     * The change nobody has looked at yet, or null.
     *
     * @return array{at: string, by: string, via: string, old: string, new: string}|null
     */
    public static function notice(Settings $settings): ?array
    {
        $notice = json_decode($settings->get('claude_instructions_notice'), true);

        return is_array($notice) && is_string($notice['at'] ?? null)
            ? ['at' => $notice['at'], 'by' => (string) ($notice['by'] ?? ''), 'via' => (string) ($notice['via'] ?? ''), 'old' => (string) ($notice['old'] ?? ''), 'new' => (string) ($notice['new'] ?? '')]
            : null;
    }

    /** An administrator has seen it (opened Claude settings, or dismissed the notice on the dashboard). */
    public static function seen(Settings $settings): void
    {
        if ($settings->get('claude_instructions_notice') !== '') {
            $settings->set('claude_instructions_notice', '');
        }
    }

    /** Who changed them and when, for the dashboard and Claude settings. */
    public static function who(array $notice): string
    {
        return $notice['via'] !== ''
            ? t('Changed by %s through the Claude connection %s on %s.', $notice['by'] ?: '?', $notice['via'], format_date($notice['at'], true))
            : t('Changed by %s on %s.', $notice['by'] ?: '?', format_date($notice['at'], true));
    }
}
