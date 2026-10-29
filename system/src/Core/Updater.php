<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * System updates from the admin.
 *
 * The source is the file aktualizace.json: {"verze","vydano","url","sha256","podpis","min_php","bezpecnostni","zmeny":[...]}.
 * 3.8: the stable channel reads aktualizace-stable.json next to it instead (setting update_channel, see CHANNELS).
 * A release marked "bezpecnostni": true can install itself ("Nastavení -> Zálohy a aktualizace", Settings -> Backups and updates).
 * The package (ZIP) is accepted only when the SHA-256 and the Ed25519 signature (Core\Signature::packageMessage) match,
 * verified by one of the public keys in system/aktualizace.pub (operational + backup, see docs/RELEASING.md). Only the
 * publisher has the private key (tools/release.php).
 * 3.9: the manifest also carries signature v2 ("podpis2", Core\Signature::manifestMessage) over every field the site acts
 * on – the channel and min_php too. It is checked as soon as the manifest is read (verified(), which also says what
 * happens to a manifest without it).
 * 3.10: freshness (audit N39-7) – the site remembers the newest signed manifest each update source gave it per channel
 * (setting update_seen) and refuses an older one, or one of the other channel, as an error, not as "no update" (admit(),
 * N310-1); System status warns when the newest one is older than FRESHNESS_DAYS (Core\Health). A date in the future is
 * never remembered, and the administrator can forget it all (forgetSeen(), N310-3).
 * 3.15 (the bridge to 4.0): each major version has its own manifest – aktualizace.json is the 3.x line (and stays on the
 * last 3.x release for good), aktualizace-4.json the 4.x line (manifestFile()). A site reads the manifest of its own line
 * for updates; from 3.15 on it also reads the one of the next major (new self(…, major: true)) only to offer it on
 * Settings -> Backups and updates and System status. A major version is installed only by an explicit administrator action
 * after a fresh backup – never in the background, never as a security release, never by the fleet console – and the
 * regular update refuses a manifest of another major version (choose(), install()). Sites before 3.15 read only
 * aktualizace.json, so they reach 3.15 first. behindDays() (3.15): how long a newer release of the line has waited.
 * config.php, media/, storage/, extensions/ (add-ons), install.php and layouts that are not part of the package are never overwritten.
 */
final class Updater
{
    /** For tests: replaces the HTTP requests of probe() – gets the address, returns [status, body]. */
    public ?\Closure $probeFetch = null;

    /** Default update source; to be filled in once the project website runs. Can be overridden in Settings. */
    public const string DEFAULT_URL = 'https://kaletacms.com/aktualizace.json';

    /**
     * 3.8 (D3): release channels, setting update_channel. "latest" (the default, also for every site from before 3.8) reads
     * aktualizace.json – a new minor every week. "stable" reads the second signed manifest next to it,
     * aktualizace-stable.json, which the publisher moves only for security releases of the stable line and for a
     * deliberate promotion to a newer minor (docs/RELEASING.md). Same key, same format, plus "kanal": "stable".
     */
    public const array CHANNELS = ['latest', 'stable'];

    public const string STABLE_FILE = 'aktualizace-stable.json';

    /** 3.9: the first version whose manifest carries signature v2 – a manifest of this or a later version without it is refused. */
    public const string SIGNED_V2_SINCE = '3.9.0';

    /**
     * 3.10 (N39-7): System status warns when the newest signed update information the site has received was published
     * longer ago than this. Releases come out weekly (the stable line about monthly) and the publisher re-signs the
     * manifests at least this often (docs/RELEASING.md), so 60 days is two missed stable cycles or eight missed weekly
     * releases – a source that is stuck or replays an old copy shows up, a quiet month or a holiday does not. A warning
     * only, never an expiry: the keys are offline with the publisher, nothing re-signs automatically, and an expired
     * manifest would stop the very update that fixes things.
     */
    public const int FRESHNESS_DAYS = 60;

    /** The exception codes of a manifest refused as stale or of the other channel (state() tells them from an unreachable source). */
    public const int STALE = 3910;
    public const int WRONG_CHANNEL = 3911;

    /**
     * 3.19.1 (N88): the exception code of an update whose rollback could not put every file back – the site is a mix of
     * two versions, the originals are kept and the maintenance page stays up (the message says what to do; "nothing has
     * changed on the site" would be untrue).
     */
    public const int RESTORE_FAILED = 3912;

    /** The first line of storage/udrzba.lock after a failed rollback: the lock then holds until a person removes it (App::boot). */
    public const string RESTORE_FAILED_MARK = 'kaleta-restore-failed';

    /** 3.10 (N310-3): a signed release date more than this many days after today (UTC) is never remembered. */
    public const int FUTURE_DAYS = 2;

    /** How many entries update_seen keeps (source + channel: latest, stable, a custom source with both channels, the next major…). */
    private const int SEEN_SOURCES = 8;

    /**
     * 3.15: the administration prompts to update once a newer release of the site's line has been available for this many
     * days. Security fixes land only in the newest release (owner's decision: no support window for older ones), and a
     * release comes out about every week – two weeks behind is a site that has missed two of them.
     */
    public const int BEHIND_DAYS = 14;

    /** The signed release date of the manifest just read when it lies in the future (admit()), for state(). */
    private string $futureDate = '';

    private const array PROTECTED_PATHS = ['config.php', 'install.php', 'media/', 'storage/', 'extensions/', 'image/ukazka/', 'tools/', '.git/']; // extensions/: add-ons (3.0)
    private const int MAX_BYTES = 60 * 1024 * 1024;

    /**
     * @param bool $major 3.15: read the manifest of the NEXT major version (aktualizace-4.json next to aktualizace.json) – only
     *                    to offer it; install() then takes only that major, and only when the administrator starts it
     */
    public function __construct(
        private readonly Settings $settings,
        private readonly string $root = KALETA_ROOT,
        private readonly string $keyFile = KALETA_SYSTEM . '/aktualizace.pub',
        private readonly bool $major = false,
    ) {
    }

    public function url(): string
    {
        if (Demo::active()) {
            return ''; // the public demo is reset every hour and never updates itself
        }

        $source = $this->settings->get('update_url') !== '' ? $this->settings->get('update_url') : self::DEFAULT_URL;
        // 3.15: the manifest of the line this updater reads, next to the configured one; a custom source with another file
        // name is followed as it is for the site's own line and has no next-major twin
        $source = self::lineUrl($source, $this->line()) ?? ($this->major ? '' : $source);
        if ($source === '') {
            return '';
        }

        return self::channel($this->settings) === 'stable' ? (self::stableUrl($source) ?? $source) : $source;
    }

    /** The major version of a version string: "3.15.2" → 3, "4.0.0-rc1" → 4 (0 for anything else). */
    public static function majorOf(string $version): int
    {
        return preg_match('/^(\d+)\./', $version, $m) === 1 ? (int) $m[1] : 0;
    }

    /** The major version whose manifest this updater reads: the running one, or the next one (3.15, $major). */
    public function line(): int
    {
        return self::majorOf(KALETA_VERSION) + ($this->major ? 1 : 0);
    }

    /**
     * The file name of the manifest of a major line (3.15): aktualizace.json for 3.x and older (every site before 3.15 reads
     * only this one, so it stays on the last 3.x release), aktualizace-4.json for 4.x, aktualizace-5.json…; the stable
     * channel's twin of each (stableUrl): aktualizace-stable.json, aktualizace-4-stable.json. tools/release.php writes them.
     */
    public static function manifestFile(int $major, string $channel = 'latest'): string
    {
        return 'aktualizace' . ($major > 3 ? '-' . $major : '') . ($channel === 'stable' ? '-stable' : '') . '.json';
    }

