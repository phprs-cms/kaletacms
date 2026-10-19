<?php
/**
 * The "Fleet console" tab (2.9, Fleet\Link): pairing this site with a console it reports to. For the variables see list.php.
 *
 * @var array{paired: bool, url: string, name: string, fingerprint: string, own: string, updates: bool, allowed: string, sent: int, error: string, kit: bool, kitVersion: int, kitApplied: int, kitError: string, kitWaiting: bool, titles: bool} $fleet
 */
?>
<p class="hlaska"><?= e(t('A fleet console is another Kaleta installation that shows all your sites on one screen. This site sends it a signed report every hour – version, health, background jobs, backups, enquiries waiting and visits, never names or the content of enquiries. The console cannot get into this site.')) ?></p>
<?php if (!$fleet['paired']): ?>
<fieldset>
<legend><?= e(t('Pair with a console')) ?></legend>
<div class="radek"><label for="pairing_key"><?= e(t('Pairing key')) ?></label><div><textarea class="textbox nizky" id="pairing_key" name="pairing_key" rows="3" spellcheck="false" autocomplete="off"></textarea>
<span class="napoveda"><?= e(t('On the console: Fleet → Add a site. The key is valid for 24 hours and only once.')) ?></span></div></div>
<div class="radek"><span class="popisek"><?= e(t('Updates')) ?></span><div class="volby"><label><input type="checkbox" name="fleet_updates" value="1" checked> <?= e(t('The console decides when new versions install here')) ?></label>
<span class="napoveda"><?= e(t('Test sites first, the rest two days later when nothing broke. Only versions signed by the Kaleta publisher install, as always.')) ?></span></div></div>
<p><button class="tl" type="submit" formaction="<?= e($module->url('fleet_pair')) ?>"><?= e(t('Pair')) ?></button></p>
</fieldset>
<?php else: ?>
<fieldset>
<legend><?= e(t('Console')) ?></legend>
<div class="tab-obal"><table class="vypis">
	<tr><th scope="row"><?= e(t('Console')) ?></th><td><?= e($fleet['name']) ?> – <a href="<?= e($fleet['url']) ?>" target="_blank" rel="noopener"><?= e($fleet['url']) ?></a></td></tr>
	<tr><th scope="row"><?= e(t('Key fingerprints')) ?></th><td><?= e(t('console %s, this site %s', $fleet['fingerprint'], $fleet['own'])) ?></td></tr>
	<tr><th scope="row"><?= e(t('Last report')) ?></th><td><?= $fleet['sent'] > 0 ? e(format_date((new DateTimeImmutable())->setTimestamp($fleet['sent']), true)) : e(t('not sent yet')) ?><?php if ($fleet['error'] !== ''): ?> <span class="stitek-chyba"><?= e(t($fleet['error'])) ?></span><?php endif ?></td></tr>
	<?php if ($fleet['updates'] && $fleet['allowed'] !== ''): ?><tr><th scope="row"><?= e(t('Allowed update')) ?></th><td><?= e(t('Version %s – it installs with the next background run.', $fleet['allowed'])) ?></td></tr><?php endif ?>
</table></div>
<div class="radek"><span class="popisek"><?= e(t('Updates')) ?></span><div class="volby"><label><input type="checkbox" name="fleet_updates" value="1"<?= $fleet['updates'] ? ' checked' : '' ?>> <?= e(t('The console decides when new versions install here')) ?></label>
<span class="napoveda"><?= e(t('Without it, updates work as before: security releases install themselves when that is on in Backups and updates, others you install yourself.')) ?></span></div></div>
<p><button class="navigace" type="submit" formaction="<?= e($module->url('fleet_updates')) ?>"><?= e(t('Save the choice about updates')) ?></button>
<button class="navigace" type="submit" formaction="<?= e($module->url('fleet_send')) ?>"><?= e(t('Send a report now')) ?></button>
<button class="navigace" type="submit" formaction="<?= e($module->url('fleet_unpair')) ?>" data-potvrdit="<?= e(t('The site will stop reporting to the console. Continue?')) ?>"><?= e(t('Disconnect from the console')) ?></button></p>
</fieldset>
<fieldset>
<legend><?= e(t('Shared design kit')) ?></legend>
<p class="napoveda"><?= e(t('The console can share a design kit – its design system, shared classes, components and saved sections. Here it arrives as drafts only: into the look draft, into component drafts and into the section library. Nothing is published until you publish it. Custom code never travels in a kit.')) ?></p>
<div class="radek"><span class="popisek"><?= e(t('Shared kit')) ?></span><div class="volby"><label><input type="checkbox" name="fleet_kit" value="1"<?= $fleet['kit'] ? ' checked' : '' ?>> <?= e(t('Receive the shared design kit')) ?></label></div></div>
<?php if ($fleet['kitVersion'] > 0): ?>
<p><?= e(t('Received kit version %d on %s.', $fleet['kitVersion'], $fleet['kitApplied'] > 0 ? format_date((new DateTimeImmutable())->setTimestamp($fleet['kitApplied']), true) : '–')) ?>
<?php if ($fleet['kitWaiting']): ?> <?= e(t('The look draft and the component drafts are waiting for your review:')) ?> <a href="<?= e($app->url('admin.php')) ?>?module=appearance"><?= e(t('Site appearance')) ?></a> · <a href="<?= e($app->url('admin.php')) ?>?module=components"><?= e(t('Components')) ?></a><?php endif ?></p>
<?php endif ?>
<?php if ($fleet['kitError'] !== ''): ?><p><span class="stitek-chyba"><?= e(t('The last kit was refused: %s', t($fleet['kitError']))) ?></span></p><?php endif ?>
<p><button class="navigace" type="submit" formaction="<?= e($module->url('fleet_kit')) ?>"><?= e(t('Save the choice about the kit')) ?></button></p>
</fieldset>
<?php endif ?>
<fieldset>
<legend><?= e(t('Requests on the console')) ?></legend>
<div class="radek"><span class="popisek"><?= e(t('Request titles')) ?></span><div class="volby"><label><input type="checkbox" name="fleet_request_titles" value="1"<?= $fleet['titles'] ? ' checked' : '' ?>> <?= e(t('Send the titles of Ask Claude requests to the console')) ?></label>
<span class="napoveda"><?= e(t('Request titles help you see the work across sites; enquiries, e-mails and contents never leave the site.')) ?> <?= e(t('E-mail addresses in a title are masked. Without the titles the console gets only how many requests wait.')) ?></span></div></div>
<p><button class="navigace" type="submit" formaction="<?= e($module->url('fleet_titles')) ?>"><?= e(t('Save the choice about request titles')) ?></button></p>
</fieldset>
