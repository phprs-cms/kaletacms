<?php

declare(strict_types=1);

/* ---------- 2.11: industry blueprints (Core\Blueprint) ---------- */
use Kaleta\Core\Blueprint;

$blueprintInput = ['kaleta_blueprint' => 1, 'key' => 'dental_clinic', 'name' => ['en' => 'Dental clinic', 'cs' => 'Zubní ordinace'], 'description' => 'For dentists', 'presets' => ['people', 'events', 'people'],
    'facts' => [['key' => 'insurers', 'label' => 'Insurers', 'type' => 'text'], ['key' => 'founded', 'label' => 'Founded', 'type' => 'year', 'schema' => 'foundingDate', 'value' => 'never copied']],
    'questions' => [['question' => 'Which insurers do you have contracts with?', 'fact' => 'insurers', 'help' => 'Comma separated']],
    'audit' => [['check' => 'fact', 'fact' => 'insurers', 'message' => 'Say which insurers you work with.'], ['check' => 'preset_items', 'preset' => 'people', 'min' => 2, 'message' => 'Add the doctors.'],
        ['check' => 'setting', 'setting' => 'company_hours', 'message' => 'Fill in the opening hours.'], ['check' => 'page', 'slugs' => ['cenik', 'price-list'], 'message' => 'Publish the price list.'],
        ['check' => 'stale_items', 'preset' => 'events', 'days' => 180, 'message' => 'No events for half a year.']],
    'claude' => 'Patients look for insurers and hours first. <b>Never</b> give medical advice.', 'unknown' => 'dropped'];
[$blueprint, $blueprintErrors] = Blueprint::sanitize($blueprintInput);
check('2.11 Blueprint::sanitize – a valid manifest keeps its parts, drops duplicates, unknown keys, fact values and tags', [$blueprintErrors, $blueprint['presets'] ?? null, array_column($blueprint['facts'] ?? [], 'key'),
    isset($blueprint['facts'][1]['value']), $blueprint['facts'][1]['schema'] ?? null, count($blueprint['audit'] ?? []), $blueprint['audit'][1]['min'] ?? null, $blueprint['claude'] ?? null, isset($blueprint['unknown'])],
    [[], ['people', 'events'], ['insurers', 'founded'], false, 'foundingDate', 5, 2, 'Patients look for insurers and hours first. Never give medical advice.', false]);
$blueprintBad = static fn (array $change): array => Blueprint::sanitize(array_replace($blueprintInput, $change))[1];
check('2.11 Blueprint::sanitize – refuses what it cannot trust', [Blueprint::sanitize(['key' => 'x'])[0], $blueprintBad(['key' => 'Bad Key']) !== [], $blueprintBad(['presets' => ['shop']]) !== [],
    $blueprintBad(['questions' => [['question' => 'Q?', 'fact' => 'not_declared']]]) !== [], $blueprintBad(['audit' => [['check' => 'php', 'message' => 'x']]]) !== [],
    $blueprintBad(['audit' => [['check' => 'setting', 'setting' => 'smtp_password', 'message' => 'x']]]) !== [], $blueprintBad(['audit' => [['check' => 'stale_items', 'preset' => 'events', 'days' => 1, 'message' => 'x']]]) !== [],
    $blueprintBad(['facts' => [['key' => 'company_phone', 'label' => 'Phone']]]) !== []], [null, true, true, true, true, true, true, true]);
check('2.11 Blueprint::text – the admin language, else English, else the first', [Blueprint::text('plain'), Blueprint::text(['en' => 'Clinic', 'cs' => 'Ordinace']), Blueprint::text(['de' => 'Praxis'])],
    ['plain', Kaleta\Core\Language::code() === 'cs' ? 'Ordinace' : 'Clinic', 'Praxis']);
$shipped = glob(KALETA_ROOT . '/system/blueprints/*.json') ?: [];
check('2.11 Blueprint: every shipped blueprint is valid and named by its key', array_values(array_filter(array_map(function (string $file): string {
    [$m, $errs] = Blueprint::sanitize(json_decode((string) file_get_contents($file), true));

    return $m === null ? basename($file) . ': ' . implode(' ', $errs) : ($m['key'] !== basename($file, '.json') ? basename($file) . ': key differs' : '');
}, $shipped))), []);
// the six industry blueprints shipped with 2.11: each asks the owner something, checks the site and guides Claude, in English, Czech and German
$shippedBlueprints = Blueprint::available();
$blueprintTexts = function (array $m): array { // every text of a manifest that visitors of the administration may read
    $texts = [$m['name'], $m['description']];
    foreach ($m['facts'] as $f) {
        $texts[] = $f['label'];
    }
    foreach ($m['questions'] as $q) {
        $texts[] = $q['question'];
        $texts[] = $q['help'] ?? ['en' => 'x', 'cs' => 'x', 'de' => 'x', 'es' => 'x']; // help is optional
    }
    foreach ($m['audit'] as $r) {
        $texts[] = $r['message'];
    }

    return $texts;
};
$shippedKeys = array_keys($shippedBlueprints);
sort($shippedKeys);
check('2.11/3.3 Blueprint: the twenty industry blueprints are shipped', $shippedKeys, ['accommodation', 'agency', 'auto_service', 'beauty_wellness', 'clinic', 'craftsman', 'driving_school', 'farm', 'fitness_studio', 'it_services', 'manufacturer', 'municipality', 'nonprofit', 'photographer', 'professional_services', 'real_estate', 'restaurant', 'retail_shop', 'school_courses', 'software_saas']);
check('2.11 Blueprint: every shipped blueprint has presets, 4–8 facts, a question for each, 3–6 checks and instructions for Claude', array_values(array_filter(array_map(fn (array $m): string => $m['presets'] === [] || count($m['facts']) < 4 || count($m['facts']) > 8
    || count($m['questions']) < 1 || count(array_unique(array_column($m['questions'], 'fact'))) !== count($m['facts']) || count($m['audit']) < 3 || count($m['audit']) > 6 || mb_strlen($m['claude']) < 200 ? $m['key'] : '', $shippedBlueprints))), []);
