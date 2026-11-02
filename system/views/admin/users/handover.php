<?php
/**
 * Hand over work (3.24, Core\Handover): what the user is responsible for, one person to give it to, and – in the same step –
 * whether the account is then kept, blocked or deleted.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Users $module
 * @var string $csrf
 * @var array<string, mixed> $author  idu, user, jmeno, blokovat
 * @var array{page: int, item: int, enquiry: int} $owned
 * @var array<int, string> $recipients  who may open the sections of that work
 * @var string $then  '' | block | delete – what the link that opened the screen asked for (that button gets the focus)
 * @var bool $isSelf
 */
$name = $author['jmeno'] !== '' ? $author['jmeno'] : $author['user'];
$what = Kaleta\Core\Handover::describe($owned);
?>
<p class="navigace-radek"><a class="navigace" href="<?= e($module->url()) ?>"><?= e(t('Back to overview')) ?></a></p>
<?php if ($what === ''): ?>
<p class="hlaska"><?= e(t('%s is not responsible for any page, collection item or open enquiry.', $name)) ?></p>
<?php else: ?>
<p class="hlaska"><strong><?= e(t('%s is responsible for %s.', $name, $what)) ?></strong> <?= e(t('Choose who takes it over – only people who may open those sections are offered. Resolved enquiries keep who handled them.')) ?></p>
<?php endif ?>
<form class="formular" method="post" action="<?= e($module->url('handover')) ?>">
<?= $csrf ?>
<input type="hidden" name="idu" value="<?= (int) $author['idu'] ?>">
<input type="hidden" name="user" value="<?= e($author['user']) ?>">
<?php if ($what !== ''): ?>
<div class="radek">
	<label for="hand_over_to"><?= e(t('Hand over to')) ?></label>
	<div><select id="hand_over_to" name="hand_over_to" required>
		<option value="" disabled selected><?= e(t('Choose…')) ?></option>
<?php foreach ($recipients as $idu => $recipient): ?>
		<option value="<?= (int) $idu ?>"><?= e($recipient) ?></option>
<?php endforeach ?>
		<option value="0"><?= e(t('nobody (unassigned)')) ?></option>
	</select>
	<span class="napoveda"><?= e(t('Nobody is told by e-mail – the person finds the work in My work.')) ?></span></div>
</div>
<?php endif ?>
<p class="tlacitka">
	<button class="tl" type="submit" name="then" value=""<?= $then === '' ? ' autofocus' : '' ?>><?= e(t('Hand over work')) ?></button>
<?php if (!$isSelf && !$author['blokovat']): ?>
	<button class="tl" type="submit" name="then" value="block"<?= $then === 'block' ? ' autofocus' : '' ?>><?= e(t('Hand over and block the account')) ?></button>
<?php endif ?>
<?php if (!$isSelf): ?>
	<button class="navigace nebezpecne" type="submit" name="then" value="delete" data-potvrdit="<?= e(t('Really delete the user? Their news items will remain, without an author.')) ?>"><?= e(t('Hand over and delete the user')) ?></button>
<?php endif ?>
</p>
</form>
