<?php

declare(strict_types=1);

/* ---------- 2.10: business facts – values by type, how they are shown, the token ---------- */
check('2.10: Facts::clean – a value must fit its type', [Kaleta\Core\Facts::clean('number', '1 500'), Kaleta\Core\Facts::clean('number', 'many'), Kaleta\Core\Facts::clean('year', '2004'),
    Kaleta\Core\Facts::clean('year', '04'), Kaleta\Core\Facts::clean('money', '1500 CZK'), Kaleta\Core\Facts::clean('money', '1500,- Kč'), Kaleta\Core\Facts::clean('date', '2026-10-02'),
    Kaleta\Core\Facts::clean('email', 'info@example.cz'), Kaleta\Core\Facts::clean('url', 'javascript:alert(1)'), Kaleta\Core\Facts::clean('text', '<b>20</b> let'), Kaleta\Core\Facts::clean('text', 'see {{fact.other}}')],
    ['1500', null, '2004', null, '1500 CZK', null, '2026-10-02', 'info@example.cz', null, '20 let', null]);
check('2.10: Facts::display – numbers with the thousands separator of the language, amounts with the currency', Kaleta\Core\Language::runWith('cs', fn (): array => [
    Kaleta\Core\Facts::display('number', '12500'), Kaleta\Core\Facts::display('money', '1500 CZK'), Kaleta\Core\Facts::display('year', '2004'), Kaleta\Core\Facts::display('text', 'od 2004')]),
    ["12\u{00A0}500", "1\u{00A0}500\u{00A0}CZK", '2004', 'od 2004']);
preg_match_all(Kaleta\Core\Facts::TOKEN_PATTERN, 'Since {{fact.founded}} – {{ fact.projects }} projects, {{fact.Bad}}, {{fact.x}}, {{nazev}}', $factTokens);
check('2.10: the fact token – spaces inside are fine, collection fields and bad keys are not facts', $factTokens[1], ['founded', 'projects']);
