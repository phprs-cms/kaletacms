<?php

declare(strict_types=1);

namespace Kaleta\Fleet;

use Kaleta\Admin\ChangeLog;
use Kaleta\Builder\Build;
use Kaleta\Builder\Components;
use Kaleta\Builder\DesignSystem;
use Kaleta\Builder\Style;
use Kaleta\Core\App;
use Kaleta\Core\Db;
use Kaleta\Core\Events;
use Kaleta\Core\Look;
use Kaleta\Core\Response;
use Kaleta\Core\Settings;

/**
 * The shared design kit of a fleet (2.16): the console snapshots its design system, chosen shared classes, components and
 * saved sections into a numbered kit version (ka_fleet_kits); the member sites that opted in (fleet_kit) pick it up.
 *
 * The security model of 2.9 holds: a site always calls the console, never the other way round. The heartbeat reply only
 * announces {version, sha256} of the newest kit; the site then asks POST /fleet/kit (signed by its own key, it must be
 * paired) and gets the manifest signed by the console's key. The site verifies the signature and that the manifest hashes
 * to the announced sha256 before it reads a byte of it. A kit is applied ONLY as drafts – the draft look (design system
 * and classes, published with "Publish look"), component drafts (stavba_koncept, never stavba) and the section library
 * (inserting a saved section is a person's act). Nothing on a member site is published by this class. Everything received
 * goes through the same sanitizers as a local save (Build::sanitize without admin rights, so a custom HTML element never
 * travels in a kit; Style for classes; DesignSystem::sanitize for the tokens), on the console when publishing and again on
 * the site when applying.
 */
final class Kit
{
    /** A manifest larger than this is refused on both sides (Fleet\Http reads at most 2 MB of an answer). */
    public const int MAX_BYTES = 1_900_000;

    /** A stable key of a component or a section: the slug of its name on the console; a site updates its copy by it. */
    public const string KEY_PATTERN = '/^[a-z0-9][a-z0-9-]{0,78}$/D';

    /* ---------- the manifest (both sides) ---------- */

    /**
     * A manifest from the console's rows: the published design system, published classes (name => [styl, css]), rows of
     * ka_komponenty (the published build, or the draft of a component that was never published) and rows of ka_sekce.
     * Sanitized like everything that comes from outside, so the kit is clean before it is signed.
     *
     * @param array<string, mixed>|null $designSystem
     * @param array<string, array{styl: array<string, mixed>, css: string}> $classes
     * @param list<array<string, mixed>> $components
     * @param list<array<string, mixed>> $sections
     * @return array<string, mixed>
     */
    public static function compose(?array $designSystem, array $classes, array $components, array $sections): array
    {
        $manifest = ['classes' => array_map(fn (array $c): array => ['styl' => $c['styl'], 'css' => $c['css']], $classes)];
        if ($designSystem !== null) {
            $manifest['design_system'] = $designSystem;
        }
        $manifest['components'] = array_map(fn (array $r): array => ['key' => slugify((string) $r['nazev'], 80), 'name' => (string) $r['nazev'],
            'stavba' => Build::fromJson($r['stavba'] ?? $r['stavba_koncept'] ?? null) ?? [], 'properties' => is_array($r['vlastnosti']) ? $r['vlastnosti'] : (json_decode((string) $r['vlastnosti'], true) ?: [])], $components);
        $manifest['sections'] = array_map(fn (array $r): array => ['key' => slugify((string) $r['nazev'], 80), 'name' => (string) $r['nazev'],
            'prvek' => is_array($r['prvek']) ? $r['prvek'] : (json_decode((string) $r['prvek'], true) ?: [])], $sections);

        return self::sanitize($manifest);
    }

