<?php

declare(strict_types=1);

namespace Kaleta\Admin;

use Kaleta\Core\App;
use Kaleta\Core\Migration;
use Kaleta\Core\Response;
use Kaleta\Core\Extensions;

/**
 * Administration. URLs: admin.php?module=<ident>&action=<action>
 */
final class Kernel
{
    /**
     * Modules whose actions write their own change-log row under another area (3.23.1, N3231-2): an exception to the opening
     * hours in Business details is a 'settings' row (Core\Hours), a menu published from Menu or an import an 'appearance' row
     * (Core\Look), the accessibility statement made in Settings and the listing page published from a collection a 'pages' row
     * (Core\Privacy, Collections::actionPublishPage) – the generic row would repeat it.
     */
    private const array LOGS_AS = ['business' => ['settings'], 'menu' => ['appearance'], 'transfer' => ['appearance'], 'settings' => ['pages'], 'collections' => ['pages']];

    /**
     * Modules in the order they appear in the menu, by group (3.25): Content, Workspace, Appearance, Claude, Site care,
     * Administration – the modules of one group stay together (Admin\MenuGroups, a unit test checks it). Dashboard and
     * My work come first in the layout.
     *
     * @var list<class-string<Module>>
     */
    public const array MODULES = [
        Modules\MyWork::class, // 3.24: the layout puts it first for everyone but administrators (after the Dashboard for them)
        Modules\Pages::class,
        Modules\News::class,
        Modules\Collections::class,
        Modules\Media::class,
        Modules\Newsletters::class, // 3.25: the Newsletter hub – Newsletters · Subscribers
        Modules\Subscribers::class,
        Modules\Categories::class,
        Modules\Tags::class,
        Modules\Business::class, // 3.25: moved from the Company group
        Modules\Facts::class,
        Modules\Blueprints::class,
        Modules\Enquiries::class, // 3.25: the Workspace group (formerly Customers) – the daily inboxes
        Modules\Contacts::class, // 3.28: Customers – the contacts and companies the enquiries, bookings and subscriptions come from
        Modules\Pipeline::class, // 3.31: Pipeline – the deals of the customers, right after Customers
        Modules\Quotes::class, // 3.38: quotes and internal prices, after the Pipeline (the feature "quotes", given only explicitly)
        Modules\Applications::class, // 3.34: job applications apart from enquiries (the feature "applications", given only explicitly)
        Modules\Bookings::class,
        Modules\Appearance::class,
        Modules\SiteParts::class,
        Modules\Menu::class,
        Modules\Components::class,
        Modules\Popups::class,
        Modules\Requests::class,
        Modules\ClaudeSettings::class,
        Modules\Schedules::class,
        Modules\Notebook::class,
        Modules\Audit::class,
        Modules\Stats::class, // 3.25: website traffic, moved from Customers to Site care
        Modules\Redirects::class,
        Modules\BrokenLinks::class, // 3.20 (C20): the site-wide link check next to Redirects; News keeps its shortcut
        Modules\ChangeLog::class,
        Modules\Status::class,
        Modules\Users::class,
        Modules\Roles::class,
        Modules\Settings::class,
        Modules\Connectors::class,
        Modules\Extensions::class,
        Modules\Addons::class,
        Modules\Transfer::class,
        Modules\Whistleblowing::class,
        Modules\Fleet::class,
    ];

    /**
     * Requests of the editors' ongoing work (unsaved state, assistant, build draft): they do not change the site, so they
     * neither clear the page cache nor publish waiting files (admin.php, Core\MediaStaging).
     */
    public const array DRAFT_ACTIONS = ['draft', 'assistant', 'build_save', 'preview', 'build_ai_text'];

    /**
     * 3.15 (N315-5): the sections whose changes can put content on the site (pages, news, collections, the look, settings,
     * the author box of My account…). Only their changes look for waiting files published content now uses (admin.php,
     * Core\MediaStaging::promotePublished) – a save in Enquiries or Users never pays for that scan.
     */
    public const array PUBLISHING_MODULES = ['pages', 'news', 'collections', 'categories', 'tags', 'parts', 'popups', 'components', 'menu',
        'appearance', 'business', 'settings', 'facts', 'bookings', 'newsletters', 'media', 'blueprints', 'transfer', 'extensions', 'changelog', 'users'];

    /**
     * 3.24 (T1): the page cache while a team works. Every POST of the admin used to empty the whole page cache, so with a few
     * people answering enquiries the site ran uncached all day. A POST now clears it only when it can change what visitors
     * see. Every section is in exactly one of the two lists below – tools/unit-tests.php checks it, so a new section has to
     * choose – and anything not classified (an unknown section, an add-on page) clears, as before.
     *
     * The sections whose changes never reach the site: enquiries, subscribers, statistics, requests to Claude and their
     * schedules, the notebook, the audit and the link check, roles, newsletters (an e-mail, no page on the site), the
     * fleet console (it publishes to other sites), the customers (3.28 – the site never reads contacts), the pipeline
     * (3.31 – nor deals), the job applications (3.34 – nor applications) and quotes with internal prices (3.38 – no page reads them).
     */
    public const array SITE_NEUTRAL_MODULES = ['my_work', 'enquiries', 'subscribers', 'stats', 'requests', 'schedules', 'notebook', 'audit', 'broken_links', 'roles',
        'newsletters', 'fleet', 'contacts', 'pipeline', 'applications', 'quotes'];

    /** The sections whose changes can alter the site: their POSTs clear the page cache, except the SITE_NEUTRAL_ACTIONS. */
    public const array SITE_CHANGING_MODULES = ['pages', 'news', 'collections', 'media', 'categories', 'tags', 'business', 'facts', 'blueprints', 'bookings',
        'appearance', 'parts', 'menu', 'components', 'popups', 'claude_settings', 'redirects', 'changelog', 'status', 'users', 'settings', 'connectors',
        'extensions', 'addons', 'transfer', 'whistleblowing'];

    /** The builder's requests that change no published content: a share link, a resolved comment, the saved-sections library. */
    private const array BUILDER_NEUTRAL = ['build_share', 'build_comment_resolve', 'build_save_section', 'build_delete_section'];

    /**
     * The actions of Settings (and of the screens that share its class, System status and Claude settings) that change
     * nothing a visitor sees: backups, the vault, checks, the console pairing, test e-mails and webhooks, reports, logs. Not
     * here: the time zone (dates on pages), a new signing key (the signed forms of cached pages), restoring a backup,
     * updates, the opening hours, the accessibility statement and the cookie scan (the cookies it finds are rows of the
     * {{cookie_table}} on the cookie policy page, Core\Privacy::cookieTable).
     */
    private const array SETTINGS_NEUTRAL = ['backup', 'download_backup', 'vault_move', 'config_permissions', 'user_ini', 'delete_backup', 'fleet_pair', 'fleet_send',
        'fleet_updates', 'fleet_unpair', 'fleet_kit', 'fleet_titles', 'firewall_unblock', 'delete_log', 'check', 'forget_updates', 'media_backup', 'test_mail',
        'report_preview', 'report_send', 'report_view', 'report_download', 'report_rebuild', 'domain_check', 'test_webhook', 'retry_webhook',
        'new_webhook_secret', 'processing_record', 'backup_key', 'backup_key_download', 'backup_key_confirm', 'backup_key_new', 'backup_key_use']; // 3.28: the recovery key

