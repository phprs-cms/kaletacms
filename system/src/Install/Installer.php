<?php

declare(strict_types=1);

namespace Kaleta\Install;

use Kaleta\Core\Auth;
use Kaleta\Core\Db;
use Kaleta\Core\Migration;
use Kaleta\Core\Request;
use Kaleta\Core\Response;
use Kaleta\Core\Extensions;
use Kaleta\Core\View;
use Kaleta\Builder\Library;
use Kaleta\Builder\Build;

/**
 * Web installer: checks the server, creates the tables and the first administrator and writes config.php. Kaleta is
 * installed like WordPress – upload the files, create a database, open install.php (2.6: no Docker, no command line).
 */
final class Installer
{
    private readonly Request $request;
    private readonly View $view;

    public function __construct()
    {
        $this->request = Request::fromGlobals();
        $this->view = new View([KALETA_SYSTEM . '/views']);
    }

    /** Installation languages (= admin languages) and the default time zone we offer for them. */
    private const array TIME_ZONES = ['cs' => 'Europe/Prague', 'en' => 'Europe/London', 'de' => 'Europe/Berlin'];

    private string $language = 'cs';

    /** Form of address of German (formal | informal): the installer's texts, the first account and the site texts for visitors. */
    private string $register = 'formal';

    /** The secret part of the cron address (/tasks?token=, the older /ulohy answers too), shown on the last screen so the owner can add it to the hosting right away (2.8). */
    private string $tasksToken = '';

    /** The public cron path (3.7): tasks, or ulohy when the site holds the English word – the same helper as System status (Routes::publicSystemPath). */
    private string $cronPath = 'tasks';

    /** Installation language: an explicit choice (?language=, hidden form field), otherwise the first known language from the browser header. */
    private function chooseLanguage(): string
    {
        $choice = (string) ($_POST['language'] ?? $_POST['jazyk'] ?? $_GET['language'] ?? $_GET['jazyk'] ?? '');
        if (isset(self::TIME_ZONES[$choice])) {
            return $choice;
        }
        foreach (explode(',', strtolower((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''))) as $part) {
            $code = substr(trim($part), 0, 2);
            if (isset(self::TIME_ZONES[$code])) {
                return $code;
            }
        }

        return 'cs';
    }

    /** Form of address: an explicit choice (?register=, hidden form field), only in German. */
    private function chooseRegister(string $language): string
    {
        return $language === 'de' && ($_POST['register'] ?? $_GET['register'] ?? '') === 'informal' ? 'informal' : 'formal';
    }

    public function handle(): Response
    {
        if (is_file(KALETA_ROOT . '/config.php')) {
            $language = $this->chooseLanguage();
            \Kaleta\Core\Language::set($language, 'install-', $this->chooseRegister($language));

            return $this->page('done', ['alreadyInstalled' => true, 'deleted' => $this->deleteSelf(), 'fromExport' => false]);
        }

        $this->language = $this->chooseLanguage();
        $this->register = $this->chooseRegister($this->language);
        \Kaleta\Core\Language::setSiteRegister($this->register); // the sample content for visitors is written in the chosen form of address
        \Kaleta\Core\Language::set($this->language, 'install-', $this->register);
        $requirements = $this->requirements();
        $data = [
            'db_host' => 'localhost', 'db_port' => '3306', 'db_name' => '', 'db_user' => '', 'db_password' => '', 'db_prefix' => 'ka_',
            'nazev_webu' => t('My website'), 'user' => 'admin', 'jmeno' => '', 'email' => '',
            'casove_pasmo' => self::TIME_ZONES[$this->language], 'web' => 'firemni', 'jazyk_webu' => $this->language,
        ];
        $errors = [];
        // extensions enabled after installation: the default set, after the form is submitted the user's choice
        $extensions = array_keys(array_filter(Extensions::CATALOG, fn (array $r): bool => $r[2]));

        if ($this->request->isPost() && !in_array(false, array_column($requirements, 'ok'), true)) {
            foreach (array_keys($data) as $key) {
                // the database password is not trimmed - it can contain spaces
                $data[$key] = $key === 'db_password' ? (string) ($_POST[$key] ?? '') : $this->request->post($key);
            }
            $data['jazyk_webu'] = isset(\Kaleta\Core\Language::AVAILABLE[$data['jazyk_webu']]) ? $data['jazyk_webu'] : $this->language;
            $extensions = array_values(array_intersect($this->request->postList('rozsireni'), array_keys(Extensions::CATALOG)));
            $errors = $this->install($data, (string) ($_POST['password'] ?? ''), (string) ($_POST['password2'] ?? ''), $extensions);
            if ($errors === []) {
                return $this->page('done', ['alreadyInstalled' => false, 'deleted' => $this->deleteSelf(), 'fromExport' => $data['web'] === 'export',
                    'mcp' => in_array('claude', $extensions, true) ? $this->request->origin() . $this->request->basePath() . '/mcp' : null,
                    'cron' => '*/5 * * * * curl -s "' . $this->request->origin() . $this->request->basePath() . '/' . $this->cronPath . '?token=' . $this->tasksToken . '" > /dev/null']);
            }
        }

        return $this->page('form', ['requirements' => $requirements, 'data' => $data, 'errors' => $errors, 'extensions' => $extensions]);
    }

