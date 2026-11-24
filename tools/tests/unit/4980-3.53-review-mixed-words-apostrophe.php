<?php

declare(strict_types=1);

use Kaleta\Core\Search;
use Kaleta\Core\SearchIndex;
use Kaleta\Core\SiteSearch;

/* ---------- 3.53 review 2: a mixed CJK word in the lists, the Ukrainian apostrophe, the re-index counts as progress ---------- */

// SQL LIKE (no escapes in these patterns) as a regular expression, to read a condition against a stored search text
$r980Like = fn (string $pattern, string $subject): bool => preg_match('/^' . str_replace('%', '.*', preg_quote($pattern, '/')) . '$/us', $subject) === 1;
$r980Text = Search::normalize('我们使用WordPress搭建网站，商品价格100元');
[$r980Sql, $r980Params] = Search::indexWords('使用WordPress', '', ['titulek']);
check('3.53 review 2: indexWords – "使用WordPress" ANDs its pieces (使用 anywhere, wordpress from a word start), so a text where 使用 is inside a Chinese run is found; the one phrase would not be', [
    $r980Text, array_slice($r980Params, 1), substr_count($r980Sql, ' AND '),
    $r980Like($r980Params[1], $r980Text) && $r980Like($r980Params[2], ' ' . $r980Text), $r980Like('% 使用 wordpress%', ' ' . $r980Text)],
    ['我们使用 wordpress 搭建网站 商品价格 100 元', ['%使用%', '% wordpress%'], 1, true, false]);
[, $r980Price] = Search::indexWords('价格100', '', ['titulek']);
[, $r980Plain] = Search::indexWords('R&B', '', ['titulek']);
check('3.53 review 2: indexWords – "价格100" is found inside "商品价格100元"; a Latin phrase ("R&B") stays one phrase from a word start', [
    array_slice($r980Price, 1), $r980Like($r980Price[1], $r980Text) && $r980Like($r980Price[2], ' ' . $r980Text), array_slice($r980Plain, 1)],
    [['%价格%', '% 100%'], true, ['% r b%']]);

// the apostrophe: a Latin elision splits, a Cyrillic word keeps it (the phrase), a part with no piece long enough too
$r980Words = fn (string $q, string $language = 'uk'): array => array_column(SiteSearch::words($q, $language), 'word');
$r980Found = fn (string $q): bool => SiteSearch::rank(SiteSearch::words($q, 'uk'), [['title' => 'Кава', 'normal' => Search::normalize('Кава. Він п\'є каву, п\'ять чашок.')]], true) !== [];
check('3.53 review 2: "п\'є", "б\'є" and "п\'ять" stay phrases ("п є", "п ять") and find the page; "l\'hôtel", "women\'s" still split; "b\'z" keeps its phrase', [
    $r980Words("п'є"), $r980Words('б’є'), $r980Words("п'ять м'ясо"), $r980Found("п'є"), $r980Found("п'ять"), $r980Found("б'є"),
    $r980Words("l'hôtel", 'fr'), $r980Words("women's rock\u{02BC}n'roll", 'en'), $r980Words("b'z", 'en')],
    [['п є'], ['б є'], ['п ять', 'м ясо'], true, true, false, ['hotel'], ['women', 'rock', 'roll'], ['b z']]);

// stuck() reads "waiting:examined" (run() adds the examined news, smoke 4980 runs it): with the version current nothing is examined
$r980Settings = new Kaleta\Core\Settings(new Kaleta\Core\Db('sqlite::memory:', '', ''));
(new ReflectionProperty($r980Settings, 'values'))->setValue($r980Settings, ['db_version' => '141', SearchIndex::STATE => Search::VERSION . ':0']);
check('3.53 review 2: reindexNews reports the rows rewritten and the rows examined – none of either when the news index has the current version', [
    SearchIndex::reindexNews(new Kaleta\Core\Db('sqlite::memory:', '', ''), $r980Settings, 5.0)],
    [['rewritten' => 0, 'examined' => 0]]);
