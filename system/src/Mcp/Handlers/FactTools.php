<?php

declare(strict_types=1);

namespace Kaleta\Mcp\Handlers;

use Kaleta\Core\Facts;
use Kaleta\Core\Language;

/**
 * MCP tools for business facts (2.10, Core\Facts): the facts, saving them, and the claims inventory – sentences that
 * state numbers as plain text, or still state the old value of a fact. Part of Mcp\Tools.
 *
 * @phpstan-ignore trait.unused
 */
trait FactTools
{
    /** list_facts */
    private function toolListFacts(string $name, array $a): mixed
    {
        $language = Language::offeredOrDefault($a['language'] ?? '');
        $usage = Facts::usage($this->app->db());

        return [
            'facts' => array_values(array_map(fn (array $f): array => ['key' => $f['key'], 'label' => $f['builtIn'] ? (Facts::BUILT_IN[$f['key']] ?? $f['label']) : $f['label'], 'type' => $f['type'], 'value' => $f['value'], 'shown_as' => $f['display'],
                'schema_property' => $f['schema'] !== '' ? $f['schema'] : null, 'source' => $f['source'] !== '' ? $f['source'] : null, 'from_settings' => $f['builtIn'],
                'used_in' => $usage[$f['key']] ?? 0], Facts::all($this->app, $language))),
            'token' => '{{fact.<key>}} in texts, buttons, links (tel:{{fact.company_phone}}) and the number of a counter; the site fills it in for visitors.',
            'computed' => ['tokens' => array_map(fn (array $c): array => ['token' => $c['token'], 'shows_now' => $c['value'] !== '' ? $c['value'] : null, 'about' => $c['about']], Facts::computedExamples($this->app)),
                'note' => 'Computed when a page is shown, so they never go stale: {{years_since:<year|YYYY-MM-DD|fact.key>}} = full years since; {{count:<collection address>}} = visible items of the collection, {{count:news}} = published news. A wrong argument shows nothing and site_audit (kind fact) reports it.'],
            'types' => array_keys(Facts::TYPES),
            'schema_properties' => array_values(array_filter(array_keys(Facts::SCHEMA_PROPS))),
        ];
    }

    /** save_fact */
    private function toolSaveFact(string $name, array $a): mixed
    {
        if (!$this->app->auth()->hasModule('business')) { // 3.2: whoever may open Business details (owner decision: editors too)
            throw new \DomainException('Facts are changed by people with access to Business details – the site states them everywhere.');
        }
        $key = mb_strtolower(trim((string) ($a['key'] ?? '')));
        $language = in_array(Language::offeredOrDefault($a['language'] ?? ''), Language::additional($this->app->settings()), true) ? (string) $a['language'] : '';
        $before = Facts::all($this->app)[$key] ?? null;
        $data = array_filter(['label' => $a['label'] ?? null, 'type' => $a['type'] ?? null, 'value' => isset($a['value']) ? (string) $a['value'] : null,
            'schema' => $a['schema_property'] ?? null, 'source' => $a['source'] ?? null], fn (mixed $v): bool => $v !== null);
        $error = Facts::save($this->app, $key, $data, $language);
        if ($error !== null) {
            throw new \DomainException($error);
        }
        $after = Facts::all($this->app, $language)[$key];
        $stillOld = $before !== null && $language === '' && $before['value'] !== '' && $before['value'] !== $after['value']
            ? Facts::occurrences($this->app->db(), $before['display'], 50) : [];

        return ['key' => $key, 'value' => $after['value'], 'shown_as' => $after['display'], 'token' => '{{fact.' . $key . '}}',
            'still_states_the_old_value' => $stillOld,
            'next' => $stillOld !== [] ? 'These sentences still state the old value as plain text: replace the value with the token {{fact.' . $key . '}} (edit_build, update_news…), so the next change updates them too.' : null];
    }

    /** delete_fact */
    private function toolDeleteFact(string $name, array $a): mixed
    {
        if (!$this->app->auth()->hasModule('business')) { // 3.2: whoever may open Business details (owner decision: editors too)
            throw new \DomainException('Facts are changed by people with access to Business details – the site states them everywhere.');
        }
        $key = (string) ($a['key'] ?? '');
        $used = Facts::occurrences($this->app->db(), '{{fact.' . $key . '}}', 50);
        if (!Facts::delete($this->app, $key)) {
            throw new \DomainException('The fact does not exist (built-in facts come from the settings). Use list_facts.');
        }

        return ['deleted' => $key, 'still_used_in' => $used];
    }

