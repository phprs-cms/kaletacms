<?php

declare(strict_types=1);

namespace Kaleta\Mcp;

use Kaleta\Admin\ChangeLog;
use Kaleta\Core\App;
use Kaleta\Core\Response;
use Kaleta\Core\Extensions;

/**
 * MCP server (Model Context Protocol, "Streamable HTTP" transport) at /mcp.
 * Through it Claude can work with the site: read and write pages and news, manage categories, collections, site parts
 * and appearance.
 *
 * Sign-in: the header "Authorization: Bearer <token>" – a personal token from the "Můj účet" (My account) menu, or the
 * token of an application connected via OAuth (connector in Claude, Front\OAuth).
 * Claude then acts with this user's permissions (author / editor / administrator), limited by the access of the connection
 * (full, drafts or read, 2.2). New installations have the extension switched on.
 */
final class Server
{
    /** Protocol versions the server speaks, newest first (2.2: the client's version is answered when it is one of them). */
    public const array PROTOCOLS = ['2025-06-18', '2025-03-26', '2024-11-05'];

    public function __construct(private readonly App $app)
    {
    }

    public function handle(): Response
    {
        $r = $this->app->request;
        \Kaleta\Extension\Registry::boot($this->app); // add-ons may add tools (3.0)
        if (!Extensions::isEnabled($this->app->settings(), 'claude')) {
            return Response::json(['error' => 'The Claude connection is switched off (Extensions menu).'], 404);
        }
        $isLocal = in_array((string) parse_url($r->origin(), PHP_URL_HOST), ['localhost', '127.0.0.1'], true);
        if (!$r->isHttps() && !$isLocal) {
            return Response::json(['error' => 'MCP is available over HTTPS only.'], 403);
        }
        if (!$r->isPost()) {
            return new Response('', 405, ['Allow' => 'POST']);
        }
        $user = $this->user();
        if ($user === null) {
            // link to the OAuth metadata: using them the Claude connector registers itself and asks the user for consent
            return new Response(json_encode(['error' => 'The token is invalid or missing.']), 401, ['Content-Type' => 'application/json',
                'WWW-Authenticate' => 'Bearer resource_metadata="' . (new \Kaleta\Front\OAuth($this->app))->metadataUrl() . '"']);
        }
        $this->app->auth()->signInAs($user);
        $this->app->auth()->useConnection((string) $user['connection_name'], (string) $user['connection_access'], (string) $user['connection_tool_set']);
        if ($this->app->auth()->isMissingRequired2fa($this->app->settings())) {
            return Response::json(['error' => 'The site requires two-factor sign-in. Turn it on in the admin under My account – the connection does not work until then.'], 403);
        }

        $message = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($message)) {
            return Response::json(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Invalid JSON.']], 400);
        }
        // a batch of messages as well as a single message
        $batch = array_is_list($message) ? $message : [$message];
        if (count($batch) > 50) {
            return Response::json(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32600, 'message' => 'A batch has at most 50 messages.']], 400);
        }
        $responses = array_values(array_filter(array_map($this->process(...), $batch)));
        if ($responses === []) {
            return new Response('', 202);
        }

        return Response::json(array_is_list($message) ? $responses : $responses[0]);
    }

    /** @param array<string, mixed> $z @return array<string, mixed>|null null = notification without a response */
    private function process(array $z): ?array
    {
        $id = $z['id'] ?? null;
        $method = (string) ($z['method'] ?? '');
        if ($id === null) {
            return null;
        }
        $ok = fn (array $result): array => ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
        $tools = new Tools($this->app);

        return match ($method) {
            'initialize' => $ok([
                'protocolVersion' => self::protocol($z['params']['protocolVersion'] ?? null),
                'capabilities' => ['tools' => new \stdClass(), 'resources' => new \stdClass(), 'prompts' => new \stdClass()],
                'serverInfo' => $this->serverInfo(),
                'instructions' => Prompts::serverInstructions($this->app),
            ]),
            // the site owner's instructions and an overview; ready-made tasks (2.2)
            'resources/list' => $ok(['resources' => Prompts::resources()]),
            'resources/read' => $this->guarded($id, fn (): array => Prompts::read($this->app, self::text($z['params']['uri'] ?? ''))),
            'prompts/list' => $ok(['prompts' => Prompts::listAll()]),
            'prompts/get' => $this->guarded($id, fn (): array => Prompts::get(self::text($z['params']['name'] ?? ''), (array) ($z['params']['arguments'] ?? []))),
            'ping' => $ok([]),
            // Czech names remain as hidden aliases
            // only the tools this connection may use (a connection limited to drafts or to reading, 2.2)
            // 3.8: each tool also carries its title (top level and annotations.title, MCP 2025-06-18) – additive fields only
            // 3.10: an essentials connection lists the core tools, find_tools and the runners it needs (Mcp\Discovery)
            'tools/list' => $ok(['tools' => $this->toolSet() === 'essentials' ? Discovery::essentials($this->available($tools), $this->access()) : $this->available($tools)]),
            'tools/call' => $ok($this->call($tools, self::text($z['params']['name'] ?? ''), (array) ($z['params']['arguments'] ?? []))),
            default => ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32601, 'message' => 'Unknown method: ' . $method]],
        };
    }

    /** A name or address from the request as text – an array or object there is no name (3.13, N313-4: no error page). */
    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /** The project's website (3.8: serverInfo.websiteUrl, MCP 2025-11-25). */
    public const string WEBSITE = 'https://kaletacms.com';

    /**
     * Who answers (3.8): the name stays as it always was (the site's name in it), the title is the product, websiteUrl the
     * project's site and the icons Kaleta's mark served by the site itself (two short URLs – fields older clients ignore).
     *
     * @return array<string, mixed>
     */
    private function serverInfo(): array
    {
        $icon = fn (string $file): string => $this->app->request->origin() . $this->app->url('image/' . $file);

        return ['name' => 'Kaleta – ' . $this->app->settings()->get('site_name'), 'title' => 'Kaleta', 'version' => KALETA_VERSION, 'websiteUrl' => self::WEBSITE,
            'icons' => [['src' => $icon('kaleta-znacka.svg'), 'mimeType' => 'image/svg+xml', 'sizes' => ['any']], ['src' => $icon('kaleta-znacka-180.png'), 'mimeType' => 'image/png', 'sizes' => ['180x180']]]];
    }

    /**
     * A tools/list item (3.8): name, title, then the definition as before (with the reason of a write tool) and the
     * annotations – the title is the same in both places.
     *
     * @param array<string, mixed> $tool a tool definition
     * @return array<string, mixed>
     */
    public static function listed(array $tool): array
    {
        $annotations = Tools::annotations((string) $tool['name']);

        return ['name' => $tool['name'], 'title' => $annotations['title']] + self::withReason($tool) + ['annotations' => $annotations];
    }

    /**
     * Every tool that changes the site takes an optional reason (2.15): one line on why – the request it answers, what the
     * user asked for. It is kept in the change log next to the change.
     *
     * @param array<string, mixed> $tool a tools/list item
     * @return array<string, mixed>
     */
    public static function withReason(array $tool): array
    {
        if (Catalog::access((string) $tool['name']) === 'read' || !is_array($tool['inputSchema'] ?? null)) {
            return $tool;
        }
        $tool['inputSchema']['properties'] = (array) ($tool['inputSchema']['properties'] ?? []) + ['reason' => ['type' => 'string',
            'description' => 'Why you make this change, in one short line – the user’s request or the site request it answers. Kept in the change log.']];

        return $tool;
    }

    /**
     * Tool call. The English name (tools/list) is translated to the Czech tool and back (Translator); the Czech name is a
     * hidden alias for connections from before 1.1 and behaves as before.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function call(Tools $tools, string $name, array $arguments): array
    {
        // the meta tools (3.10): callable on any connection, they only find tools and run them through this same method
        if ($name === 'find_tools') {
            $available = $this->available($tools);
            $essentials = $this->toolSet() === 'essentials';
            try {
                $found = Discovery::find($available, $essentials ? Catalog::ESSENTIALS : array_column($available, 'name'), $arguments, $essentials ? Discovery::runners($available, $this->access()) : []);
            } catch (\InvalidArgumentException $e) {
                return ['content' => [['type' => 'text', 'text' => $e->getMessage()]], 'isError' => true];
            }

            return ['content' => [['type' => 'text', 'text' => json_encode($found, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]]];
        }
        if (isset(Discovery::RUNNERS[$name])) {
            return $this->runTool($tools, $arguments, $name);
        }
        $czech = Translator::czech($name);
        $isEnglish = $czech !== null || !in_array($name, $tools->names(), true);
        if (Catalog::english($name) !== null && !Catalog::allows($this->access(), $name)) {
            return ['content' => [['type' => 'text', 'text' => $this->access() === 'read'
                ? 'This connection can only read the site. Changes need a connection with more access – the user sets it when connecting Claude, or under My account.'
                : 'This connection can only save drafts: builds, hidden pages and collection items, news drafts, the draft look, proposed exceptions to the opening hours, enquiry triage and notebook notes. This tool changes the live site – the user can do it in the admin, or connect Claude with full access.']], 'isError' => true];
        }
        try {
            $items = $czech !== null ? Translator::listAll($tools->listAll()) : $tools->listAll();
            $arguments = self::extractJson($items, $name, $arguments);
            // why Claude makes the change (2.15): any write tool takes it; it goes to the change log, never to the tool
            $reason = \Kaleta\Core\Guardrails::reason($arguments['reason'] ?? null);
            unset($arguments['reason']);
            $unknownParams = self::unknownParams($items, $name, $arguments);
            // a huge number would be cast to a wrong one by the tool (or stop it with an error page): a clear error instead (3.7, N37-27)
            $badNumber = self::badNumber($items, $name, $arguments);
            if ($badNumber !== null) {
                return ['content' => [['type' => 'text', 'text' => $badNumber]], 'isError' => true];
            }
            if ($czech !== null) {
                $arguments = Translator::arguments($name, $arguments);
            }
            $tool = $czech ?? $name;
            // the site owner's guardrails (2.15) hold for every connection, on top of its access; a write call gets its
            // change-log row before the tool runs, so parallel calls count each other against the hourly limit (3.7, N37-20)
            // – a batch records how many rows it sent, an import 1 until its step has run (Guardrails::COUNTED_AFTER)
            $what = isset(\Kaleta\Core\Guardrails::BATCH_ROWS[$tool]) || in_array($tool, \Kaleta\Core\Guardrails::COUNTED_AFTER, true) ? \Kaleta\Core\Guardrails::weight($tool, $arguments) . ' rows'
                : mb_substr((string) ($arguments['titulek'] ?? $arguments['nazev'] ?? $arguments['sablona'] ?? $arguments['id'] ?? ''), 0, 200);
            $logged = \Kaleta\Core\Guardrails::reserve($this->app, $tool, Catalog::access($tool), $arguments, (string) ($this->app->auth()->connection()['name'] ?? ''), $what, $reason);
            if (is_string($logged)) {
                return ['content' => [['type' => 'text', 'text' => $logged]], 'isError' => true];
            }
            // every content row a change touches is journaled, so the whole Claude session can be undone (2.17, Core\AgentJournal)
            $db = $this->app->db();
            $db->journal = $tools->isWriteTool($tool) && $tool !== 'undo_agent_session'
                ? \Kaleta\Core\AgentJournal::start($db, (string) ($this->app->auth()->connection()['name'] ?? 'Claude'), Catalog::english($tool) ?? $name) : null;
            try {
                $result = $tools->call($tool, $arguments);
            } catch (\InvalidArgumentException | \DomainException $e) {
                // the tool refused the call before changing anything: no change to log or count
                ChangeLog::remove($this->app, $logged);
                throw $e;
            } finally {
                $db->journal = null;
            }
            if ($tools->isWriteTool($tool)) {
                if (in_array($tool, \Kaleta\Core\Guardrails::COUNTED_AFTER, true)) {
                    ChangeLog::describe($this->app, $logged, max(1, $tools->recordsCreated) . ' rows'); // what the step created (N37-26)
                }
                \Kaleta\Front\Cache::clear();
            }
            if (($czech ?? $name) === 'seznam_poptavek') {
                // enquiries hold personal data: every read by Claude is in the change log, with how many it saw
                ChangeLog::write($this->app, 'claude', 'list_enquiries', t('%d enquiries read', is_array($result) ? count($result) : 0));
            }
            if ($unknownParams !== [] && is_array($result) && !array_is_list($result)) {
                // a typo in a parameter name would otherwise get lost without a trace (the tool does not know it, so it skips it)
                $result['nezname_parametry'] = $unknownParams;
            }
            if ($czech !== null) {
                $result = Translator::result($name, $result);
            }

            return ['content' => [['type' => 'text', 'text' => is_string($result) ? $result : json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]]];
        } catch (\InvalidArgumentException | \DomainException $e) {
            return ['content' => [['type' => 'text', 'text' => $isEnglish ? Translator::message($e->getMessage()) : $e->getMessage()]], 'isError' => true];
        }
    }

    /**
     * The runners (3.10): the inner tool goes through call() like a direct tools/call – the connection's access, the
     * guardrails and the reason, the journal for undo, unknown parameters, the translation, the change log and the cache.
     * Each runs only its own class of tools (Discovery::runner – the audit's N310-2 and N310-4), so its annotations hold
     * for whatever it runs; none runs a meta tool, so a call cannot nest.
     *
     * @param array<string, mixed> $arguments
     * @param string $runner run_read_tool | run_tool | run_destructive_tool
     * @return array<string, mixed>
     */
    private function runTool(Tools $tools, array $arguments, string $runner): array
    {
        $error = fn (string $text): array => ['content' => [['type' => 'text', 'text' => $text]], 'isError' => true];
        $name = trim(is_scalar($arguments['name'] ?? null) ? (string) $arguments['name'] : '');
        if ($name === '') {
            return $error($runner . ' needs name – the tool to run (find_tools lists them) – and its arguments as an object.');
        }
        if (isset(Catalog::META[$name])) {
            return $error($runner . ' runs the site\'s tools, not ' . $name . ' – call ' . ($name === 'find_tools' ? 'find_tools directly.' : 'the tool you need directly in ' . $runner . '.'));
        }
        $inner = $arguments['arguments'] ?? [];
        if (is_string($inner)) {
            // a client without the schema may send the object as JSON text
            $inner = trim($inner) === '' ? [] : json_decode($inner, true);
        }
        if (!is_array($inner) || ($inner !== [] && array_is_list($inner))) {
            return $error($runner . ': arguments must be an object with the tool\'s parameters, e.g. {"id": 12}. find_tools with names gives them.');
        }
        if (!isset($inner['reason']) && is_string($arguments['reason'] ?? null)) {
            $inner['reason'] = $arguments['reason']; // the reason may come with run_tool itself
        }
        if (Catalog::english($name) === null) {
            return $error('Unknown tool: ' . mb_substr($name, 0, 100) . '. Call find_tools for the tools of this site.');
        }
        if (Discovery::runner($name) !== $runner) {
            // a client may "always allow" one runner: it must never reach a tool of a more dangerous class
            return $error(Discovery::refusal($runner, $name));
        }

        return $this->call($tools, $name, $inner);
    }

    /**
     * Every tool this connection may use, as tools/list items (3.10: the whole list of an "all" connection – unchanged –
     * and what find_tools searches).
     *
     * @return list<array<string, mixed>>
     */
    private function available(Tools $tools): array
    {
        return array_values(array_filter(array_map(fn (array $t): array => self::listed($t),
            [...Translator::listAll($tools->listAll()), ...\Kaleta\Extension\Registry::get()->toolDefinitions()]), fn (array $t): bool => Catalog::allows($this->access(), $t['name'])));
    }

    /** The tool set of this connection: all | essentials (3.10). */
    private function toolSet(): string
    {
        return $this->app->auth()->connection()['tool_set'] ?? 'all';
    }

    /**
     * An object or array sent as JSON text (a client without the tool schema, some proxies) is decoded by the parameter
     * type in the schema – otherwise the tool would not recognize it and would silently skip the values.
     *
     * @param list<array<string, mixed>> $items tool definitions (tools/list)
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public static function extractJson(array $items, string $name, array $arguments): array
    {
        foreach ($items as $tool) {
            if (($tool['name'] ?? '') !== $name) {
                continue;
            }
            $properties = (array) ($tool['inputSchema']['properties'] ?? []);
            foreach ($arguments as $key => $value) {
                // the type can also be a list, e.g. ["array", "null"] for menu items
                $types = (array) ($properties[$key]['type'] ?? []);
                // a boolean sent as text: „false“ would be true in PHP (a hidden page would get published)
                if (is_string($value) && in_array('boolean', $types, true) && in_array(strtolower(trim($value)), ['true', 'false', '1', '0', ''], true)) {
                    $arguments[$key] = in_array(strtolower(trim($value)), ['true', '1'], true);
                    continue;
                }
                if (!is_string($value) || !array_intersect($types, ['object', 'array']) || !preg_match('/^\s*[\[{]/', $value)) {
                    continue;
                }
                $decoded = json_decode($value, true);
                if (!is_array($decoded)) {
                    continue;
                }
                $kind = $decoded === [] ? null : (array_is_list($decoded) ? 'array' : 'object'); // both [] and {} match either type
                if ($kind === null || in_array($kind, $types, true)) {
                    $arguments[$key] = $decoded;
                }
            }
            break;
        }

        return $arguments;
    }

    /** The largest number a tool takes (2^53 - 1: every whole number up to it is exact in JSON and in PHP's float and int). */
    public const int MAX_NUMBER = 9007199254740991;

    /**
     * A number no tool can use (3.7, N37-27): a float beyond MAX_NUMBER anywhere in the arguments (JSON 1e20 – a tool's
     * (int) would turn it into another number, PHP 8.5 even into an error page), or an integer parameter given as text
     * beyond it. The error names the parameter, so the caller can send a sensible value.
     *
     * @param list<array<string, mixed>> $items tool definitions (tools/list)
     * @param array<string, mixed> $arguments
     */
    public static function badNumber(array $items, string $name, array $arguments): ?string
    {
        $integers = [];
        foreach ($items as $tool) {
            if (($tool['name'] ?? '') === $name) {
                foreach ((array) ($tool['inputSchema']['properties'] ?? []) as $key => $property) {
                    if (in_array('integer', (array) (is_array($property) ? ($property['type'] ?? []) : []), true)) {
                        $integers[(string) $key] = true;
                    }
                }
                break;
            }
        }
        $find = function (mixed $value, string $path, bool $integer) use (&$find): ?string {
            if (is_float($value) || ($integer && is_string($value) && is_numeric(trim($value)))) {
                $number = (float) $value;

                return !is_finite($number) || abs($number) > self::MAX_NUMBER ? $path : null;
            }
            foreach (is_array($value) ? $value : [] as $key => $inner) {
                $found = $find($inner, $path . '.' . $key, false);
                if ($found !== null) {
                    return $found;
                }
            }

            return null;
        };
        foreach ($arguments as $key => $value) {
            $path = $find($value, (string) $key, isset($integers[(string) $key]));
            if ($path !== null) {
                return 'The number in ' . $path . ' is too large – numbers up to ' . self::MAX_NUMBER . ' are accepted. Send the value the user meant (an ID from a list tool, a count, a position).';
            }
        }

        return null;
    }

    /**
     * Parameters the tool does not have in its schema – returned in the result so the caller knows they were not used.
     *
     * @param list<array<string, mixed>> $items
     * @param array<string, mixed> $arguments
     * @return list<string>
     */
    public static function unknownParams(array $items, string $name, array $arguments): array
    {
        foreach ($items as $tool) {
            if (($tool['name'] ?? '') === $name) {
                return array_values(array_diff(array_map('strval', array_keys($arguments)), array_keys((array) ($tool['inputSchema']['properties'] ?? []))));
            }
        }

        return [];
    }

    /** The protocol version to answer with: the client's when the server speaks it, otherwise the newest (2.2). */
    public static function protocol(mixed $requested): string
    {
        return is_string($requested) && in_array($requested, self::PROTOCOLS, true) ? $requested : self::PROTOCOLS[0];
    }

    /**
     * A method whose parameters may be wrong (an unknown resource or prompt): the error goes back as a JSON-RPC error.
     *
     * @param callable(): array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function guarded(mixed $id, callable $result): array
    {
        try {
            return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result()];
        } catch (\InvalidArgumentException $e) {
            return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32602, 'message' => $e->getMessage()]];
        }
    }

    /** What the connection of this request may do: full | drafts | read (2.2). */
    private function access(): string
    {
        return $this->app->auth()->connection()['access'] ?? 'full';
    }

    /** @return array<string, mixed>|null user by token */
    private function user(): ?array
    {
        $header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        $db = $this->app->db();
        // the visitor's address behind the configured proxy, an IPv6 address by its /64 (3.3.3, N54)
        $ip = \Kaleta\Core\Antispam::hash(\Kaleta\Core\Firewall::visitorKey($this->app->request, $this->app->settings()));
        // a personal token from "Můj účet" (kaleta_…) or the access token of an application connected via OAuth
        // (kaleta_oa_…, valid for an hour)
        if (!preg_match('/^Bearer\s+(kaleta_(?:oa_)?[a-f0-9]{48})$/D', $header, $m)) {
            return null;
        }
        // Wrong tokens are recorded per address, but only up to 20 rows per 15 minutes: the cap keeps a flood from filling the
        // table, it never refuses a request (3.3.3, N62). A request is not refused on purpose: a valid token must always work,
        // so nobody can lock out the site's Claude connections by sending wrong tokens from a shared address (a proxy in
        // front of Docker, Claude's own servers – N5), and guessing a 192-bit token gains nothing from more tries anyway.
        $limited = (int) $db->value("SELECT COUNT(*) FROM {kontrola_ip} WHERE typ = 'mcp' AND ip_adresa = ? AND cas > ?", [$ip, \Kaleta\Core\Clock::at('-15 minutes')]) >= 20;
        $select = fn (string $toolSet): ?array => $db->one('SELECT t.idt, t.nazev AS connection_name, t.access AS connection_access, ' . $toolSet . " AS connection_tool_set, u.* FROM {api_tokeny} t JOIN {uzivatele} u ON u.idu = t.idu WHERE t.otisk = ? AND u.blokovat = 0 AND t.druh <> 'obnova' AND (t.expirace IS NULL OR t.expirace > ?)",
            [hash('sha256', $m[1]), date('Y-m-d H:i:s')]);
        try {
            $token = $select('t.tool_set');
        } catch (\PDOException $e) {
            // 3.10 audit (N310-9): before migration 0085 has run (no ALTER privilege, a lock timeout) every connection
            // keeps working with all tools instead of failing with an SQL error
            if ($e->getCode() !== '42S22') {
                throw $e;
            }
            $token = $select("'all'");
        }
        if ($token === null) {
            if (!$limited) {
                $db->insert('kontrola_ip', ['ip_adresa' => $ip, 'typ' => 'mcp', 'cas' => date('Y-m-d H:i:s')]);
            }

            return null;
        }
        $db->run('UPDATE {api_tokeny} SET pouzit = ? WHERE idt = ?', [\Kaleta\Core\Clock::now(), $token['idt']]);
        if ($this->app->settings()->get('claude_first_used') === '') {
            // the first call of any Claude connection: the dashboard stops leading with "Connect Claude" (3.5, Core\AskClaude)
            $this->app->settings()->set('claude_first_used', date('Y-m-d H:i:s'));
        }

        return $token;
    }
}