    /**
     * The manifest of a major line next to an update source (3.15): …/aktualizace.json (or …/aktualizace-N.json) →
     * …/aktualizace-4.json for 4. Null for a source with another file name (it has no twins).
     */
    public static function lineUrl(string $source, int $major): ?string
    {
        $line = preg_replace('#/aktualizace(-\d+)?\.json(?=$|[?\#])#', '/' . self::manifestFile($major), $source, 1, $count);

        return $count === 1 && is_string($line) ? $line : null;
    }

    /** The channel the site chose; anything but "stable" is the default "latest" (3.8). */
    public static function channel(Settings $settings): string
    {
        return $settings->get('update_channel') === 'stable' ? 'stable' : 'latest';
    }

    /**
     * The stable manifest lies next to the latest one: …/aktualizace.json → …/aktualizace-stable.json – for the project's
     * source as for a custom one (a mirror of both files). A custom source with another file name has no stable twin (null).
     */
    public static function stableUrl(string $source): ?string
    {
        // 3.15: the stable twin of a major line's manifest too (aktualizace-4.json → aktualizace-4-stable.json)
        $stable = preg_replace('#/aktualizace(-\d+)?\.json(?=$|[?\#])#', '/aktualizace$1-stable.json', $source, 1, $count);

        return $count === 1 && is_string($stable) ? $stable : null;
    }

    /**
     * Which channel the site really follows: "latest", "stable", or "custom" – a custom update source without a stable twin,
     * which the site follows whatever channel it chose.
     */
    public function effectiveChannel(): string
    {
        $custom = $this->settings->get('update_url');

        return $custom !== '' && self::stableUrl($custom) === null ? 'custom' : self::channel($this->settings);
    }

    /**
     * The version choice (pure, 3.8): what a site running $current on PHP $php is offered by the manifest of its channel.
     * Only a newer version is ever offered – a site that moved to the stable channel while it runs a newer minor than the
     * stable manifest gets no downgrade, it waits (ahead_of = the stable version) until the stable line passes it. A release
     * for a newer PHP is not offered, the site says which PHP it needs (3.7). On the stable channel the manifest must say
     * "kanal": "stable" – a latest manifest served there by mistake (a wrong redirect, a custom source) offers nothing.
     *
     * 3.15: only a release of the site's own major version is offered ($nextMajor false) – a regular manifest that offers
     * another major (4.0 at aktualizace.json by mistake) offers nothing; with $nextMajor only one of the next major version.
     *
     * @param array<string, mixed>|null $manifest
     * @return array{nova: ?array<string, mixed>, vyzaduje_php: ?array{verze: string, min_php: string, bezpecnostni: bool}, ahead_of: ?string, chyba: ?string}
     */
    public static function choose(?array $manifest, string $channel, string $current = KALETA_VERSION, string $php = PHP_VERSION, bool $nextMajor = false): array
    {
        $choice = ['nova' => null, 'vyzaduje_php' => null, 'ahead_of' => null, 'chyba' => null];
        if ($manifest === null || !is_scalar($manifest['verze'] ?? null)) {
            return $choice;
        }
        if ($channel === 'stable' && ($manifest['kanal'] ?? null) !== 'stable') {
            $choice['chyba'] = self::notStableMessage();

            return $choice;
        }
        $version = (string) $manifest['verze'];
        if (self::majorOf($version) !== self::majorOf($current) + ($nextMajor ? 1 : 0)) {
            // 3.15: another major is never offered; an older one on the stable channel still says the site is ahead of it
            if (!$nextMajor && $channel === 'stable' && version_compare($version, $current, '<')) {
                $choice['ahead_of'] = $version;
            }

            return $choice;
        }
        if (version_compare($version, $current, '>')) {
            // 3.7: a release that needs a newer PHP than the server runs is not offered (nor installed in the background
            // or by the fleet console) – the site says which PHP it needs instead
            if (self::phpTooOld($manifest, $php)) {
                $choice['vyzaduje_php'] = ['verze' => $version, 'min_php' => self::minPhp($manifest), 'bezpecnostni' => !empty($manifest['bezpecnostni'])];
            } else {
                $choice['nova'] = $manifest;
            }
        } elseif ($channel === 'stable' && !$nextMajor && version_compare($version, $current, '<')) {
            $choice['ahead_of'] = $version;
        }

        return $choice;
    }

    private static function notStableMessage(): string
    {
        return t('The update source of the stable channel does not offer a stable-channel release, so nothing is offered. Switch to the Latest channel or check the custom update source.');
    }

    /**
     * The oldest PHP a release runs on, from its manifest. A manifest without min_php counts as 8.4 – the releases before
     * 3.7 needed it.
     *
     * @param array<string, mixed> $manifest
     */
    public static function minPhp(array $manifest): string
    {
        $minPhp = $manifest['min_php'] ?? null;

        return is_string($minPhp) && preg_match('/^\d+\.\d+(\.\d+)?$/D', $minPhp) === 1 ? $minPhp : '8.4';
    }

    /** @param array<string, mixed> $manifest */
    public static function phpTooOld(array $manifest, string $php = PHP_VERSION): bool
    {
        return version_compare($php, self::minPhp($manifest), '<');
    }

    /**
     * Update status; the result of the request is remembered for 12 hours.
     *
     * 3.8: channel = latest | stable | custom (effectiveChannel); ahead_of = on the stable channel, the stable version this
     * site is already past (it waits for the stable line, never downgrades).
     *
     * 3.10: stale = the source answered with older signed update information than it gave before (N39-7); refused = it was
     * refused as stale or as the other channel's (N310-1); failing_since = when the checks started failing (0 = they work);
     * newest = the newest signed manifest this source gave the site (version, release date); future_date = the signed date
     * of the manifest just read when it lies in the future, so it was not remembered (N310-3).
     *
     * 3.15: behind_since = since when a newer release of the site's line has been offered (0 = none; behindDays()).
     *
     * @return array{nastaveno:bool, aktualni:string, nova:?array<string, mixed>, vyzaduje_php:?array{verze:string, min_php:string, bezpecnostni:bool}, chyba:?string, overeno:int, channel:string, ahead_of:?string, stale:bool, refused:bool, failing_since:int, newest:?array{verze:string, vydano:string, at:int}, future_date:string, behind_since:int}
     */
    public function state(bool $force = false): array
    {
        $state = ['nastaveno' => $this->url() !== '', 'aktualni' => KALETA_VERSION, 'nova' => null, 'vyzaduje_php' => null, 'chyba' => null, 'overeno' => 0,
            'channel' => $this->effectiveChannel(), 'ahead_of' => null, 'stale' => false, 'refused' => false, 'failing_since' => 0, 'newest' => null, 'future_date' => '', 'behind_since' => 0];
        if (!$state['nastaveno']) {
            return $state;
        }
        $cache = json_decode($this->settings->get($this->cacheKey()), true);
        // a good answer is kept 12 hours, a failure only one – a source that was briefly unreachable is asked again soon;
        // the next major's manifest 12 hours either way (3.15: until it is published, its address answers 404 – the norm)
        $keep = is_array($cache) && ($cache['chyba'] ?? null) !== null && !$this->major ? 3600 : 12 * 3600;
        if (!$force && is_array($cache) && ($cache['url'] ?? '') === $this->url() && time() - (int) ($cache['overeno'] ?? 0) < $keep) {
            $manifest = $cache['manifest'] ?? null;
            $state['chyba'] = $cache['chyba'] ?? null;
            $state['overeno'] = (int) $cache['overeno'];
            $state['stale'] = ($cache['stale'] ?? false) === true;
            $state['refused'] = ($cache['refused'] ?? false) === true;
            $state['failing_since'] = (int) ($cache['failing_since'] ?? 0);
            $state['future_date'] = is_string($cache['future_date'] ?? null) ? $cache['future_date'] : '';
        } else {
            try {
                $manifest = $this->manifest();
            } catch (\RuntimeException $e) {
                $manifest = null;
                $state['chyba'] = $e->getMessage();
                $state['stale'] = $e->getCode() === self::STALE;
                $state['refused'] = in_array($e->getCode(), [self::STALE, self::WRONG_CHANNEL], true);
                // 3.10: since when the checks fail – carried over while they keep failing (System status says how long)
                $state['failing_since'] = is_array($cache) && ($cache['chyba'] ?? null) !== null && (int) ($cache['failing_since'] ?? 0) > 0 ? (int) $cache['failing_since'] : time();
            }
            $state['overeno'] = time();
            $state['future_date'] = $this->futureDate;
            $this->settings->set($this->cacheKey(), (string) json_encode(['url' => $this->url(), 'overeno' => time(), 'manifest' => $manifest, 'chyba' => $state['chyba'],
                'stale' => $state['stale'], 'refused' => $state['refused'], 'failing_since' => $state['failing_since'], 'future_date' => $state['future_date']], JSON_UNESCAPED_UNICODE));
        }
        $state['newest'] = self::newestOf($this->seen(), $this->url());
        $choice = self::choose(is_array($manifest) ? $manifest : null, $state['channel'], KALETA_VERSION, PHP_VERSION, $this->major);
        if (!$this->major && $state['chyba'] === null) {
            $this->noteBehind($choice['nova']);
        }

        return ['nova' => $choice['nova'], 'vyzaduje_php' => $choice['vyzaduje_php'], 'ahead_of' => $choice['ahead_of'], 'chyba' => $state['chyba'] ?? $choice['chyba'],
            'behind_since' => $this->settings->int('update_behind_since')] + $state;
    }

