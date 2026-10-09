<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * A simple container of shared services. No magic - what the application can do is visible here.
 */
final class App
{
    public readonly Request $request;
    public readonly Session $session;
    public readonly View $view;

    private ?Db $db = null;
    private ?Auth $auth = null;
    private ?Settings $settings = null;

    /** @param array<string, mixed> $config contents of config.php */
    public function __construct(public readonly array $config, ?Request $request = null)
    {
        $this->request = $request ?? Request::fromGlobals();
        $this->session = new Session($this->request->isHttps(), $this->request->basePath() . '/');
        $this->view = new View([KALETA_SYSTEM . '/views']);
        Demo::configure($config['demo'] ?? null);
    }

    /** Loads config.php; when it is missing, the site is not installed yet. */
    public static function boot(): self
    {
        $file = KALETA_ROOT . '/config.php';
        if (!is_file($file)) {
            $base = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
            header('Location: ' . $base . '/install.php');
            exit;
        }
        // while files are being updated the site briefly answers 503 (a lock older than 10 minutes is a leftover and is ignored);
        // the dictionary is not loaded here yet, so a short English sentence follows the Czech text
        $lock = KALETA_ROOT . '/storage/udrzba.lock';
        if (is_file($lock) && time() - (int) filemtime($lock) < 600 && basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')) !== 'admin.php') {
            http_response_code(503);
            header('Retry-After: 60');
            header('Content-Type: text/html; charset=utf-8');
            // the site language is not known yet: Czech on purpose only for visitors with Czech or Slovak in the browser (as the error page)
            [$lang, $title, $heading, $help] = preg_match('/^\s*(cs|sk)\b/i', (string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''))
                ? ['cs', 'Probíhá aktualizace', 'Web se právě aktualizuje', 'Zkuste to prosím za minutu.'] // Czech on purpose
                : ['en', 'Updating', 'The site is being updated', 'Please try again in a minute.'];
            exit('<!doctype html><html lang="' . $lang . '"><meta charset="utf-8"><title>' . $title . '</title><body style="font:16px system-ui,sans-serif;margin:3em"><h1 style="font-size:22px">' . $heading . '</h1><p>' . $help . '</p>');
        }
        $app = new self(require $file);
        $app->installErrorHandler();

        return $app;
    }

    public function db(): Db
    {
        return $this->db ??= Db::fromConfig($this->config['db']);
    }

    public function auth(): Auth
    {
        return $this->auth ??= new Auth($this->db(), $this->session);
    }

    public function settings(): Settings
    {
        return $this->settings ??= new Settings($this->db());
    }

    /**
     * The site time zone from Settings applies to PHP and to the database session (PHP writes the dates, queries compare them with NOW()).
     * Both kernels call it right after start; until then the default zone from the bootstrap applies.
     */
    public function applyTimezone(): void
    {
        $timeZone = $this->settings()->get('time_zone');
        if ($timeZone !== date_default_timezone_get() && in_array($timeZone, \DateTimeZone::listIdentifiers(), true)) {
            date_default_timezone_set($timeZone);
            $this->db()->pdo()->exec("SET time_zone = '" . date('P') . "'");
        }
    }

    public function debug(): bool
    {
        return (bool) ($this->config['debug'] ?? false);
    }

    /** Prefix of the site's language version ("en"); set by Front\Kernel when a visitor browses /en/… */
    public string $languagePrefix = '';

    /**
     * Absolute path within the installation: url('admin.php') -> "/magazin/admin.php".
     * In a language version the URLs of site pages get the language prefix (url('novinky/x') -> "/en/novinky/x");
     * files and services (anything with an extension, mcp) stay shared.
     */
    public function url(string $path = ''): string
    {
        $path = ltrim($path, '/');
        $endpoint = false; // a system endpoint keeps one form whatever url_slash says
        if (preg_match('#^(novinky|hledani)(?=$|[/?.])#', $path) && isset($this->config['db'])) {
            // system URLs in the version's language (/news, /search outside Czech) – Core\Routes
            $language = $this->languagePrefix !== '' ? $this->languagePrefix : Language::defaults($this->settings());
            $path = Routes::publicPath($path, $language, $this->db());
        } elseif (preg_match('#^_?[a-z]#', $path) && isset($this->config['db']) && ($public = Routes::publicSystemPath($path, $this->db())) !== null) {
            // 3.7: the English form of a system endpoint (/tasks, /subscription, /form…); the Czech one keeps answering
            $path = $public;
            $endpoint = true;
        }
        if ($this->languagePrefix !== '') {
            $pathOnly = explode('?', $path, 2)[0];
            // a path that already names its language version (a menu link written as /cs/funkce) keeps it – no /cs/cs/…
            $ownPrefix = Language::splitPrefix('/' . $pathOnly) !== null;
            if (!$ownPrefix && (!str_contains($pathOnly, '.') || $pathOnly === 'rss.xml' || $pathOnly === 'feed.json') && $pathOnly !== 'mcp') {
                $path = $this->languagePrefix . ($path === '' ? '/' : '/' . $path);
            }
        }

        // trailing slash preference (setting url_slash): page-like paths only, the query and fragment stay behind it
        if (!$endpoint && isset($this->config['db']) && preg_match('~^([^?#]+)(.*)$~', $path, $m) && !str_ends_with($m[1], '/') && !str_ends_with($m[1], '.html') && Routes::pageLike('/' . $m[1], $this->db())) {
            $path = $m[1] . Routes::suffix($this->settings()->get('url_slash')) . $m[2];
        }

        return $this->request->basePath() . '/' . $path;
    }

    /**
     * URL of a news item in ITS language version – regardless of which version the current request came from
     * (publication notifications are sent in the background of someone else's visit).
     */
    public function newsItemUrl(string $seo, string $language): string
    {
        $previous = $this->languagePrefix;
        $this->languagePrefix = in_array($language, Language::additional($this->settings()), true) ? $language : '';
        try {
            return $this->url('novinky/' . $seo);
        } finally {
            $this->languagePrefix = $previous;
        }
    }

    private function installErrorHandler(): void
    {
        error_reporting(E_ALL);
        ini_set('display_errors', '0');
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });
        set_exception_handler(function (\Throwable $e): void {
            $line = sprintf("[%s] %s: %s in %s:%d\n", date('c'), $e::class, $e->getMessage(), $e->getFile(), $e->getLine());
            @file_put_contents(KALETA_ROOT . '/storage/log/chyby.log', $line, FILE_APPEND | LOCK_EX);
            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: text/html; charset=utf-8');
            }
            // the site language may not be known here yet (an error even at start): Czech on purpose only for visitors with Czech or Slovak in the browser
            $czech = (bool) preg_match('/^\s*(cs|sk)\b/i', (string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
            [$title, $heading, $help] = $czech // Czech on purpose
                ? ['Error', 'Omlouváme se, na stránce došlo k chybě.', 'Podrobnosti najde správce v souboru storage/log/chyby.log.']
                : ['Error', 'Sorry, something went wrong on this page.', 'The site administrator can find the details in storage/log/chyby.log.'];
            echo '<!doctype html><html lang="' . ($czech ? 'cs' : 'en') . '"><meta charset="utf-8"><title>' . $title . '</title>'
                . '<body style="font:14px Verdana,sans-serif;margin:3em">'
                . '<h1 style="font-size:18px">' . $heading . '</h1>';
            if ($this->debug()) {
                echo '<pre style="white-space:pre-wrap">' . e((string) $e) . '</pre>';
            } else {
                echo '<p>' . $help . '</p>';
            }
        });
    }
}
