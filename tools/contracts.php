<?php
/**
 * Kaleta – public contracts (2.1). What sites, agencies and Claude connections rely on is recorded in tools/contracts/
 * and checked by tools/unit-tests.php: an MCP tool or parameter, an MCP prompt or its argument (3.13), a design token or a
 * builder element (and its content properties) may be added, never removed or changed outside the deprecation policy (docs/RELEASING.md). 3.49 P0:
 * tools/contracts/mcp-definitions.json holds a hash of every MCP tool definition as Tools::definitions() gives it (the
 * schema rows sit next to their handlers in Mcp/Handlers since 3.49) – a changed definition is listed until it is
 * recorded, so the reviewer reads the diff. After a deliberate addition or change, record it:
 *
 *   php tools/contracts.php --update
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/system/bootstrap.php';

/** @return array<string, array<string, mixed>> the MCP interface: English tool => parameters (type), required ones, annotations */
function kaleta_mcp_contract(): array
{
    $contract = [];
    // 3.10: the meta tools find_tools and run_tool (Mcp\Discovery) are part of the interface too
    foreach ([...Kaleta\Mcp\Translator::listAll(Kaleta\Mcp\Tools::definitions()), ...Kaleta\Mcp\Discovery::definitions()] as $tool) {
        $properties = (array) $tool['inputSchema']['properties'];
        ksort($properties);
        $contract[$tool['name']] = [
            'parameters' => array_map(fn (array $p): mixed => $p['type'] ?? 'any', $properties),
            'required' => $tool['inputSchema']['required'],
            'annotations' => Kaleta\Mcp\Tools::annotations($tool['name']),
        ];
    }
    ksort($contract);

    return $contract;
}

/**
 * The MCP definitions as the tools hold them (3.49 P0): the stored names in their order, a hash of every definition, of
 * the whole list (raw, Czech names of the older tools as Translator reads them) and of the meta tools (Mcp\Discovery).
 * A definition moved to another file without a change keeps every hash.
 *
 * @return array{order: list<string>, raw: string, discovery: string, sha256: array<string, string>}
 */