    /** The setting that keeps the last answer of this updater's source: the site's own line, or the next major (3.15). */
    private function cacheKey(): string
    {
        return $this->major ? 'update_major_cache' : 'update_cache';
    }

    /**
     * 3.15: remembers since when a newer release of the site's line has been offered (update_behind_since, a Unix time; 0 =
     * up to date) – written only when it changes, so the check costs nothing on an up-to-date site.
     *
     * @param array<string, mixed>|null $newVersion
     */
    private function noteBehind(?array $newVersion): void
    {
        $since = $this->settings->int('update_behind_since');
        if ($newVersion === null && $since !== 0) {
            $this->settings->set('update_behind_since', '0');
        } elseif ($newVersion !== null && $since === 0) {
            $this->settings->set('update_behind_since', (string) time());
        }
    }

    /**
     * 3.15: for how many days a newer release of the site's line has been available, or null when none is offered. The
     * site has been behind at least since it first saw the offer, and at least since the newest release came out (its
     * signed date) – a site that checks rarely, or that just installed 3.15, is not counted as up to date.
     *
     * @param array{nova: ?array<string, mixed>, behind_since?: int} $state from state()
     */
    public static function behindDays(array $state, ?int $now = null): ?int
    {
        if ($state['nova'] === null) {
            return null;
        }
        $now ??= time();
        $since = ($state['behind_since'] ?? 0) > 0 ? (int) $state['behind_since'] : $now;
        $released = self::releaseDate($state['nova']);
        if ($released !== '' && ($date = \DateTimeImmutable::createFromFormat('!Y-m-d', $released)) !== false) {
            $since = min($since, $date->getTimestamp());
        }

        return max(0, intdiv($now - $since, 86400));
    }

    /**
     * 3.15 (N315-3): why the next major version cannot be installed now, or null when this site runs the newest release of
     * its own line. $state is the line's state() – the update action asks for a fresh one (state(true)). Fails closed: the
     * newest release must be confirmed – a failing, refused or stale source says nothing, and a newer release of the line
     * counts even when it needs a newer PHP.
     *
     * @param array{nastaveno: bool, nova: ?array<string, mixed>, vyzaduje_php: ?array{verze: string, min_php: string, bezpecnostni: bool}, chyba: ?string, stale: bool, refused: bool} $state
     */
    public static function majorBlocker(array $state, string $current = KALETA_VERSION): ?string
    {
        return match (true) {
            $state['nova'] !== null => t('Install version %s first – a new major version is installed only from the newest release of the current one.', (string) $state['nova']['verze']),
            $state['vyzaduje_php'] !== null => t('Install version %s first – it needs PHP %s or newer, and a new major version is installed only from the newest release of the current one.',
                $state['vyzaduje_php']['verze'], $state['vyzaduje_php']['min_php']),
            !$state['nastaveno'] || $state['chyba'] !== null || $state['stale'] || $state['refused']
                => t('Kaleta could not confirm that this site runs the newest %d.x release, and a new major version is installed only from it. Press Check now and try again.', self::majorOf($current)),
            default => null,
        };
    }

    /**
     * 3.15: the next major version on offer – the release of the next major's manifest (4.0 for a 3.15 site) – or null.
     * Read from the remembered answer (12 hours), asked anew only when that is older; never throws.
     *
     * @return array<string, mixed>|null the verified manifest: verze, vydano, zmeny, min_php, bezpecnostni…
     */
    public static function nextMajor(Settings $settings): ?array
    {
        try {
            $state = (new self($settings, major: true))->state();
        } catch (\Throwable) {
            return null;
        }

        return $state['nova'] ?? null;
    }

    /**
     * The background job "updates" (2.9, Core\Scheduler): once every 12 hours checks for a new version and installs it when
     * that is allowed – a security release with automatic updates on, or the version the fleet console allowed (the site
     * lets the console decide, Fleet\Link). Otherwise a security release is announced to the site e-mail. Each version is
     * tried once (update_attempt); a failure is recorded as an event (update.failed / update.rolled_back), so it reaches
     * the alert e-mail.
     */
    public static function runInBackground(App $app): string
    {
        $s = $app->settings();
        $a = new self($s);
        if ($a->url() === '') {
            return 'no update source';
        }
        // 3.15: the next major version is only looked up here (remembered 12 hours) so the admin pages need not ask – it is
        // never installed from the background, whatever its manifest says
        self::nextMajor($s);
        $byConsole = $s->bool('fleet_updates') && \Kaleta\Fleet\Link::isPaired($s) ? $s->get('fleet_update_allowed') : '';
        $cache = json_decode($s->get('update_cache'), true);
        $fresh = is_array($cache) && ($cache['url'] ?? '') === $a->url() && time() - (int) ($cache['overeno'] ?? 0) < (($cache['chyba'] ?? null) !== null ? 3600 : 12 * 3600);
        if ($fresh && ($byConsole === '' || !version_compare($byConsole, KALETA_VERSION, '>') || $s->get('update_attempt') === $byConsole)) {
            return 'checked recently';
        }
        $newVersion = $a->state(!$fresh)['nova'];
        if ($newVersion === null) {
            return 'up to date';
        }
        $version = (string) $newVersion['verze'];
        $allowedByConsole = $byConsole !== '' && $byConsole === $version;
        if ((!$allowedByConsole && empty($newVersion['bezpecnostni'])) || $s->get('update_attempt') === $version) {
            return 'version ' . $version . ' available';
        }
        $s->set('update_attempt', $version); // each version is tried and announced only once
        $install = $allowedByConsole || $s->bool('auto_updates');
        // written to the site e-mail (an address without an account): admin texts in the site's default language. The task also
        // runs from the public site, where the admin dictionary is not loaded – Language::runWith() loads it just for this moment
        // (also for installation error messages).
        [$subject, $text, $result] = Language::runWith(Language::defaults($s), function () use ($app, $a, $s, $newVersion, $version, $install, $allowedByConsole): array {
            $result = 'announced';
            $message = t('Security update %s is available. Install it in the administration: Settings → Backups and updates.', $version);
            if ($install) {
                try {
                    Backup::create($app->db(), 'predaktualizaci');
                    // 3.3.2 (N40): the decision came from the cached manifest – install only that version with that flag
                    $a->install($app->db(), $version, $allowedByConsole ? null : !empty($newVersion['bezpecnostni']));
                    $result = 'installed';
                    $message = t('Security update %s was installed automatically. A database backup was created before the installation.', $version);
                } catch (\Throwable $e) {
                    $result = 'failed';
                    $message .= ' ' . t('The automatic installation failed: %s', $e->getMessage());
                }
            }

            return [
                t('Kaleta: security update %s', $version),
                $message . "\n\n" . t('Changes:') . "\n- " . implode("\n- ", (array) $newVersion['zmeny']) . "\n\n" . $s->get('site_name'),
                $result,
            ];
        }, 'admin-');
        // an update the console decided about is the console owner's business – the client is not e-mailed (a failure still
        // reaches the alert e-mail as an event)
        $recipient = $s->get('site_email');
        if ($recipient !== '' && !empty($newVersion['bezpecnostni']) && !$allowedByConsole) {
            Mail::send($s, $recipient, $subject, $text);
        }

        return $result . ' ' . $version;
    }

