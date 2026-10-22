<?php

declare(strict_types=1);

namespace Kaleta\Extension;

use Kaleta\Core\App;
use Kaleta\Core\Events;
use Kaleta\Core\Settings;

/**
 * Add-ons (3.0): code from other developers in extensions/<slug>/, switched on by an administrator in Add-ons.
 *
 * Trust: an add-on is PHP that runs with the same rights as Kaleta, like a WordPress plug-in – Add-ons says so before it
 * is switched on. Kaleta never uploads code from the administration and never fetches it from the internet: an add-on is
 * copied into extensions/ by whoever manages the hosting. What Kaleta does guarantee:
 *  - an add-on that throws while loading or registering is switched off at once and the error is shown in Add-ons
 *    (so a broken add-on never takes the site down twice);
 *  - an add-on needs the API version it was written for (extension.json → requires.api) and a Kaleta version that
 *    satisfies requires.kaleta; otherwise it is not loaded;
 *  - its tools for Claude go through the connection's access and the guardrails like Kaleta's own tools;
 *  - nothing is loaded in the public demo or when config.php says 'addons' => false (a safe mode for the hosting admin).
 */
final class Registry
{
    public const string DIR = KALETA_ROOT . '/extensions';

    private static ?self $instance = null;

    /** @var array<string, list<callable>> */
    private array $listeners = [];

    /** @var array<string, list<callable>> */
    private array $filters = [];

    /** @var array<string, callable> "slug.name" => render */
    private array $tokens = [];

    /** @var array<string, array{slug: string, name: string, title: string, render: callable}> "slug.name" => page */
    private array $pages = [];

    /** @var array<string, array{name: string, description: string, inputSchema: array<string, mixed>, access: string, handler: callable, requires: string, title: string, openWorld: bool, idempotent: bool}> */
    private array $tools = [];

    /** @var array<string, array{0: int, 1: string, 2: string, 3: callable}> */
    private array $jobs = [];

    private bool $dispatching = false;

    /** The add-ons of this request (empty until boot()). */
    public static function get(): self
    {
        return self::$instance ??= new self();
    }

    /** Loads the switched-on add-ons once per request. */
    public static function boot(App $app): self
    {
        $registry = self::get();
        static $booted = false;
        if ($booted) {
            return $registry;
        }
        $booted = true;
        if (\Kaleta\Core\Demo::active() || ($app->config['addons'] ?? true) === false) {
            return $registry;
        }
        $manifests = self::discover();
        $enabled = self::enabled($app->settings());
        // 3.14: from here on code outside system/ may run queries – Db logs the old table names it writes (Core\LegacyNames)
        \Kaleta\Core\LegacyNames::$watching = $enabled !== [];
        foreach ($enabled as $slug) {
            $manifest = $manifests[$slug] ?? null;
            if ($manifest === null || $manifest['problem'] !== '') {
                continue;
            }
            try {
                require_once $manifest['path'] . '/' . $manifest['entry'];
                $class = $manifest['class'];
                if (!class_exists($class) || !is_subclass_of($class, ExtensionInterface::class)) {
                    throw new \RuntimeException('The class ' . $class . ' does not implement Kaleta\Extension\ExtensionInterface.');
                }
                (new $class())->register(new Api($registry, $slug, $app));
            } catch (\Throwable $e) {
                self::fail($app, $slug, $e);
            }
        }

        return $registry;
    }

    /**
     * Add-ons found in extensions/: slug => manifest with 'problem' ('' = can be switched on).
     *
     * @return array<string, array{slug: string, name: string, version: string, description: string, author: string, url: string, class: string, entry: string, path: string, requires_kaleta: string, requires_api: int, problem: string}>
     */
    public static function discover(): array
    {
        $found = [];
        foreach (glob(self::DIR . '/*/extension.json') ?: [] as $file) {
            $path = dirname($file);
            $slug = basename($path);
            $json = json_decode((string) file_get_contents($file), true);
            $json = is_array($json) ? $json : [];
            $text = fn (string $key, int $max): string => is_scalar($json[$key] ?? null) ? mb_substr(trim((string) $json[$key]), 0, $max) : '';
            $manifest = ['slug' => $slug, 'name' => $text('name', 80) ?: $slug, 'version' => $text('version', 20), 'description' => $text('description', 300),
                'author' => $text('author', 100), 'url' => $text('url', 200), 'class' => $text('class', 150), 'entry' => $text('entry', 100) ?: 'Extension.php', 'path' => $path,
                'requires_kaleta' => is_scalar($json['requires']['kaleta'] ?? null) ? (string) $json['requires']['kaleta'] : '', 'requires_api' => (int) ($json['requires']['api'] ?? 0), 'problem' => ''];
            $manifest['problem'] = match (true) {
                preg_match('/^[a-z][a-z0-9_]{1,30}$/D', $slug) !== 1 => 'The folder name must be lowercase letters, digits and _ (it is the add-on\'s slug).',
                $json === [] => 'extension.json is not valid JSON.',
                $manifest['class'] === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_\\\\]*$/D', $manifest['class']) !== 1 => 'extension.json has no valid "class".',
                preg_match('/^[A-Za-z0-9_\/.-]+\.php$/D', $manifest['entry']) !== 1 || str_contains($manifest['entry'], '..') || !is_file($path . '/' . $manifest['entry']) => 'The entry file is missing or not a .php file inside the add-on.',
                $manifest['requires_api'] !== Api::VERSION => 'It is written for extension API ' . $manifest['requires_api'] . ', this Kaleta has API ' . Api::VERSION . '.',
                !self::satisfies(KALETA_VERSION, $manifest['requires_kaleta']) => 'It needs Kaleta ' . $manifest['requires_kaleta'] . '.',
                default => '',
            };
            $found[$slug] = $manifest;
        }
        ksort($found);

