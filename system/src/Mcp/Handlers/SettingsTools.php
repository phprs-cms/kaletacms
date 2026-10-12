<?php

declare(strict_types=1);

namespace Kaleta\Mcp\Handlers;

use Kaleta\Admin\Modules\Media;
use Kaleta\Admin\Modules\Categories;
use Kaleta\Admin\Modules\Pages;
use Kaleta\Core\App;
use Kaleta\Core\Language;
use Kaleta\Front\SiteIdentity;
use Kaleta\Builder\SiteParts;
use Kaleta\Builder\DesignSystem;
use Kaleta\Builder\Library;
use Kaleta\Builder\Collections;
use Kaleta\Builder\Publisher;
use Kaleta\Builder\Build;
use Kaleta\Builder\HtmlConverter;

/**
 * MCP tools: settings, redirects and audit (one method per tool, see Mcp\Catalog). Part of Mcp\Tools.
 *
 * @phpstan-ignore trait.unused
 */
trait SettingsTools
{
    /** update_settings (uprav_nastaveni) */
    private function toolUpdateSettings(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();
        $siteSettings = $this->app->settings();
        $adminOnly = function () use ($auth): void {
            if (!$auth->isAdmin()) {
                throw new \DomainException('Tento nástroj smí použít jen správce webu.');
            }
        };

        $adminOnly();
        $changes = is_array($a['nastaveni'] ?? null) ? $a['nastaveni'] : [];
        $stored = [];
        $errors = [];
        $inLookDraft = [];
        foreach ($changes as $key => $value) {
            // keys of 1.4.0 and older still work (nazev_webu, firma_email, nazev_webu_de…)
            $key = (string) $key;
            $key = \Kaleta\Core\OldSettingsKeys::current($key); // keys of 1.4.0 and older keep working over MCP
            if ($key === 'extensions' || $key === 'additional_languages') {
                // lists (2.2): extensions switched on, further language versions of the site
                $list = array_values(array_filter(array_map('trim', is_array($value) ? array_map('strval', $value) : explode(',', (string) $value))));
                if ($key === 'extensions') {
                    $unknown = array_diff($list, array_keys(\Kaleta\Core\Extensions::CATALOG));
                    if ($unknown !== [] || !in_array('claude', $list, true)) {
                        $errors[$key] = $unknown !== [] ? 'Unknown extensions: ' . implode(', ', $unknown) . '. Known: ' . implode(', ', array_keys(\Kaleta\Core\Extensions::CATALOG)) . '.'
                            : 'The Claude connection cannot switch itself off – keep "claude" in the list (the user can switch it off in the admin).';
                        continue;
                    }
                    \Kaleta\Core\Extensions::save($siteSettings, $list);
                    $stored[$key] = \Kaleta\Core\Extensions::enabled($siteSettings);
                } else {
                    $codes = explode('|', \Kaleta\Core\Language::CODES);
                    $unknown = array_diff($list, $codes);
                    if ($unknown !== [] || in_array(\Kaleta\Core\Language::defaults($siteSettings), $list, true)) {
                        $errors[$key] = $unknown !== [] ? 'Unknown language codes: ' . implode(', ', $unknown) . '.' : 'The default language of the site is not a further language version.';
                        continue;
                    }
                    $siteSettings->set($key, implode(',', array_unique($list)));
                    $stored[$key] = array_values(array_unique($list));
                }
                continue;
            }
            if ($key === 'screen_collections') {
                // screen mode (2.11): the collections the screen shows – only ones that exist
                $list = array_values(array_unique(array_filter(array_map('trim', is_array($value) ? array_map('strval', $value) : explode(',', (string) $value)))));
                $known = array_map('strval', array_column($db->all('SELECT seo_link FROM {kolekce}'), 'seo_link'));
                $unknown = array_diff($list, $known);
                if ($unknown !== []) {
                    $errors[$key] = 'Unknown collections: ' . implode(', ', $unknown) . ' (list_collections).';
                    continue;
                }
                $siteSettings->set($key, implode(',', $list));
                $stored[$key] = $list;
                continue;
            }
            if (in_array($key, ['logo', 'favicon', 'share_image'], true)) {
                // logo and icon: a file from Media (nahraj_soubor) or from the system (image/…); empty = no logo / icon
                $path = ltrim(trim((string) $value), '/');
                $ok = $path === '' || (preg_match('#^(media|image)/[A-Za-z0-9/_.-]{1,200}\.(svg|png|webp|jpe?g|avif)$#D', $path) && !str_contains($path, '..') && is_file(KALETA_ROOT . '/' . $path));
                if (!$ok) {
                    $errors[$key] = 'Cesta k souboru z Médií (media/…) nebo ze systému (image/…); ikona musí jít převést na PNG.';
                    continue;
                }
                if ($key !== 'share_image') {
                    // 3.12 (UX310-11): the logo and the icon go to the draft look, as in Site appearance – publish_look puts
                    // them live (and prepares the icon's PNG sizes)
                    if (\Kaleta\Core\Look::setIdentity($siteSettings, $key, $path)) {
                        $inLookDraft[] = $key;
                    }
                    $stored[$key] = $path;
                    continue;
                }
                $siteSettings->set($key, $path);
                $stored[$key] = $path;
                continue;
            }
            if ($key === 'stats' && is_scalar($value)) {
                // 3.2: the Statistics feature is the only switch – the old setting keeps working and switches the feature
                $on = is_bool($value) ? $value : in_array(strtolower(trim((string) $value)), ['1', 'true', 'ano'], true);
                $list = array_values(array_diff(\Kaleta\Core\Extensions::enabled($siteSettings), ['statistika']));
                \Kaleta\Core\Extensions::save($siteSettings, $on ? [...$list, 'statistika'] : $list);
                $stored[$key] = $on ? '1' : '0';
                continue;
            }
            // booking settings have no type in the settings form: they are checked like the Bookings settings form does
            $clean = preg_match(self::MCP_SETTINGS, $key) && is_scalar($value)
                ? (str_starts_with($key, 'booking_') ? \Kaleta\Core\Booking::settingValue($key, (string) $value) : \Kaleta\Admin\Modules\Settings::verifyValue($key, is_bool($value) ? ($value ? '1' : '0') : (string) $value))
                : null;
            if ($clean !== null && $key === 'home_page' && (int) $clean > 0
                && $db->value('SELECT ids FROM {stranky} WHERE ids = ? AND zobrazit = 1 AND smazano IS NULL', [(int) $clean]) === null) {
                $errors[$key] = 'Úvodní stránkou může být jen zveřejněná stránka.';
                continue;
            }
            if ($key === 'news_slug' && is_scalar($value) && ($slugError = \Kaleta\Core\Routes::slugError(trim((string) $value), $db)) !== null) {
                // before the generic check, so a badly formed slug gets the reason, not just "invalid value"
                $errors[$key] = $slugError;
                continue;
            }
            if (in_array($key, ['head_code', 'marketing_code', 'cookies_external_code'], true)) {
                $errors[$key] = 'Code that runs on the site is set only in the administration (Settings), not through a Claude connection.';
                continue;
            }
            if (in_array($key, ['gtm_id', 'matomo_url', 'matomo_id'], true)) {
                // 3.3.2: a GTM container or a Matomo host runs whatever script its owner configures – the administrator sets them
                $errors[$key] = 'Google Tag Manager and Matomo load script chosen by whoever runs them, so they are set only in the administration (Settings → Analytics), not through a Claude connection. ga4_id and plausible_domain can be set here.';
                continue;
            }
            if ($clean === null) {
                $errors[$key] = preg_match(self::MCP_SETTINGS, $key) ? 'Neplatná hodnota.' : 'Tohle nastavení přes MCP měnit nejde (jen v administraci).';
                continue;
            }
            $siteSettings->set($key, $clean);
            $stored[$key] = $clean;
        }
        if ($stored !== []) {
            \Kaleta\Front\Cache::clear();
        }
        if (isset($stored['company_hours'])) {
            \Kaleta\Core\GoogleBusiness::hoursChanged($this->app); // the Business Profile gets the new week (2.13)
        }
        if (($stored['screen_mode'] ?? '') === '1') {
            \Kaleta\Front\Screen::ensureSecret($siteSettings); // the address exists as soon as the mode is on – the administrator finds it in Settings → General
        }
        $current = [];
        foreach (['site_name', 'site_description', 'footer_text', 'logo', 'favicon', 'home_page', 'news_slug', 'social_facebook', 'social_instagram', 'social_x', 'social_youtube', 'social_linkedin', 'news_per_page',
            'share_image', 'company_name', 'company_type', 'company_id', 'company_vat_id', 'company_register', 'company_representative', 'company_street', 'company_city', 'company_postcode', 'company_country', 'company_phone', 'company_email', 'company_hours', 'company_map', 'company_gps', 'dark_mode', 'theme_switcher',
            'indexing', 'schema_org', 'llms_txt', 'markdown_news', 'indexnow', 'ai_crawlers', 'cookies_mode', 'cookies_log', 'stats', 'security_contact'] as $key) {
            $current[$key] = $siteSettings->get($key);
        }
        $current['stats'] = \Kaleta\Front\Stats::enabled($siteSettings) ? '1' : '0'; // the Statistics feature (3.2)
        $current += ['extensions' => \Kaleta\Core\Extensions::enabled($siteSettings), 'additional_languages' => \Kaleta\Core\Language::additional($siteSettings),
            'claude_instructions' => $siteSettings->get('claude_instructions'),
            'screen' => \Kaleta\Front\Screen::settings($siteSettings)]; // on, seconds, collections, news, hours, clock – never the secret address

        // the logo and the icon in the draft look (3.12): what visitors see is still the published value in nastaveni
        $draft = $inLookDraft !== [] ? ['look_draft' => \Kaleta\Core\Language::runWith('en', fn (): array => \Kaleta\Core\Look::summary($db, $siteSettings), 'admin-'),
            'stav' => implode(', ', $inLookDraft) . ' in the draft look – visitors see it after publish_look'] : [];

        return ['ulozeno' => $stored ?: new \stdClass(), 'chyby' => $errors ?: new \stdClass(), 'nastaveni' => $current] + $draft;
    }

