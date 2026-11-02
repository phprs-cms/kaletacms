<?php

declare(strict_types=1);

namespace Kaleta\Admin\Modules;

use Kaleta\Admin\Module;
use Kaleta\Core\Facts as FactStore;
use Kaleta\Core\Language;
use Kaleta\Core\Response;

/**
 * Business facts (2.10, Core\Facts): the facts the site states, where each is used, the sentences that still state an
 * old value after a change, and the claims inventory – sentences with numbers and years that are not facts yet.
 */
final class Facts extends Module
{
    public const string IDENT = 'facts';
    public const string SHARES_PERMISSION_OF = 'business';
    public const string HUB = 'business';
    public const string PARENT = 'business';
    public const string NAME = 'Facts';
    public const string GROUP = 'Content'; // 3.25: Business details moved from the Company group (gone) to Content
    public const string ICON = 'fakta';
    public const bool ADMIN_ONLY = false;

    protected function actionList(): Response
    {
        return $this->view('list', 'Facts', ['facts' => FactStore::all($this->app), 'usage' => FactStore::usage($this->db), 'computed' => FactStore::computedExamples($this->app)]);
    }

    protected function actionEdit(): Response
    {
        $key = $this->request->get('key');
        $facts = FactStore::all($this->app);
        $fact = $facts[$key] ?? null;
        if ($key !== '' && ($fact === null || $fact['builtIn'])) {
            return $this->back($fact !== null ? 'This fact comes from the settings (Business details) – change it there.' : 'The fact does not exist.', '', [], 'chyba');
        }
        $old = $this->app->session->get('fact_old_value');
        $this->app->session->set('fact_old_value', null);
        $translations = $fact !== null ? $this->db->pairs("SELECT language, value FROM {facts} WHERE fact_key = ? AND language <> ''", [$key]) : [];

        return $this->view('edit', $fact !== null ? (string) $fact['label'] : 'New fact', [
            'fact' => $fact, 'languages' => Language::additional($this->app->settings()), 'translations' => $translations,
            'history' => $fact !== null ? FactStore::history($this->db, $key) : [],
            'oldValue' => is_string($old) ? $old : '', 'stillOld' => is_string($old) && $old !== '' ? FactStore::occurrences($this->db, $old, 50) : [],
            'used' => $fact !== null ? FactStore::occurrences($this->db, '{{fact.' . $key . '}}', 50) : [],
        ]);
    }

    protected function actionSave(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        $key = mb_strtolower(trim($this->request->post('key')));
        $before = FactStore::all($this->app)[$key] ?? null;
        $error = FactStore::save($this->app, $key, ['label' => $this->request->post('label'), 'type' => $this->request->post('type'), 'value' => $this->request->post('value'),
            'schema' => $this->request->post('schema'), 'source' => $this->request->post('source')]);
        foreach (Language::additional($this->app->settings()) as $language) {
            if ($error === null && $this->request->post('value_' . $language) !== '') {
                $error = FactStore::save($this->app, $key, ['value' => $this->request->post('value_' . $language)], $language);
            } elseif ($error === null) {
                $this->db->delete('facts', ['fact_key' => $key, 'language' => $language]); // empty = the default value
            }
        }
        if ($error !== null) {
            return $this->back($error, 'edit', $before !== null ? ['key' => $key] : [], 'chyba');
        }
        $after = FactStore::all($this->app)[$key] ?? null;
        if ($before !== null && $after !== null && $before['value'] !== $after['value'] && $before['value'] !== '') {
            // the claims inventory: where does the site still state the old value as plain text?
            $this->app->session->set('fact_old_value', $before['display']);
        }

        return $this->back('The fact is saved.', 'edit', ['key' => $key]);
    }

    protected function actionDelete(): Response
    {
        $key = $this->request->post('key');
        if ($this->request->isPost() && FactStore::delete($this->app, $key)) {
            $used = FactStore::occurrences($this->db, '{{fact.' . $key . '}}', 1);

            return $this->back($used !== [] ? 'The fact is deleted – some content still uses it and shows nothing there now; the site audit lists it.' : 'The fact is deleted.');
        }

        return $this->back();
    }

    protected function actionClaims(): Response
    {
        return $this->view('claims', 'Facts', ['claims' => FactStore::claims($this->db, 300)]);
    }
}