    /**
     * Only the known parts of a manifest, each cleaned the way a local save is: unknown keys are dropped, a class needs a
     * valid name, a component or section needs a name and a build that survives the validator without admin rights (so
     * custom HTML – the only way to carry script – is left out), a repeated key keeps the first item. Custom fonts are
     * files of the console, they do not travel: the design system comes without them.
     *
     * @return array{design_system?: array<string, mixed>, classes: array<string, array{styl: array<string, mixed>, css: string}>, components: list<array{key: string, name: string, stavba: array<string, mixed>, properties: list<array<string, mixed>>}>, sections: list<array{key: string, name: string, prvek: array<string, mixed>}>}
     */
    public static function sanitize(mixed $manifest): array
    {
        $manifest = is_array($manifest) ? $manifest : [];
        $clean = ['classes' => [], 'components' => [], 'sections' => []];
        if (is_array($manifest['design_system'] ?? null)) {
            $clean['design_system'] = DesignSystem::sanitize(['vlastni_pisma' => []] + $manifest['design_system']);
        }
        foreach (is_array($manifest['classes'] ?? null) ? array_slice($manifest['classes'], 0, 200, true) : [] as $name => $class) {
            if (!is_string($name) || !preg_match(Build::CLASS_PATTERN, $name) || !is_array($class)) {
                continue;
            }
            $errors = [];
            $discarded = [];
            $style = Style::sanitize($class['styl'] ?? [], $name, $errors);
            $css = Style::customCss(mb_substr((string) ($class['css'] ?? ''), 0, 4000), $discarded);
            if ($style !== [] || $css !== '') {
                $clean['classes'][$name] = ['styl' => $style, 'css' => $css];
            }
        }
        $name = fn (mixed $v): string => mb_substr(trim(strip_tags((string) (is_scalar($v) ? $v : ''))), 0, 100);
        $used = [];
        foreach (is_array($manifest['components'] ?? null) ? array_slice(array_values($manifest['components']), 0, 100) : [] as $c) {
            $key = is_array($c) ? self::key($c['key'] ?? null, $name($c['name'] ?? '')) : null;
            if ($key === null || isset($used['c:' . $key])) {
                continue;
            }
            [$build] = Build::sanitize($c['stavba'] ?? null, false);
            if ($build['deti'] === []) {
                continue;
            }
            $used['c:' . $key] = true;
            $clean['components'][] = ['key' => $key, 'name' => $name($c['name']), 'stavba' => $build, 'properties' => Components::sanitizeProperties($c['properties'] ?? [])];
        }
        foreach (is_array($manifest['sections'] ?? null) ? array_slice(array_values($manifest['sections']), 0, 100) : [] as $sec) {
            $key = is_array($sec) ? self::key($sec['key'] ?? null, $name($sec['name'] ?? '')) : null;
            if ($key === null || isset($used['s:' . $key])) {
                continue;
            }
            [$build] = Build::sanitize(['deti' => [$sec['prvek'] ?? null]], false);
            if (($build['deti'][0] ?? null) === null) {
                continue;
            }
            $used['s:' . $key] = true;
            $clean['sections'][] = ['key' => $key, 'name' => $name($sec['name']), 'prvek' => $build['deti'][0]];
        }

        return $clean;
    }

    /** The given key when it is one, otherwise the slug of the name; null for an item without a usable name. */
    private static function key(mixed $key, string $name): ?string
    {
        if ($name === '') {
            return null;
        }
        if (is_string($key) && $key !== 'n-a' && preg_match(self::KEY_PATTERN, $key)) {
            return $key;
        }
        $slug = slugify($name, 80);

        return $slug !== 'n-a' ? $slug : null; // slugify's answer for a name without a usable letter
    }

    /** What a sanitized manifest carries, in words (English; the console's log and the site's event). */
    public static function summary(array $manifest): string
    {
        $parts = [];
        if (isset($manifest['design_system'])) {
            $parts[] = 'design system';
        }
        foreach (['classes' => 'class', 'components' => 'component', 'sections' => 'section'] as $key => $word) {
            $n = count($manifest[$key] ?? []);
            if ($n > 0) {
                $parts[] = $n . ' ' . $word . ($n === 1 ? '' : 's');
            }
        }

        return $parts === [] ? 'nothing' : implode(', ', $parts);
    }

