<?php

declare(strict_types=1);

namespace MiroCMS\Core;

/**
 * Šablony jsou obyčejné PHP soubory. Proměnné z $data jsou v šabloně dostupné přímo,
 * výstup se ošetřuje funkcí e().
 */
final class View
{
    /** @param list<string> $dirs adresáře prohledávané v daném pořadí (např. layout webu, pak systémové šablony) */
    public function __construct(private readonly array $dirs)
    {
    }

    /** @param array<string, mixed> $data */
    public function render(string $template, array $data = []): string
    {
        $file = $this->find($template);

        return (static function (string $__file, array $__data, View $view): string {
            extract($__data, EXTR_SKIP);
            ob_start();
            try {
                require $__file;

                return (string) ob_get_clean();
            } catch (\Throwable $e) {
                ob_end_clean();
                throw $e;
            }
        })($file, $data, $this);
    }

    public function exists(string $template): bool
    {
        try {
            $this->find($template);

            return true;
        } catch (\RuntimeException) {
            return false;
        }
    }

    private function find(string $template): string
    {
        if (!preg_match('#^[a-z0-9_\-/]+$#i', $template)) {
            throw new \RuntimeException("Neplatný název šablony: {$template}");
        }
        foreach ($this->dirs as $dir) {
            $file = $dir . '/' . $template . '.php';
            if (is_file($file)) {
                return $file;
            }
        }
        throw new \RuntimeException("Šablona nenalezena: {$template}");
    }
}