    /**
     * In a section of SITE_CHANGING_MODULES, the actions that never reach the site ('' = the screens outside a section:
     * signing out, consent to an app, hiding First steps, the notifications behind the bell (3.26); My account is decided by
     * its form, see clearsPageCache). Each is a real action of its section (tools/unit-tests.php).
     */
    public const array SITE_NEUTRAL_ACTIONS = [
        '' => ['logout', 'oauth', 'hide_first_steps', 'token', 'inbox'],
        'pages' => [...self::BUILDER_NEUTRAL, 'export', 'owner', 'comment', 'task'], // 3.24: the responsible person is never on the site; 3.26: nor the team's comments; 3.27: nor its tasks
        'news' => ['social_save', 'social_posted', 'social_suggest', 'links'], // the social post drafts are never on the site
        'collections' => [...self::BUILDER_NEUTRAL, 'signature', 'comment', 'task'], // 3.26: the team's comments on an item; 3.27: its tasks
        'components' => self::BUILDER_NEUTRAL,
        'popups' => self::BUILDER_NEUTRAL,
        'parts' => self::BUILDER_NEUTRAL,
        'bookings' => ['anonymise'], // the person is removed, the booking keeps its time – the free times on the site stay
        'redirects' => ['ignore', 'ignore_all', 'clear'], // the list of addresses not found
        'users' => ['password_link', 'reactivate', 'revoke_connection'], // a saved or deleted account changes author names
        'whistleblowing' => ['detail', 'reply', 'status', 'attachment'], // the reports; the channel's setup and footer link are public
        'transfer' => ['report_start', 'report', 'report_delete', 'export', 'download', 'delete_export'], // reading the old site, exports
        'settings' => self::SETTINGS_NEUTRAL,
        'status' => [...self::SETTINGS_NEUTRAL, 'save'], // its form saves the System status tab only
        'claude_settings' => [...self::SETTINGS_NEUTRAL, 'save', 'instructions_seen'], // the instructions and guardrails of Claude
    ];

    /** The Settings tabs no visitor sees; a save of any other tab (or of the old single General form) clears the page cache. */
    public const array SITE_NEUTRAL_TABS = ['mail', 'reports', 'webhooks', 'backups', 'security', 'console', 'health'];

    /** Does this admin POST clear the page cache? Yes unless it is known to change nothing visitors see (3.24, T1). */
    public static function clearsPageCache(\Kaleta\Core\Request $request): bool
    {
        $module = $request->get('module');
        $action = $request->get('action');
        if (in_array($action, self::DRAFT_ACTIONS, true) || in_array($module, self::SITE_NEUTRAL_MODULES, true)
            || in_array($action, self::SITE_NEUTRAL_ACTIONS[$module] ?? [], true)) {
            return false;
        }
        if ($module === '' && $action === 'account') {
            return $request->post('co') === 'profil'; // the name, photo and bio are the author box on the site; passwords, 2FA and tokens are not
        }
        if ($module === 'settings' && $action === 'save') {
            return !in_array($request->post('tab'), self::SITE_NEUTRAL_TABS, true);
        }

        return true;
    }

    public function __construct(public readonly App $app)
    {
    }

