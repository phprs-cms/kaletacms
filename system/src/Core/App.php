<?php

declare(strict_types=1);

namespace MiroCMS\Core;

/**
 * Jednoduchý kontejner sdílených služeb. Žádná magie - co aplikace umí, je vidět tady.
 */
final class App
{
    public readonly Request $request;
    public readonly Session $session;
    public readonly View $view;

    private ?Db $db = null;
    private ?Auth $auth = null;
    private ?Settings $settings = null;

    /** @param array<string, mixed> $config obsah config.php */
    public function __construct(public readonly array $config, ?Request $request = null)
    {
        $this->request = $request ?? Request::fromGlobals();
        $this->session = new Session($this->request->isHttps(), $this->request->basePath() . '/');
        $this->view = new View([MIROCMS_SYSTEM . '/views']);
    }

    /** Načte config.php; když chybí, web ještě není nainstalovaný. */
    public static function boot(): self
    {
        $file = MIROCMS_ROOT . '/config.php';
        if (!is_file($file)) {
            $base = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
            header('Location: ' . $base . '/install.php');
            exit;
        }
        // během aktualizace souborů web krátce odpovídá 503 (zámek starší než 10 minut je pozůstatek a ignoruje se);
        // slovník tu ještě není načtený, proto je pod českým textem krátká anglická věta
        $zamek = MIROCMS_ROOT . '/storage/udrzba.lock';
        if (is_file($zamek) && time() - (int) filemtime($zamek) < 600 && basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')) !== 'admin.php') {
            http_response_code(503);
            header('Retry-After: 60');
            header('Content-Type: text/html; charset=utf-8');
            exit('<!doctype html><meta charset="utf-8"><title>Probíhá aktualizace</title><body style="font:16px system-ui,sans-serif;margin:3em"><h1 style="font-size:22px">Web se právě aktualizuje</h1><p>Zkuste to prosím za minutu.</p><p lang="en" style="color:#666">The site is being updated. Please try again in a minute.</p>');
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
     * Časové pásmo webu z Nastavení platí pro PHP i pro relaci databáze (data zapisuje PHP, dotazy je porovnávají s NOW()).
     * Volají oba kernely hned po startu; do té doby platí výchozí pásmo z bootstrapu.
     */
    public function casovePasmo(): void
    {
        $pasmo = $this->settings()->get('casove_pasmo');
        if ($pasmo !== date_default_timezone_get() && in_array($pasmo, \DateTimeZone::listIdentifiers(), true)) {
            date_default_timezone_set($pasmo);
            $this->db()->pdo()->exec("SET time_zone = '" . date('P') . "'");
        }
    }

    public function debug(): bool
    {
        return (bool) ($this->config['debug'] ?? false);
    }

    /** Předpona jazykové verze webu ("en"); nastavuje Front\Kernel, když čtenář prochází /en/… */
    public string $jazykPrefix = '';

    /**
     * Absolutní cesta v rámci instalace: url('admin.php') -> "/magazin/admin.php".
     * V jazykové verzi dostanou adresy stránek webu předponu jazyka (url('clanek/x') -> "/en/clanek/x");
     * soubory a služby (cokoli s příponou, api/, mcp, push/, platba/) zůstávají společné.
     */
    public function url(string $path = ''): string
    {
        $path = ltrim($path, '/');
        if ($this->jazykPrefix !== '') {
            $cesta = explode('?', $path, 2)[0];
            if ((!str_contains($cesta, '.') || $cesta === 'rss.xml' || $cesta === 'feed.json') && !preg_match('#^(api/|mcp$|push/|platba/)#', $cesta)) {
                $path = $this->jazykPrefix . ($path === '' ? '/' : '/' . $path);
            }
        }

        return $this->request->basePath() . '/' . $path;
    }

    /**
     * Adresa článku v JEHO jazykové verzi – nezávisle na tom, ze které verze přišel právě běžící požadavek
     * (oznámení o vydání se rozesílají na pozadí cizí návštěvy).
     */
    public function urlClanku(string $seo, string $jazyk): string
    {
        $puvodni = $this->jazykPrefix;
        $this->jazykPrefix = in_array($jazyk, Jazyk::dalsi($this->settings()), true) ? $jazyk : '';
        try {
            return $this->url('clanek/' . $seo);
        } finally {
            $this->jazykPrefix = $puvodni;
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
            @file_put_contents(MIROCMS_ROOT . '/storage/log/chyby.log', $line, FILE_APPEND | LOCK_EX);
            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: text/html; charset=utf-8');
            }
            echo '<!doctype html><meta charset="utf-8"><title>Chyba</title>'
                . '<body style="font:14px Verdana,sans-serif;margin:3em">'
                . '<h1 style="font-size:18px">Omlouváme se, na stránce došlo k chybě.</h1>';
            if ($this->debug()) {
                echo '<pre style="white-space:pre-wrap">' . e((string) $e) . '</pre>';
            } else {
                echo '<p>Podrobnosti najde správce v souboru storage/log/chyby.log.</p>';
            }
        });
    }
}
