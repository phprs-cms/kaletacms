<?php

declare(strict_types=1);

/* ---------- 2.15: comments on drafts (Core\DraftComments, Core\Preview) ---------- */
$dc = Kaleta\Core\DraftComments::class;
check('2.15 DraftComments::parseTarget: a page draft only, with a positive id', [$dc::parseTarget('stranka:12'), $dc::parseTarget('stranka:0'), $dc::parseTarget('stranka:12x'), $dc::parseTarget('cast:hlavicka:en'), $dc::parseTarget('popup:3'), $dc::parseTarget('')],
    [['kind' => 'stranka', 'id' => 12], null, null, null, null, null]);
check('2.15 DraftComments::clean: plain text only – tags out, entities decoded, spaces collapsed, one blank line at most, trimmed to the limit',
    [$dc::clean(" <b>Please</b> fix &amp; the   heading\n\n\n  second line <script>x()</script> ", 2000), $dc::clean("abcdef", 3), $dc::clean("<p></p>  \t ", 80), $dc::clean("a\x00b\x07c", 80)],
    ["Please fix & the heading\nsecond line x()", 'abc', '', 'abc']);
check('2.15 DraftComments::cleanElement: builder ids only', [$dc::cleanElement('nad1'), $dc::cleanElement('e_1-x'), $dc::cleanElement('a b'), $dc::cleanElement(''), $dc::cleanElement(str_repeat('a', 41))], ['nad1', 'e_1-x', null, null, null]);
check('2.15: the comment event is known, the tools are a read and a write, the resolve action maps to its tool, the Czech alias of comments is on preview_link',
    [isset(Kaleta\Core\Events::TYPES['comment.received']), Kaleta\Mcp\Catalog::TOOLS['list_draft_comments'], Kaleta\Mcp\Catalog::TOOLS['resolve_draft_comment'],
        Kaleta\Mcp\Translator::arguments('preview_link', ['id' => 3, 'comments' => true])],
    [true, ['read', ''], ['write', ''], ['id' => 3, 'komentare' => true]]);
// the comments flag is signed into the preview key: a plain key never allows comments and a flag added by hand breaks the signature
$dcSettings = $wbSettings(str_repeat('ef', 32));
$dcDb = new Kaleta\Core\Db('mysql:host=127.0.0.1;dbname=none', '', ''); // never connects: the secret key is set
$dcPlain = Kaleta\Core\Preview::key($dcDb, $dcSettings, 'stranka:5', 60);
$dcComments = Kaleta\Core\Preview::key($dcDb, $dcSettings, 'stranka:5', 60, true);
check('2.15 Preview: a key with comments verifies and allows them, a plain key verifies and does not, a forged flag or another target fails',
    [Kaleta\Core\Preview::verify($dcDb, $dcSettings, 'stranka:5', $dcComments), Kaleta\Core\Preview::allowsComments($dcDb, $dcSettings, 'stranka:5', $dcComments),
        Kaleta\Core\Preview::verify($dcDb, $dcSettings, 'stranka:5', $dcPlain), Kaleta\Core\Preview::allowsComments($dcDb, $dcSettings, 'stranka:5', $dcPlain),
        Kaleta\Core\Preview::verify($dcDb, $dcSettings, 'stranka:5', preg_replace('/^(\d{10})\./', '$1k.', $dcPlain)), Kaleta\Core\Preview::allowsComments($dcDb, $dcSettings, 'stranka:6', $dcComments),
        preg_match('/^\d{10}k\.[a-f0-9]{64}$/', $dcComments)],
    [true, true, true, false, false, false, 1]);

