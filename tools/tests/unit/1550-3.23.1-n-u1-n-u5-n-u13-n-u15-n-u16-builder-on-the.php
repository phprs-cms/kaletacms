<?php

declare(strict_types=1);

/* ---------- 3.23.1 (N-U1, N-U5, N-U13, N-U15, N-U16): builder on the canvas, at 1024–1179 px and for screen readers ---------- */
$builder3231 = (string) file_get_contents(KALETA_ROOT . '/image/stavitel.js');
$builderCss3231 = (string) file_get_contents(KALETA_ROOT . '/image/stavitel.css');
check('3.23.1 (N-U1): a paste while editing on the canvas goes through the cleanup of the panel editor; a heading, a button and a quote take plain text', [
    str_contains($builder3231, "target.addEventListener('paste', paste);"), str_contains($builder3231, "target.removeEventListener('paste', paste);"),
    str_contains($builder3231, "doc.execCommand('insertHTML', false, window.kaletaCistiHtml(html, 'paste'))"),
    str_contains($builder3231, "const html = n.p.typ === 'text' && window.kaletaCistiHtml ? transfer.getData('text/html') : '';"),
    str_contains((string) file_get_contents(KALETA_ROOT . '/image/editor.js'), 'window.kaletaCistiHtml = cleanHtml;'),
], [true, true, true, true, true]);
check('3.23.1 (N-U5, N-U13): the rails up to 1179 px in the style and the script alike; the first selection on the canvas opens Properties without taking the focus', [
    substr_count($builderCss3231, '@media (max-width: 1179px) {'), substr_count($builderCss3231, '@media (max-width: 1023px)'),
    str_contains($builder3231, "window.matchMedia('(max-width: 1179px)')"), str_contains($builder3231, "window.matchMedia('(max-width: 1023px)')"),
    str_contains($builder3231, "if (t && state.vybrane && narrowLayout.matches && !state.vlastnostiUkazany) { state.vlastnostiUkazany = true; setPanel('pravy', false, true); }"),
], [1, 0, true, false, true]);
check('3.23.1 (N-U15): Alt+arrow moves are announced in a visually hidden live region, translated', [
    str_contains($builder3231, "el('div', { class: 'st-ohlas', 'aria-live': 'polite' })"),
    str_contains($builderCss3231, '.st-ohlas { position: absolute; width: 1px; height: 1px; overflow: hidden; clip-path: inset(50%);'),
    array_map(fn (string $f): bool => isset($jsDictionary(KALETA_ROOT . '/image/jazyky/admin-' . $f . '.js')['Moved after %s.'], $jsDictionary(KALETA_ROOT . '/image/jazyky/admin-' . $f . '.js')['Moved to the start of %s.'], $jsDictionary(KALETA_ROOT . '/image/jazyky/admin-' . $f . '.js')['Moved to the start of the page.']), ['cs', 'de', 'es']),
], [true, true, [true, true, true]]);
check('3.23.1 (N-U16): "Edit here" keeps image/web.js on the page, and the script skips the page transition into the administration', [
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Front/Kernel.php'), '|rel="alternate" hreflang=|class="ka-upravit-zde"/'),
    str_contains((string) file_get_contents(KALETA_ROOT . '/image/web.js'), "if (transition && next && /\\/admin\\.php$/.test(new URL(next, location.href).pathname)) { transition.skipTransition(); }"),
], [true, true]);