    public function handle(): Response
    {
        $app = $this->app;
        $request = $app->request;
        \Kaleta\Extension\Registry::boot($app); // add-ons (3.0): their admin pages and tools

        // admin language: the user's choice (My account); the sign-in page follows the site language. It is set first so that even the message about an expired form is translated
        // (a regional site language such as de-at gives the administration of its language)
        $language = \Kaleta\Core\Language::base((string) ($app->auth()->user()['jazyk'] ?? '') ?: \Kaleta\Core\Language::defaults($app->settings()));
        \Kaleta\Core\Language::setAdminRegister((string) ($app->auth()->user()['register'] ?? ''));
        \Kaleta\Core\Language::setSiteRegister($app->settings()->get('german_register'));
        \Kaleta\Core\Language::set(isset(\Kaleta\Core\Language::ADMIN_LANGUAGES[$language]) ? $language : 'cs', 'admin-');
        if ($request->isPost() && !$app->session->csrfValid($request)) {
            return $this->page('Invalid request', $app->view->render('admin/error', [
                'text' => 'The form has expired. Go back, reload the page and submit it again.',
            ]), 400);
        }

        // a change in the admin that can reach the site invalidates the page cache; the editors' ongoing requests (unsaved state,
        // assistant, build draft) and the team's work that never reaches the site (enquiries, notes, requests…, 3.24) do not –
        // otherwise the cache would be cold all the time during work. Signing in changes nothing either.
        if ($request->isPost() && $app->auth()->user() !== null && self::clearsPageCache($request)) {
            \Kaleta\Front\Cache::clear();
        }
        $action = $request->get('action');
        // site URL: older installs do not have it yet - it is written from the URL the signed-in administrator works on
        if ($app->settings()->get('site_url') === '' && $app->auth()->isAdmin()) {
            $app->settings()->set('site_url', $request->origin());
        }
        $request->setOrigin($app->settings()->get('site_url'));
        $app->applyTimezone();
        \Kaleta\Core\Collation::setLanguage(\Kaleta\Core\Language::defaults($app->settings())); // lists sort in the site's alphabet (3.12)
        if ($app->auth()->user() === null) {
            return $action === 'password' ? (new PasswordReset($app))->handle() : $this->login();
        }
        if ($action === 'password' && ($request->get('token') !== '' || $request->post('token') !== '')) {
            // a link from an invitation or a password e-mail opened where someone is signed in (an administrator trying the
            // invitation, a shared computer): it still sets the password, and that signs this browser out (PasswordReset)
            return (new PasswordReset($app))->handle();
        }
        if ($action === 'logout' && $request->isPost()) {
            $app->auth()->logout();

            return Response::redirect($app->url('admin.php'));
        }

        // after moving to a new version, clean up the known removed files once (see Updater::REMOVED_FILES)
        $newVersion = $app->auth()->isAdmin() && $app->settings()->get('cleaned_version') !== KALETA_VERSION;
        if ($newVersion) {
            \Kaleta\Core\Updater::cleanUpRemoved(KALETA_ROOT);
            $app->settings()->set('cleaned_version', KALETA_VERSION);
        }

        // update of the database structure after a new system version is uploaded
        if ($app->auth()->isAdmin() && Migration::pending($app->settings(), Migration::latest())) {
            try {
                foreach (Migration::apply($app->db(), $app->settings()) as $migration) {
                    $app->session->flash('info', t('The database has been updated: %s', $migration));
                }
            } catch (\Throwable $e) {
                // the admin must stay usable so that a fix can be installed (Nastavení → Zálohy a aktualizace, Settings → Backups and updates)
                Migration::writeError($e);
                $app->session->flash('chyba', t('The database update failed: %s. The site keeps running; install the fix in Settings → Backups and updates, or write to info@kaletacms.com.', $e->getMessage()));
            }
        }

        // 3.39 (N106): the first administrator request of a new version seals the secrets still in plain text (the daily
        // security task does it too) – after the migrations, which widen the TOTP column (0126)
        if ($newVersion && !Migration::pending($app->settings(), Migration::latest())) {
            \Kaleta\Core\Vault::sealStored($app->db(), $app->settings());
        }

        if ($app->auth()->isAdmin() && !\Kaleta\Core\Demo::active()) {
            \Kaleta\Core\Backup::createAutomatic($app->db(), $app->settings());
        }
        // T1 (Q5): the trash (30 days) is emptied by the daily background job 'trash' (Core\Scheduler), not on every admin request

        // 3.34 review low (f): only someone who may open Applications changes a Form's Purpose (Builder\Build::sanitize, the schema)
        \Kaleta\Builder\Build::$purposeAllowed = $app->auth()->hasModule(\Kaleta\Core\Applications::SECTION);
        $ident = $request->get('module');
        if (\Kaleta\Core\Demo::active() && \Kaleta\Core\Demo::blocksAdmin($ident, $action, $request->post('tab') ?: $request->get('tab'), $request->isPost())) {
            $app->session->flash('chyba', \Kaleta\Core\Demo::refusal());

            return Response::redirect($app->url('admin.php' . ($ident !== '' ? '?module=' . rawurlencode($ident) : '')));
        }
        // mandatory two-factor sign-in: whoever does not have it yet can only go to My account (and sign out) until they enable it
        if ($app->auth()->isMissingRequired2fa($app->settings()) && !in_array($action, ['account', 'token'], true)) {
            $app->session->flash('chyba', t('This site requires two-factor sign-in. Please turn it on below – until then the administration is locked.'));

            return Response::redirect($app->url('admin.php?action=account'));
        }
        if (\Kaleta\Core\SignInPolicy::escapeHatch() && $app->auth()->isAdmin() && !$request->isPost()) {
            // 3.35 review L1: the escape hatch takes the passkey rules away for administrators too – said on every page
            $app->session->flash('chyba', t(self::ESCAPE_HATCH_WARNING, "define('" . \Kaleta\Core\SignInPolicy::ESCAPE_HATCH . "', true);"));
        }
        if ($action === 'token') {
            // after a new sign-in in another tab, the editor takes the valid form token here and continues saving
            return Response::json(['csrf' => $app->session->csrfToken()]);
        }
        if ($action === 'link_targets' && $ident === '') {
            // 3.22 (U6): the link dialog of the text editor – pages, news, collection items, files and language versions
            return Response::json(['groups' => LinkTargets::search($app, $request->get('q'))]);
        }
        if ($action === 'account') {
            return (new Account($this))->handle();
        }
        if ($action === 'inbox' && $ident === '') {
            return (new InboxPage($this))->handle(); // 3.26: the notifications behind the bell – every signed-in user, no menu item
        }
        if ($action === 'oauth') {
            return $this->handleOAuthConsent();
        }
        if ($action === 'hide_first_steps' && $request->isPost() && $app->auth()->isAdmin()) {
            $app->settings()->set('first_steps_hidden', '1');

            return Response::redirect($app->url('admin.php'));
        }
        if ($ident === '') {
            $update = $app->auth()->isAdmin() ? (new \Kaleta\Core\Updater($app->settings()))->state() : null;
            $newVersion = $update['nova'] ?? null;
            // 3.15: a regular release waiting for BEHIND_DAYS and more is the quiet prompt among the warnings below instead
            $behind = $update !== null && empty($newVersion['bezpecnostni']) && (\Kaleta\Core\Updater::behindDays($update) ?? 0) >= \Kaleta\Core\Updater::BEHIND_DAYS;
            if ($newVersion !== null && !$behind) {
                // the text is translated here (with the version number); the menu path is turned into a link only when the message is rendered (Admin\MenuPaths)
                $app->session->flash(!empty($newVersion['bezpecnostni']) ? 'chyba' : 'info', !empty($newVersion['bezpecnostni'])
                    ? t('A SECURITY update %s is available – install it in Settings → Backups and updates.', (string) $newVersion['verze'])
                    : t('A new version %s is available – install it in Settings → Backups and updates.', (string) $newVersion['verze']));
            }

            return $this->page('', $app->view->render('admin/dashboard', $this->desktop()));
        }
        $class = $this->modules()[$ident] ?? null;
        if ($class === null) {
            // 3.11 (UX310-30): a mistyped section is not a matter of access
            $known = array_values(array_filter(self::MODULES, fn (string $c): bool => $c::IDENT === $ident));
            if ($known !== [] && !Extensions::isEnabled($app->settings(), $known[0]::EXTENSION)) {
                // 3.19.2 (C7): a module whose feature is off is "switched off", not "no access" – a guide link or an old
                // bookmark used to send the administrator to Users; the path to Features becomes a link
                $feature = t(Extensions::CATALOG[$known[0]::EXTENSION][0] ?? $known[0]::NAME);
                $text = $app->auth()->isAdmin() ? t('The feature “%s” is switched off – switch it on in Features.', $feature) : t('The feature “%s” is switched off on this site.', $feature);

                return $this->page('Error', $app->view->render('admin/error', ['app' => $app, 'text' => $text, 'modules' => array_keys($this->modules())]), 403);
            }

            return $known !== [] ? $this->page('Error', $app->view->render('admin/error', ['text' => 'You do not have access to this module.']), 403)
                : $this->page('Error', $app->view->render('admin/error', ['text' => 'This page does not exist.']), 404);
        }

        ChangeLog::forget();
        $flashes = $request->isPost() ? count($app->session->flashTypes()) : 0;
        $response = (new $class($this))->handle($action === '' ? 'list' : $action);
        if ($request->isPost() && $response->status === 302 && $action !== 'poradi' && !ChangeLog::wrote($ident, ...self::LOGS_AS[$ident] ?? [])
            && (ChangeLog::isChangedAnyway() || !in_array('chyba', array_slice($app->session->flashTypes(), $flashes), true))) {
            // every change made in the admin goes to the change log – once: a module that wrote its own row (with what it did,
            // e.g. "reply #3") is not logged again, and an action the module refused (an error message) is no change (3.23.1,
            // N-C3) – unless it says it changed something anyway ("saved, but …", ChangeLog::changedAnyway); the generic row
            // carries the record number when the form has one
            $description = $request->post('titulek') ?: ($request->post('nazev') ?: ($request->post('user') ?: $request->post('tab')));
            $record = self::recordId($request);
            ChangeLog::write($app, $ident, $action, trim(($record > 0 ? '#' . $record . ' ' : '') . $description));
        }

        return $response;
    }

