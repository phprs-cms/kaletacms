<?php

declare(strict_types=1);

namespace Kaleta\Admin;

use Kaleta\Core\Auth;
use Kaleta\Core\Response;

/**
 * 3.46 (N127, N136, N158): ONE default-deny rule for the administrator's sections. Every POST to a module only the
 * administrator may open (Module::ADMIN_ONLY – Settings and its screens, Users, Roles, Integrations, Add-ons, Import and
 * export, the console…) needs a recent proof of who is at the keyboard (Auth::freshProof 'recent': a sign-in, a confirmed
 * password or a passkey within Auth::VERIFIED_WINDOW, otherwise the current password typed now; an account that signs in
 * with a passkey only needs its passkey). A new admin-only module or action is covered without a line of code here;
 * what may go without the proof is listed by name in EXEMPT – the one place to read and to review.
 *
 * Without the proof the Kernel answers with "Confirm it is you" (views/admin/confirm.php): the form posted again with the
 * same fields and the password field or the passkey button, so nothing typed is lost and nothing happens before the proof.
 * A request a script sent (JSON) gets the message as JSON. The checks a module makes on top (Users, Roles, Settings →
 * Security) stay as they are.
 *
 * Outside this rule, with their own proof: My account (Admin\Account – e-mail, password, tokens, two-factor, passkeys) and
 * the OAuth consent of every new connection (Kernel::handleOAuthConsent).
 */
final class FreshProof
{
    /**
     * The POST actions of admin-only modules that need no recent proof, by module and action ('*' = every action of the
     * module). Each entry says why: work on the site's content and look that a draft, a version or the change log brings
     * back; reading or checking that changes nothing; continuing a batch a proven request started; less access, never more.
     * A unit test fails for an entry naming a module or an action that does not exist.
     */
    public const array EXEMPT = [
        // the site's look: draft look, versions and the live preview (a request of image/admin.js while typing)
        'appearance' => ['*'],
        // redirects of the site's addresses – content, kept in the change log
        'redirects' => ['*'],
        // industry blueprints write content (facts, pages, items) the editor reviews
        'blueprints' => ['*'],
        // undoing what a Claude session changed in the content
        'changelog' => ['undo'],
        // Settings and its screens: checks, previews and drafts that send nothing to a new place and weaken nothing
        'settings' => self::SETTINGS_EXEMPT,
        'status' => self::SETTINGS_EXEMPT,
        'extensions' => self::SETTINGS_EXEMPT,
        'claude_settings' => [...self::SETTINGS_EXEMPT, 'instructions_seen'], // and the marker that the instructions were read
        // Import and export: the batches of an import, its images and the report of the old site continue what a proven
        // request started (each submission is one batch, a run lasts longer than the window)
        'transfer' => ['progress', 'web_progress', 'report', 'source_progress', 'kaleta', 'images', 'source_images'],
        // Integrations: reading the lists of an existing connection, syncing it, disconnecting
        'connectors' => ['properties', 'gbp_locations', 'gbp_sync', 'disconnect'],
        // Users: revoking a person's connections takes access away
        'users' => ['revoke_connection'],
        // the console: acknowledging checks, customers' names and notes, pinging a site, the calendar and reports
        'fleet' => ['accept', 'unaccept', 'customer', 'customer_bulk', 'customer_rename', 'check', 'ring', 'calendar_save', 'report_build'],
    ];

    /**
     * Settings (and Status, Features, Claude settings, which share its actions): an update check, a domain check, the
     * cookie scan, a test e-mail or webhook to the address already set, the report preview, a new backup on this server and
     * its copy to the target already set (the public media, and – 3.46 – the private folders, each file encrypted under the
     * recovery key, never before it is saved), the accessibility statement as a hidden draft, the opening hours exceptions
     * (open to Business details too). Saving the backup target or backup_remote_days, restoring, deleting and downloading a
     * backup are not here: they need the proof.
     */
    private const array SETTINGS_EXEMPT = ['check', 'domain_check', 'cookie_scan', 'test_mail', 'test_webhook', 'retry_webhook',
        'report_preview', 'report_rebuild', 'backup', 'media_backup', 'accessibility_statement',
        'hours_add', 'hours_delete', 'hours_apply', 'hours_discard'];

    /**
     * Review L3: the administrator-only actions of sections that are not the administrator's alone – they need the proof too
     * (by module and action): who reads the whistleblowing channel and its settings, which internal folders exist and who
     * sees them, the erasure of a person's data (a restore never brings it back), the retention periods of enquiries and
     * (3.46.1) the settings of Applications. A page's own head code asks in Pages::actionSave() when it changes (3.46.1, re-audit
     * M3). A unit test fails for an entry naming a module or an action that does not exist.
     */
    public const array ADMIN_ACTIONS = [
        'whistleblowing' => ['settings'],
        'internal_files' => ['folder_save', 'folder_delete'],
        'enquiries' => ['personal', 'settings'],
        // 3.46.1 (re-audit L4): the administrator's settings of Applications – their webhook, Claude's access and the retention
        'applications' => ['settings_save'],
    ];

    /** The field of the forms that carries the current password (the same as My account, Users and the OAuth consent). */
    public const string FIELD = 'soucasne';

    /** Does a POST of $action in the module $ident need the recent proof? Pure: only admin-only modules, minus EXEMPT. */
    public static function required(string $ident, bool $adminOnly, string $action): bool
    {
        if (!$adminOnly) {
            return in_array($action, self::ADMIN_ACTIONS[$ident] ?? [], true);
        }
        $exempt = self::EXEMPT[$ident] ?? [];

        return !in_array('*', $exempt, true) && !in_array($action === '' ? 'list' : $action, $exempt, true);
    }