    /**
     * @param Db|null $db the site database: the new version's migrations run right after the files are uploaded, and when they
     *                    fail, the files are reverted too (so the site is not left with new code over an unmigrated database)
     * @param string|null $expectedVersion the version the decision was made for (3.3.2): the manifest downloaded now must offer
     *                    exactly it, otherwise nothing is installed
     * @param bool|null $expectedSecurity whether that version was announced as a security release: the signed flag of the
     *                    package must say the same (an automatic installation relies on it)
     * @return string the installed version
     */
    public function install(?Db $db = null, ?string $expectedVersion = null, ?bool $expectedSecurity = null): string
    {
        if (!class_exists(\ZipArchive::class) || !function_exists('sodium_crypto_sign_verify_detached')) {
            throw new \RuntimeException(t('The server lacks the zip or sodium extension – update manually by uploading the files over FTP.'));
        }
        // download, writing, migrations and the check after it may take longer than a page view is allowed to (2.10)
        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }
        if ($this->major && $expectedVersion === null) {
            throw new \LogicException('A major version is installed only for the version the administrator chose.');
        }
        $m = $this->manifest();
        if ($this->effectiveChannel() === 'stable' && ($m['kanal'] ?? null) !== 'stable') {
            throw new \RuntimeException(self::notStableMessage());
        }
        // 3.15 (defence in depth): a regular update never moves to another major version, the next-major update only to the next one
        if (self::majorOf((string) $m['verze']) !== $this->line()) {
            throw new \RuntimeException($this->major
                ? t('The update source of Kaleta %d offers version %s – nothing was installed.', $this->line(), (string) $m['verze'])
                : t('Version %s is a new major version of Kaleta. It is never installed as a regular update – Settings → Backups and updates offers it on its own, with a backup first.', (string) $m['verze']));
        }
        if (!version_compare((string) $m['verze'], KALETA_VERSION, '>')) {
            throw new \RuntimeException(t('No newer version is available.'));
        }
        // the manifest is checked again against what was decided on; the signature below covers the version and the flag
        if ($expectedVersion !== null && (string) $m['verze'] !== $expectedVersion) {
            // 3.14: the screen shows what the source offers now – before, it kept the remembered version for up to 12 hours
            // and every further click failed the same way (the owner's report from kaletacms.com, 3.12.1 vs 3.13.1)
            $this->remember($m);
            throw new \RuntimeException(t('The update source now offers version %s instead of %s – nothing was installed. The screen now shows version %s; check what it brings and update again.', (string) $m['verze'], $expectedVersion, (string) $m['verze']));
        }
        if ($expectedSecurity !== null && !empty($m['bezpecnostni']) !== $expectedSecurity) {
            $this->remember($m);
            throw new \RuntimeException(t('The update source changed whether version %s is a security release – nothing was installed. Check for updates again.', (string) $m['verze']));
        }
        if (self::phpTooOld($m)) {
            throw new \RuntimeException(t('The new version requires PHP %s; the server runs %s.', self::minPhp($m), PHP_VERSION));
        }
        if (!is_writable($this->root) || !is_writable($this->root . '/system')) {
            throw new \RuntimeException(t('The system files are not writable – update manually over FTP.'));
        }
        if (Signature::keys($this->keyFile) === []) {
            throw new \RuntimeException(t('The publisher\'s public key (system/aktualizace.pub) is missing, the package cannot be verified.'));
        }

