<?php

declare(strict_types=1);

/* ---------- 3.31.2 (pre-release review): rich text drops Kaleta's "ka-" classes like the builder; the editor's own classes stay ---------- */
check('3.31.2: Html::textClasses – "ka-" tokens go in any case, other classes stay as they were, nothing left = ""', [
    Kaleta\Core\Html::textClasses('ka-kolekce-strany karta'), Kaleta\Core\Html::textClasses('KA-X Ka-y'), Kaleta\Core\Html::textClasses('galerie'), Kaleta\Core\Html::textClasses(' a  b '),
    Kaleta\Core\Html::textClasses("karta\tka-tlacitko\nvelke"), Kaleta\Core\Html::textClasses('aka-x ka_x kaz')],
    ['karta', '', 'galerie', ' a  b ', 'karta velke', 'aka-x ka_x kaz']);
check('3.31.2: Html::safe leaves "ka-" classes out of a news text or description (the attribute goes when nothing is left) and keeps what the editor writes – a gallery, an image with its Media number, a table, a link', [
    Kaleta\Core\Html::safe('<p class="ka-kolekce-strany karta"><a class="KA-Tlacitko" href="https://example.com/x">x</a></p>'),
    Kaleta\Core\Html::safe('<figure class="galerie"><img src="/media/a.jpg" alt="A" data-id="12"></figure><table><tbody><tr><td class="vpravo">1</td></tr></tbody></table>'),
    Kaleta\Core\Html::safe('<div class="ka-pobocky-seznam"><p id="cenik" class="ka-x">t</p></div>')],
    ['<p class="karta"><a href="https://example.com/x">x</a></p>', '<figure class="galerie"><img src="/media/a.jpg" alt="A" data-id="12"></figure><table><tbody><tr><td class="vpravo">1</td></tr></tbody></table>',
        '<div><p id="cenik">t</p></div>']);
// the admin editor puts no "ka-" class into a text (so no allowlist is needed); if it ever does, this fails and Html::textClasses needs one
preg_match_all('/(?<![a-z0-9_-])ka-[a-z0-9-]+/', (string) file_get_contents(KALETA_ROOT . '/image/editor.js'), $t3312Editor);
check('3.31.2: image/editor.js writes no "ka-" class into the text it edits', $t3312Editor[0], []);