    /** find_claims */
    private function toolFindClaims(string $name, array $a): mixed
    {
        $text = trim((string) ($a['text'] ?? ''));
        $limit = max(1, min(300, (int) ($a['limit'] ?? 100)));
        $found = $text !== '' ? Facts::occurrences($this->app->db(), $text, $limit) : Facts::claims($this->app->db(), $limit);

        return ['sentences' => $found, 'count' => count($found),
            'next' => $text !== '' ? 'Sentences that state this text. Replace it with a fact token where it is the same fact.'
                : 'Sentences with years, numbers, percentages or amounts written as plain text. Ask the user which are facts; save_fact creates one, then put {{fact.key}} in place of the number.'];
    }

    /** list_hours */
    private function toolListHours(string $name, array $a): mixed
    {
        $s = $this->app->settings();
        $week = \Kaleta\Core\Hours::week($s);

        return [
            'week' => array_map(fn (array $ranges): array => array_map(fn (array $r): string => $r[0] . '-' . $r[1], $ranges), $week),
            'as_written' => $s->get('company_hours'),
            'exceptions' => \Kaleta\Core\Hours::exceptions($this->app->db(), (bool) ($a['past_too'] ?? false)),
            // proposed by a drafts-only connection (3.2): the site ignores them until a person applies them
            'proposed' => \Kaleta\Core\Hours::proposed($this->app->db()),
            'now' => \Kaleta\Core\Hours::statusText($this->app),
            'today' => \Kaleta\Core\Hours::todayText($this->app),
            'tokens' => '{{hours.status}} (open now, until when) and {{hours.today}} (today\'s hours) in texts; the Company details element shows the hours with the exceptions of the next 30 days.',
            'next' => 'The regular week is changed with update_settings (company_hours, one day or range per line). Holidays and other days: save_hours_exception.',
        ];
    }

    /**
     * save_hours_exception. A drafts-only connection (3.2) saves a proposal the site ignores until a person applies it, and
     * may change only its own proposals; with full access the exception applies at once (saving a proposal applies it).
     */
    private function toolSaveHoursException(string $name, array $a): mixed
    {
        if (!$this->app->auth()->hasModule('business')) { // 3.2: whoever may open Business details (owner decision: editors too)
            throw new \DomainException('Opening hours are changed by people with access to Business details.');
        }
        $db = $this->app->db();
        $id = (int) ($a['id'] ?? 0);
        $propose = $this->app->auth()->draftsOnly();
        if ($propose && $id > 0 && \Kaleta\Core\Hours::find($db, $id, true) === null) {
            throw new \DomainException('This connection can only propose exceptions to the opening hours, and this one is already in use on the site (or does not exist). '
                . 'Propose the change as a new exception without id – a person applies it – or write it into the note for a person to change.');
        }
        $error = \Kaleta\Core\Hours::save($this->app, ['from' => (string) ($a['from'] ?? ''), 'to' => (string) ($a['to'] ?? ''), 'closed' => ($a['hours'] ?? '') === '' || (bool) ($a['closed'] ?? false),
            'hours' => (string) ($a['hours'] ?? ''), 'note' => (string) ($a['note'] ?? ''), 'notice_days' => (int) ($a['notice_days'] ?? 7), 'proposed' => $propose], $id);
        if ($error !== null) {
            throw new \DomainException($error);
        }

        return ['exceptions' => \Kaleta\Core\Hours::exceptions($db), 'proposed' => \Kaleta\Core\Hours::proposed($db), 'now' => \Kaleta\Core\Hours::statusText($this->app)]
            + ($propose ? ['next' => 'Saved as a PROPOSAL: the site does not use it yet – not in the hours, the notice bar, the structured data or Google. '
                . 'A person applies it in the administration (the exceptions to the opening hours, “Proposed by Claude”); tell the user it waits there.'] : []);
    }

    /** delete_hours_exception */
    private function toolDeleteHoursException(string $name, array $a): mixed
    {
        if (!$this->app->auth()->hasModule('business')) { // 3.2: whoever may open Business details (owner decision: editors too)
            throw new \DomainException('Opening hours are changed by people with access to Business details.');
        }
        if (!\Kaleta\Core\Hours::delete($this->app, (int) ($a['id'] ?? 0))) {
            throw new \DomainException('No such exception. Use list_hours.');
        }

        return ['exceptions' => \Kaleta\Core\Hours::exceptions($this->app->db())];
    }
}
