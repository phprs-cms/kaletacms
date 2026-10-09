<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * The public demo (2.6, demo.kaletacms.com): anyone signs in with the account shown on the sign-in screen, tries the
 * admin and the builder, and every hour the site goes back to its snapshot (php system/demo.php reset from cron).
 * Switched on in config.php: 'demo' => ['user' => 'demo', 'password' => '…'].
 *
 * Visitors share one admin, so the demo refuses what could be abused or lock the others out: sending e-mail, requests
 * to other servers (webhooks, downloads from URLs, updates, off-site backups, IndexNow, the Claude connection), code
 * fields, secret keys, users and passwords, backups, updates and imports.
 */
final class Demo
{
    /** @var array{user: string, password: string}|null */
    private static ?array $config = null;

    /** Called when the app starts, with config.php's 'demo' entry. */
    public static function configure(mixed $config): void
    {
        self::$config = is_array($config) && is_string($config['user'] ?? null) && is_string($config['password'] ?? null) && $config['user'] !== ''
            ? ['user' => $config['user'], 'password' => $config['password']] : null;
    }

    public static function active(): bool
    {
        return self::$config !== null;
    }

    /** @return array{user: string, password: string}|null the shared sign-in, shown on the sign-in screen */
    public static function account(): ?array
    {
        return self::$config;
    }

    /** The message for anything the demo refuses. */
    public static function refusal(): string
    {
        return t('This is switched off in the public demo. Install Kaleta to try it.');
    }

    /**
     * What each Settings screen may do in the demo (3.3.2, N24): Status, Claude settings, Features and Business details
     * inherit every action of Settings, so the filter goes by the class, and an action not listed here is refused. A
     * Settings screen added later and missing here may only be looked at.
     */
    private const array SETTINGS_ACTIONS = [
        'settings' => ['', 'list', 'save', 'hours_sign'], // hours_sign only renders a printable page
        'business' => ['', 'list', 'save', 'hours_add', 'hours_delete', 'hours_sign', 'hours_apply', 'hours_discard'],
    ];

    /** Settings tabs the demo saves (the screens of their own save only their fixed tab). */
    private const array SETTINGS_TABS = ['', 'general', 'company', 'seo', 'cookies', 'analytics'];

    /** Settings keys the demo saves – everything else (code, secret keys, addresses of other servers, tokens) stays as it is. */
    private const string SETTINGS_KEYS = '/^(site_name|site_description|footer_text|social_(facebook|instagram|x|youtube|linkedin)|social_networks|home_page|news_per_page|share_buttons|article_outline|related_news_auto|'
        . 'screen_(mode|seconds|news|hours|clock|collections)|time_zone|site_language|additional_languages|(nazev|popis)_webu_' . Language::TAG . '|company_[a-z_]+|indexing|schema_org|share_image|share_image_auto|'
        . 'verification_(google|bing)|robots_extra|ai_crawlers|llms_txt|markdown_news|ga4_id|plausible_domain|cookies_(mode|text|log|log_months)|lead_attribution|accessibility_toolbar)$/';

    /**
     * Admin requests the demo refuses. Looking around is allowed; changes to accounts, imports, backups, updates, mail
     * and webhooks are not, and backups cannot be downloaded either.
     */
    public static function blocksAdmin(string $module, string $action, string $tab, bool $post): bool
    {
        $class = null;
        foreach (\Kaleta\Admin\Kernel::MODULES as $candidate) {
            if ($candidate::IDENT === $module) {
                $class = $candidate;
            }
        }
        if ($class !== null && is_a($class, \Kaleta\Admin\Modules\Settings::class, true)) {
            return !in_array($action, self::SETTINGS_ACTIONS[$module] ?? ['', 'list'], true) || ($post && $module === 'settings' && !in_array($tab, self::SETTINGS_TABS, true))
                || ($post && !isset(self::SETTINGS_ACTIONS[$module]));
        }

        return $post && match ($module) {
            'users', 'roles', 'transfer', 'newsletters', 'fleet', 'addons' => true,
            '' => in_array($action, ['account', 'oauth'], true),
            default => false,
        };
    }

    /** Settings keys the demo saves: only the allow-list above (the type is kept for the callers; code and secrets are never listed). */
    public static function blocksSetting(string $key, string $type): bool
    {
        return $type === 'kod' || str_starts_with($type, 'tajne') || preg_match(self::SETTINGS_KEYS, $key) !== 1;
    }

    /** Seconds until the next reset, from the time of the last one (storage/demo/reset). */
    public static function secondsToReset(): int
    {
        $last = (int) @file_get_contents(self::dir() . '/reset');

        return max(0, ($last > 0 ? $last : time()) + 3600 - time());
    }

    public static function dir(): string
    {
        return KALETA_ROOT . '/storage/demo';
    }

    /** Saves the current database and media as the state every reset returns to. */
    public static function snapshot(Db $db): void
    {
        if (!is_dir(self::dir()) && !mkdir(self::dir(), 0775, true)) {
            throw new \RuntimeException('storage/demo cannot be created.');
        }
        $name = Backup::create($db, 'demo');
        $backup = Backup::path($name);
        array_map('unlink', glob(self::dir() . '/kaleta-demo-snapshot.sql*') ?: []);
        if ($backup === null || !rename($backup, self::dir() . '/kaleta-demo-snapshot.' . (str_ends_with($name, '.gz') ? 'sql.gz' : 'sql'))) {
            throw new \RuntimeException('The database snapshot could not be saved.');
        }
        self::copyTree(KALETA_ROOT . '/media', self::dir() . '/media');
        file_put_contents(self::dir() . '/reset', (string) time());
    }

    /** Puts the database and media back to the snapshot and empties the page cache. */
    public static function reset(Db $db): void
    {
        $snapshot = (glob(self::dir() . '/kaleta-demo-snapshot.sql*') ?: [''])[0];
        if ($snapshot === '') {
            throw new \RuntimeException('There is no snapshot yet: run php system/demo.php snapshot first.');
        }
        // Backup::restore reads only from its own folder: the snapshot goes there for the moment of the restore
        $name = basename($snapshot);
        copy($snapshot, Backup::FOLDER . '/' . $name);
        try {
            Backup::restore($db, $name);
        } finally {
            @unlink(Backup::FOLDER . '/' . $name);
        }
        self::copyTree(self::dir() . '/media', KALETA_ROOT . '/media');
        self::removeTree(KALETA_ROOT . '/storage/cache/stranky', false);
        file_put_contents(self::dir() . '/reset', (string) time());
    }

    /** Makes $to an exact copy of $from (files missing in $from are deleted). */
    private static function copyTree(string $from, string $to): void
    {
        self::removeTree($to, false);
        if (!is_dir($from)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($items as $item) {
            $target = $to . '/' . substr($item->getPathname(), strlen($from) + 1);
            if ($item->isDir()) {
                is_dir($target) || mkdir($target, 0775, true);
            } else {
                is_dir(dirname($target)) || mkdir(dirname($target), 0775, true);
                copy($item->getPathname(), $target);
            }
        }
    }

    private static function removeTree(string $dir, bool $self = true): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        if ($self) {
            rmdir($dir);
        }
    }
}
