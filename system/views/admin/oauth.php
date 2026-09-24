<?php
/**
 * Souhlas s připojením aplikace přes OAuth (konektor Claude a jiné klienty MCP).
 *
 * @var Kaleta\Core\App $app
 * @var string $csrf
 * @var array{nazev:string, redirect_uri:string} $ceka
 * @var array<string, mixed> $user
 * @var string $adresa  server, kam se aplikace po souhlasu vrátí
 */
$role = t(Kaleta\Core\Auth::TYPY[(int) $user['admin']] ?? '');
?>
<div class="oauth-souhlas">
	<p class="oauth-kdo"><strong><?= e($ceka['nazev']) ?></strong> <?= e(t('chce pracovat s webem %s.', $app->settings()->get('nazev_webu'))) ?></p>
	<p><?= e(t('Bude jednat s právy vašeho účtu %s (%s): číst a upravovat stránky, novinky, části webu a vzhled – stejně jako vy v administraci. Stavby a novinky ukládá jako koncept.', (string) $user['user'], $role)) ?></p>
	<p class="napoveda"><?= e(t('Po povolení se vrátíte do aplikace na adrese %s. Připojení kdykoli zrušíte v Můj účet → Připojené aplikace.', $adresa)) ?></p>
	<form method="post" action="<?= e($app->url('admin.php?akce=oauth')) ?>" class="tlacitka">
		<?= $csrf ?>
		<button class="tl" type="submit" name="povolit" value="1"><?= e(t('Povolit přístup')) ?></button>
		<button class="navigace" type="submit" name="povolit" value="0"><?= e(t('Nepovolit')) ?></button>
	</form>
</div>
