<?php

declare(strict_types=1);

namespace Kaleta\Admin\Modules;

use Kaleta\Admin\Module;
use Kaleta\Core\Blueprint;
use Kaleta\Core\Facts;
use Kaleta\Core\Response;

/**
 * Industry blueprints (2.11, Core\Blueprint): apply one shipped with Kaleta or a manifest from another site, answer its
 * questions (each answer is a fact), see its failing checks, take it off, and download the current site as a manifest.
 */
final class Blueprints extends Module
{
    public const string IDENT = 'blueprints';
    public const string HUB = 'business';
    public const string PARENT = 'business';
    public const string NAME = 'Blueprints';
    public const string GROUP = 'Content'; // 3.25: Business details moved from the Company group (gone) to Content
    public const string ICON = 'sablony';
    public const bool ADMIN_ONLY = true;

    protected function actionList(): Response
    {
        $applied = Blueprint::applied($this->db);

        return $this->view('list', 'Blueprints', ['applied' => $applied, 'available' => array_diff_key(Blueprint::available(), $applied),
            'questions' => Blueprint::questions($this->app), 'findings' => Blueprint::findings($this->app)]);
    }

    /** Applies a shipped blueprint (key) or an uploaded manifest (file). */
    protected function actionApply(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        $file = $_FILES['manifest'] ?? null;
        if (is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && is_uploaded_file((string) $file['tmp_name']) && (int) $file['size'] <= 512 * 1024) {
            [$manifest, $errors] = Blueprint::sanitize(json_decode((string) file_get_contents((string) $file['tmp_name']), true));
            if ($manifest === null) {
                return $this->back(t('The file is not a valid blueprint: %s', implode(' ', $errors)), '', [], 'chyba');
            }
        } else {
            $manifest = Blueprint::available()[$this->request->post('key')] ?? null;
            if ($manifest === null) {
                return $this->back('Choose a blueprint or a file.', '', [], 'chyba');
            }
        }
        $created = Blueprint::apply($this->app, $manifest);

        return $this->back(t('The blueprint is applied: %d new collections (with hidden list pages) and %d facts. Answer its questions below.', count($created['collections']), count($created['facts'])));
    }

    protected function actionRemove(): Response
    {
        if ($this->request->isPost() && Blueprint::remove($this->app, $this->request->post('key'))) {
            return $this->back('The blueprint is removed – its collections and facts stay.');
        }

        return $this->back();
    }

    /** The answers to the questions: each into its fact (the fact's type checks the value). */
    protected function actionAnswers(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        $answers = is_array($_POST['answer'] ?? null) ? $_POST['answer'] : [];
        $asked = array_column(Blueprint::questions($this->app), 'fact', 'fact');
        foreach ($answers as $fact => $value) {
            if (!is_string($fact) || !isset($asked[$fact]) || !is_string($value)) {
                continue;
            }
            $error = Facts::save($this->app, $fact, ['value' => trim($value)]);
            if ($error !== null) {
                return $this->back(t($error) . ' (' . $fact . ')', '', [], 'chyba');
            }
        }

        return $this->back('The answers are saved – the site states them as facts.');
    }

    /** The current site as a blueprint manifest (JSON download). */
    protected function actionExport(): Response
    {
        $key = $this->request->get('key');
        if (preg_match(Blueprint::KEY_PATTERN, $key) !== 1) {
            return $this->back('The key may contain lowercase letters, digits and _ (2–40 characters).', '', [], 'chyba');
        }
        $manifest = Blueprint::export($this->app, $key, mb_substr(trim($this->request->get('name')) ?: $key, 0, 100));

        return new Response((string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 200,
            ['Content-Type' => 'application/json; charset=utf-8', 'Content-Disposition' => 'attachment; filename="' . $key . '.blueprint.json"']);
    }
}