    /** The record a form of the admin changes: the first positive of its usual id fields (id, idp, ids…), 0 when none. */
    public static function recordId(\Kaleta\Core\Request $request): int
    {
        foreach (['id', 'idp', 'ids', 'idc', 'idk', 'idr', 'idu', 'ido', 'idt'] as $field) {
            if ($request->postInt($field) > 0) {
                return $request->postInt($field);
            }
        }

        return 0;
    }

    /**
     * Modules available to the signed-in user.
     *
     * @return array<string, class-string<Module>> ident => class
     */
    public function modules(): array
    {
        $auth = $this->app->auth();
        $modules = [];
        foreach (self::MODULES as $class) {
            if (!Extensions::isEnabled($this->app->settings(), $class::EXTENSION)) {
                continue;
            }
            $allowed = $class::ADMIN_ONLY ? $auth->isAdmin() : $auth->hasModule($class::SHARES_PERMISSION_OF !== '' ? $class::SHARES_PERMISSION_OF : $class::IDENT, $class::FOR_ALL_USERS);
            if ($allowed && $class::availableTo($this->app)) {
                $modules[$class::IDENT] = $class;
            }
        }

        return $modules;
    }

    /** Wraps the content in the common admin frame (menu, sign-in bar, messages). */
    public function page(string $heading, string $content, int $status = 200): Response
    {
        $app = $this->app;
        // 3.19 (N3183-2): a user held at the required two-factor gate sees My account only – no module menu, no collection
        // links and no quick-search palette (page titles, collection names) before the second factor is set up
        $gated = $app->auth()->user() !== null && $app->auth()->isMissingRequired2fa($app->settings());

        return Response::html($app->view->render('admin/layout', [
            'app' => $app,
            'heading' => t($heading),
            'content' => $content,
            'modules' => $app->auth()->user() !== null && !$gated ? $this->modules() : [],
            'gated' => $gated,
            'active' => $app->request->get('module'),
            'user' => $app->auth()->user(),
            'flashes' => $app->session->takeFlashes(),
        ]), $status);
    }

