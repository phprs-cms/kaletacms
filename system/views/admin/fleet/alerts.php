<?php
/**
 * Alerts and digest of the fleet console (3.17, Fleet\AlertRules, Fleet\Digest): which states of a site e-mail the owner
 * and from what threshold, the recipients, the quiet hours, and the weekly digest.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Fleet $module
 * @var array<string, array{on: bool, threshold: ?int}> $rules
 * @var string $recipients the saved fleet_alert_recipients
 * @var list<string> $fallback where the alerts go now
 * @var int $quietHours
 * @var bool $digest
 * @var bool $digestTitles
 * @var bool $alertsEnabled the alert e-mails of System status (the master switch)
 */
use Kaleta\Fleet\AlertRules;

?>
<?= $app->view->render('admin/fleet/tabs', ['module' => $module, 'current' => 'alerts']) ?>
<?php if (!$alertsEnabled): ?><p class="hlaska hlaska-chyba"><?= e(t('Alert e-mails are switched off in System status → Alerts, so no alert is sent – the rules below wait until they are on again.')) ?></p><?php endif ?>
<form class="formular" method="post" action="<?= e($module->url('alerts_save')) ?>">
<?= $csrf ?>
<fieldset>
<legend><?= e(t('Alert rules')) ?></legend>
<p class="napoveda"><?= e(t('The console checks every 5 minutes and e-mails what a rule matches. By default only a site that is down or stopped reporting is sent, as before.')) ?>
	<?= e(t('The inbox uses the same thresholds – also for a rule that is off, which only sends no e-mail.')) ?></p>
<div class="tab-obal"><table class="vypis">
<thead><tr><th scope="col"><?= e(t('Alert')) ?></th><th scope="col"><?= e(t('On')) ?></th><th scope="col"><?= e(t('Threshold')) ?></th></tr></thead>
<tbody>
<?php foreach (AlertRules::KINDS as $kind => [, $default, $label, $thresholdLabel, $min, $max]): ?>
<tr>
	<th scope="row"><label for="on_<?= e($kind) ?>"><?= e(t($label)) ?></label></th>
	<td><input type="checkbox" id="on_<?= e($kind) ?>" name="on_<?= e($kind) ?>" value="1"<?= $rules[$kind]['on'] ? ' checked' : '' ?>></td>
	<td><?php if ($default !== null): ?><input class="textpole" type="number" id="threshold_<?= e($kind) ?>" name="threshold_<?= e($kind) ?>" value="<?= (int) $rules[$kind]['threshold'] ?>" min="<?= (int) $min ?>" max="<?= (int) $max ?>" aria-label="<?= e(t($label) . ': ' . t($thresholdLabel)) ?>">
		<span class="smltxt"><?= e(t($thresholdLabel)) ?></span><?php else: ?><span class="smltxt">–</span><?php endif ?></td>
</tr>
<?php endforeach ?>
</tbody></table></div>
<div class="radek"><label for="fleet_alert_recipients"><?= e(t('Recipients')) ?></label><div><textarea class="textbox nizky" id="fleet_alert_recipients" name="fleet_alert_recipients" rows="2" maxlength="2000"><?= e($recipients) ?></textarea>
<span class="napoveda"><?= e(t('One address per line or separated by commas, at most 10. Empty = the alert address in System status, otherwise the site e-mail.')) ?><?php if ($fallback !== []): ?> <?= e(t('Now: %s', implode(', ', $fallback))) ?><?php endif ?></span>
<span class="napoveda"><?= e(t('The alert and digest e-mails cover every site in the console, of every customer – add only your own team\'s addresses.')) ?></span></div></div>
<div class="radek"><label for="fleet_alert_quiet_hours"><?= e(t('Quiet hours')) ?></label><div><input class="textpole" type="number" id="fleet_alert_quiet_hours" name="fleet_alert_quiet_hours" value="<?= $quietHours ?>" min="1" max="<?= AlertRules::QUIET_MAX ?>">
<span class="napoveda"><?= e(t('The same alert for the same site is not sent again within this many hours; a state that lasts is sent again after them.')) ?></span></div></div>
</fieldset>
<fieldset>
<legend><?= e(t('Weekly digest')) ?></legend>
<div class="radek"><span class="popisek"><?= e(t('Send the weekly digest')) ?></span><div class="volby"><label><input type="checkbox" name="fleet_digest" value="1"<?= $digest ? ' checked' : '' ?>> <?= e(t('Yes')) ?></label>
<span class="napoveda"><?= e(t('Every Monday morning one e-mail about the week before, by customer: the sites that need attention, what waits, outages and uptime, the updates installed. To the same recipients as the alerts.')) ?></span></div></div>
<div class="radek"><span class="popisek"><?= e(t('Request titles in the digest')) ?></span><div class="volby"><label><input type="checkbox" name="fleet_digest_titles" value="1"<?= $digestTitles ? ' checked' : '' ?>> <?= e(t('Yes')) ?></label>
<span class="napoveda"><?= e(t('Off: the digest only counts the requests. The e-mail leaves the console – turn the titles on only when the recipients may read them.')) ?></span></div></div>
<p><a class="navigace" href="<?= e($module->url('digest_preview')) ?>"><?= e(t('Preview the digest')) ?></a></p>
</fieldset>
<p><button class="tl" type="submit"><?= e(t('Save')) ?></button></p>
</form>