    /** list_redirects and save_redirect (seznam_presmerovani, uloz_presmerovani) */
    private function toolListRedirects(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();
        $siteSettings = $this->app->settings();
        $adminOnly = function () use ($auth): void {
            if (!$auth->isAdmin()) {
                throw new \DomainException('Tento nástroj smí použít jen správce webu.');
            }
        };

        if (!\Kaleta\Core\Extensions::isEnabled($siteSettings, 'presmerovani')) {
            throw new \DomainException('Rozšíření Přesměrování je vypnuté (Rozšíření v administraci).');
        }
        if (!$auth->hasModule('redirects')) {
            throw new \DomainException('Přesměrování smí spravovat jen role se sekcí Přesměrování.');
        }
        if ($name === 'uloz_presmerovani') {
            $adminOnly();
            $z = trim((string) parse_url((string) ($a['z'] ?? ''), PHP_URL_PATH), '/ ');
            $commandName = trim((string) ($a['na'] ?? ''));
            if ($z === '' || !preg_match('#^[A-Za-z0-9/._~%-]{1,250}$#D', $z)) {
                throw new \InvalidArgumentException('Stará cesta musí být cesta na tomto webu, např. /stara-stranka.');
            }
            if (!empty($a['smazat'])) {
                $db->delete('presmerovani', ['z_adresy' => $z]);
            } else {
                if (!preg_match('#^https?://[^\s]{3,240}$#iD', $commandName) && !preg_match('#^/?[^\s:]{0,250}$#D', $commandName)) {
                    throw new \InvalidArgumentException('Nová adresa musí být cesta (/nova) nebo https://… adresa.');
                }
                $commandName = preg_match('#^https?://#i', $commandName) ? $commandName : trim($commandName, '/');
                \Kaleta\Admin\Modules\Redirects::add($db, $z, $commandName);
                $db->run('UPDATE {presmerovani} SET typ = ? WHERE z_adresy = ?', [(int) ($a['typ'] ?? 301) === 302 ? 302 : 301, $z]);
                $db->delete('nenalezeno', ['cesta' => $z]);
            }
            \Kaleta\Front\Cache::clear();
        }

        // every missing address carries the page the visitor most likely meant (2.14, Core\RedirectMatcher)
        $pending = \Kaleta\Core\NotFound::pending($this->app, 60, 30);
        $suggestions = \Kaleta\Core\RedirectMatcher::suggestions($this->app, $pending);

        return ['presmerovani' => array_map(fn (array $r): array => $r + ['auto_score' => $r['auto_score'] !== null ? (int) $r['auto_score'] : null], $db->all('SELECT z_adresy AS z, na_adresu AS na, typ, pocet, auto_score FROM {presmerovani} ORDER BY z_adresy LIMIT 500')),
            'nenalezeno' => array_map(fn (array $n): array => $n + ['suggestion' => isset($suggestions[$n['cesta']]) ? '/' . $suggestions[$n['cesta']]['to'] : null, 'score' => $suggestions[$n['cesta']]['score'] ?? null], $pending),
            'auto' => ['on' => $siteSettings->bool('redirect_auto'), 'threshold' => \Kaleta\Core\RedirectMatcher::threshold($siteSettings)]];
    }