    /**
     * Data of the start screen: site overview.
     *
     * @return array<string, mixed>
     */
    private function desktop(): array
    {
        $data = ['app' => $this->app, 'modules' => $this->modules()];
        $db = $this->app->db();
        $scope = ' AND smazano IS NULL' . $this->app->auth()->articleScope();      // for queries without an alias (news in the trash are not counted)
        $aliasedScope = ' AND c.smazano IS NULL' . $this->app->auth()->articleScope('c.');  // for queries with the alias c

        $modules = $this->modules();
        $warnings = [];
        if (isset($modules['redirects'])) {
            // only addresses that still end in 404 and nobody ignored (Core\NotFound cleans the rest up)
            $missing = count(\Kaleta\Core\NotFound::pending($this->app));
            if ($missing > 0) {
                $warnings[] = [t('Addresses on your site that repeatedly ended with “page not found” this week: %d. Visitors came from an old link or a typo – send each one to the right page with a redirect, or ignore it.', $missing),
                    $this->app->url('admin.php?module=redirects#nenalezeno'), t('Review the addresses'), $this->app->url('admin.php?module=redirects&action=ignore_all')];
            }
        }
        if ($this->app->auth()->isAdmin() && ($instructions = \Kaleta\Core\ClaudeInstructions::notice($this->app->settings())) !== null) {
            // 3.15 (N44): every connection gets the instructions – a change by anyone (a Claude connection too) is checked by a person
            $warnings[] = [t('The instructions for Claude changed – check them.') . ' ' . \Kaleta\Core\ClaudeInstructions::who($instructions),
                $this->app->url('admin.php?module=claude_settings#zmena-pokynu'), t('Open Claude settings'), $this->app->url('admin.php?module=claude_settings&action=instructions_seen'), t('Dismiss')];
        }
        if ($this->app->auth()->isAdmin()) {
            // 3.15: security fixes land only in the newest release (no support window for older ones) – a site that has not
            // updated for BEHIND_DAYS is reminded here, quietly, until it does; no e-mail
            $update = (new \Kaleta\Core\Updater($this->app->settings()))->state(); // remembered by the check above
            $behind = \Kaleta\Core\Updater::behindDays($update);
            if ($behind !== null && $behind >= \Kaleta\Core\Updater::BEHIND_DAYS && empty($update['nova']['bezpecnostni'])) {
                $warnings[] = [t('Version %s has been available for %d days and this site still runs %s. Security fixes come out only in the newest release, so keep the site up to date.', (string) $update['nova']['verze'], $behind, KALETA_VERSION),
                    $this->app->url('admin.php?module=settings&tab=backups'), t('Update')];
            }
            $backup = \Kaleta\Core\Backup::listLocal()[0]['cas'] ?? 0; // 3.31.1: an uploaded copy (….enc) does not hide a missing backup
            if (time() - $backup > 8 * 86400) {
                $warnings[] = [$backup === 0 ? t('The site has no database backup yet.') : t('The last database backup is from %s.', format_date(date('Y-m-d H:i:s', $backup))), $this->app->url('admin.php?module=settings&tab=backups')];
            }
        }
        // recently edited content: pages and news together
        $edited = [];
        if (isset($modules['pages'])) {
            foreach ($db->all('SELECT ids, titulek, zmeneno, zobrazit, stavba_koncept IS NOT NULL AS koncept FROM {stranky} WHERE smazano IS NULL AND zmeneno IS NOT NULL ORDER BY zmeneno DESC LIMIT 6') as $r) {
                $edited[] = ['druh' => t('Page'), 'titulek' => $r['titulek'], 'kdy' => (string) $r['zmeneno'], 'url' => $this->app->url('admin.php?module=pages&action=edit&id=' . (int) $r['ids']),
                    'stav' => !$r['zobrazit'] ? t('hidden') : ($r['koncept'] ? t('unpublished changes') : '')];
            }
        }
        if (isset($modules['news'])) {
            foreach ($db->all('SELECT c.idc, c.titulek, COALESCE(c.zmeneno, c.datum) AS kdy, c.visible, c.datum > ? AS plan FROM {novinky} c WHERE 1 = 1' . $aliasedScope . ' ORDER BY COALESCE(c.zmeneno, c.datum) DESC LIMIT 6', [\Kaleta\Core\Clock::now()]) as $r) {
                $edited[] = ['druh' => t('Novinka'), 'titulek' => $r['titulek'], 'kdy' => (string) $r['kdy'], 'url' => $this->app->url('admin.php?module=news&action=edit&id=' . (int) $r['idc']),
                    'stav' => !$r['visible'] ? t('draft') : ($r['plan'] ? t('naplánovaná') : '')];
            }
        }
        usort($edited, fn (array $a, array $b): int => strcmp($b['kdy'], $a['kdy']));

        // "Ask Claude" (3.1): a front door to the requests inbox – only with the section and the Claude connection on
        // (not in the public demo, where the Claude connection is refused); 3.18: it knows whether a connection exists now
        // and how requests reach Claude (Core\ClaudeConnection) – without one it saves for later, never "sends"
        $claudeOn = Extensions::isEnabled($this->app->settings(), 'claude') && !\Kaleta\Core\Demo::active();
        $claude = null;
        if ($claudeOn) {
            \Kaleta\Core\AskClaude::connected($db, $this->app->settings()); // remembers the first use of a site connected before 3.5 (claude_first_used)
            $claude = \Kaleta\Core\ClaudeConnection::status($db, $this->app->settings());
        }
        $ask = isset($modules['requests']) && $claude !== null ? [
            'connected' => \Kaleta\Core\ClaudeConnection::reachable($claude),
            'status' => $claude,
            'examples' => \Kaleta\Core\AskClaude::examples($modules),
            'recent' => \Kaleta\Core\AskClaude::recent($db, $this->app->auth()->id()),
            'routine' => \Kaleta\Core\AskClaude::routine($db),
            'prompt' => \Kaleta\Core\AskClaude::prompt(rtrim($this->app->settings()->get('site_url') ?: $this->app->request->origin(), '/') . rtrim($this->app->url(''), '/'), \Kaleta\Core\Language::visitorAddress($this->app->settings())),
            'admin' => $this->app->auth()->isAdmin(),
        ] : null;

        // "Connect Claude" (3.5): while no connection exists (3.18 – also after every connection was revoked) or the only
        // one was never used, the dashboard leads with it – the address with Copy, three steps and the HTTPS warning – then
        // First steps, and Ask Claude below
        $connect = $claude !== null && \Kaleta\Core\ClaudeConnection::needsConnecting($claude)
            ? ['url' => \Kaleta\Core\AskClaude::mcpUrl($this->app), 'admin' => $this->app->auth()->isAdmin()] : null;

        return $data + [
            'ask' => $ask,
            'connect' => $connect,
            // "Waiting for you" (3.2): drafts and proposals that wait for a person – only what this person may open
            'pending' => \Kaleta\Core\PendingReview::all($this->app, $modules),
            'firstSteps' => $this->firstSteps(),
            'warnings' => $warnings,
            // traffic for 14 days (own measurement without cookies)
            'traffic' => Extensions::isEnabled($this->app->settings(), 'statistika') && isset($modules['stats'])
                ? $db->all('SELECT den, navstevy, zobrazeni FROM {stat_dny} WHERE den > ? ORDER BY den', [\Kaleta\Core\Clock::day('-14 days')]) : [],
            // 3.29.1: the first day of browser-confirmed counting when older numbers exist – the chart marks it (views/admin/dashboard.php)
            'confirmedSince' => Extensions::isEnabled($this->app->settings(), 'statistika') && isset($modules['stats']) ? \Kaleta\Front\Stats::confirmedSince($this->app->settings(), $db) : null,
            'counts' => array_filter([
                // 3.24: what waits for me (Core\MyWork) – administrators keep the Dashboard first, the tile leads them to My work
                // – without the enquiries assigned to me, which the tile "Assigned to me" counts already, and the new ones nobody
                // handles, which "New enquiries" counts (no two tiles for the same rows, N324-3)
                'My work' => isset($modules['my_work']) && ($mine = \Kaleta\Core\MyWork::count($this->app, $modules, $this->app->auth()->id(), ['assigned', 'unassigned'])) > 0 ? [$mine, 'admin.php?module=my_work'] : null,
                // drafts of news authors wait for an editor – the tile only when there are some
                'News from authors awaiting publication' => isset($modules['news']) && ($pending = Modules\News::countAwaitingPublication($this->app)) > 0 ? [$pending, 'admin.php?module=news&status=ke_vydani'] : null,
                'New enquiries' => isset($modules['enquiries']) ? [Modules\Enquiries::countNew($db), 'admin.php?module=enquiries'] : null, // spam left out, as in the list (N315-10)
                // 3.24: the open enquiries someone handed to me (or I took) – the Mine tab of Enquiries lists them
                'Assigned to me' => isset($modules['enquiries']) ? [Modules\Enquiries::countAssigned($db, $this->app->auth()->id()), 'admin.php?module=enquiries&status=moje'] : null,
                // 3.20 (C1): each tile leads to the tab of the Pages list that counts by the same condition (Modules\Pages::STATUSES)
                'Published pages' => isset($modules['pages']) ? [(int) $db->value('SELECT COUNT(*) FROM {stranky} WHERE ' . Modules\Pages::STATUSES['published']), 'admin.php?module=pages&status=published'] : null,
                'Pages with unpublished changes' => isset($modules['pages']) ? [(int) $db->value('SELECT COUNT(*) FROM {stranky} WHERE ' . Modules\Pages::STATUSES['draft']), 'admin.php?module=pages&status=draft'] : null,
                'Published news' => isset($modules['news']) ? [(int) $db->value("SELECT COUNT(*) FROM {novinky} WHERE visible = 1 AND datum <= ?{$scope}", [\Kaleta\Core\Clock::now()]), 'admin.php?module=news&status=vydane'] : null,
                'News drafts' => isset($modules['news']) ? [(int) $db->value("SELECT COUNT(*) FROM {novinky} WHERE visible = 0{$scope}"), 'admin.php?module=news&status=koncepty'] : null,
            ]),
            'enquiries' => isset($modules['enquiries']) ? $db->all('SELECT idp, datum, formular, tema, zdroj, email, data, anonymizovano, stav FROM {poptavky} WHERE ' . Modules\Enquiries::NOT_SPAM . ' AND ' . Modules\Enquiries::visible($db, $this->app->settings(), isset($modules[\Kaleta\Core\Applications::SECTION])) . ' ORDER BY idp DESC LIMIT 5') /* 3.32 review: spam stays out, as in the enquiry list */ : [],
            'edited' => array_slice($edited, 0, 8),
        ];
    }