function kaleta_definitions_contract(): array
{
    $hash = fn (mixed $v): string => hash('sha256', (string) json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $definitions = Kaleta\Mcp\Tools::definitions();

    return ['order' => array_column($definitions, 'name'), 'raw' => $hash($definitions), 'discovery' => $hash(Kaleta\Mcp\Discovery::definitions()),
        'sha256' => array_combine(array_column($definitions, 'name'), array_map($hash, $definitions))];
}

/** @return list<string> design tokens: the stored names and their English names */
function kaleta_token_contract(): array
{
    $css = Kaleta\Builder\DesignSystem::css(Kaleta\Builder\DesignSystem::DEFAULTS);
    preg_match_all('/^\t+(--ka-[a-z0-9-]+):/m', $css, $m);
    $tokens = array_values(array_unique($m[1]));
    sort($tokens);

    return $tokens;
}

/** @return array<string, list<string>> builder element types => their content properties (what stored builds hold) */
function kaleta_element_contract(): array
{
    $elements = [];
    foreach (Kaleta\Builder\Build::ELEMENTS as $class) {
        $properties = array_keys($class::properties());
        sort($properties);
        $elements[$class::TYPE] = $properties;
    }
    ksort($elements);

    return $elements;
}

/**
 * The extension API (3.0): its version, the public methods of Extension\Api with their parameters, the filters and the
 * access levels of tools – what add-ons are written against.
 *
 * @return array{version: int, methods: array<string, list<string>>, filters: list<string>, tool_access: list<string>}
 */
function kaleta_extension_contract(): array
{
    $methods = [];
    foreach ((new ReflectionClass(Kaleta\Extension\Api::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
        if ($m->isStatic() || $m->getName() === '__construct') {
            continue;
        }
        $methods[$m->getName()] = array_map(fn (ReflectionParameter $p): string => (string) $p->getType() . ' $' . $p->getName(), $m->getParameters());
    }
    ksort($methods);

    return ['version' => Kaleta\Extension\Api::VERSION, 'methods' => $methods, 'filters' => array_keys(Kaleta\Extension\Api::FILTERS), 'tool_access' => Kaleta\Extension\Api::TOOL_ACCESS];
}

/**
 * The MCP prompts (3.13): every name prompts/get accepts – the listed ones and the earlier names kept as aliases – with its
 * arguments (name => required). A connection in use may call any of them, so a name or an argument is never removed and
 * an argument never becomes required.
 *
 * @return array<string, array<string, bool>>
 */
function kaleta_prompt_contract(): array
{
    $prompts = [];
    foreach (Kaleta\Mcp\Prompts::listAll() as $prompt) {
        $prompts[$prompt['name']] = array_column($prompt['arguments'], 'required', 'name');
    }
    foreach (Kaleta\Mcp\Prompts::HIDDEN as $name => $prompt) {
        $prompts[$name] = array_map(fn (array $arg): bool => $arg[1], $prompt['arguments']);
    }
    ksort($prompts);

    return $prompts;
}

/**
 * Differences that break the contract: something recorded that is gone or changed. Additions are listed separately –
 * they are fine, but the record has to be updated.
 *
 * @return array{broken: list<string>, added: list<string>}
 */
function kaleta_contract_diff(): array
{
    $broken = $added = [];
    $recorded = json_decode((string) file_get_contents(__DIR__ . '/contracts/mcp-tools.json'), true);
    $current = kaleta_mcp_contract();
    foreach ($recorded as $tool => $r) {
        if (!isset($current[$tool])) {
            $broken[] = "MCP tool $tool removed";
            continue;
        }
        $c = $current[$tool];
        foreach ($r['parameters'] as $param => $type) {
            if (!array_key_exists($param, $c['parameters'])) {
                $broken[] = "MCP $tool: parameter $param removed";
            } elseif ($c['parameters'][$param] !== $type) {
                $broken[] = "MCP $tool: parameter $param changed type";
            }
        }
        foreach (array_diff($c['required'], $r['required']) as $param) {
            $broken[] = "MCP $tool: parameter $param became required";
        }
        $annotations = kaleta_annotation_diff($r['annotations'], $c['annotations']);
        array_push($broken, ...array_map(fn (string $change): string => "MCP $tool: $change", $annotations['broken']));
        array_push($added, ...array_map(fn (string $change): string => "MCP $tool: $change", $annotations['added']));
        foreach (array_diff(array_keys($c['parameters']), array_keys($r['parameters'])) as $param) {
            $added[] = "MCP $tool: parameter $param";
        }
    }
    foreach (array_diff(array_keys($current), array_keys($recorded)) as $tool) {
        $added[] = "MCP tool $tool";
    }
    $tokens = json_decode((string) file_get_contents(__DIR__ . '/contracts/design-tokens.json'), true);
    foreach (array_diff($tokens, kaleta_token_contract()) as $token) {
        $broken[] = "design token $token removed";
    }
    foreach (array_diff(kaleta_token_contract(), $tokens) as $token) {
        $added[] = "design token $token";
    }
    $elements = json_decode((string) file_get_contents(__DIR__ . '/contracts/builder-elements.json'), true);
    $currentElements = kaleta_element_contract();
    foreach ($elements as $type => $properties) {
        if (!isset($currentElements[$type])) {
            $broken[] = "builder element $type removed";
            continue;
        }
        foreach (array_diff($properties, $currentElements[$type]) as $property) {
            $broken[] = "builder element $type: property $property removed";
        }
        foreach (array_diff($currentElements[$type], $properties) as $property) {
            $added[] = "builder element $type: property $property";
        }
    }
    foreach (array_diff_key($currentElements, $elements) as $type => $_) {
        $added[] = "builder element $type";
    }

    $extensionFile = __DIR__ . '/contracts/extension-api.json';
    if (is_file($extensionFile)) {
        $recordedApi = json_decode((string) file_get_contents($extensionFile), true);
        $currentApi = kaleta_extension_contract();
        if ($recordedApi['version'] !== $currentApi['version']) {
            $broken[] = 'extension API version changed';
        }
        foreach ($recordedApi['methods'] as $method => $parameters) {
            if (!isset($currentApi['methods'][$method])) {
                $broken[] = "extension API: $method() removed";
            } elseif (array_slice($currentApi['methods'][$method], 0, count($parameters)) !== $parameters) {
                $broken[] = "extension API: $method() parameters changed";
            }
        }
        foreach (array_diff($recordedApi['filters'], $currentApi['filters']) as $filter) {
            $broken[] = "extension API: filter $filter removed";
        }
        foreach (array_diff(array_keys($currentApi['methods']), array_keys($recordedApi['methods'])) as $method) {
            $added[] = "extension API: $method()";
        }
    }

    $promptFile = __DIR__ . '/contracts/mcp-prompts.json';
    if (is_file($promptFile)) {
        $recordedPrompts = json_decode((string) file_get_contents($promptFile), true);
        $currentPrompts = kaleta_prompt_contract();
        foreach ($recordedPrompts as $prompt => $arguments) {
            if (!isset($currentPrompts[$prompt])) {
                $broken[] = "MCP prompt $prompt removed";
                continue;
            }
            foreach ($arguments as $argument => $required) {
                if (!array_key_exists($argument, $currentPrompts[$prompt])) {
                    $broken[] = "MCP prompt $prompt: argument $argument removed";
                } elseif ($currentPrompts[$prompt][$argument] && !$required) {
                    $broken[] = "MCP prompt $prompt: argument $argument became required";
                }
            }
            foreach (array_diff_key($currentPrompts[$prompt], $arguments) as $argument => $required) {
                if ($required) {
                    $broken[] = "MCP prompt $prompt: new required argument $argument";
                } else {
                    $added[] = "MCP prompt $prompt: argument $argument";
                }
            }
        }
        foreach (array_diff_key($currentPrompts, $recordedPrompts) as $prompt => $_) {
            $added[] = "MCP prompt $prompt";
        }
    }

    // 3.49 P0: a definition that changed is no break (mcp-tools.json above guards names, parameters and annotations), but it
    // is listed until it is recorded
    $definitionsFile = __DIR__ . '/contracts/mcp-definitions.json';
    if (is_file($definitionsFile)) {
        $recordedDefinitions = json_decode((string) file_get_contents($definitionsFile), true);
        $currentDefinitions = kaleta_definitions_contract();
        foreach (['order' => 'the order of the tools', 'raw' => 'the whole list (raw)', 'discovery' => 'the meta tools (find_tools and the runners)'] as $key => $what) {
            if ($recordedDefinitions[$key] !== $currentDefinitions[$key]) {
                $added[] = "MCP definitions: $what changed";
            }
        }
        foreach ($currentDefinitions['sha256'] as $tool => $sha) {
            if (($recordedDefinitions['sha256'][$tool] ?? null) !== $sha) {
                $added[] = "MCP definition $tool " . (isset($recordedDefinitions['sha256'][$tool]) ? 'changed' : 'new');
            }
        }
    }

    return ['broken' => $broken, 'added' => $added];
}

if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['argv'][0] ?? '')) === __FILE__) {
    if (in_array('--update', $_SERVER['argv'], true)) {
        $diff = kaleta_contract_diff_safe();
        if ($diff['broken'] !== []) {
            fwrite(STDERR, "The change breaks the contract – not recorded:\n  " . implode("\n  ", $diff['broken']) . "\n");
            exit(1);
        }
        $json = fn (mixed $v): string => json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        file_put_contents(__DIR__ . '/contracts/mcp-tools.json', $json(kaleta_mcp_contract()));
        file_put_contents(__DIR__ . '/contracts/design-tokens.json', $json(kaleta_token_contract()));
        file_put_contents(__DIR__ . '/contracts/builder-elements.json', $json(kaleta_element_contract()));
        file_put_contents(__DIR__ . '/contracts/extension-api.json', $json(kaleta_extension_contract()));
        file_put_contents(__DIR__ . '/contracts/mcp-prompts.json', $json(kaleta_prompt_contract()));
        file_put_contents(__DIR__ . '/contracts/mcp-definitions.json', $json(kaleta_definitions_contract()));
        echo 'recorded: ' . count(kaleta_mcp_contract()) . ' MCP tools, ' . count(kaleta_prompt_contract()) . ' MCP prompts, ' . count(kaleta_token_contract()) . ' design tokens, ' . count(kaleta_element_contract()) . " builder elements\n";
        exit(0);
    }
    $diff = kaleta_contract_diff();
    echo $diff['broken'] === [] && $diff['added'] === [] ? "contracts unchanged\n" : "broken:\n  " . implode("\n  ", $diff['broken'] ?: ['–']) . "\nadded:\n  " . implode("\n  ", $diff['added'] ?: ['–']) . "\n";
    exit($diff['broken'] === [] ? 0 : 1);
}

