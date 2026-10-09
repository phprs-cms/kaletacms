<?php

declare(strict_types=1);

namespace Kaleta\Core;

use Kaleta\Builder\Collections;
use Kaleta\Builder\Presets;

/**
 * Industry blueprints (2.11): what a kind of business needs from its site, as a package – a clinic, a manufacturer, a
 * craftsman, a driving school, a farm, a municipality. A blueprint is a JSON manifest (FORMAT 1):
 *
 *  - key, name, description – texts are a string or {"en": "…", "cs": "…"} (the admin language, else English);
 *  - group – where the Blueprints screen lists it (GROUPS, 3.3); optional, another site's manifest without one is "other";
 *  - presets – ready-made collections it needs (Builder\Presets keys), created when it is applied;
 *  - facts – facts to define, never their values: [{key, label, type, schema?}];
 *  - questions – what to ask the owner, each answered into one of its facts: [{key, question, fact, help?}];
 *  - audit – checks the site audit runs, from a closed set of declarative rules (RULES), never code;
 *  - claude – how to work on such a site, added to the instructions of every Claude connection.
 *
 * Shipped blueprints are system/blueprints/<key>.json; a site keeps the ones applied in ka_blueprints, they travel with
 * the site export, and export() writes the current site as a manifest (presets in use, facts without values, its rules).
 */
final class Blueprint
{
    public const int FORMAT = 1;

    public const string KEY_PATTERN = '/^[a-z][a-z0-9_]{1,39}$/D';

    /** Audit rules: check => required parameters. */
    public const array RULES = [
        'fact' => ['fact'],                 // the fact has a value
        'preset_items' => ['preset'],       // a collection made from the preset has at least `min` (1) visible items
        'setting' => ['setting'],           // a company setting is filled in (SETTINGS)
        'page' => ['slugs'],                // a visible page has one of the addresses
        'stale_items' => ['preset', 'days'], // the preset's items changed within `days` days (a price list, opening times)
    ];

    /** Groups of the Blueprints screen (3.3): key => label, in this order; "other" collects manifests without a known group. */
    public const array GROUPS = [
        'services' => 'Services and trades',
        'health' => 'Health, sport and learning',
        'food' => 'Food and stays',
        'products' => 'Shops and production',
        'tech' => 'Software and agencies',
        'public' => 'Public and non-profit',
        'other' => 'Other',
    ];

    /** Settings an audit rule may check: the company details. */
    public const array SETTINGS = ['company_name', 'company_phone', 'company_email', 'company_address', 'company_hours', 'company_map', 'company_id', 'company_vat'];