    /** save_redirect: the same as list_redirects */
    private function toolSaveRedirect(string $name, array $a): mixed
    {
        return $this->toolListRedirects($name, $a);
    }

    /**
     * save_redirects (3.6): many redirects in one call, exact ones and patterns, every row checked on its own
     * (Core\RedirectRules – the same as the CSV import in the admin); dry_run only says what would happen.
     */
    private function toolSaveRedirects(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        if (!$auth->isAdmin() || !$auth->hasModule('redirects')) {
            throw new \DomainException('Redirects are managed by administrators.');
        }
        $rows = is_array($a['redirects'] ?? null) ? array_values($a['redirects']) : [];
        if ($rows === [] || count($rows) > \Kaleta\Core\RedirectRules::MAX_BATCH) {
            throw new \InvalidArgumentException('Send 1–' . \Kaleta\Core\RedirectRules::MAX_BATCH . ' redirects: [{"from":"/old","to":"/new","code":301}].');
        }
        $text = fn (mixed $v): string => is_string($v) ? $v : (is_int($v) ? (string) $v : '');
        $input = array_map(fn (mixed $r): array => is_array($r)
            ? ['from' => $text($r['from'] ?? null), 'to' => $text($r['to'] ?? null), 'code' => $text($r['code'] ?? null)]
            : ['from' => '', 'to' => '', 'refused' => 'Each redirect is an object with from, to and code.'], $rows);
        $dryRun = !empty($a['dry_run']);
        $results = $dryRun ? \Kaleta\Core\RedirectRules::plan($this->app->db(), $input) : \Kaleta\Core\RedirectRules::save($this->app->db(), $input);
        if (!$dryRun) {
            \Kaleta\Front\Cache::clear();
        }
        $counts = array_count_values(array_column($results, 'status')) + ['added' => 0, 'changed' => 0, 'unchanged' => 0, 'refused' => 0];

        return ['dry_run' => $dryRun, 'added' => $counts['added'], 'changed' => $counts['changed'], 'unchanged' => $counts['unchanged'], 'refused' => $counts['refused'],
            'results' => array_map(fn (array $r): array => array_filter($r, fn (mixed $v, string $k): bool => $k !== 'reason' || $v !== '', ARRAY_FILTER_USE_BOTH), $results)];
    }