        return $found;
    }

    /** "3.0", ">=3.0", ">=3.0 <4.0" – an empty requirement is satisfied. */
    public static function satisfies(string $version, string $requirement): bool
    {
        foreach (preg_split('/\s+/', trim($requirement)) ?: [] as $part) {
            if ($part === '') {
                continue;
            }
            if (preg_match('/^(>=|<=|>|<|=|\^)?(\d+(?:\.\d+){0,2})$/D', $part, $m) !== 1) {
                return false;
            }
            $operator = $m[1] !== '' ? $m[1] : '>=';
            $ok = $operator === '^' ? version_compare($version, $m[2], '>=') && (int) $version === (int) $m[2] : version_compare($version, $m[2], $operator === '=' ? '==' : $operator);
            if (!$ok) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    public static function enabled(Settings $settings): array
    {
        return array_values(array_filter(explode(',', $settings->get('addons_enabled')), fn (string $s): bool => $s !== ''));
    }

    /** Switches an add-on on: its install.sql (CREATE TABLE IF NOT EXISTS {ext_<slug>_…}) runs, then it loads from the next request. */
    public static function enable(App $app, string $slug): void
    {
        $manifest = self::discover()[$slug] ?? throw new \InvalidArgumentException('The add-on is not in extensions/.');
        if ($manifest['problem'] !== '') {
            throw new \DomainException($manifest['problem']);
        }
        $sql = $manifest['path'] . '/install.sql';
        if (is_file($sql)) {
            // the same rewrite as the core migrations: on a database 4.0 renamed, a REFERENCES ka_stranky names ka_pages (N315-12)
            foreach (\Kaleta\Core\Migration::statements((string) file_get_contents($sql), $app->db()->prefix, $app->db()->englishTables()) as $statement) {
                if (preg_match('/^\s*CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+`?' . preg_quote($app->db()->prefix, '/') . 'ext_' . preg_quote($slug, '/') . '_[a-z0-9_]+`?\s*\(/i', $statement) !== 1) {
                    throw new \DomainException('install.sql may only CREATE TABLE IF NOT EXISTS {ext_' . $slug . '_…} tables.');
                }
                $app->db()->pdo()->exec($statement);
            }
        }
        $app->settings()->set('addons_enabled', implode(',', array_unique([...self::enabled($app->settings()), $slug])));
        $app->settings()->set('addons_error.' . $slug, '');
        Events::record($app->db(), 'addon.enabled', 'info', t('The add-on %s was switched on.', $manifest['name']), ['addon' => $slug, 'version' => $manifest['version']]);
    }

    public static function disable(App $app, string $slug): void
    {
        $app->settings()->set('addons_enabled', implode(',', array_diff(self::enabled($app->settings()), [$slug])));
    }

    /** A broken add-on is switched off at once and the error kept for the Add-ons screen. */
    private static function fail(App $app, string $slug, \Throwable $e): void
    {
        $message = mb_substr(get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')', 0, 500);
        try {
            self::disable($app, $slug);
            $app->settings()->set('addons_error.' . $slug, $message);
            Events::record($app->db(), 'addon.failed', 'error', t('The add-on %s failed and was switched off: %s', $slug, $message), ['addon' => $slug]);
        } catch (\Throwable) {
            // the site keeps running even when the error cannot be written down
        }
    }

    /* ---------- what add-ons registered ---------- */

    public function addListener(string $type, callable $listener): void
    {
        $this->listeners[$type][] = $listener;
    }

    public function addFilter(string $name, callable $filter): void
    {
        $this->filters[$name][] = $filter;
    }

    public function addToken(string $slug, string $name, callable $render): void
    {
        $this->tokens[$slug . '.' . $name] = $render;
    }

    public function addAdminPage(string $slug, string $name, string $title, callable $render): void
    {
        $this->pages[$slug . '.' . $name] = ['slug' => $slug, 'name' => $name, 'title' => mb_substr($title, 0, 80), 'render' => $render];
    }

    /**
     * An add-on's tool. The MCP title (3.8) comes from Api::mcpTool (the name in words unless the add-on gives one, the full
     * name here as the last resort); openWorld and idempotent are the MCP hints the add-on declares (both off unless it says so).
     *
     * @param array<string, mixed> $schema
     */
    public function addTool(string $name, string $description, array $schema, string $access, callable $handler, string $requires = 'admin', string $title = '', bool $openWorld = false, bool $idempotent = false): void
    {
        $title = trim(preg_replace('/\s+/u', ' ', strip_tags($title)) ?? '');
        $this->tools[$name] = ['name' => $name, 'description' => mb_substr($description, 0, 2000), 'inputSchema' => $schema, 'access' => $access, 'handler' => $handler, 'requires' => $requires,
            'title' => mb_substr($title !== '' ? $title : $name, 0, 60),
            'openWorld' => $openWorld, 'idempotent' => $idempotent];
    }

    public function addJob(string $name, int $interval, string $label, callable $run): void
    {
        $this->jobs[$name] = [$interval, 'any', mb_substr($label, 0, 100), $run];
    }

    /** An event recorded by Kaleta goes to the listeners of its type; a failing listener never breaks the caller. */
    public static function dispatch(string $type, array $data): void
    {
        $self = self::$instance;
        if ($self === null || $self->listeners === [] || $self->dispatching) {
            return;
        }
        $self->dispatching = true;
        try {
            foreach ($self->listeners[$type] ?? [] as $listener) {
                try {
                    $listener($type, $data);
                } catch (\Throwable) {
                    // an add-on's mistake stays in the add-on
                }
            }
        } finally {
            $self->dispatching = false;
        }
    }

    /** A filtered value: each filter in turn; a failing filter is skipped. */
    public static function applyFilter(string $name, string $value): string
    {
        foreach (self::$instance->filters[$name] ?? [] as $filter) {
            try {
                $result = $filter($value);
                $value = is_string($result) ? $result : $value;
            } catch (\Throwable) {
                // keep the value as it was
            }
        }

        return $value;
    }

    /**
     * {{ext.<slug>.<name> key="value"}} tokens in what editors wrote (builds, page and news text). Front\Kernel calls it before
     * a template adds anything a visitor sent, never over a whole page (3.3.2, N38). Only real quotes delimit a value: the
     * escaped form (&quot;) is how a visitor's text such as the search query looks on the page, so it never runs a token.
     */
    public static function fillTokens(string $html): string
    {
        $tokens = self::$instance->tokens ?? [];
        if ($tokens === [] || !str_contains($html, '{{ext.')) {
            return $html;
        }

        return (string) preg_replace_callback('/\{\{ext\.([a-z0-9_]+\.[a-z0-9_]+)((?:\s+[a-z_]+="[^"]*")*)\s*\}\}/', function (array $m) use ($tokens): string {
            if (!isset($tokens[$m[1]])) {
                return $m[0];
            }
            preg_match_all('/([a-z_]+)="([^"]*)"/', $m[2], $a, PREG_SET_ORDER);
            $attributes = [];
            foreach ($a as $pair) {
                $attributes[$pair[1]] = html_entity_decode($pair[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
            try {
                $out = ($tokens[$m[1]])($attributes);

                return is_string($out) ? $out : '';
            } catch (\Throwable) {
                return '';
            }
        }, $html);
    }

    /** @return array<string, array{slug: string, name: string, title: string, render: callable}> */
    public function adminPages(): array
    {
        return $this->pages;
    }

    /** MCP definitions of the add-ons' tools (tools/list). @return list<array{name: string, description: string, inputSchema: array<string, mixed>}> */
    public function toolDefinitions(): array
    {
        return array_values(array_map(fn (array $t): array => ['name' => $t['name'], 'description' => $t['description'], 'inputSchema' => $t['inputSchema']], $this->tools));
    }

    /** @return array{name: string, description: string, inputSchema: array<string, mixed>, access: string, handler: callable, requires: string, title: string, openWorld: bool, idempotent: bool}|null */
    public function tool(string $name): ?array
    {
        return $this->tools[$name] ?? null;
    }

    /** Jobs in the shape of Core\Scheduler::jobs(): name => [interval, 'any', label, fn (App, string): string]. @return array<string, array{0: int, 1: string, 2: string, 3: callable}> */
    public function jobs(): array
    {
        return array_map(fn (array $j): array => [$j[0], $j[1], $j[2], function () use ($j): string {
            $result = ($j[3])();

            return is_string($result) ? mb_substr($result, 0, 200) : 'ok';
        }], $this->jobs);
    }
}
