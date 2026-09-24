<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Bezpečné HTML od uživatelů bez práva správce (autor a editor novinek a stránek, MCP s jejich tokenem).
 * Na rozdíl od převodu z WordPressu (WpObsah) zachová strukturu i třídy z editoru a odstraní jen to, čím se dá spustit kód
 * nebo převzít účet: skripty, rámy, formuláře, obsluhy událostí, styly, háčky skriptů webu (data-…) a adresy javascript:/data:.
 * Správce smí vkládat vlastní HTML (prvek HTML, šablony), jeho text se proto nečistí.
 */
final class Html
{
    private const array ZAHODIT = ['script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'form', 'input', 'button', 'select', 'textarea',
        'template', 'noscript', 'svg', 'math', 'link', 'meta', 'base', 'head', 'title', 'dialog', 'portal'];

    /** Povolené atributy (mimo aria-*); ostatní – hlavně on…, style, data-…, formaction, srcdoc – zmizí. */
    private const array ATRIBUTY = ['href', 'src', 'alt', 'title', 'class', 'id', 'width', 'height', 'colspan', 'rowspan', 'scope', 'target', 'rel', 'lang', 'dir',
        'loading', 'decoding', 'srcset', 'sizes', 'start', 'reversed', 'type', 'cite', 'datetime', 'controls', 'poster', 'preload', 'playsinline', 'muted', 'loop'];

    public static function bezpecne(string $html): string
    {
        if (trim($html) === '' || !preg_match('/<|&/', $html)) {
            return $html;
        }
        $doc = \Dom\HTMLDocument::createFromString('<!DOCTYPE html><html><body>' . $html . '</body></html>', LIBXML_NOERROR, 'UTF-8');
        $telo = $doc->body;
        if ($telo === null) {
            return '';
        }
        self::uzel($telo);
        $vystup = '';
        foreach ($telo->childNodes as $n) {
            $vystup .= $doc->saveHtml($n);
        }

        return $vystup;
    }

    /** Čistí jen pro uživatele bez práva správce. */
    public static function proUzivatele(string $html, Auth $auth): string
    {
        return $auth->isAdmin() ? $html : self::bezpecne($html);
    }

    private static function uzel(\Dom\Node $uzel): void
    {
        foreach (iterator_to_array($uzel->childNodes) as $n) {
            if ($n instanceof \Dom\Comment) {
                $n->remove();
                continue;
            }
            if (!$n instanceof \Dom\Element) {
                continue;
            }
            if (in_array(strtolower($n->localName), self::ZAHODIT, true)) {
                $n->remove();
                continue;
            }
            foreach (iterator_to_array($n->attributes) as $a) {
                $nazev = strtolower($a->name);
                $ok = (in_array($nazev, self::ATRIBUTY, true) || preg_match('/^aria-[a-z]{2,20}$/', $nazev))
                    && (!in_array($nazev, ['href', 'src', 'poster', 'cite'], true) || WpObsah::bezpecnaAdresa($a->value))
                    && ($nazev !== 'srcset' || !preg_match('/(javascript|data|vbscript):/i', $a->value))
                    && ($nazev !== 'target' || $a->value === '_blank');
                if (!$ok) {
                    $n->removeAttribute($a->name);
                }
            }
            if (strtolower($n->localName) === 'a' && $n->getAttribute('target') === '_blank') {
                $n->setAttribute('rel', 'noopener');
            }
            self::uzel($n);
        }
    }
}