    /** ignore_not_found */
    private function toolIgnoreNotFound(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $need = function (bool $allowed, string $message): void {
            if (!$allowed) {
                throw new \DomainException($message);
            }
        };

        $need($auth->isAdmin(), 'Addresses not found are handled by an administrator.');
        $paths = is_array($a['paths'] ?? null) ? array_values(array_filter($a['paths'], 'is_string')) : [];
        $need($paths !== [] || !empty($a['all']), 'Send paths, or all: true.');

        return ['ignored' => \Kaleta\Core\NotFound::ignore($this->app, !empty($a['all']) ? null : $paths),
            'not_found' => array_map(fn (array $n): array => ['path' => $n['cesta'], 'count' => $n['pocet'], 'last_seen' => $n['naposledy']], \Kaleta\Core\NotFound::pending($this->app, 60, 30))];
    }

    /** site_audit */
    private function toolSiteAudit(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $need = function (bool $allowed, string $message): void {
            if (!$allowed) {
                throw new \DomainException($message);
            }
        };

        $need($auth->isAdmin() || $auth->hasModule('pages'), 'The site audit is for administrators and editors of pages.');
        $findings = \Kaleta\Core\Language::runWith('en', fn (): array => (new \Kaleta\Core\Audit($this->app))->run(), 'admin-'); // the findings in English, as Site audit in an English administration
        if (is_string($a['kind'] ?? null) && $a['kind'] !== '') {
            $findings = array_values(array_filter($findings, fn (array $f): bool => $f['kind'] === $a['kind']));
        }
        $counts = array_count_values(array_column($findings, 'kind'));

        return ['total' => count($findings), 'by_kind' => $counts ?: new \stdClass(), 'findings' => $findings];
    }

    /** get_stats (2.3): the same report as the Statistics screen */
    private function toolGetStats(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        if (!$auth->isAdmin() && !$auth->hasModule('stats')) {
            throw new \DomainException('Statistics are for administrators and users with access to Statistics.');
        }

        return \Kaleta\Core\Report::build($this->app->db(), (int) ($a['days'] ?? 30)) + ['statistics_on' => \Kaleta\Front\Stats::enabled($this->app->settings())];
    }