check('2.11/3.14 Blueprint: every text of a shipped blueprint is in English, Czech, German and Spanish', array_values(array_filter(array_map(fn (array $m): string => array_filter($blueprintTexts($m),
    fn (string|array $t): bool => !is_array($t) || array_diff(['en', 'cs', 'de', 'es'], array_keys($t)) !== [] || in_array('', $t, true)) === [] ? '' : $m['key'], $shippedBlueprints))), []);
// 3.14: the same straight from the files, before sanitize() – every language object in a manifest has exactly the four admin
// languages, none empty, none the English left untranslated in Spanish (a name like "Team" may match; long texts must not)
$blueprintLanguageGaps = [];
foreach ($shipped as $file) {
    $walk = function (mixed $v, string $path) use (&$walk, &$blueprintLanguageGaps, $file): void {
        if (!is_array($v)) {
            return;
        }
        if (isset($v['en']) && is_string($v['en'])) {
            $keys = array_keys($v);
            sort($keys);
            if ($keys !== ['cs', 'de', 'en', 'es'] || in_array('', array_map(fn (mixed $t): string => is_string($t) ? trim($t) : '', $v), true)
                || (mb_strlen($v['en']) > 30 && $v['es'] === $v['en'])) {
                $blueprintLanguageGaps[] = basename($file) . $path;
            }

            return;
        }
        foreach ($v as $k => $child) {
            $walk($child, $path . '/' . $k);
        }
    };
    $walk(json_decode((string) file_get_contents($file), true), '');
}
check('3.14 Blueprint: every language object of every shipped manifest file has en, cs, de and es, all filled in and Spanish translated', $blueprintLanguageGaps, []);
check('3.14 Blueprint::text – the Spanish admin gets Spanish, a regional code its base language', [
    Kaleta\Core\Language::runWith('es', fn (): string => Blueprint::text($shippedBlueprints['farm']['name']), 'admin-'),
    Kaleta\Core\Language::runWith('es', fn (): string => Blueprint::text(['en' => 'Clinic', 'es' => 'Clínica']), 'admin-'),
    Kaleta\Core\Language::runWith('de', fn (): string => Blueprint::text($shippedBlueprints['farm']['name']), 'admin-')], ['Granja con tienda', 'Clínica', 'Hof mit Hofladen']);
check('2.11 Blueprint: shipped blueprints define no built-in fact and give no fact a value', array_values(array_filter(array_map(fn (array $m): string => array_filter($m['facts'],
    fn (array $f): bool => isset(Kaleta\Core\Facts::BUILT_IN[$f['key']]) || isset($f['value'])) === [] ? '' : $m['key'], $shippedBlueprints))), []);
check('3.3 Blueprint: every shipped blueprint sits in a group of the Blueprints screen (not "other")', array_values(array_filter(array_map(fn (array $m): string => in_array($m['group'], ['', 'other'], true) || !isset(Blueprint::GROUPS[$m['group']]) ? $m['key'] : '', $shippedBlueprints))), []);
check('3.3 Blueprint::sanitize – the group is kept when known, "other" otherwise (a manifest from before 3.3 has none)', [
    Blueprint::sanitize(['group' => 'tech'] + $blueprintInput)[0]['group'] ?? null, Blueprint::sanitize(['group' => 'spaceships'] + $blueprintInput)[0]['group'] ?? null, Blueprint::sanitize($blueprintInput)[0]['group'] ?? null], ['tech', 'other', 'other']);
check('3.3 Prompt draft_blueprint: works without an argument, names the business when given, applies only after the owner agrees', [
    str_contains(Kaleta\Mcp\Prompts::get('draft_blueprint', [])['messages'][0]['content']['text'], 'kind of business. (1)'),
    str_contains(Kaleta\Mcp\Prompts::get('draft_blueprint', ['business' => 'a bike shop'])['messages'][0]['content']['text'], 'kind of business: a bike shop.'),
    str_contains(Kaleta\Mcp\Prompts::get('draft_blueprint', [])['messages'][0]['content']['text'], 'only after I agree')], [true, true, true]);
$plansPreset = (array) Kaleta\Builder\Presets::get('plans');
$plansFields = Kaleta\Builder\Collections::sanitizeFields(array_map(fn (array $f): array => ['klic' => $f[0], 'popisek' => $f[1], 'typ' => $f[2]], $plansPreset['fields']));
check('3.3.1 Presets: each card of the pricing plans has a button to the plan\'s link', str_contains((string) json_encode(Kaleta\Builder\Presets::listPage($plansPreset, 'Plans', 'plans', $plansFields)), '{{link}}'), true);
check('3.3 Presets: pricing plans, a food and drink menu, rooms and property listings are offered', array_values(array_diff(['plans', 'menu', 'rooms', 'properties'], array_keys(Kaleta\Builder\Presets::all()))), []);
