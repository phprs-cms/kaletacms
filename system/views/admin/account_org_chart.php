<?php
/**
 * My account → Org chart (3.42, Core\Colleagues::chart): the departments as nested lists – each with its head and its
 * people, whom each person reports to and who is away today with their deputy – then the people in no department. Work data
 * of active accounts only (never the reason of an absence). Plain HTML: no script, one column on a phone, printable.
 *
 * @var Kaleta\Core\App $app
 * @var array<int, array<string, mixed>> $colleagues  Core\Colleagues::all by id
 * @var array{departments: list<array{id: int, name: string, depth: int, parent_id: int|null, head: int|null, members: list<array<string, mixed>>}>, none: list<array<string, mixed>>} $chart
 */
$me = $app->auth()->id();
$name = fn (?int $id): string => $id !== null && isset($colleagues[$id]) ? (string) $colleagues[$id]['name'] : '';
$person = function (array $c, bool $head = false) use ($app, $me, $name): string {
    $html = '<li class="kolega">';
    $html .= $c['photo'] !== '' ? '<img class="kolega-foto" src="' . e($app->request->basePath() . '/' . ltrim((string) $c['photo'], '/')) . '" alt="" width="48" height="48" loading="lazy">'
        : '<span class="avatar kolega-foto" aria-hidden="true">' . e(mb_strtoupper(mb_substr((string) $c['name'], 0, 1))) . '</span>';
    $html .= '<div><strong>' . bdi((string) $c['name']) . '</strong>' . ($c['id'] === $me ? ' <span class="smltxt">(' . e(t('you')) . ')</span>' : '')
        . ($head ? ' <span class="stitek">' . e(t('Head')) . '</span>' : '');
    if ($c['position'] !== '') {
        $html .= '<br><span class="smltxt">' . bdi((string) $c['position']) . '</span>';
    }
    if ($c['reports_to'] !== null && $name($c['reports_to']) !== '') {
        $html .= '<br><span class="smltxt">' . e(t('Reports to %s', $name($c['reports_to']))) . '</span>';
    }
    if ($c['absent_until'] !== '') {
        $html .= '<br><span class="stitek stitek-koncept">' . e(t('Away until %s', format_date((string) $c['absent_until']))) . '</span>'
            . ($c['deputy'] !== null && $name($c['deputy']) !== '' ? ' <span class="smltxt">' . e(t('Deputy: %s', $name($c['deputy']))) . '</span>' : '');
    }
    if ($c['phone'] !== '') {
        $html .= '<br><a href="tel:' . e((string) preg_replace('/[^+\d]/', '', (string) $c['phone'])) . '">' . e((string) $c['phone']) . '</a>';
    }
    if ($c['email'] !== '') {
        $html .= '<br><a href="mailto:' . e((string) $c['email']) . '">' . bdi((string) $c['email']) . '</a>';
    }

    return $html . '</div></li>';
};
$children = [];
foreach ($chart['departments'] as $d) {
    $children[$d['parent_id'] ?? 0][] = $d;
}
$top = array_values(array_filter($chart['departments'], fn (array $d): bool => $d['depth'] === 0));
$branch = function (array $departments) use (&$branch, $children, $colleagues, $person): string {
    $html = '<ul class="organigram">';
    foreach ($departments as $d) {
        $html .= '<li class="organigram-oddeleni"><section aria-labelledby="oddeleni-' . $d['id'] . '"><h2 id="oddeleni-' . $d['id'] . '">' . bdi($d['name']) . '</h2>';
        $people = $d['members'];
        if ($d['head'] !== null && !in_array($d['head'], array_column($people, 'id'), true) && isset($colleagues[$d['head']])) {
            array_unshift($people, $colleagues[$d['head']]); // a head from another department is still shown here first
        }
        $html .= $people !== [] ? '<ul class="kolegove">' . implode('', array_map(fn (array $c): string => $person($c, $c['id'] === $d['head']), $people)) . '</ul>'
            : '<p class="napoveda">' . e(t('Nobody in this department yet.')) . '</p>';
        $html .= isset($children[$d['id']]) ? $branch($children[$d['id']]) : '';
        $html .= '</section></li>';
    }

    return $html . '</ul>';
};
?>
<?= $app->view->render('admin/account_tabs', ['app' => $app, 'current' => 'org_chart']) ?>
<p class="napoveda"><?= e(t('Departments, their heads and who reports to whom. Administrators set them in Users → Departments; who is away and their deputy each person adds in My account.')) ?></p>
<?php if ($chart['departments'] === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'uzivatele', 'heading' => t('No departments yet.'),
    'text' => t('An administrator creates departments in Users → Departments and chooses each person\'s department and whom they report to.'), 'action' => null]) ?>
<?php else: ?>
<?= $branch($top) ?>
<?php endif ?>
<?php if ($chart['none'] !== []): ?>
<section aria-labelledby="oddeleni-zadne" class="organigram-zbytek">
<h2 id="oddeleni-zadne"><?= e($chart['departments'] === [] ? t('Colleagues') : t('Not in a department')) ?></h2>
<ul class="kolegove"><?= implode('', array_map(fn (array $c): string => $person($c), $chart['none'])) ?></ul>
</section>
<?php endif ?>
