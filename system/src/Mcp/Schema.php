<?php

declare(strict_types=1);

namespace Kaleta\Mcp;

/**
 * Building blocks of the MCP input schemas (3.49 P0): the tool definitions in Mcp/Handlers use them, so every schema is
 * written the same way. An object without properties gets a fresh \stdClass – JSON {} – never an empty array, which
 * would be encoded as [] and change the tools/list answer.
 */
final class Schema
{
    /**
     * @param array<string, mixed> $properties
     * @param list<string> $required
     * @return array{type: 'object', properties: array<string, mixed>|\stdClass, required: list<string>}
     */
    public static function object(array $properties, array $required = []): array
    {
        return ['type' => 'object', 'properties' => $properties === [] ? new \stdClass() : $properties, 'required' => $required];
    }

    /** @return array{type: 'string', description: string} */
    public static function text(string $description): array
    {
        return ['type' => 'string', 'description' => $description];
    }

    /** @return array{type: 'integer', description: string} */
    public static function number(string $description): array
    {
        return ['type' => 'integer', 'description' => $description];
    }
}
