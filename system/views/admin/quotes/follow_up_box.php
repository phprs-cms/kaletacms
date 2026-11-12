<?php
/**
 * The box "Remind me in N days" of the send dialog (3.40, Admin\QuoteExtras::followUpAfterSend): after the quote goes out
 * (or is finalised) a task about it for its responsible person – for the sender when nobody is responsible. Off unless
 * ticked.
 */
use Kaleta\Core\Quotes;
?>
	<div class="radek" data-quote-send-follow-up><span></span><div><label><input type="checkbox" name="follow_up" value="1"> <?= e(t('Remind me in')) ?></label>
		<input class="textpole cislo-kratke" type="number" name="follow_up_days" min="1" max="<?= Quotes::FOLLOW_UP_MAX_DAYS ?>" value="<?= Quotes::FOLLOW_UP_DAYS ?>" aria-label="<?= e(t('Days until the reminder')) ?>"> <?= e(t('days')) ?>
		<span class="napoveda"><?= e(t('A task about this quote for its responsible person (for you when nobody is responsible).')) ?></span></div></div>