    /** list_changes (2.2): the change log, people and Claude told apart */
    private function toolListChanges(string $name, array $a): mixed
    {
        if (!$this->app->auth()->isAdmin()) {
            throw new \DomainException('The change log is for administrators.');
        }
        $conditions = ['1 = 1'];
        $params = [];
        if (in_array($a['by'] ?? '', ['people', 'claude'], true)) {
            $conditions[] = $a['by'] === 'claude' ? "via <> ''" : "via = ''";
        }
        if (is_string($a['since'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $a['since'])) {
            $conditions[] = 'cas >= ?';
            $params[] = $a['since'] . ' 00:00:00';
        }
        $limit = max(1, min(200, (int) ($a['limit'] ?? 50)));

        return ['changes' => array_map(fn (array $r): array => ['when' => substr((string) $r['cas'], 0, 16), 'who' => $r['jmeno'], 'claude_connection' => $r['via'] !== '' ? $r['via'] : null,
            'where' => $r['modul'], 'action' => $r['akce'], 'detail' => $r['popis']] + ($r['duvod'] !== '' ? ['reason' => $r['duvod']] : []),
            $this->app->db()->all('SELECT cas, jmeno, via, modul, akce, popis, duvod FROM {protokol} WHERE ' . implode(' AND ', $conditions) . ' ORDER BY idp DESC LIMIT ' . $limit, $params))];
    }

    /** list_agent_sessions (2.17, Core\AgentJournal) */
    private function toolListAgentSessions(string $name, array $a): mixed
    {
        if (!$this->app->auth()->isAdmin()) {
            throw new \DomainException('Claude sessions are for administrators.');
        }

        return ['sessions' => array_map(fn (array $r): array => ['id' => (int) $r['id'], 'connection' => $r['connection'], 'started' => substr((string) $r['started_at'], 0, 16),
            'last' => substr((string) $r['last_at'], 0, 16), 'rows_changed' => (int) $r['rows_changed'], 'untracked_writes' => (int) $r['rows_untracked'], 'tools' => (string) ($r['tools'] ?? ''),
            'undone' => $r['undone_at'] !== null ? substr((string) $r['undone_at'], 0, 16) : null], \Kaleta\Core\AgentJournal::sessions($this->app->db(), max(1, min(100, (int) ($a['limit'] ?? 20)))))];
    }

    /** undo_agent_session (2.17) */
    private function toolUndoAgentSession(string $name, array $a): mixed
    {
        if (!$this->app->auth()->isAdmin()) {
            throw new \DomainException('Only an administrator can undo a Claude session.');
        }
        if (($a['confirm'] ?? false) !== true) {
            throw new \InvalidArgumentException('Undoing needs confirm=true – only when the user asked for it.');
        }

        return \Kaleta\Core\AgentJournal::undo($this->app, (int) ($a['id'] ?? 0), ($a['force'] ?? false) === true)
            + ['note' => 'Tell the user what was put back, and list any rows left because they changed since (conflicts) and writes undo could not follow (untracked).'];
    }

    /** processing_record (2.14, Core\Privacy) */
    private function toolProcessingRecord(string $name, array $a): mixed
    {
        if (!$this->app->auth()->isAdmin()) {
            throw new \DomainException('The record of processing is for administrators – it describes the whole site.');
        }
        $sections = \Kaleta\Core\Privacy::processingRecord($this->app);

        return ['markdown' => \Kaleta\Core\Privacy::markdown($sections, t('Record of processing')), 'sections' => count($sections),
            'note' => 'A template assembled from the configuration, not legal advice: the owner reviews it, adds what the site does not know (paper files, other systems) and keeps it with their documentation. Printable in Settings → Privacy and cookies → Record of processing.'];
    }

    /** accessibility_statement (2.14, Core\Privacy) */
    private function toolAccessibilityStatement(string $name, array $a): mixed
    {
        $statement = \Kaleta\Core\Privacy::accessibilityStatement($this->app);
        $pageId = $this->app->settings()->int('accessibility_statement_page');
        $page = $pageId > 0 ? $this->app->db()->one('SELECT ids, seo_link, zobrazit FROM {stranky} WHERE ids = ? AND smazano IS NULL', [$pageId]) : null;

        return ['title' => $statement['title'], 'status' => $statement['status'] === 'full' ? 'fully_compliant' : 'partially_compliant', 'standard' => 'EN 301 549 / WCAG 2.1 AA',
            'barriers' => $statement['findings'], 'date' => $statement['date'], 'text' => $statement['text'],
            'page' => $page !== null ? ['id' => (int) $page['ids'], 'slug' => $page['seo_link'], 'published' => (int) $page['zobrazit'] === 1] : null,
            'next' => $statement['findings'] !== [] ? 'Fix the barriers (site_audit kind accessibility), then let the administrator regenerate the draft in Settings → Privacy and cookies. A template, not legal advice.'
                : ($page === null ? 'The administrator creates the page in Settings → Privacy and cookies (a hidden draft to review and publish). A template, not legal advice.' : 'Review the page with the user before publishing it (publish_build). A template, not legal advice.')];
    }
}