    /**
     * After installation the installer deletes itself, so the administrator does not have to use FTP. When the hosting does
     * not allow it (file permissions), a prompt to delete it remains and the system health page keeps warning about the file.
     * In a development copy (a .git folder) it is not deleted.
     */
    private function deleteSelf(): bool
    {
        if (is_dir(KALETA_ROOT . '/.git')) {
            return false;
        }

        return !is_file(KALETA_ROOT . '/install.php') || @unlink(KALETA_ROOT . '/install.php');
    }

    /** @return list<array{nazev:string, ok:bool, info:string}> */
    private function requirements(): array
    {
        $write = fn (string $path): bool => is_writable(KALETA_ROOT . $path);

        return [
            ['nazev' => t('PHP %s or newer', KALETA_MIN_PHP), 'ok' => version_compare(PHP_VERSION, KALETA_MIN_PHP, '>='), 'info' => t('running') . ' ' . PHP_VERSION],
            ['nazev' => t('pdo_mysql extension'), 'ok' => extension_loaded('pdo_mysql'), 'info' => t('connection to a MySQL / MariaDB database')],
            ['nazev' => t('mbstring extension'), 'ok' => extension_loaded('mbstring'), 'info' => t('working with accented text (UTF-8)')],
            ['nazev' => t('Write access to the root folder'), 'ok' => $write(''), 'info' => t('needed to create config.php')],
            ['nazev' => t('Write access to the storage/ folder'), 'ok' => $write('/storage/log') && $write('/storage/cache'), 'info' => t('logs and cache')],
        ];
    }