    /** The manifest as the bytes that are hashed, stored and sent – always the same encoding on both sides. */
    public static function encode(array $manifest): string
    {
        return (string) json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Does a heartbeat reply announce a kit newer than the one applied? Pure. Returns its version and hash, or null when
     * there is nothing (new) to fetch or the announcement is malformed.
     *
     * @return array{version: int, sha256: string}|null
     */
    public static function announced(mixed $kit, int $applied): ?array
    {
        if (!is_array($kit) || !is_int($kit['version'] ?? null) || $kit['version'] <= $applied || $kit['version'] > 1_000_000
            || !is_string($kit['sha256'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $kit['sha256']) !== 1) {
            return null;
        }

        return ['version' => $kit['version'], 'sha256' => $kit['sha256']];
    }

    /**
     * Checks the console's answer to /fleet/kit: HTTP 200, signed by the console's key, the announced version, and a
     * manifest whose bytes hash to the announced sha256 (the one the signed heartbeat promised). Pure; throws with the
     * reason otherwise. Returns the decoded manifest – still to be sanitized.
     *
     * @param array{status: int, body: string, json: ?array<string, mixed>, signature: string, error: string} $answer
     * @return array<string, mixed>
     */
    public static function verifyAnswer(array $answer, string $consoleKey, int $version, string $sha256): array
    {
        if ($answer['status'] !== 200 || $answer['json'] === null) {
            throw new \RuntimeException('The console did not hand over the kit (' . ($answer['error'] !== '' ? $answer['error'] : 'HTTP ' . $answer['status']) . ').');
        }
        if (!Keys::verify($answer['body'], $answer['signature'], $consoleKey)) {
            throw new \RuntimeException('The kit is not signed by the console – refused.');
        }
        $j = $answer['json'];
        if (($j['version'] ?? null) !== $version || !is_string($j['manifest'] ?? null)) {
            throw new \RuntimeException('The console handed over a different kit version than it announced – refused.');
        }
        if (strlen($j['manifest']) > self::MAX_BYTES) {
            throw new \RuntimeException('The kit is too large – refused.');
        }
        if (!hash_equals($sha256, hash('sha256', $j['manifest'])) || !hash_equals($sha256, (string) ($j['sha256'] ?? ''))) {
            throw new \RuntimeException('The kit does not match the hash the console announced – refused.');
        }
        $manifest = json_decode($j['manifest'], true);
        if (!is_array($manifest)) {
            throw new \RuntimeException('The kit is not readable – refused.');
        }

        return $manifest;
    }

    /* ---------- the console ---------- */

    /**
     * Publishes a new kit version from what the administrator chose: the design system (yes/no), classes (names; null =
     * all), components and sections (ids); since 3.17 optionally for one customer's sites only (customer = its id, null =
     * every site). Returns the version, its summary and size.
     *
     * @param array{design_system: bool, classes: ?list<string>, components: list<int>, sections: list<int>, customer?: ?int} $choice
     * @return array{version: int, summary: string, bytes: int}
     */
    public static function publish(App $app, array $choice): array
    {
        $db = $app->db();
        $s = $app->settings();
        $classes = Look::classes($db, $s, false);
        if ($choice['classes'] !== null) {
            $classes = array_intersect_key($classes, array_flip(array_filter($choice['classes'], 'is_string')));
        }
        $in = fn (array $ids): string => $ids === [] ? '0' : implode(',', array_map('intval', $ids));
        $manifest = self::compose($choice['design_system'] ? DesignSystem::load($s) : null, $classes,
            $db->all('SELECT nazev, vlastnosti, stavba, stavba_koncept FROM {komponenty} WHERE idm IN (' . $in($choice['components']) . ') ORDER BY nazev'),
            $db->all('SELECT nazev, prvek FROM {sekce} WHERE idx IN (' . $in($choice['sections']) . ') ORDER BY nazev'));
        if (!isset($manifest['design_system']) && $manifest['classes'] === [] && $manifest['components'] === [] && $manifest['sections'] === []) {
            throw new \RuntimeException(t('Choose at least one thing for the kit: the design system, a class, a component or a section.'));
        }
        $json = self::encode($manifest);
        if (strlen($json) > self::MAX_BYTES) {
            throw new \RuntimeException(t('The kit is too large (%s) – choose fewer components or sections.', \Kaleta\Core\Files::size(strlen($json))));
        }
        $version = (int) $db->value('SELECT COALESCE(MAX(version), 0) + 1 FROM {fleet_kits}');
        $summary = self::summary($manifest);
        $db->insert('fleet_kits', ['version' => $version, 'created_at' => date('Y-m-d H:i:s'), 'created_by' => $app->auth()->id() ?: null, 'manifest' => $json,
            'sha256' => hash('sha256', $json), 'summary' => mb_substr($summary, 0, 255), 'customer_id' => ($choice['customer'] ?? null) ?: null]);
        Events::record($db, 'fleet.kit_published', 'info', t('Kit version %d was published for the fleet: %s.', $version, $summary), ['version' => $version]);

        return ['version' => $version, 'summary' => $summary, 'bytes' => strlen($json)];
    }

    /** @return array{id: int, version: int, created_at: string, sha256: string, summary: string, manifest?: string}|null the newest kit */
    public static function latest(Db $db, bool $withManifest = false): ?array
    {
        $row = $db->one('SELECT id, version, created_at, sha256, summary' . ($withManifest ? ', manifest' : '') . ' FROM {fleet_kits} ORDER BY version DESC LIMIT 1');

        return $row === null ? null : ['id' => (int) $row['id'], 'version' => (int) $row['version'], 'created_at' => (string) $row['created_at'], 'sha256' => (string) $row['sha256'],
            'summary' => (string) $row['summary']] + ($withManifest ? ['manifest' => (string) $row['manifest']] : []);
    }

    /**
     * 3.17: the newest kit one site may have – one meant for every site, or for the site's customer (Fleet\Customers).
     *
     * @return array{id: int, version: int, created_at: string, sha256: string, summary: string, manifest?: string}|null
     */
    public static function latestFor(Db $db, ?int $customerId, bool $withManifest = false): ?array
    {
        $row = $db->one('SELECT id, version, created_at, sha256, summary' . ($withManifest ? ', manifest' : '') . ' FROM {fleet_kits} WHERE customer_id IS NULL'
            . ($customerId !== null ? ' OR customer_id = ' . $customerId : '') . ' ORDER BY version DESC LIMIT 1');

        return $row === null ? null : ['id' => (int) $row['id'], 'version' => (int) $row['version'], 'created_at' => (string) $row['created_at'], 'sha256' => (string) $row['sha256'],
            'summary' => (string) $row['summary']] + ($withManifest ? ['manifest' => (string) $row['manifest']] : []);
    }

    /** What the heartbeat reply of one site announces: the newest kit's version and hash it may have (latestFor, 3.17), or nothing. @return array{version: int, sha256: string}|null */
    public static function announcementFor(Db $db, ?int $customerId): ?array
    {
        $kit = self::latestFor($db, $customerId);

        return $kit === null ? null : ['version' => $kit['version'], 'sha256' => $kit['sha256']];
    }

    /** @return list<array{version: int, created_at: string, summary: string, author: ?string, customer_id: ?int}> newest first */
    public static function history(Db $db, int $limit = 20): array
    {
        return array_map(fn (array $r): array => ['version' => (int) $r['version'], 'created_at' => (string) $r['created_at'], 'summary' => (string) $r['summary'], 'author' => $r['autor'],
            'customer_id' => $r['customer_id'] !== null ? (int) $r['customer_id'] : null],
            $db->all('SELECT k.version, k.created_at, k.summary, k.customer_id, u.jmeno AS autor FROM {fleet_kits} k LEFT JOIN {uzivatele} u ON u.idu = k.created_by ORDER BY k.version DESC LIMIT ' . $limit));
    }

    /** Which kit version each site applied, from its last heartbeat: site id => version (0 = none or not reported). @return array<int, int> */
    public static function appliedVersions(Db $db): array
    {
        $out = [];
        foreach (Console::sites($db) as $site) {
            $beat = json_decode((string) ($site['heartbeat'] ?? ''), true);
            $out[(int) $site['id']] = is_array($beat) ? (int) ($beat['kit_version'] ?? 0) : 0;
        }

        return $out;
    }

    /** POST /fleet/kit – a paired site asks for the newest kit; the answer is signed by the console's key. */
    public static function answer(App $app, string $body, string $signature): Response
    {
        $d = json_decode($body, true);
        $db = $app->db();
        $site = is_array($d) ? $db->one('SELECT id, public_key, customer_id FROM {fleet_sites} WHERE id = ?', [(int) ($d['site_id'] ?? 0)]) : null;
        if ($site === null) {
            return Response::json(['error' => 'This console does not know the site.'], 404);
        }
        if (($d['action'] ?? '') !== 'kit' || !Keys::verify($body, $signature, (string) $site['public_key']) || abs(time() - (int) ($d['ts'] ?? 0)) > Link::MAX_SKEW) {
            return Response::json(['error' => 'The signature or the time of the request is not valid.'], 403);
        }
        $kit = self::latestFor($db, $site['customer_id'] !== null ? (int) $site['customer_id'] : null, true); // 3.17: never another customer's kit
        if ($kit === null) {
            return Response::json(['error' => 'No kit has been published.'], 404);
        }
        if (strlen($kit['manifest']) > self::MAX_BYTES) {
            return Response::json(['error' => 'The kit is too large.'], 413);
        }

        // the manifest travels as the exact string that was hashed, so the site checks the bytes it received
        return Console::signed($app->settings(), ['ok' => true, 'version' => $kit['version'], 'sha256' => $kit['sha256'], 'created_at' => $kit['created_at'], 'manifest' => $kit['manifest']]);
    }

    /* ---------- the member site ---------- */

    /**
     * After a confirmed heartbeat: when the site receives kits and the console announced a newer one, fetch, verify and
     * apply it as drafts. Never throws – a refused kit is an event and a note in Settings → Fleet console, the heartbeat
     * itself succeeded. Returns a suffix for the job's result.
     */
    public static function afterHeartbeat(App $app, mixed $announced): string
    {
        $s = $app->settings();
        $kit = self::announced($announced, $s->int('fleet_kit_version'));
        if ($kit === null || !$s->bool('fleet_kit')) {
            return '';
        }
        try {
            $counts = self::receive($app, $kit['version'], $kit['sha256']);
        } catch (\RuntimeException $e) {
            $s->set('fleet_kit_error', mb_substr($e->getMessage(), 0, 255));
            Events::record($app->db(), 'fleet.kit_refused', 'warning', t('Kit version %d from the console was refused: %s', $kit['version'], $e->getMessage()), ['version' => $kit['version']]);

            return ', kit ' . $kit['version'] . ' refused';
        }

        return ', kit ' . $kit['version'] . ' applied as drafts (' . $counts . ')';
    }

    /** Fetches the kit from the console, verifies it and applies it. Returns the summary of what arrived. */
    public static function receive(App $app, int $version, string $sha256): string
    {
        $s = $app->settings();
        if (!Link::isPaired($s)) {
            throw new \RuntimeException('The site is not paired with a console.');
        }
        $answer = Http::post($s->get('fleet_console_url') . '/fleet/kit', ['action' => 'kit', 'site_id' => $s->int('fleet_site_id'), 'ts' => time()],
            fn (string $body): string => Keys::sign($s, $body), 30);
        $manifest = self::verifyAnswer($answer, $s->get('fleet_console_key'), $version, $sha256);

        return self::apply($app, $manifest, $version);
    }

    /**
     * Applies a verified manifest – as drafts only: the design system and the classes into the draft look, components
     * into their drafts (a component with the same key is updated, a new one has no published build), sections into the
     * library. Sanitized again here, exactly like a local save. Returns the summary.
     */
    public static function apply(App $app, array $manifest, int $version): string
    {
        $db = $app->db();
        $s = $app->settings();
        $clean = self::sanitize($manifest);
        $now = date('Y-m-d H:i:s');
        if (isset($clean['design_system'])) {
            Look::setDesignSystem($s, $clean['design_system']);
        }
        foreach ($clean['classes'] as $name => $class) {
            Look::setClass($s, $name, $class, true); // always the draft: the kit is reviewed as a whole before it is published
        }
        foreach ($clean['components'] as $c) {
            $row = $db->one('SELECT idm, stavba FROM {komponenty} WHERE kit_key = ?', [$c['key']]);
            $properties = (string) json_encode($c['properties'], JSON_UNESCAPED_UNICODE);
            if ($row === null) {
                $db->insert('komponenty', ['nazev' => $c['name'], 'vlastnosti' => $properties, 'stavba' => null, 'stavba_koncept' => Build::toJson($c['stavba']), 'kit_key' => $c['key'], 'zmeneno' => $now]);
            } else {
                // the properties of a component that is already published change what its uses show – they stay until a person decides
                $db->update('komponenty', ['nazev' => $c['name'], 'stavba_koncept' => Build::toJson($c['stavba']), 'zmeneno' => $now] + ($row['stavba'] === null ? ['vlastnosti' => $properties] : []), ['idm' => (int) $row['idm']]);
            }
        }
        foreach ($clean['sections'] as $sec) {
            $json = (string) json_encode($sec['prvek'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $id = $db->value('SELECT idx FROM {sekce} WHERE kit_key = ?', [$sec['key']]);
            if ($id === null) {
                $db->insert('sekce', ['nazev' => $sec['name'], 'prvek' => $json, 'kit_key' => $sec['key'], 'zmeneno' => $now]);
            } else {
                $db->update('sekce', ['nazev' => $sec['name'], 'prvek' => $json, 'zmeneno' => $now], ['idx' => (int) $id]);
            }
        }
        $summary = self::summary($clean);
        $s->set('fleet_kit_version', (string) $version);
        $s->set('fleet_kit_applied_at', (string) time());
        $s->set('fleet_kit_error', '');
        Events::record($db, 'fleet.kit_received', 'info', t('Kit version %d from the console arrived as drafts: %s.', $version, $summary),
            ['version' => $version, 'classes' => count($clean['classes']), 'components' => count($clean['components']), 'sections' => count($clean['sections']), 'design_system' => isset($clean['design_system'])]);
        ChangeLog::write($app, 'settings', 'fleet_kit_received', 'version ' . $version . ': ' . $summary);

        return $summary;
    }

    /** Are drafts from a kit waiting for a person's review (the look draft or a component draft that came with a kit)? */
    public static function waiting(Db $db, Settings $s): bool
    {
        return $s->int('fleet_kit_version') > 0
            && (Look::hasDraft($s) || $db->value('SELECT 1 FROM {komponenty} WHERE kit_key IS NOT NULL AND stavba_koncept IS NOT NULL LIMIT 1') !== null);
    }
}