    /**
     * First steps after installation: what is already done is recognized from the data. Only the administrator sees them,
     * until they hide or complete them.
     *
     * @return list<array{nazev:string, popis:string, url:string, hotovo:bool}>
     */
    private function firstSteps(): array
    {
        $app = $this->app;
        $s = $app->settings();
        if (!$app->auth()->isAdmin() || $s->bool('first_steps_hidden')) {
            return [];
        }
        $db = $app->db();
        // 3.23.1 (N-C9): the step leads to the privacy policy page itself (the installer makes it hidden), not to the list of pages
        $privacyPage = Modules\Settings::privacyPolicyPage($db, $s);
        $steps = [
            // done only after the user's own choice: appearance and pages from the starter site do not count
            ['Give your site a face', 'Logo, main colour and fonts.', 'admin.php?module=appearance', $s->get('logo') !== '' || $s->bool('appearance_saved') || $s->get('brand_accent') !== ''],
            ['Fill in business details', 'Address, phone and opening hours appear on the contact page, in the footer and to search engines.', 'admin.php?module=business', $s->get('company_street') !== '' && ($s->get('company_phone') !== '' || $s->get('company_email') !== '' || $s->get('site_email') !== '')],
            ['Prepare your pages', 'About us, Services, Contact – and pick the home page in Settings.', 'admin.php?module=pages', (int) $db->value('SELECT COUNT(*) FROM {stranky} WHERE smazano IS NULL AND zobrazit = 1') >= 3 && $s->int('home_page') > 0
                // 3.20 (C11): a person's edit (zmenil) – the installer stamps zmeneno on the starters now
                && $db->value('SELECT 1 FROM {stranky} WHERE smazano IS NULL AND zobrazit = 1 AND zmenil IS NOT NULL LIMIT 1') !== null],
            ['Complete the privacy policy', 'The enquiry form collects personal data – visitors must know how you handle it. The page is prepared as a hidden draft: fill in the details in square brackets and publish it.', $privacyPage !== null ? 'admin.php?module=pages&action=edit&id=' . $privacyPage['ids'] : 'admin.php?module=pages',
                // done once the page exists and no longer contains the square brackets of the skeleton from the installation ([NÁZEV FIRMY]…)
                $db->value("SELECT 1 FROM {stranky} WHERE smazano IS NULL AND zobrazit = 1 AND (" . implode(' OR ', array_map(fn (string $w): string => "seo_link LIKE '%" . $w . "%'", ['soukromi', 'osobni', 'osobnych', 'gdpr', 'dsgvo', 'privacy', 'datenschutz', 'privacidad', 'confidentialite', 'riservatezza', 'prywatnosc', 'prywatnosci'])) . ") AND text NOT LIKE '%[%]%' LIMIT 1") !== null],
            ['Set up e-mail', 'Where the site sends e-mail from (forms, password reset).', 'admin.php?module=settings&tab=mail', $s->get('mail_mode') === 'smtp' || $s->get('mail_from') !== ''],
            // 3.2: a suggestion, not an installer question – done once a blueprint is applied
            ['Your kind of business', 'Twenty ready-made setups – from a software company or a restaurant to a clinic or a trade – add the collections, facts and checks such a business needs; Claude can make one for any other.', 'admin.php?module=blueprints',
                $db->value('SELECT 1 FROM {blueprints} LIMIT 1') !== null],
        ];
        // 2.2: Claude is the main way to build and edit a Kaleta site – done once any user has connected it; the first step
        // since 3.1.1, because "Ask Claude" on the dashboard and the scheduled runs depend on it
        // 3.12 (UX310-26): while the "Connect Claude" card leads the dashboard (not connected, not the demo), the step would
        // repeat it – it shows once connected (ticked) or in the demo, where there is no card
        // 3.18: done = a connection exists that was used (Core\ClaudeConnection::needsConnecting is the card's own test)
        $connected = \Kaleta\Core\AskClaude::connected($db, $s) && !\Kaleta\Core\ClaudeConnection::needsConnecting(\Kaleta\Core\ClaudeConnection::status($db, $s));
        if (\Kaleta\Core\Extensions::isEnabled($s, 'claude') && ($connected || \Kaleta\Core\Demo::active())) {
            array_unshift($steps, ['Connect Claude', 'Build and edit the site by talking to Claude. In the Claude app, add a custom connector with your site address followed by /mcp – My account shows the exact address.',
                // 3.20 (C6): once connected the dashboard card is gone, so the step leads to the one "how to connect" place
                \Kaleta\Core\Demo::active() ? 'admin.php?action=account#claude' : (isset($this->modules()['claude_settings']) ? 'admin.php?module=claude_settings#how-to-connect' : 'admin.php#pripojit-claude'), $connected]);
        }
        $result = array_map(fn (array $k): array => ['nazev' => $k[0], 'popis' => $k[1], 'url' => $app->url($k[2]), 'hotovo' => (bool) $k[3]], $steps);

        return array_filter($result, fn (array $k): bool => !$k['hotovo']) === [] ? [] : $result;
    }

    /** Where to go after sign-in: if an app connection via OAuth (the Claude connector) is waiting, straight to the consent, otherwise to the overview. */
    private function resolveAfterSignIn(): string
    {
        if (\Kaleta\Front\OAuth::pendingRequests($this->app->session) !== []) {
            return $this->app->url('admin.php?action=oauth');
        }

        return $this->app->url(self::landsOnMyWork($this->app->auth()->isAdmin(), $this->modules()) ? 'admin.php?module=my_work' : 'admin.php');
    }

    /**
     * The landing rule (3.32, U36/A2): after sign-in a person who sees no content section – only Workspace (Customers,
     * Pipeline, Enquiries…) and the rest – lands on My work, not on the Dashboard. Media (offered to every user, for the
     * upload in the text editor) and My work itself do not count as content. Administrators always land on the Dashboard.
     *
     * @param array<string, class-string<Module>> $modules the sections the person may open (modules())
     */
    public static function landsOnMyWork(bool $admin, array $modules): bool
    {
        if ($admin || !isset($modules['my_work'])) {
            return false;
        }
        foreach ($modules as $ident => $class) {
            if ($class::GROUP === 'Content' && !in_array($ident, ['my_work', 'media'], true)) {
                return false;
            }
        }

        return true;
    }