    /**
     * @param array<string, string> $d
     * @param list<string> $extensions enabled extensions
     * @return array<string, string> errors; empty array = installed
     */
    private function install(array $d, string $password, string $password2, array $extensions): array
    {
        $errors = [];
        if (!preg_match('/^[a-z][a-z0-9_]{0,15}$/D', $d['db_prefix'])) {
            $errors['db_prefix'] = t('Prefix: lowercase letters, digits and underscore, at most 16 characters (e.g. ka_).');
        }
        if ($d['db_name'] === '' || $d['db_user'] === '') {
            $errors['db_name'] = t('Fill in the database name and user.');
        }
        if (!preg_match('/^[a-zA-Z0-9._-]{2,40}$/D', $d['user'])) {
            $errors['user'] = t('Username: 2–40 characters, letters without accents, digits, dot, hyphen, underscore.');
        }
        if (mb_strlen($password) < 10) {
            $errors['password'] = t('The password must be at least 10 characters long.');
        } elseif ($password !== $password2) {
            $errors['password'] = t('The passwords do not match.');
        }
        if ($d['email'] !== '' && filter_var($d['email'], FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = t('The e-mail address is not valid.');
        }
        if ($errors !== []) {
            return $errors;
        }

        $config = [
            'db' => [
                'host' => $d['db_host'] !== '' ? $d['db_host'] : 'localhost',
                'port' => (int) $d['db_port'] ?: 3306,
                'name' => $d['db_name'],
                'user' => $d['db_user'],
                'password' => $d['db_password'],
                'prefix' => $d['db_prefix'],
            ],
            'debug' => false,
        ];

        try {
            $db = Db::fromConfig($config['db']);
            $db->pdo();
        } catch (\PDOException $e) {
            return self::connectionError($e);
        }
        $exists = $db->value(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            [$d['db_prefix'] . 'uzivatele'],
        );
        if ((int) $exists > 0) {
            return ['db_prefix' => t('Tables with this prefix already exist in the database. Choose another prefix or remove them first.')];
        }

        try {
            foreach (Migration::statements((string) file_get_contents(KALETA_SYSTEM . '/sql/schema.sql'), $d['db_prefix']) as $sql) {
                $db->pdo()->exec($sql);
            }
            $this->createDefaultData($db, $d, $password, $extensions);
            // the cron line names the path the site answers the cron on – never a page's (3.7, N37-5)
            $this->cronPath = \Kaleta\Core\Routes::publicSystemPath('ulohy', $db) ?? 'ulohy';
        } catch (\PDOException $e) {
            return ['db_name' => t('Creating the tables failed:') . ' ' . $e->getMessage()];
        }

        $content = "<?php\n/**\n * Kaleta - configuration created by the installer on " . date('Y-m-d') . ".\n */\n\nreturn " . var_export($config, true) . ";\n";
        if (file_put_contents(KALETA_ROOT . '/config.php', $content, LOCK_EX) === false) {
            return ['db_name' => t('The tables were created, but config.php could not be written. Check the write permissions.')];
        }

        return [];
    }

    /**
     * A database connection error in human terms and at the field that needs fixing (MySQL/MariaDB error code); an unknown error with the driver's text.
     *
     * @return array<string, string>
     */
    private static function connectionError(\PDOException $e): array
    {
        return match ((int) ($e->errorInfo[1] ?? $e->getCode())) {
            1045 => ['db_user' => t('The database user name or password is wrong. Check them in your hosting control panel.')],
            1044 => ['db_name' => t('This user has no access to the database. Grant it in your hosting control panel.')],
            1049 => ['db_name' => t('There is no database with this name on the server. Create it in your hosting control panel or correct the name.')],
            2002, 2005, 2006 => ['db_host' => t('Could not connect to the database server. Check the server and port.')],
            default => ['db_name' => t('Could not connect to the database:') . ' ' . $e->getMessage()],
        };
    }

    /**
     * @param array<string, string> $d
     * @param list<string> $extensions
     */
    private function createDefaultData(Db $db, array $d, string $password, array $extensions): void
    {
        // the chosen time zone already applies to the initial content: otherwise the welcome news item could have a date "in the future" and the site would not show it
        $timeZone = in_array($d['casove_pasmo'], \DateTimeZone::listIdentifiers(), true) ? $d['casove_pasmo'] : self::TIME_ZONES[$this->language];
        date_default_timezone_set($timeZone);
        $db->pdo()->exec("SET time_zone = '" . date('P') . "'");
        $d['casove_pasmo'] = $timeZone;
        $this->tasksToken = bin2hex(random_bytes(16));
        $db->transaction(function (Db $db) use ($d, $password, $extensions): void {
            $admin = $db->insert('uzivatele', [
                'user' => $d['user'],
                'password' => password_hash($password, PASSWORD_DEFAULT),
                'jmeno' => $d['jmeno'],
                'email' => $d['email'],
                'admin' => Auth::ADMIN,
                'jazyk' => $this->language === 'cs' ? '' : $this->language, // the admin of the first account in the installation language
                'register' => $this->register === 'informal' ? 'informal' : '',
                'potvrzeno' => date('Y-m-d H:i:s'),
            ]);

            // the site content is created in the site language (the site dictionary), the admin of the first account stays in the installation language
            $siteLanguage = $d['jazyk_webu'];
            if ($d['web'] === 'export') {
                // "Start from an export" (1.8): an empty site – the content, look and settings come with the import (Import and export)
                $settings = ['site_name' => $d['nazev_webu'], 'site_url' => $this->request->origin(), 'site_email' => $d['email'], 'site_language' => $siteLanguage, 'german_register' => $this->register,
                    'time_zone' => $d['casove_pasmo'], 'tasks_token' => $this->tasksToken, 'db_version' => (string) Migration::latest(), 'data_migrations' => implode(',', Migration::DATA), 'extensions' => $extensions === [] ? '-' : implode(',', $extensions)];
                foreach ($settings as $key => $value) {
                    $db->insert('nastaveni', ['promenna' => $key, 'hodnota' => $value]);
                }

                return;
            }
            $x = fn (string $text): string => \Kaleta\Core\Language::runWith($siteLanguage, fn (): string => t($text));
            // skeleton of a typical company site: home, about us, services, contact – the texts are only a guide to what belongs on the page
            $pages = [
                [$x('Úvod'), slugify($x('Úvod')), 0, '<h1>' . e($d['nazev_webu']) . '</h1><p>' . e($x('In one sentence: what you do and for whom. Edit this page in the administration under Pages.')) . '</p>'],
                [$x('About us'), slugify($x('About us')), 1, '<p>' . e($x('Who you are, how long you have been doing it and why customers trust you.')) . '</p>'],
                [$x('Services'), slugify($x('Services')), 1, '<p>' . e($x('What you offer – each service briefly and clearly.')) . '</p>'],
                [$x('Contact'), slugify($x('Contact')), 1, '<p>' . e($x('Address, phone, e-mail and opening hours.')) . '</p>'],
            ];
            // pages straight from builder sections according to the chosen sample site – a new site looks like a site, not like an empty template
            $siteSettings = Library::SITES[$d['web']] ?? Library::SITES['firemni'];
            $home = 0;
            foreach ($pages as $i => [$title, $url, $inMenu, $text]) {
                $row = ['titulek' => $title, 'seo_link' => $url, 'text' => $text, 'v_menu' => $inMenu, 'poradi' => ($i + 1) * 10];
                if (($siteSettings['stranky'][$i] ?? []) !== []) {
                    // sections with elements of disabled extensions (news list, form) are not put on the initial pages, neither are empty images
                    $build = Library::page($db, $siteSettings['stranky'][$i], $title, $siteLanguage, Build::disabledTypes($extensions), true);
                    $row['stavba'] = Build::toJson($build);
                    $row['text'] = Build::asText($build);
                }
                $id = $db->insert('stranky', $row);
                $home = $home ?: $id;
            }

            // privacy policy: a skeleton to fill in, in the site language (the site dictionary, not the installer's), hidden until the
            // administrator fills it in and publishes it (First steps remind of it); outside the main menu, linked from the footer,
            // the cookie bar and the consent in the form
            [$privacyPolicy, $privacyPolicyText] = \Kaleta\Core\Language::runWith($siteLanguage, fn (): array => [t('Privacy policy'), Library::privacyPolicyText()]);
            $privacyPolicyId = $db->insert('stranky', ['titulek' => $privacyPolicy, 'seo_link' => slugify($privacyPolicy), 'text' => $privacyPolicyText, 'zobrazit' => 0, 'v_menu' => 0, 'poradi' => 90]);
            \Kaleta\Core\Menu::save($db, 'paticka', '', [['typ' => 'stranka', 'ids' => $privacyPolicyId, 'text' => '']]);

            \Kaleta\Core\Search::complete($db);
            $settings = ['site_name' => $d['nazev_webu'], 'site_url' => $this->request->origin(), 'site_email' => $d['email'], 'site_language' => $siteLanguage, 'german_register' => $this->register,
                'design_system' => (string) json_encode(\Kaleta\Builder\DesignSystem::preset($siteSettings['predvolba']), JSON_UNESCAPED_SLASHES),
                'time_zone' => $d['casove_pasmo'], 'tasks_token' => $this->tasksToken, 'home_page' => (string) $home, 'db_version' => (string) Migration::latest(), 'data_migrations' => implode(',', Migration::DATA),
                'extensions' => $extensions === [] ? '-' : implode(',', $extensions), 'cookies_policy_url' => $this->request->basePath() . '/' . slugify($privacyPolicy)];
            foreach ($settings as $key => $value) {
                $db->insert('nastaveni', ['promenna' => $key, 'hodnota' => $value]);
            }

            if (!in_array('novinky', $extensions, true)) {
                return; // without news and without the welcome news item
            }
            $category = $db->insert('kategorie', ['nazev' => $x('Aktuality'), 'seo_link' => slugify($x('Aktuality')), 'popis' => '']);
            $db->insert('novinky', [
                'seo_link' => slugify($x('Our new website is live')),
                'titulek' => $x('Our new website is live'),
                'uvod' => '<p>' . e($x('Welcome to our new website. This is where we will share news about our work, projects and offers.')) . '</p>',
                'text' => '<p>' . e($x('We have rebuilt the website so that it is easier to see what we do and how to get in touch. Have a look around – and if you have a question, just write to us.')) . '</p>',
                'tema' => $category,
                'autor' => $admin,
                'datum' => date('Y-m-d H:i:s'),
                'visible' => 1,
            ]);
        });
    }

    /** @param array<string, mixed> $data */
    private function page(string $template, array $data): Response
    {
        return Response::html($this->view->render('install/' . $template, $data + [
            'base' => $this->request->basePath(), 'language' => \Kaleta\Core\Language::code(), 'register' => $this->register,
            'languages' => array_intersect_key(\Kaleta\Core\Language::ADMIN_LANGUAGES, self::TIME_ZONES),
        ]));
    }
}