    /**
     * Review L4: an exempt action that needs the proof right now – "Create backup now" again within Backup::MANUAL_PAUSE of
     * the last manual backup (ten clicks from a held session would push every earlier backup out of the rotation).
     */
    public static function requiredNow(string $ident, string $action): bool
    {
        return $action === 'backup' && in_array('backup', self::EXEMPT[$ident] ?? [], true) && \Kaleta\Core\Backup::recentManual();
    }

    /**
     * Why the request may not go on now – null when the proof is there: a proof within the window, or the right current
     * password typed in the field (Auth::confirmPassword – the sign-in's counter and lock, and a fresh proof for the next
     * minutes). Nothing typed is no attempt.
     */
    public static function refusal(Auth $auth): ?string
    {
        return match ($auth->freshProof(is_string($_POST[self::FIELD] ?? null) ? $_POST[self::FIELD] : '', 'recent')) {
            'ok' => null,
            'passkey' => self::PASSKEY,
            'locked' => Auth::CONFIRM_LOCKED,
            default => ($_POST[self::FIELD] ?? '') === '' ? self::NEEDED : self::WRONG,
        };
    }

    public const string NEEDED = 'This change needs a recent confirmation that it is you – you signed in or confirmed more than 10 minutes ago. Enter your current password to continue. Nothing has been changed yet.';

    public const string WRONG = 'The password is not correct. Nothing has been changed – try again.';

    public const string PASSKEY = 'This change needs a recent confirmation that it is you. You sign in with a passkey only: confirm with your passkey to continue. Nothing has been changed yet.';

    /**
     * The answer without the proof: JSON for a request of a script, otherwise "Confirm it is you" with the posted form.
     * Uploaded files cannot be posted again: then the confirmation alone goes to admin.php?action=confirm and the person
     * chooses the file again.
     */
    public static function page(Kernel $kernel, string $reason): Response
    {
        $app = $kernel->app;
        if (self::wantsJson()) {
            return Response::json(['ok' => false, 'chyba' => t($reason), 'proof' => true], 403);
        }
        $query = array_filter($_GET, is_string(...));
        $target = $app->url('admin.php' . ($query !== [] ? '?' . http_build_query($query) : ''));
        $back = $app->url('admin.php' . (isset($query['module']) ? '?' . http_build_query(['module' => $query['module']] + (isset($query['tab']) ? ['tab' => $query['tab']] : [])) : ''));
        $files = array_filter($_FILES, static fn ($f): bool => is_array($f) && ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE);
        $siteUrl = $app->settings()->get('site_url') ?: $app->request->origin();
        $html = $app->view->render('admin/confirm', [
            'app' => $app, 'csrf' => $app->session->csrfField(), 'reason' => $reason,
            'action' => $files !== [] ? $app->url('admin.php?action=confirm&' . http_build_query(['back' => self::localBack($back)])) : $target,
            'fields' => $files !== [] ? [] : self::hiddenFields($_POST), 'files' => $files !== [], 'back' => $back,
            'passkeyOnly' => $app->auth()->isPasskeyOnly(),
            'passkeyConfirm' => $app->auth()->workingKeys($app->auth()->id()) !== [] && \Kaleta\Core\SignInPolicy::hostMatches((string) ($app->request->serverValues()['HTTP_HOST'] ?? ''), $siteUrl),
        ]);

        return $kernel->page('Confirm it is you', $html, 403);
    }

    /**
     * admin.php?action=confirm: the proof alone (after a form with files), then back to the section – the person submits
     * the form again within the window.
     */
    public static function confirm(Kernel $kernel): Response
    {
        $app = $kernel->app;
        $back = self::localBack($app->request->get('back'));
        if (!$app->request->isPost()) {
            return Response::redirect($app->url($back));
        }
        $reason = self::refusal($app->auth());
        if ($reason !== null) {
            return self::page($kernel, $reason);
        }
        $app->session->flash('info', t('Confirmed. Submit the form again within 10 minutes – choose the file again.'));

        return Response::redirect($app->url($back));
    }

    /** Only an address of the administration is a way back (never another site, never a scheme). Pure. */
    public static function localBack(string $back): string
    {
        $back = (string) preg_replace('#^.*?(admin\.php)#', '$1', $back);

        return preg_match('#^admin\.php(\?[A-Za-z0-9_=&%.\-]*)?$#D', $back) === 1 ? $back : 'admin.php';
    }

    /**
     * The posted fields as name => value pairs for hidden inputs (arrays as name[key]), without the CSRF token (the page
     * writes a current one) and without the password field. Pure.
     *
     * @param array<array-key, mixed> $post
     * @return list<array{0: string, 1: string}>
     */
    public static function hiddenFields(array $post, string $prefix = ''): array
    {
        $out = [];
        foreach ($post as $key => $value) {
            $name = $prefix === '' ? (string) $key : $prefix . '[' . $key . ']';
            if ($prefix === '' && in_array($name, ['_csrf', self::FIELD], true)) {
                continue;
            }
            if (is_array($value)) {
                array_push($out, ...self::hiddenFields($value, $name));
            } elseif (is_scalar($value)) {
                $out[] = [$name, (string) $value];
            }
        }

        return $out;
    }

    /** Did a script send the request (it asks for JSON)? Then the refusal is JSON too. */
    private static function wantsJson(): bool
    {
        return str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
            || ($_GET['format'] ?? '') === 'json' || ($_POST['format'] ?? '') === 'json';
    }
}