    private function login(): Response
    {
        $app = $this->app;
        $error = null;
        // the sign-in limits count the visitor's address behind the configured proxy, an IPv6 address by its /64 (3.3.3, N54)
        $address = \Kaleta\Core\Firewall::visitorKey($app->request, $app->settings());
        $url = $app->settings()->get('site_url') ?: $app->request->origin();
        // 3.35: the sign-in without a password – the button and the user-name autofill (conditional UI) of image/klice.js
        // ask for a challenge (the same for everyone) and send the passkey's signature; the server finds the account
        if ($app->request->isPost() && in_array($app->request->post('krok'), ['passkey_options', 'passkey'], true)) {
            if ($app->request->post('krok') === 'passkey_options') {
                return Response::json($app->auth()->passkeyChallenge($url));
            }
            $error = $app->auth()->signInWithPasskey((array) json_decode((string) ($_POST['odpoved'] ?? ''), true), $url, $address);
            if ($error === null) {
                ChangeLog::write($app, 'prihlaseni', 'login', t('with a passkey, without a password'));
                $this->noticesAfterSignIn();
            } else {
                $account = $app->auth()->passkeyAccount();
                ChangeLog::write($app, 'prihlaseni', 'neuspech', 'passkey' . ($account !== '' ? ', account: ' . mb_substr($account, 0, 40) : ''));
            }

            return Response::json($error === null ? ['ok' => true, 'kam' => $this->resolveAfterSignIn()] : ['chyba' => $error], $error === null ? 200 : 401);
        }
        // second step with a passkey (fingerprint, Face ID): the script image/klice.js asks for a challenge and sends the device signature
        if ($app->request->isPost() && in_array($app->request->post('krok'), ['klic_moznosti', 'klic'], true)) {
            if ($app->request->post('krok') === 'klic_moznosti') {
                $options = $app->auth()->keyChallenge($url);

                return Response::json($options ?? ['chyba' => t('The sign-in has expired, please start again.')], $options === null ? 400 : 200);
            }
            $error = $app->auth()->verifyKey((array) json_decode((string) ($_POST['odpoved'] ?? ''), true), $url, $address);
            if ($error === null) {
                ChangeLog::write($app, 'prihlaseni', 'login', t('with a passkey'));
                $this->noticesAfterSignIn();
            }

            return Response::json($error === null ? ['ok' => true, 'kam' => $this->resolveAfterSignIn()] : ['chyba' => $error], $error === null ? 200 : 401);
        }
        if ($app->request->isPost()) {
            $secondStep = $app->request->post('kod') !== '' || $app->request->post('krok') === 'kod';
            $error = $secondStep
                ? $app->auth()->verifyCode($app->request->post('kod'), $address)
                // the password as typed, not trimmed – as every place that sets one reads it (3.3.3, N61)
                : $app->auth()->login($app->request->post('user'), is_string($_POST['password'] ?? null) ? $_POST['password'] : '', $address);
            if ($error === null && $app->auth()->user() !== null) {
                ChangeLog::write($app, 'prihlaseni', 'login', $secondStep ? t('two-factor') : '');
                $this->noticesAfterSignIn();

                return Response::redirect($this->resolveAfterSignIn());
            }
            if ($error !== null && !$secondStep) {
                // 3.41 (N117): only the name of an existing account – what was typed may be a password typed into the wrong field
                $account = $app->auth()->failedAccount();
                ChangeLog::write($app, 'prihlaseni', 'neuspech', $account !== '' ? 'account: ' . mb_substr($account, 0, 40) : 'unknown account');
            }
        }

        $steps = $app->auth()->awaitingSteps();

        return Response::html($app->view->render('admin/login', [
            'app' => $app,
            'error' => $error,
            'login' => $app->request->post('user'),
            'code' => $app->auth()->isAwaitingCode(),
            'keys' => $steps['passkey'],
            'steps' => $steps,
            // 3.35: passkeys are bound to the site's canonical host – another host name of the site gets a link there instead
            'passkeyHost' => \Kaleta\Core\SignInPolicy::hostMatches((string) ($app->request->serverValues()['HTTP_HOST'] ?? ''), $url),
            'canonicalAdmin' => rtrim($url, '/') . $app->url('admin.php'),
        ]), $error === null ? 200 : 401);
    }

    /**
     * After a completed sign-in (3.35): a site that requires passkeys tells an account without a working one to add it;
     * an account that has just used a backup code instead of its passkey is sent to register a new passkey.
     */
    private function noticesAfterSignIn(): void
    {
        $app = $this->app;
        $user = $app->auth()->user();
        if ($user === null) {
            return;
        }
        $keys = $app->auth()->workingKeys((int) $user['idu']); // 3.35 review M3: the passkeys of this host
        if ($app->settings()->get('signin_passkey_only') === '1' && !\Kaleta\Core\SignInPolicy::escapeHatch() && !\Kaleta\Core\SignInPolicy::hasWorkingPasskey($keys)) {
            $app->session->flash('info', t('This site asks everyone to sign in with a passkey. Add one for this device in My account – until then you sign in as before.'));
        } elseif ($keys !== [] && $app->auth()->isFreshlyVerified() && $app->request->post('krok') === 'kod') {
            $app->session->flash('info', t('You signed in with a backup code. If you lost your passkey, remove it in My account and register a new one now – no password is needed for the next few minutes.'));
        }
    }

    /**
     * Consent to connecting an app via OAuth (the Claude connector): shows who is asking, where it returns and with which
     * permissions, and after confirmation returns a one-time code to the app. Each request waits in the session under its
     * own nonce (Front\OAuth::authorize) for at most 15 minutes; the form names the nonce, and the consent goes only to the
     * request the person saw (3.3.4, N14).
     */
    private function handleOAuthConsent(): Response
    {
        $app = $this->app;
        $isPost = $app->request->isPost();
        $siteUrl = $app->settings()->get('site_url') ?: $app->request->origin();
        // 3.35: "Confirm with your passkey" (image/klice.js, data-klic-potvrdit) – the fresh proof of an account that signs
        // in with a passkey: a challenge for the account's own passkeys, then their signature (Auth::confirmWithPasskey,
        // markVerified). The page then reloads with the proof and full access asks for nothing more.
        if ($isPost && in_array($app->request->post('krok'), ['passkey_confirm_options', 'passkey_confirm'], true)) {
            if ($app->request->post('krok') === 'passkey_confirm_options') {
                $options = $app->auth()->confirmChallenge($siteUrl);

                return Response::json($options ?? ['chyba' => t('This account has no passkey.')], $options === null ? 400 : 200);
            }
            $result = $app->auth()->confirmWithPasskey((array) json_decode((string) ($_POST['odpoved'] ?? ''), true), $siteUrl, \Kaleta\Core\Firewall::visitorKey($app->request, $app->settings()));
            if ($result === 'ok') {
                ChangeLog::write($app, 'ucet', 'passkey_confirm');
            }
            $back = $app->url('admin.php?action=oauth' . (preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $app->request->get('request')) === 1 ? '&request=' . $app->request->get('request') : ''));

            return Response::json($result === 'ok' ? ['ok' => true, 'kam' => $back] : ['chyba' => t($result === 'locked' ? self::PASSKEY_CONFIRM_LOCKED : self::PASSKEY_CONFIRM_FAILED)], $result === 'ok' ? 200 : 401);
        }
        // a GET without a nonce (the page after sign-in) shows the newest request; the consent itself needs the nonce
        $found = \Kaleta\Front\OAuth::pendingRequest($app->session, $isPost ? $app->request->post('request') : $app->request->get('request'));
        if ($found === null || ($isPost && $app->request->post('request') === '')) {
            return $this->page('Connect an application', $app->view->render('admin/error', ['text' => 'The request to connect the application has expired or does not exist. Start connecting again in the application.']), 400);
        }
        [$nonce, $pending] = $found;
        $oauth = new \Kaleta\Front\OAuth($app);
        $passwordError = null;
        if ($isPost) {
            if (!$app->request->postBool('povolit')) {
                \Kaleta\Front\OAuth::forgetRequest($app->session, $nonce);

                return Response::redirect($oauth->deny($pending));
            }
            $access = \Kaleta\Front\OAuth::access($app->request->post('access') ?: 'drafts'); // no choice sent: drafts only, the default of the page (3.11, N311-3)
            // 3.10: the tool set (essentials by default on the page); a consent page from before 3.10 sends none: all tools, as before
            $toolSet = \Kaleta\Mcp\Catalog::toolSet($app->request->post('tool_set'));
            $toolSet = in_array($toolSet, \Kaleta\Mcp\Catalog::offered($app->settings()), true) ? $toolSet : 'essentials'; // 3.36: the fleet set only on a fleet site
            // 3.35 (owner decision 11 Nov 2026): full access – publishing and deleting with this account – needs a fresh
            // proof: the current password typed here, or a sign-in or confirmed password within Auth::VERIFIED_WINDOW (an
            // account that signs in without a password signs in again). Drafts and read stay one click. The request waits
            // in the session for another try; nothing is issued until the proof is there.
            $passwordError = $access === 'full' ? self::fullAccessRefusal($app->auth()) : null;
            if ($passwordError === null) {
                \Kaleta\Front\OAuth::forgetRequest($app->session, $nonce);
                ChangeLog::write($app, 'claude', 'app_connect', mb_substr((string) $pending['nazev'] . ' (' . $access . ($toolSet !== 'all' ? ', ' . $toolSet : '') . ')', 0, 100));

                return Response::redirect($oauth->issueCode($pending, $app->auth()->id(), $access, $toolSet));
            }
        }

