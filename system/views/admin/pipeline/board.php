<?php
/**
 * The board of the Pipeline (3.31): a column per stage with its count and, for open stages, the sums of the open values per
 * currency; won and lost columns hold the deals closed this month. Every card moves without JavaScript (views/admin/
 * pipeline/card.php); image/admin.js ([data-pipeline-board]) adds drag and drop and the keyboard (Space, arrows, Space,
 * Esc) and announces each move. On a phone the columns stack.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Pipeline $module
 * @var string $csrf
 * @var array{owner: string, contact: int, company: int, search: string, status: string, view: string} $filter
 * @var array<string, int|string> $query
 * @var list<array{key: string, label: string, kind: string, deals: list<array<string, mixed>>, count: int, sums: array<string, string>}> $columns
 * @var list<array{key: string, label: string, kind: string, custom: bool}> $stages
 * @var array<int, string> $owners
 * @var array<int, string> $people
 * @var list<array<string, mixed>> $companies
 * @var string $contactName
 * @var string $companyName
 * @var int $more open deals beyond Pipeline::BOARD_MAX
 * @var string $today
 * @var bool $canContacts
 * @var bool $admin
 * @var int $me
 */
use Kaleta\Admin\Modules\Pipeline;
use Kaleta\Core\Deals;

$total = array_sum(array_column($columns, 'count'));
$filtered = $filter['search'] !== '' || $filter['owner'] !== '' || $filter['company'] > 0 || $filter['contact'] > 0;
$return = http_build_query(['module' => 'pipeline'] + $query);
$sums = fn (array $sums): string => implode(' · ', array_map(fn (string $cur, string $sum): string => Deals::formatValue($sum, $cur), array_keys($sums), $sums));
?>
<?= $app->view->render('admin/pipeline/tabs', ['module' => $module, 'current' => 'board', 'query' => $query, 'admin' => $admin]) ?>
<?= $app->view->render('admin/pipeline/filter', ['app' => $app, 'module' => $module, 'filter' => $filter, 'people' => $people, 'companies' => $companies, 'contactName' => $contactName,
    'companyName' => $companyName, 'canContacts' => $canContacts, 'me' => $me]) ?>
<?php if ($total === 0 && !$filtered): ?>
<?= $app->view->render('admin/empty', ['icon' => 'obchody', 'heading' => t('No deals yet.'),
    'text' => t('A deal is what a customer may buy – it moves from New to Won or Lost. Create one here, or with “Create deal” on an enquiry or a customer.'),
    'action' => [$module->url('new'), t('New deal')]]) ?>
<?php else: ?>
<?php if ($more > 0): ?>
<p class="hlaska hlaska-info"><?= e(t('The board shows the first %d open deals – %d more are in the list.', Pipeline::BOARD_MAX, $more)) ?> <a href="<?= e($module->url('', ['view' => 'list'] + $query)) ?>"><?= e(t('Open the list')) ?></a></p>
<?php endif ?>
<?php /* 3.37 (U28): the instructions fold behind "How to move a deal" – the keyboard handles still read them (aria-describedby) */ ?>
<details class="obchody-navod"><summary><?= e(t('How to move a deal')) ?></summary><p class="napoveda" id="obchody-navod"><?= e(t('Drag a card to another column, or choose its stage and press Move – with a mouse they are behind ⋯ on the card. With the keyboard: focus the handle of a card, press Space, choose the stage with the arrow keys and press Space again; Esc cancels.')) ?></p></details>
<div class="obchody-tabule" data-pipeline-board data-money="<?= e((string) json_encode(Deals::moneyFormat(), JSON_UNESCAPED_UNICODE)) ?>">
<?php foreach ($columns as $col): ?>
	<section class="obchody-sloupec obchody-<?= e($col['kind']) ?>" data-stage="<?= e($col['key']) ?>" data-kind="<?= e($col['kind']) ?>" aria-labelledby="etapa-<?= e($col['key']) ?>">
		<header class="obchody-hlava">
			<h2 id="etapa-<?= e($col['key']) ?>"><span data-stage-label><?= e($col['label']) ?></span> <span class="obchody-pocet" data-stage-count><?= format_count($col['count']) ?></span></h2>
<?php if ($col['kind'] === 'open'): ?>
			<p class="obchody-soucty" data-stage-sums><?= e($sums($col['sums'])) ?></p>
<?php else: ?>
			<p class="obchody-soucty"><?= e(t('closed this month')) ?></p>
<?php endif ?>
		</header>
		<ol class="obchody-karty" data-stage-cards>
<?php foreach ($col['deals'] as $d): ?>
<?= $app->view->render('admin/pipeline/card', ['app' => $app, 'module' => $module, 'csrf' => $csrf, 'd' => $d, 'stages' => $stages, 'owners' => $owners, 'return' => $return,
    'today' => $today, 'canContacts' => $canContacts]) ?>
<?php endforeach ?>
		</ol>
		<p class="obchody-prazdne napoveda" data-stage-empty<?= $col['count'] > 0 ? ' hidden' : '' ?>><?= e(t('No deal here.')) ?></p>
	</section>
<?php endforeach ?>
</div>
<p class="navod-skryte" aria-live="polite" data-pipeline-announce></p>
<?php endif ?>
