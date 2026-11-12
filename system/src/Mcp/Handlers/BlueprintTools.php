<?php

declare(strict_types=1);

namespace Kaleta\Mcp\Handlers;

use Kaleta\Core\Blueprint;

/**
 * MCP tools for industry blueprints (2.11, Core\Blueprint): what the site's kind of business needs – the questions to
 * ask the owner, the checks the audit runs and how to work on it – applying a shipped or a given manifest, taking one
 * off and writing the current site as a manifest. Part of Mcp\Tools.
 *
 * @phpstan-ignore trait.unused
 */
trait BlueprintTools
{
    /** list_connectors (2.13) */
    private function toolListConnectors(string $name, array $a): mixed
    {
        if (!$this->app->auth()->isAdmin()) {
            throw new \DomainException('Connections are managed by administrators.');
        }
        $db = $this->app->db();

        return ['connectors' => \Kaleta\Core\Connectors::status($db), 'waiting_deliveries' => (int) $db->value('SELECT COUNT(*) FROM {connector_queue} WHERE next_attempt IS NOT NULL'),
            'note' => 'Connecting and credentials are only in Administration → Connections; Claude never sees or sets them.'];
    }

    /** get_blueprint */
    private function toolGetBlueprint(string $name, array $a): mixed
    {
        $db = $this->app->db();
        $applied = Blueprint::applied($db);
        $describe = fn (array $m): array => ['key' => $m['key'], 'group' => $m['group'] ?? 'other', 'name' => Blueprint::text($m['name']), 'description' => Blueprint::text($m['description']),
            'presets' => $m['presets'], 'facts' => array_column($m['facts'], 'key'), 'questions' => count($m['questions']), 'checks' => count($m['audit'])];

        return [
            'applied' => array_values(array_map($describe, $applied)),
            'available' => array_values(array_map($describe, array_diff_key(Blueprint::available(), $applied))),
            'questions' => array_map(fn (array $q): array => ['question' => $q['question'], 'fact' => $q['fact'], 'answer' => $q['answer'] !== '' ? $q['answer'] : null] + ($q['help'] !== '' ? ['help' => $q['help']] : []),
                Blueprint::questions($this->app)),
            'failing_checks' => array_map(fn (array $f): array => ['blueprint' => $f[0], 'message' => $f[1]], Blueprint::findings($this->app)),
            'instructions' => Blueprint::instructions($db),
            // 3.3: what a manifest of its own may contain (prompt draft_blueprint) – the groups, the fact types and the audit rules
            'manifest_format' => ['groups' => array_keys(Blueprint::GROUPS), 'fact_types' => array_keys(\Kaleta\Core\Facts::TYPES),
                'fact_schema' => array_values(array_filter(array_keys(\Kaleta\Core\Facts::SCHEMA_PROPS))), 'audit_rules' => Blueprint::RULES, 'audit_settings' => Blueprint::SETTINGS],
            'next' => $applied === [] ? 'apply_blueprint {"key": "<available key>"} sets the site up for its kind of business (when the user wants it); when none fits, the prompt draft_blueprint makes one with the user.'
                : 'Ask the user the unanswered questions and save each answer with save_fact (key = fact); then fix the failing checks.',
        ];
    }

    /** apply_blueprint */
    private function toolApplyBlueprint(string $name, array $a): mixed
    {
        if (!$this->app->auth()->isAdmin()) {
            throw new \DomainException('Blueprints are applied by administrators.');
        }
        if (isset($a['manifest'])) {
            [$manifest, $errors] = Blueprint::sanitize(is_string($a['manifest']) ? json_decode($a['manifest'], true) : $a['manifest']);
            if ($manifest === null) {
                throw new \InvalidArgumentException('The manifest is not valid: ' . implode(' ', $errors));
            }
            if (($refusal = \Kaleta\Core\Language::runWith('en', fn (): ?string => Blueprint::customRefusal($manifest), 'admin-')) !== null) {
                throw new \InvalidArgumentException($refusal . ' Nothing was applied.'); // 3.39 (N111)
            }
        } else {
            $manifest = Blueprint::available()[(string) ($a['key'] ?? '')] ?? throw new \InvalidArgumentException('Unknown blueprint – get_blueprint lists the available ones.');
        }
        $created = Blueprint::apply($this->app, $manifest);

        return ['applied' => $manifest['key'], 'created_collections' => $created['collections'], 'created_facts' => $created['facts'],
            'next' => 'The new collections have hidden list pages. Ask the blueprint\'s questions (get_blueprint) and save the answers with save_fact.'];
    }

    /** remove_blueprint */
    private function toolRemoveBlueprint(string $name, array $a): mixed
    {
        if (!$this->app->auth()->isAdmin()) {
            throw new \DomainException('Blueprints are removed by administrators.');
        }
        if (!Blueprint::remove($this->app, (string) ($a['key'] ?? ''))) {
            throw new \InvalidArgumentException('This blueprint is not applied on the site.');
        }

        return ['removed' => (string) $a['key'], 'note' => 'Its checks, questions and instructions are gone; the collections and facts it created stay.'];
    }

    /** export_blueprint */
    private function toolExportBlueprint(string $name, array $a): mixed
    {
        if (!$this->app->auth()->isAdmin()) {
            throw new \DomainException('Blueprints are exported by administrators.');
        }
        $key = (string) ($a['key'] ?? '');
        if (preg_match(Blueprint::KEY_PATTERN, $key) !== 1) {
            throw new \InvalidArgumentException('key: lowercase letters, digits and _ (2–40 characters).');
        }

        return ['manifest' => Blueprint::export($this->app, $key, mb_substr(trim((string) ($a['name'] ?? $key)), 0, 100)),
            'note' => 'Facts are listed without their values. Add questions, checks and instructions, then the manifest can be applied on another site with apply_blueprint.'];
    }
}