        $host = \Kaleta\Front\OAuth::host((string) $pending['redirect_uri']);
        $claudeHost = \Kaleta\Front\OAuth::isClaudeHost($host);
        // 3.11 (UX310-8): a focused frame without the admin menu and the draft-look bar – a stray Publish has no place here
        $page = Response::html($app->view->render('admin/bare', ['app' => $app, 'heading' => t('Connect an application'), 'content' => $app->view->render('admin/oauth', [
            'app' => $app, 'csrf' => $app->session->csrfField(), 'pending' => $pending, 'nonce' => $nonce, 'user' => $app->auth()->user(),
            'url' => $host, 'claudeHost' => $claudeHost, 'approved' => $oauth->isApprovedClient((string) $pending['client_id']),
            'registered' => $oauth->registeredAt((string) $pending['client_id']), // 3.41 (N123)
            // 3.11 (UX310-2, owner decision 11 Oct 2026): every new connection starts at drafts only – the "drafts you
            // approve" the installer and the site promise; publishing is one click away. Connections that exist keep their access.
            'selected' => 'drafts',
            // 3.35: why full access was refused just now (the view keeps full chosen), and whether a fresh proof is there
            'passwordError' => $passwordError, 'verified' => $app->auth()->isPasskeyOnly() ? $app->auth()->isFreshlyVerified() : $app->auth()->recentlyVerified(),
            // 3.35: an account with a passkey may confirm with it (on the site's own host); one that signs in with a passkey
            // only is offered nothing else
            'passkeyConfirm' => $app->auth()->workingKeys($app->auth()->id()) !== [] && \Kaleta\Core\SignInPolicy::hostMatches((string) ($app->request->serverValues()['HTTP_HOST'] ?? ''), $siteUrl),
            'passkeyOnly' => $app->auth()->isPasskeyOnly(), // 3.35 review M2: on every host – the password is no proof for it anywhere
            // 3.10: a new connection lists the essentials – a shorter tools/list in every conversation; the rest is a find_tools away
            // (3.36, owner decision 10: new Claude connections start on the essentials); a refused try keeps the set chosen –
            // 3.36 review L4: only a set this site offers (the fleet set on a fleet site), as the approval itself does
            'toolSet' => $isPost && $app->request->post('tool_set') !== ''
                && in_array(\Kaleta\Mcp\Catalog::toolSet($app->request->post('tool_set')), \Kaleta\Mcp\Catalog::offered($app->settings()), true)
                ? \Kaleta\Mcp\Catalog::toolSet($app->request->post('tool_set')) : 'essentials',
        ])]));
        // sending the consent ends with a redirect to the app – CSP form-action must allow it (admin.php)
        $target = parse_url((string) $pending['redirect_uri']);
        $origin = ($target['scheme'] ?? '') . '://' . ($target['host'] ?? '') . (isset($target['port']) ? ':' . $target['port'] : '');

        return new Response($page->body, $passwordError !== null ? 403 : $page->status, $page->headers + ['X-Kaleta-Form-Action' => $origin]);
    }

    /**
     * 3.35: why approving full access is refused now – null when the person proved who they are within
     * Auth::VERIFIED_WINDOW or typed the right current password into the consent form (Auth::confirmPassword: the
     * sign-in's counter and lock).
     */
    private static function fullAccessRefusal(\Kaleta\Core\Auth $auth): ?string
    {
        // 3.35 review M2: Auth::freshProof – any proof within the window or the current password; an account that signs in
        // with a passkey only needs the strong proof (a passkey or backup-code step, or "Confirm with your passkey") on
        // every host – a right password typed elsewhere (verified_at) does not count for it
        return match ($auth->freshProof(is_string($_POST['soucasne'] ?? null) ? $_POST['soucasne'] : '', 'recent')) {
            'ok' => null,
            'passkey' => self::FULL_ACCESS_PASSKEY, // an account that signs in with a passkey only confirms with it, never with a password
            'locked' => \Kaleta\Core\Auth::CONFIRM_LOCKED,
            default => self::FULL_ACCESS_PASSWORD,
        };
    }

    /** 3.35 review L1: the warning on every admin page while the escape hatch is in config.php. */
    public const string ESCAPE_HATCH_WARNING = 'Passkey sign-in rules are switched off: %s is in config.php, so passwords sign in without passkeys – administrators too. Register a passkey again in My account and then remove the line from config.php.';

    /** 3.35: the answer when an account that signs in with a passkey only approves full access without a fresh proof. */
    public const string FULL_ACCESS_PASSKEY = '“Can also publish” lets the application publish and delete with your account – confirm with your passkey to allow it, or choose “Drafts only”.';

    /** 3.35: the answers of a passkey confirmation that did not succeed. */
    public const string PASSKEY_CONFIRM_FAILED = 'The passkey did not confirm it. Try again, or choose “Drafts only”.';

    public const string PASSKEY_CONFIRM_LOCKED = 'Too many failed attempts in a row – confirming is locked for 15 minutes. You stay signed in; nothing was changed.';

    /** 3.35: the answer when full access is approved without a fresh proof. */
    public const string FULL_ACCESS_PASSWORD = '“Can also publish” lets the application publish and delete with your account – enter your current password to allow it, or choose “Drafts only”. An account without a password signs in again and then starts connecting again in the application.';
}