        // lock: an automatic update from background tasks and an administrator's click (or two visits at once) must not overwrite files simultaneously
        $lock = fopen(KALETA_ROOT . '/storage/cache/aktualizace.zamek', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            throw new \RuntimeException(t('An update is already running. Try again in a moment.'));
        }
        $workDir = KALETA_ROOT . '/storage/cache/aktualizace-' . bin2hex(random_bytes(4));
        $zip = $workDir . '.zip';
        $restoreFailed = false;
        try {
            $this->download((string) $m['url'], $zip);
            $sha = hash_file('sha256', $zip);
            if (!hash_equals(strtolower((string) $m['sha256']), $sha)) {
                throw new \RuntimeException(t('The package checksum does not match.'));
            }
            // the signature also covers the security-release flag: whoever controlled only the site with the manifest must not declare a regular release a security one
            if (!Signature::isValid(Signature::packageMessage((string) $m['verze'], $sha, !empty($m['bezpecnostni'])), (string) $m['podpis'], $this->keyFile)) {
                throw new \RuntimeException(t('The package signature is not valid – the package does not come from the Kaleta publisher.'));
            }
            $files = $this->extract($zip, $workDir);
            // 3.9 (N37-4): the PHP the package itself needs, before a file is written – the manifest's min_php is unsigned
            // in a manifest without signature v2
            $packageMinPhp = self::packageMinPhp((string) file_get_contents($workDir . '/system/bootstrap.php'));
            if ($packageMinPhp !== null && version_compare(PHP_VERSION, $packageMinPhp, '<')) {
                throw new \RuntimeException(t('The new version requires PHP %s; the server runs %s.', $packageMinPhp, PHP_VERSION));
            }
            $previous = $this->releaseFiles();
            $releaseHashes = $this->releaseHashes();
            touch(KALETA_ROOT . '/storage/udrzba.lock');
            // every file being overwritten is set aside first: if writing fails halfway, the site returns to its original state (not a mix of versions)
            $setAside = $workDir . '-puvodni';
            $written = [];
            try {
                foreach ($files as $relativePath) {
                    $target = $this->root . '/' . $relativePath;
                    if ($relativePath === '.htaccess' && is_file($target) && isset($releaseHashes['.htaccess']) && !hash_equals($releaseHashes['.htaccess'], (string) hash_file('sha256', $target))) {
                        // custom edits of .htaccess (HTTPS, www, redirects) are not overwritten – the new version is placed next to it for comparison
                        copy($workDir . '/' . $relativePath, $target . '.kaleta-nova');
                        continue;
                    }
                    if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0775, true)) {
                        throw new \RuntimeException(t('Cannot create the folder %s.', dirname($relativePath)));
                    }
                    if (is_file($target)) {
                        if (!is_dir(dirname($setAside . '/' . $relativePath)) && !mkdir(dirname($setAside . '/' . $relativePath), 0775, true) || !copy($target, $setAside . '/' . $relativePath)) {
                            throw new \RuntimeException(t('Cannot write the file %s.', 'storage/cache'));
                        }
                    }
                    if (!copy($workDir . '/' . $relativePath, $target)) {
                        throw new \RuntimeException(t('Cannot write the file %s.', $relativePath));
                    }
                    $written[] = $relativePath;
                }
                if ($db !== null) {
                    // migrations read files from disk, i.e. already from the new version; the changes are additive only, the old code keeps running on them
                    Migration::apply($db, $this->settings);
                }
            } catch (\Throwable $e) {
                $stuck = $this->rollBack($written, $setAside, $db, (string) $m['verze']);
                $restoreFailed = $stuck !== null;
                if ($db !== null) {
                    Events::record($db, 'update.failed', 'error', mb_substr(t('Installing version %s failed: %s', (string) $m['verze'], $e->getMessage()), 0, 255), ['version' => (string) $m['verze']]);
                }
                throw new \RuntimeException($e->getMessage() . ' ' . ($stuck ?? t('The website files have been returned to their state before the update.')), $restoreFailed ? self::RESTORE_FAILED : 0, $e);
            }
            // 2.8: does the site work on the new version? Maintenance ends, the site is asked; when it answers with an error,
            // the previous files come back (the database changes are additive, the old version keeps working on them)
            @unlink(KALETA_ROOT . '/storage/udrzba.lock');
            $answers = [];
            $problem = $db !== null ? $this->probe((string) $m['verze'], $answers) : null;
            if ($problem !== null) {
                $stuck = $this->rollBack($written, $setAside, $db, (string) $m['verze']);
                $restoreFailed = $stuck !== null;
                if (function_exists('opcache_reset')) {
                    @opcache_reset();
                }
                $this->settings->set('update_attempt', (string) $m['verze']); // an automatic update does not try the same version again
                if ($stuck !== null) {
                    // not rolled back: the monthly report and the heartbeat count it as a failed update, the event above names the files
                    Events::record($db, 'update.failed', 'error', mb_substr(t('Installing version %s failed: %s', (string) $m['verze'], $problem), 0, 255), ['version' => (string) $m['verze']]);
                    throw new \RuntimeException(t('Version %s was installed but the site did not work afterwards (%s).', (string) $m['verze'], $problem) . ' ' . $stuck, self::RESTORE_FAILED);
                }
                Events::record($db, 'update.rolled_back', 'error', mb_substr(t('Version %s did not work (%s), so the site went back to version %s.', (string) $m['verze'], $problem, KALETA_VERSION), 0, 255),
                    ['version' => (string) $m['verze'], 'answers' => array_map(fn (array $a): array => [$a[0], mb_substr(trim(strip_tags($a[1])), 0, 80)], $answers)]);
                throw new \RuntimeException(t('Version %s was installed but the site did not work afterwards (%s). The website files have been returned to their state before the update.', (string) $m['verze'], $problem));
            }
            self::deleteFolder($setAside);
            self::cleanUpObsolete($this->root, $previous, $files);
        } finally {
            if (!$restoreFailed) {
                @unlink(KALETA_ROOT . '/storage/udrzba.lock'); // N88: after a rollback that left files behind, the maintenance page stays
            }
            @unlink($zip);
            self::deleteFolder($workDir);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
        // the version just installed is the newest the source knows: the admin page right after the update need not ask again
        // (asking the source in that moment failed on sites that are their own update source and showed a red error, 2.10.2);
        // 3.15: after a major update this source is the new version's own line – remembered as the answer of its own check
        $this->remember($m, $this->major ? 'update_cache' : null);
        if ($this->major) {
            $this->settings->set('update_major_cache', '');
        }
        if ($db !== null) {
            Events::record($db, 'update.applied', 'info', t('Version %s was installed (from %s).', (string) $m['verze'], KALETA_VERSION), ['version' => (string) $m['verze'], 'from' => KALETA_VERSION]);
        }

        return (string) $m['verze'];
    }

    /**
     * Files written by the update go back: each from the set-aside copy, a new one is removed. 3.19.1 (N88): a file that
     * cannot be put back (a full disk or quota, a file owned by another user – often the same cause that made the update
     * fail) is counted, and the set-aside folder is deleted only when every file came back; otherwise it is the only copy
     * of the originals and stays.
     *
     * @param list<string> $written
     * @return list<string> the files that could not be put back, in the order they were written
     */
    private function restore(array $written, string $setAside): array
    {
        $failed = [];
        foreach (array_reverse($written) as $relativePath) {
            $original = $setAside . '/' . $relativePath;
            $target = $this->root . '/' . $relativePath;
            $back = is_file($original) ? @copy($original, $target) && hash_file('sha256', $original) === hash_file('sha256', $target)
                : !file_exists($target) || @unlink($target);
            if (!$back) {
                $failed[] = $relativePath;
            }
        }
        if ($failed === []) {
            self::deleteFolder($setAside);
        }

        return array_reverse($failed);
    }

    /**
     * Rolls the written files back (restore). Null when every file came back. Otherwise (N88) the site is a mix of two
     * versions: the set-aside folder stays, storage/udrzba.lock is written with RESTORE_FAILED_MARK so visitors keep
     * getting the maintenance page until a person fixes the files (App::boot honours it however old it is), the event
     * update.restore_failed names the folder and the files (the alert e-mail carries it), and the text for the
     * administrator and the background job's e-mail comes back.
     *
     * @param list<string> $written
     */
    private function rollBack(array $written, string $setAside, ?Db $db, string $version): ?string
    {
        $failed = $this->restore($written, $setAside);
        if ($failed === []) {
            return null;
        }
        $folder = str_starts_with($setAside, KALETA_ROOT . '/') ? substr($setAside, strlen(KALETA_ROOT) + 1) : $setAside;
        $text = self::restoreFailedText(count($failed), $failed, $folder, KALETA_VERSION);
        @file_put_contents(KALETA_ROOT . '/storage/udrzba.lock', self::RESTORE_FAILED_MARK . "\n" . $text . "\n", LOCK_EX);
        if ($db !== null) {
            Events::record($db, 'update.restore_failed', 'error', mb_substr(t('Installing version %s failed and %d file(s) could not be put back – the originals are in %s.', $version, count($failed), $folder), 0, 255),
                ['version' => $version, 'from' => KALETA_VERSION, 'folder' => $folder, 'count' => count($failed), 'files' => array_slice($failed, 0, 50)]);
        }

        return $text;
    }

    /**
     * What the administrator (and the background job's e-mail) reads after a rollback that left files behind (N88).
     *
     * @param list<string> $files
     */
    public static function restoreFailedText(int $count, array $files, string $folder, string $version): string
    {
        $shown = implode(', ', array_slice($files, 0, 5)) . (count($files) > 5 ? ', …' : '');

        return t('%d file(s) could not be put back (%s), so the site is a mix of two versions. The originals are in %s: copy them back over FTP, or upload the files of version %s over FTP, then delete storage/udrzba.lock. Until then visitors see the maintenance page.', $count, $shown, $folder, $version);
    }

    /** N88: the text of a rollback that left files behind while storage/udrzba.lock still says so; null otherwise. */
    public static function restoreFailed(string $lock = KALETA_ROOT . '/storage/udrzba.lock'): ?string
    {
        if (!is_file($lock)) {
            return null;
        }
        $content = (string) @file_get_contents($lock, false, null, 0, 4096);
        if (!str_starts_with($content, self::RESTORE_FAILED_MARK)) {
            return null;
        }

        return trim(substr($content, strlen(self::RESTORE_FAILED_MARK)));
    }

    /**
     * After the files are written: asks the site itself whether the new version runs – the cron address with a one-time
     * probe code must answer with the new version, and the home page and the administration must not end with a server
     * error. Returns the problem, or null when it works – also when the site cannot reach itself (some hostings block
     * that): an update is never undone without a clear sign that it broke the site.
     *
     * @param array<string, array{0: int, 1: string}> $answers what the site answered (for the event when the update is undone)
     * @param-out array<string, array{0: int, 1: string}> $answers
     */
    private function probe(string $version, array &$answers): ?string
    {
        $answers = [];
        $site = rtrim($this->settings->get('site_url'), '/');
        if ($site === '' || getenv('KALETA_UPDATE_PROBE') === 'off') {
            return null;
        }
        $code = bin2hex(random_bytes(12));
        $this->settings->set('update_probe', $code);
        // the web server must run the new files, not the cached old ones (a mix of both can fail where nothing is broken)
        $reset = function_exists('opcache_reset') && @opcache_reset();
        if (filter_var(ini_get('opcache.enable'), FILTER_VALIDATE_BOOL)) {
            if (filter_var(ini_get('opcache.validate_timestamps'), FILTER_VALIDATE_BOOL)) {
                // other PHP processes (and hostings that restrict opcache_reset) notice changed files after revalidate_freq
                sleep(min(10, max(0, (int) ini_get('opcache.revalidate_freq'))) + 1);
            } elseif (!$reset) {
                $this->settings->set('update_probe', '');

                return null; // files are never checked again and the cache cannot be cleared – the answers would say nothing
            }
        }
        /** @var \Closure(string): array{0: int, 1: string} $fetch */
        $fetch = $this->probeFetch ?? function (string $url): array {
            $context = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true, 'follow_location' => 1, 'max_redirects' => 3,
                'header' => "User-Agent: Kaleta-update-check/" . KALETA_VERSION . "\r\n"], 'ssl' => ['verify_peer' => true]]);
            $body = @file_get_contents($url, false, $context, 0, 200_000);
            $status = 0;
            foreach (last_response_headers($http_response_header ?? null) as $line) { // @phpstan-ignore nullCoalesce.variable (undefined on PHP 8.3 when the request fails)
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $s)) {
                    $status = (int) $s[1]; // the last one wins (after redirects)
                }
            }

            return [$status, $body === false ? '' : $body];
        };
        try {
            $probe = $fetch($site . '/ulohy?probe=' . $code);
            if (str_starts_with(trim($probe[1]), 'KALETA-PROBE ') && trim($probe[1]) !== 'KALETA-PROBE ' . $version) {
                // the old version answered: PHP still runs the cached old files (opcache checks them every few seconds)
                sleep(3);
                $probe = $fetch($site . '/ulohy?probe=' . $code);
            }
            // no answer at all: the site cannot reach itself (a hosting firewall, one PHP worker) – the rest would only wait
            $unreachable = $probe[0] === 0;
            $answers = [
                'probe' => $probe,
                'home' => $unreachable ? [0, ''] : $fetch($site . '/'),
                'admin' => $unreachable ? [0, ''] : $fetch($site . '/admin.php'),
            ];
        } finally {
            $this->settings->set('update_probe', '');
        }

        return self::probeVerdict($answers, $version);
    }

    /**
     * Whether the answers say the new version is broken: only a server error counts. Nothing reachable, a refusal or an
     * answer of the old version (PHP still runs cached files, or a proxy answers) = unknown, not broken (null) – undoing
     * a working update would be worse than keeping it.
     *
     * @param array{probe: array{0: int, 1: string}, home: array{0: int, 1: string}, admin: array{0: int, 1: string}} $answers [status, body]
     */
    public static function probeVerdict(array $answers, string $version): ?string
    {
        if ($answers['probe'][0] >= 500 && trim($answers['probe'][1]) !== 'KALETA-PROBE ' . $version) {
            return t('the site does not start');
        }
        foreach (['home' => t('the home page'), 'admin' => t('the administration')] as $key => $what) {
            if ($answers[$key][0] >= 500) {
                return t('%s ends with error %d', $what, $answers[$key][0]);
            }
        }

        return null;
    }

    /**
     * What of a downloaded manifest the site may act on (3.9, manifest signature v2; audit findings N38-3 and N37-4).
     *
     * - With "podpis2": it must be valid (Signature::manifestValid), so every field the site acts on is the publisher's,
     *   the channel and min_php included. An invalid one refuses the manifest: nothing is offered or installed.
     * - Without it (a manifest from before 3.9): only a version older than SIGNED_V2_SINCE is read this way – a newer one
     *   without v2 was tampered with (the signature removed to get around it) and is refused. Pre-releases of 3.9.0 need v2
     *   too (needsSignatureV2, 3.10, N39-8). An old manifest is read
     *   under the v1 rules, except that its unsigned "kanal" is dropped: it never makes a stable-channel manifest (the
     *   stable channel offers nothing from it). Its unsigned min_php decides only what is offered; install() checks the
     *   requirement of the package itself (its KALETA_MIN_PHP) before it writes a file.
     *
     * The v1 signature ("podpis") is checked at installation as before, in both cases.
     *
     * @param array<array-key, mixed> $manifest the decoded manifest, before anything in it is changed
     * @return array<array-key, mixed>
     */
    public static function verified(array $manifest, string $keyFile): array
    {
        $version = $manifest['verze'] ?? null;
        $signed = array_key_exists('podpis2', $manifest) ? Signature::manifestValid($manifest, $keyFile)
            : is_string($version) && !self::needsSignatureV2($version);
        if (!$signed) {
            throw new \RuntimeException(t('The update information is not signed by the Kaleta publisher, so nothing is offered or installed.'));
        }
        if (!array_key_exists('podpis2', $manifest)) {
            unset($manifest['kanal']);
        }

        return $manifest;
    }

    /**
     * Must a manifest of this version carry signature v2 (3.10, audit N39-8)? Only the numeric release (X.Y.Z) counts: in
     * version_compare() "3.9.0-rc1" and "3.9.0-dev" are lower than "3.9.0" and an unknown suffix ("3.9.0-x") lower still,
     * so a pre-release of 3.9.0 would pass as a release from before 3.9. Every 3.9.0 build needs v2; a version that does not
     * start with X.Y.Z needs it too (there is nothing older to read it as).
     */
    public static function needsSignatureV2(string $version): bool
    {
        return preg_match('/^(\d+\.\d+\.\d+)/', $version, $release) !== 1 || version_compare($release[1], self::SIGNED_V2_SINCE, '>=');
    }

    /**
     * Is a verified manifest older than the newest signed one the same update source already gave this site (3.10, audit
     * N39-7)? A host or mirror that serves an older, validly signed manifest would otherwise freeze the site on it, or – on
     * a site that has not updated yet – offer an older release than the source really has.
     *
     * Order: the signed release date ("vydano", YYYY-MM-DD) first, the version on the same day (tools/release.php writes the
     * day only, and a patch can follow on the same day). A newer date wins even with a lower version: the publisher may
     * deliberately re-sign (docs/RELEASING.md). A manifest without signature v2 after a v2 one is stale by definition (it
     * comes from before 3.9, and its date is not signed); without a valid date a v2 manifest sorts below any dated one.
     *
     * @param array<array-key, mixed> $manifest the verified manifest (verified())
     * @param array{verze: string, vydano: string, at?: int}|null $seen the newest one the source gave before (null = none yet)
     */
    public static function isStale(array $manifest, ?array $seen): bool
    {
        if ($seen === null) {
            return false;
        }
        if (!array_key_exists('podpis2', $manifest)) {
            return true;
        }
        $date = self::releaseDate($manifest);
        if ($date !== $seen['vydano']) {
            return $date < $seen['vydano'];
        }

        return version_compare(is_scalar($manifest['verze'] ?? null) ? (string) $manifest['verze'] : '', $seen['verze'], '<');
    }

    /**
     * The signed release date of a manifest (YYYY-MM-DD) as a site orders by it; '' when it is missing or not a date.
     *
     * @param array<array-key, mixed> $manifest
     */
    public static function releaseDate(array $manifest): string
    {
        $date = $manifest['vydano'] ?? null;

        return is_string($date) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $d) === 1 && checkdate((int) $d[2], (int) $d[3], (int) $d[1]) ? $date : '';
    }

    /**
     * The newest signed manifest each update source gave this site, per channel: "<update source address>#<signed kanal>"
     * => version, release date and when it was received (setting update_seen, 3.10; the channel in the key since N310-1).
     *
     * @return array<string, array{verze: string, vydano: string, at: int}>
     */
    private function seen(): array
    {
        $seen = [];
        foreach ((array) json_decode($this->settings->get('update_seen'), true) as $key => $entry) {
            if (is_string($key) && is_array($entry) && is_string($entry['verze'] ?? null) && is_string($entry['vydano'] ?? null)) {
                $seen[$key] = ['verze' => $entry['verze'], 'vydano' => $entry['vydano'], 'at' => (int) ($entry['at'] ?? 0)];
            }
        }

        return $seen;
    }

    /**
     * Whether a site may act on a verified manifest, and what it then remembers (pure, 3.10; audit N39-7, N310-1, N310-3).
     *
     * 1. The channel first, before anything is remembered (N310-1): on the stable channel only a manifest that says
     *    "kanal": "stable" counts, on the latest channel never one that says "stable" (a manifest without signature v2 has
     *    no channel left after verified(), so it counts as latest). A custom source without a stable twin takes both, as
     *    before. Otherwise the other channel's genuine manifest, served once at the wrong address, would make the right one
     *    look stale – a lasting block on Stable, a silent freeze on Latest.
     * 2. Freshness (N39-7): the newest one remembered for this source AND its channel (key "<url>#<kanal>") must not be
     *    newer (isStale). A manifest without v2 is stale when anything is remembered for the source.
     * 3. Only a v2 manifest is remembered, and not one dated more than FUTURE_DAYS after today (N310-3): a wrong date (a
     *    typo, the clock of the signing machine – or of this server) would otherwise lock out every later release. It is
     *    still offered; System status reports the date.
     *
     * @param array<array-key, mixed> $manifest the verified manifest (verified())
     * @param string $channel effectiveChannel(): latest | stable | custom
     * @param array<string, array{verze: string, vydano: string, at: int}> $seen
     * @return array{refused: ?string, newest: ?array{verze: string, vydano: string, at: int}, seen: array<string, array{verze: string, vydano: string, at: int}>, future: ?string}
     *         refused = null | "channel" | "stale"; newest = the remembered one it was compared with; future = a date not remembered
     */
    public static function admit(array $manifest, string $channel, string $url, array $seen, ?int $now = null): array
    {
        $now ??= time();
        $result = ['refused' => null, 'newest' => null, 'seen' => $seen, 'future' => null];
        $kanal = is_string($manifest['kanal'] ?? null) && $manifest['kanal'] !== '' ? $manifest['kanal'] : 'latest';
        if ($channel !== 'custom' && ($channel === 'stable') !== ($kanal === 'stable')) {
            $result['refused'] = 'channel';

            return $result;
        }
        if (!array_key_exists('podpis2', $manifest)) {
            // from before 3.9 (its date and channel are not signed): stale after any signed one of this source
            foreach ($seen as $key => $entry) {
                if (str_starts_with($key, $url . '#')) {
                    return ['refused' => 'stale', 'newest' => $entry] + $result;
                }
            }

            return $result;
        }
        $key = $url . '#' . $kanal;
        if (self::isStale($manifest, $seen[$key] ?? null)) {
            return ['refused' => 'stale', 'newest' => $seen[$key]] + $result;
        }
        $date = self::releaseDate($manifest);
        if ($date > gmdate('Y-m-d', $now + self::FUTURE_DAYS * 86400)) {
            $result['future'] = $date;

            return $result;
        }
        unset($seen[$key]); // the source just used goes last, the oldest ones drop out
        $seen[$key] = ['verze' => (string) $manifest['verze'], 'vydano' => $date, 'at' => $now];
        $result['seen'] = array_slice($seen, -self::SEEN_SOURCES, null, true);

        return $result;
    }

    /**
     * How old the newest update information of the source is, in days, when it is older than FRESHNESS_DAYS – otherwise
     * null (3.11, UX310-3: System status and Backups and updates show the same warning from it).
     *
     * @param array{newest: ?array{verze: string, vydano: string, at: int}} $state from state()
     */
    public static function staleDays(array $state, ?int $now = null): ?int
    {
        $published = $state['newest'] !== null && $state['newest']['vydano'] !== '' ? \DateTimeImmutable::createFromFormat('!Y-m-d', $state['newest']['vydano']) : false;
        $now ??= time();
        if ($published === false || $published->getTimestamp() >= $now - self::FRESHNESS_DAYS * 86400) {
            return null;
        }

        return intdiv($now - $published->getTimestamp(), 86400);
    }

    /**
     * The newest manifest remembered for an update source, of any channel (a custom source may have both).
     *
     * @param array<string, array{verze: string, vydano: string, at: int}> $seen
     * @return array{verze: string, vydano: string, at: int}|null
     */
    public static function newestOf(array $seen, string $url): ?array
    {
        $newest = null;
        foreach ($seen as $key => $entry) {
            if (str_starts_with($key, $url . '#') && ($newest === null || $entry['vydano'] > $newest['vydano'] || ($entry['vydano'] === $newest['vydano'] && version_compare($entry['verze'], $newest['verze'], '>')))) {
                $newest = $entry;
            }
        }

        return $newest;
    }

    /**
     * Applies admit() to a downloaded manifest: refuses it with a message the administrator can act on, or remembers it.
     *
     * @param array<array-key, mixed> $manifest the verified manifest
     */
    private function admitManifest(array $manifest): void
    {
        $channel = $this->effectiveChannel();
        $seen = $this->seen();
        $verdict = self::admit($manifest, $channel, $this->url(), $seen);
        $version = is_scalar($manifest['verze'] ?? null) ? (string) $manifest['verze'] : '?';
        if ($verdict['refused'] === 'channel') {
            throw new \RuntimeException($channel === 'stable' ? self::notStableMessage()
                : t('The update source of the Latest channel answered with the stable-channel release (version %s) – usually a wrong redirect at the source. Nothing is offered or installed from it; the check repeats every hour.', $version), self::WRONG_CHANNEL);
        }
        if ($verdict['refused'] === 'stale' && $verdict['newest'] !== null) {
            throw new \RuntimeException(t('The update source answered with older update information (version %s, published %s) than it gave this site before (version %s, published %s) – an outdated copy of the file, or someone serving an old one on purpose. Nothing is offered or installed from it; the check repeats every hour.',
                $version, self::releaseDate($manifest) !== '' ? self::releaseDate($manifest) : '?', $verdict['newest']['verze'], $verdict['newest']['vydano'] !== '' ? $verdict['newest']['vydano'] : '?'), self::STALE);
        }
        $this->futureDate = $verdict['future'] ?? '';
        if ($verdict['seen'] !== $seen) {
            $this->settings->set('update_seen', (string) json_encode($verdict['seen'], JSON_UNESCAPED_SLASHES));
        }
    }

    /** Remembers a verified manifest as the answer of the last check, so the update screen shows it (3.14). */
    private function remember(array $manifest, ?string $key = null): void
    {
        // the warning about a manifest signed with a date in the future stays (N314-5)
        $this->settings->set($key ?? $this->cacheKey(), (string) json_encode(['url' => $this->url(), 'overeno' => time(), 'manifest' => $manifest, 'chyba' => null,
            'future_date' => $this->futureDate], JSON_UNESCAPED_UNICODE));
    }

    /**
     * Forgets every remembered manifest and the last check (3.10, N310-3: Settings -> Backups and updates, "Forget
     * remembered update information"): the next check accepts whatever valid signed manifest the source gives.
     */
    public function forgetSeen(): void
    {
        $this->settings->set('update_seen', '');
        $this->settings->set('update_cache', '');
        $this->settings->set('update_major_cache', '');
    }

    /** The oldest PHP a package says it needs (KALETA_MIN_PHP in its system/bootstrap.php, 3.7 and later); null = it does not say. */
    public static function packageMinPhp(string $bootstrapSource): ?string
    {
        return preg_match("/^const KALETA_MIN_PHP = '(\\d+\\.\\d+(?:\\.\\d+)?)';/m", $bootstrapSource, $found) === 1 ? $found[1] : null;
    }

    /** @return array<string, mixed> */
    private function manifest(): array
    {
        $json = $this->http($this->url(), 200 * 1024, 6); // short limit: the check runs after the page is sent, but not every server can detach it
        $m = json_decode($json, true);
        if (!is_array($m) || !isset($m['verze'], $m['url'], $m['sha256'], $m['podpis']) || !preg_match('/^\d+\.\d+\.\d+([.-][0-9A-Za-z.-]+)?$/D', (string) $m['verze'])) {
            throw new \RuntimeException(t('The update information file is not in a valid format.'));
        }
        $m = self::verified($m, $this->keyFile); // 3.9: before anything else is read from it
        $this->admitManifest($m); // 3.10 (N39-7, N310-1): of this channel, and not older than what this source gave before
        $m['zmeny'] = array_values(array_filter(array_map(fn ($z): string => mb_substr((string) $z, 0, 300), (array) ($m['zmeny'] ?? []))));

        return $m;
    }

    private function download(string $url, string $target): void
    {
        file_put_contents($target, $this->http($url, self::MAX_BYTES));
    }

    private function http(string $url, int $maxBytes, int $timeoutSeconds = 30): string
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $isLocal = in_array($host, ['localhost', '127.0.0.1'], true);
        if (!preg_match('#^https://#i', $url) && !($isLocal && preg_match('#^http://#i', $url))) {
            throw new \RuntimeException(t('The update source must use an https:// address.'));
        }
        $data = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => $timeoutSeconds, 'follow_location' => 1, 'max_redirects' => 5, 'header' => "User-Agent: Kaleta/" . KALETA_VERSION . "\r\n"]]), 0, $maxBytes + 1);
        if ($data === false || $data === '') {
            throw new \RuntimeException(t('The update source is not reachable (%s).', $host));
        }
        if (strlen($data) > $maxBytes) {
            throw new \RuntimeException(t('The downloaded file is unexpectedly large.'));
        }

        return $data;
    }

    /**
     * Extracts the package into a working folder and returns the list of files to overwrite (without protected paths).
     *
     * @return list<string>
     */
    private function extract(string $zipFile, string $destination): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipFile) !== true) {
            throw new \RuntimeException(t('The package cannot be opened.'));
        }
        $fileNames = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $fileNames[] = (string) $zip->getNameIndex($i);
        }
        // the package may have everything in one top-level folder (as GitHub does) - it is stripped off
        $first = array_unique(array_map(fn (string $j): string => explode('/', $j, 2)[0], $fileNames));
        $prefix = count($first) === 1 && !in_array('index.php', $fileNames, true) ? $first[0] . '/' : '';

        $files = [];
        foreach ($fileNames as $i => $displayName) {
            $relativePath = substr($displayName, strlen($prefix));
            if ($relativePath === '' || str_ends_with($relativePath, '/')) {
                continue;
            }
            if (str_contains($relativePath, '..') || str_starts_with($relativePath, '/') || str_contains($relativePath, "\0") || str_contains($relativePath, '\\')) {
                throw new \RuntimeException(t('The package contains an unsafe path.'));
            }
            foreach (self::PROTECTED_PATHS as $protectedPath) {
                if ($relativePath === $protectedPath || (str_ends_with($protectedPath, '/') && str_starts_with($relativePath, $protectedPath))) {
                    continue 2;
                }
            }
            $target = $destination . '/' . $relativePath;
            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0775, true);
            }
            file_put_contents($target, $zip->getFromIndex($i));
            $files[] = $relativePath;
        }
        $zip->close();
        if (!in_array('system/bootstrap.php', $files, true) || !in_array('index.php', $files, true)) {
            throw new \RuntimeException(t('The package does not contain Kaleta.'));
        }

        return $files;
    }

    /**
     * Files that left the package before the update could clean up after itself (or the site skipped them): path => hashes
     * of all released versions. The core file list no longer knows about them, so they are cleaned up by this list.
     */
    private const array REMOVED_FILES = [
        // 'path/to/file.php' => ['sha256 of a released version', …] – files the new version removed
    ];

    /**
     * One-time cleanup after moving to a new version: deletes known removed files, but only when they are exactly as we
     * released them. A file the administrator edited is left alone.
     *
     * @return int number of deleted files
     */
    public static function cleanUpRemoved(string $root): int
    {
        $deleted = 0;
        foreach (self::REMOVED_FILES as $relativePath => $hashes) {
            $file = $root . '/' . $relativePath;
            if (is_file($file) && in_array(hash_file('sha256', $file), $hashes, true) && @unlink($file)) {
                $deleted++;
                @rmdir(dirname($file)); // the folder disappears only if it was left empty
            }
        }
        // files of the previous release that the package carries only for the update to run: the old code that installs it
        // still loads its classes in the same request (renamed classes would otherwise be missing) – the new version then deletes them
        $legacy = json_decode((string) @file_get_contents($root . '/system/soubory.json'), true)['legacy'] ?? [];
        foreach (is_array($legacy) ? $legacy : [] as $relativePath => $hash) {
            $file = $root . '/' . $relativePath;
            if ((str_starts_with((string) $relativePath, 'system/src/') || $relativePath === 'system/class-aliases.php') && !str_contains((string) $relativePath, '..') && is_file($file)
                && hash_equals((string) $hash, (string) hash_file('sha256', $file)) && @unlink($file)) {
                $deleted++;
                for ($folder = dirname($file); $folder !== $root . '/system/src' && @rmdir($folder); $folder = dirname($folder));
            }
        }

        return $deleted;
    }

    /** @return array<string, string> hashes of the files of the currently installed release (system/soubory.json) */
    private function releaseHashes(): array
    {
        $data = json_decode((string) @file_get_contents($this->root . '/system/soubory.json'), true);

        return is_array($data['soubory'] ?? null) ? array_map('strval', $data['soubory']) : [];
    }

    /** @return list<string> core files per the list of the currently installed release (system/soubory.json); empty without the list */
    private function releaseFiles(): array
    {
        $data = json_decode((string) @file_get_contents($this->root . '/system/soubory.json'), true);

        return is_array($data['soubory'] ?? null) ? array_map('strval', array_keys($data['soubory'])) : [];
    }

    /**
     * Deletes files that belonged to the old release and are no longer in the new one (renamed and removed parts of the system).
     * Deletes only what the old release itself brought - it does not touch custom files, templates, media or protected paths.
     *
     * @param list<string> $previous
     * @param list<string> $newItems
     * @return int number of deleted files
     */
    public static function cleanUpObsolete(string $root, array $previous, array $newItems): int
    {
        $deleted = 0;
        foreach (array_diff($previous, $newItems) as $relativePath) {
            if (str_contains($relativePath, '..') || str_starts_with($relativePath, '/') || str_contains($relativePath, '\\') || str_contains($relativePath, "\0")) {
                continue;
            }
            foreach (self::PROTECTED_PATHS as $protectedPath) {
                if ($relativePath === $protectedPath || (str_ends_with($protectedPath, '/') && str_starts_with($relativePath, $protectedPath))) {
                    continue 2;
                }
            }
            $file = $root . '/' . $relativePath;
            if (is_file($file) && @unlink($file)) {
                $deleted++;
                @rmdir(dirname($file)); // the folder disappears only if it was left empty
            }
        }

        return $deleted;
    }

    private static function deleteFolder(string $folder): void
    {
        if (!is_dir($folder)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($folder, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($folder);
    }
}