    /**
     * A manifest from a file, an import or Claude, checked field by field: unknown keys are dropped, wrong values are
     * reported. Returns [manifest or null, errors].
     *
     * @return array{0: array<string, mixed>|null, 1: list<string>}
     */
    public static function sanitize(mixed $input): array
    {
        $errors = [];
        if (!is_array($input) || (int) ($input['kaleta_blueprint'] ?? 0) !== self::FORMAT) {
            return [null, ['Not a Kaleta blueprint (format ' . self::FORMAT . ').']];
        }
        $key = is_string($input['key'] ?? null) ? $input['key'] : '';
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            $errors[] = 'key: lowercase letters, digits and _ (2–40 characters).';
        }
        $text = function (mixed $v, int $max): string|array|null {
            if (is_string($v)) {
                return mb_substr(trim(strip_tags($v)), 0, $max);
            }
            if (is_array($v) && $v !== [] && !array_is_list($v)) {
                $out = [];
                foreach ($v as $language => $t) {
                    if (is_string($language) && Language::isOffered($language) && is_string($t) && trim($t) !== '') {
                        $out[$language] = mb_substr(trim(strip_tags($t)), 0, $max);
                    }
                }

                return $out === [] ? null : $out;
            }

            return null;
        };
        $name = $text($input['name'] ?? null, 100);
        if ($name === null || $name === '') {
            $errors[] = 'name: missing.';
        }
        $presets = [];
        foreach ((array) ($input['presets'] ?? []) as $p) {
            if (is_string($p) && Presets::get($p) !== null) {
                $presets[] = $p;
            } else {
                $errors[] = 'presets: unknown preset ' . (is_scalar($p) ? (string) $p : '?') . '.';
            }
        }
        $facts = [];
        foreach ((array) ($input['facts'] ?? []) as $f) {
            $fk = is_array($f) && is_string($f['key'] ?? null) ? $f['key'] : '';
            if (preg_match(Facts::KEY_PATTERN, $fk) !== 1 || isset(Facts::BUILT_IN[$fk]) || ($label = $text($f['label'] ?? null, 150)) === null) {
                $errors[] = 'facts: ' . ($fk !== '' ? $fk : '?') . ' needs a valid key (not a built-in one) and a label.';
                continue;
            }
            $facts[$fk] = ['key' => $fk, 'label' => $label, 'type' => isset(Facts::TYPES[$f['type'] ?? '']) ? (string) $f['type'] : 'text']
                + (isset($f['schema']) && is_string($f['schema']) && isset(Facts::SCHEMA_PROPS[$f['schema']]) && $f['schema'] !== '' ? ['schema' => $f['schema']] : []);
        }
        $questions = [];
        foreach ((array) ($input['questions'] ?? []) as $q) {
            $question = is_array($q) ? $text($q['question'] ?? null, 300) : null;
            $fact = is_array($q) && is_string($q['fact'] ?? null) ? $q['fact'] : '';
            if ($question === null || !isset($facts[$fact])) {
                $errors[] = 'questions: each needs a question and one of the blueprint\'s facts.';
                continue;
            }
            $questions[] = ['key' => $fact, 'question' => $question, 'fact' => $fact] + (($help = $text($q['help'] ?? null, 500)) !== null ? ['help' => $help] : []);
        }
        $audit = [];
        foreach ((array) ($input['audit'] ?? []) as $r) {
            $check = is_array($r) && is_string($r['check'] ?? null) ? $r['check'] : '';
            $message = is_array($r) ? $text($r['message'] ?? null, 300) : null;
            if (!isset(self::RULES[$check]) || $message === null) {
                $errors[] = 'audit: unknown check ' . $check . ' or no message.';
                continue;
            }
            $rule = ['check' => $check, 'message' => $message];
            $valid = match ($check) {
                'fact' => is_string($r['fact'] ?? null) && (isset($facts[$r['fact']]) || isset(Facts::BUILT_IN[$r['fact']])),
                'preset_items' => is_string($r['preset'] ?? null) && Presets::get($r['preset']) !== null,
                'setting' => in_array($r['setting'] ?? null, self::SETTINGS, true),
                'page' => is_array($r['slugs'] ?? null) && $r['slugs'] !== [] && array_filter($r['slugs'], fn (mixed $s): bool => !is_string($s) || preg_match('/^[a-z0-9-]{1,120}$/D', $s) !== 1) === [],
                'stale_items' => is_string($r['preset'] ?? null) && Presets::get($r['preset']) !== null && is_int($r['days'] ?? null) && $r['days'] >= 7 && $r['days'] <= 3650,
            };
            if (!$valid) {
                $errors[] = 'audit: the ' . $check . ' check has wrong parameters.';
                continue;
            }
            foreach (self::RULES[$check] as $param) {
                $rule[$param] = $r[$param];
            }
            if ($check === 'preset_items') {
                $rule['min'] = max(1, min(1000, (int) ($r['min'] ?? 1)));
            }
            $audit[] = $rule;
        }
        $claude = is_string($input['claude'] ?? null) ? mb_substr(trim(strip_tags($input['claude'])), 0, 4000) : '';

        $group = is_string($input['group'] ?? null) && isset(self::GROUPS[$input['group']]) ? $input['group'] : 'other';