/**
 * How the annotations of one tool changed (3.8). readOnlyHint and destructiveHint decide what Claude confirms with the user,
 * so they never change; a hint may only become more careful – openWorldHint false → true, idempotentHint true → false (or
 * gone); a new hint or a new or reworded title (display only) is an addition. A recorded hint that is gone is broken.
 *
 * @param array<string, mixed> $recorded
 * @param array<string, mixed> $current
 * @return array{broken: list<string>, added: list<string>}
 */
function kaleta_annotation_diff(array $recorded, array $current): array
{
    $broken = $added = [];
    foreach ($recorded as $key => $value) {
        $now = $current[$key] ?? null;
        $careful = ['openWorldHint' => $value === false && $now === true, 'idempotentHint' => $value === true && $now !== true, 'title' => is_string($now)][$key] ?? false;
        if ($now === $value) {
            continue;
        } elseif ($careful) {
            $added[] = "annotation $key " . json_encode($value) . ' → ' . json_encode($now);
        } elseif ($now === null && $key !== 'idempotentHint') {
            $broken[] = "annotation $key removed";
        } else {
            $broken[] = "annotation $key changed";
        }
    }
    foreach (array_diff_key($current, $recorded) as $key => $value) {
        $added[] = "annotation $key " . json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    return ['broken' => $broken, 'added' => $added];
}

/** The first recording has nothing to compare with. */
function kaleta_contract_diff_safe(): array
{
    return is_file(__DIR__ . '/contracts/mcp-tools.json') && is_file(__DIR__ . '/contracts/design-tokens.json') && is_file(__DIR__ . '/contracts/builder-elements.json') ? kaleta_contract_diff() : ['broken' => [], 'added' => []];
}
