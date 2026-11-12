<?php
/**
 * My account → Colleagues (3.37, Core\Colleagues): everyone with an active account – photo or initial, name, position,
 * work phone and e-mail as each of them entered it in My account. For every signed-in user.
 *
 * @var Kaleta\Core\App $app
 * @var list<array{id: int, name: string, position: string, phone: string, email: string, photo: string}> $colleagues
 */
$me = $app->auth()->id();
?>
<?= $app->view->render('admin/account_tabs', ['app' => $app, 'current' => 'colleagues']) ?>
<p class="napoveda"><?= e(t('Everyone with an account on this site. Each of them fills in their position and work phone in My account.')) ?></p>
<ul class="kolegove">
<?php foreach ($colleagues as $c): ?>
	<li class="kolega">
<?php if ($c['photo'] !== ''): ?>
		<img class="kolega-foto" src="<?= e($app->request->basePath() . '/' . ltrim($c['photo'], '/')) ?>" alt="" width="48" height="48" loading="lazy">
<?php else: ?>
		<span class="avatar kolega-foto" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($c['name'], 0, 1))) ?></span>
<?php endif ?>
		<div>
			<strong><?= bdi($c['name']) ?></strong><?= $c['id'] === $me ? ' <span class="smltxt">(' . e(t('you')) . ')</span>' : '' ?>
<?php if ($c['position'] !== ''): ?>
			<br><span class="smltxt"><?= bdi($c['position']) ?></span>
<?php endif ?>
<?php if ($c['phone'] !== ''): ?>
			<br><a href="tel:<?= e((string) preg_replace('/[^+\d]/', '', $c['phone'])) ?>"><?= e($c['phone']) ?></a>
<?php endif ?>
<?php if ($c['email'] !== ''): ?>
			<br><a href="mailto:<?= e($c['email']) ?>"><?= bdi($c['email']) ?></a>
<?php endif ?>
		</div>
	</li>
<?php endforeach ?>
</ul>
<?php if (count($colleagues) <= 1): ?>
<p class="napoveda"><?= e(t('You are the only one with an account so far.')) ?></p>
<?php endif ?>