        return $errors !== [] ? [null, $errors] : [['kaleta_blueprint' => self::FORMAT, 'key' => $key, 'name' => $name, 'group' => $group, 'description' => $text($input['description'] ?? null, 500) ?? '',
            'presets' => array_values(array_unique($presets)), 'facts' => array_values($facts), 'questions' => $questions, 'audit' => $audit, 'claude' => $claude], []];
    }

    /** A manifest text in the admin language: the string, or the language's version, English, the first. */
    public static function text(string|array $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        $language = Language::code();

        return (string) ($value[$language] ?? $value['en'] ?? reset($value));
    }

    /** @return array<string, array<string, mixed>> shipped blueprints, key => manifest (an invalid file is left out) */
    public static function available(): array
    {
        $out = [];
        foreach (glob(KALETA_SYSTEM . '/blueprints/*.json') ?: [] as $file) {
            [$manifest] = self::sanitize(json_decode((string) file_get_contents($file), true));
            if ($manifest !== null && $manifest['key'] === basename($file, '.json')) {
                $out[$manifest['key']] = $manifest;
            }
        }
        uasort($out, fn (array $a, array $b): int => strcmp(self::text($a['name']), self::text($b['name'])));

        return $out;
    }

    /** @return array<string, array<string, mixed>> blueprints applied on this site, key => manifest */
    public static function applied(Db $db): array
    {
        $out = [];
        foreach ($db->all('SELECT bkey, manifest FROM {blueprints} ORDER BY applied_at') as $r) {
            [$manifest] = self::sanitize(json_decode((string) $r['manifest'], true));
            if ($manifest !== null) {
                $out[(string) $r['bkey']] = $manifest;
            }
        }

        return $out;
    }

    /**
     * Applies a blueprint: its ready-made collections (those the site does not have yet, each with its hidden list page)
     * and its facts (without values; a fact the site already has keeps its value). Applying it again updates the stored
     * manifest and adds what is new. Returns what was created.
     *
     * @param array<string, mixed> $manifest a sanitized manifest
     * @return array{collections: list<string>, facts: list<string>}
     */
    public static function apply(App $app, array $manifest): array
    {
        $db = $app->db();
        $created = ['collections' => [], 'facts' => []];
        foreach ($manifest['presets'] as $preset) {
            if ($db->value('SELECT 1 FROM {kolekce} WHERE preset = ?', [$preset]) === null && ($id = Presets::create($app, $preset)) !== null) {
                $created['collections'][] = (string) $db->value('SELECT seo_link FROM {kolekce} WHERE idk = ?', [$id]);
            }
        }
        foreach ($manifest['facts'] as $f) {
            if ($db->value("SELECT 1 FROM {facts} WHERE fact_key = ? AND language = ''", [$f['key']]) === null
                && Facts::save($app, $f['key'], ['label' => self::text($f['label']), 'type' => $f['type'], 'value' => '', 'schema' => $f['schema'] ?? '']) === null) {
                $created['facts'][] = $f['key'];
            }
        }
        $json = (string) json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($db->value('SELECT 1 FROM {blueprints} WHERE bkey = ?', [$manifest['key']]) !== null) {
            $db->update('blueprints', ['manifest' => $json, 'nazev' => self::text($manifest['name'])], ['bkey' => $manifest['key']]);
        } else {
            $db->insert('blueprints', ['bkey' => $manifest['key'], 'nazev' => self::text($manifest['name']), 'manifest' => $json, 'applied_at' => date('Y-m-d H:i:s')]);
        }
        \Kaleta\Admin\ChangeLog::write($app, 'blueprints', 'apply', self::text($manifest['name']));

        return $created;
    }

    /** Takes a blueprint off the site: its checks, questions and instructions; collections and facts stay. */
    public static function remove(App $app, string $key): bool
    {
        $done = $app->db()->delete('blueprints', ['bkey' => $key]) > 0;
        if ($done) {
            \Kaleta\Admin\ChangeLog::write($app, 'blueprints', 'remove', $key);
        }

        return $done;
    }

    /**
     * The questions of the applied blueprints with the current answer (the fact's value).
     *
     * @return list<array{blueprint: string, fact: string, question: string, help: string, answer: string, type: string}>
     */
    public static function questions(App $app): array
    {
        $facts = Facts::all($app);
        $out = [];
        foreach (self::applied($app->db()) as $key => $m) {
            foreach ($m['questions'] as $q) {
                $out[] = ['blueprint' => $key, 'fact' => $q['fact'], 'question' => self::text($q['question']), 'help' => isset($q['help']) ? self::text($q['help']) : '',
                    'answer' => (string) ($facts[$q['fact']]['value'] ?? ''), 'type' => (string) ($facts[$q['fact']]['type'] ?? 'text')];
            }
        }

        return $out;
    }

    /**
     * The audit rules of the applied blueprints that are not met: [blueprint name, message, admin link].
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    public static function findings(App $app): array
    {
        $db = $app->db();
        $settings = $app->settings();
        $facts = Facts::all($app);
        $out = [];
        foreach (self::applied($db) as $m) {
            foreach ($m['audit'] as $r) {
                $collection = in_array($r['check'], ['preset_items', 'stale_items'], true) ? $db->one('SELECT idk FROM {kolekce} WHERE preset = ? ORDER BY idk LIMIT 1', [$r['preset']]) : null;
                [$ok, $edit] = match ($r['check']) {
                    'fact' => [trim((string) ($facts[$r['fact']]['value'] ?? '')) !== '', 'admin.php?module=facts'],
                    'setting' => [trim($settings->get($r['setting'])) !== '', 'admin.php?module=settings&action=company'],
                    'page' => [$db->value('SELECT 1 FROM {stranky} WHERE zobrazit = 1 AND smazano IS NULL AND seo_link IN (' . implode(',', array_fill(0, count($r['slugs']), '?')) . ')', $r['slugs']) !== null, 'admin.php?module=pages'],
                    'preset_items' => [$collection !== null && (int) $db->value('SELECT COUNT(*) FROM {kolekce_polozky} WHERE idk = ? AND zobrazit = 1 AND smazano IS NULL', [$collection['idk']]) >= $r['min'],
                        $collection !== null ? 'admin.php?module=collections&action=items&id=' . (int) $collection['idk'] : 'admin.php?module=collections'],
                    'stale_items' => [$collection === null || ($last = $db->value('SELECT MAX(COALESCE(zmeneno, datum)) FROM {kolekce_polozky} WHERE idk = ? AND smazano IS NULL', [$collection['idk']])) === null
                        || strtotime((string) $last) >= strtotime('-' . $r['days'] . ' days'), $collection !== null ? 'admin.php?module=collections&action=items&id=' . (int) $collection['idk'] : 'admin.php?module=collections'],
                    default => [true, ''], // sanitize() keeps only the known checks
                };
                if (!$ok) {
                    $out[] = [self::text($m['name']), self::text($r['message']), $edit];
                }
            }
        }

        return $out;
    }

    /** The instructions of the applied blueprints for Claude (Mcp\Prompts::serverInstructions); never in the way of a connection. */
    public static function instructions(Db $db): string
    {
        $parts = [];
        try {
            $applied = self::applied($db);
        } catch (\Throwable) {
            return ''; // a site whose database update has not run yet
        }
        foreach ($applied as $m) {
            if ($m['claude'] !== '') {
                $parts[] = self::text($m['name']) . ': ' . $m['claude'];
            }
        }

        return implode("\n", $parts);
    }

    /**
     * The current site as a blueprint manifest: the presets its collections were made from, its facts (keys, labels,
     * types – never values) and the questions, checks and instructions of its applied blueprints.
     *
     * @return array<string, mixed>
     */
    public static function export(App $app, string $key, string $name): array
    {
        $db = $app->db();
        $applied = self::applied($db);
        $facts = [];
        foreach (Facts::all($app) as $f) {
            if (!$f['builtIn']) {
                $facts[] = ['key' => $f['key'], 'label' => $f['label'], 'type' => $f['type']] + ($f['schema'] !== '' ? ['schema' => $f['schema']] : []);
            }
        }
        $manifest = ['kaleta_blueprint' => self::FORMAT, 'key' => $key, 'name' => $name, 'group' => (string) (array_values($applied)[0]['group'] ?? 'other'), 'description' => '',
            'presets' => array_values(array_filter(array_map('strval', array_column($db->all("SELECT DISTINCT preset FROM {kolekce} WHERE preset <> '' ORDER BY preset"), 'preset')), fn (string $p): bool => Presets::get($p) !== null)),
            'facts' => $facts,
            'questions' => array_merge(...array_values(array_map(fn (array $m): array => $m['questions'], $applied)) ?: [[]]),
            'audit' => array_merge(...array_values(array_map(fn (array $m): array => $m['audit'], $applied)) ?: [[]]),
            'claude' => implode("\n", array_filter(array_map(fn (array $m): string => $m['claude'], $applied)))];

        return self::sanitize($manifest)[0] ?? $manifest;
    }
}
