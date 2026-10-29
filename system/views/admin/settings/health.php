<?php
/** The "Stav systému" (System health) tab. */
$icons = ['ok' => '✓', 'varovani' => '!', 'chyba' => '✕'];
$summary = Kaleta\Core\Health::summary($checks);
$group = '';
?>
<p class="hlaska hlaska-<?= ['ok' => 'ok', 'varovani' => 'varovani', 'chyba' => 'chyba'][$summary] ?>"><?= e(t(['ok' => 'Everything is fine.', 'varovani' => 'The system is running, but some items deserve attention.', 'chyba' => 'Errors were found that prevent the site from running properly.'][$summary])) ?></p>
<div class="tab-obal">
<table class="vypis">
<tbody>
<?php foreach ($checks as $k): ?>
<?php if ($k['skupina'] !== $group): $group = $k['skupina']; ?>
<tr><th colspan="3" scope="colgroup"><?= e($group) ?></th></tr>
<?php endif ?>
<tr>
	<td class="stred"><span class="stitek stitek-<?= ['ok' => 'vydano', 'varovani' => 'koncept', 'chyba' => 'chyba'][$k['stav']] ?>" title="<?= e(t(['ok' => 'v pořádku', 'varovani' => 'varování', 'chyba' => 'chyba'][$k['stav']])) ?>"><?= $icons[$k['stav']] ?></span></td>
	<td><strong><?= e($k['nazev']) ?></strong></td>
	<td><?= Kaleta\Admin\MenuPaths::links($app->url('admin.php'), (string) $k['info'], ['settings', 'appearance', 'menu', 'business', 'status', 'claude_settings']) ?><?php if (($k['odkazy'] ?? []) !== []): ?><br><span class="smltxt"><?php foreach ($k['odkazy'] as $i => $link): ?><?= $i > 0 ? ', ' : '' ?><a href="<?= e($link['url']) ?>"><?= e($link['text']) ?></a><?php endforeach ?></span><?php endif ?></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<?php if (Kaleta\Core\Vault::configWorldReadable() && Kaleta\Core\Vault::canProtectConfig()): // 3.19.1 (N83) ?>
<p><button class="navigace" type="submit" formaction="<?= e($module->url('config_permissions')) ?>"<?= Kaleta\Core\Vault::configMode() === 0640 ? ' data-potvrdit="' . e(t('PHP does not run as the owner of the site\'s folder here, so config.php gets mode 0640. Your FTP user may then no longer be able to read or download it – keep a copy of config.php first. Continue?')) . '"' : '' ?>><?= e(t('Make config.php readable only by the site')) ?></button></p>
<?php endif ?>
<?php if ($readiness !== []): ?>
<fieldset id="ready-4">
<legend><?= e(t('Ready for 4.0')) ?></legend>
<?php if (($major ?? null) !== null): // 3.15: the next major version is on its own update source ?>
<p class="hlaska hlaska-ok"><?= e(t('Kaleta %s is available – read what changes, back up, then update.', (string) $major['verze'])) ?> <a href="<?= e($app->url('admin.php?module=settings&tab=backups#major-update')) ?>"><?= e(t('Backups and updates')) ?></a></p>
<?php endif ?>
<p><?= e(t(Kaleta\Core\Readiness::summary($readiness) === 'ok'
    ? 'Nothing on this site stands in the way of Kaleta 4.0 so far. The checks only read – they change nothing.'
    : 'Some items need attention before the update to Kaleta 4.0. The checks only read – they change nothing.')) ?></p>
<div class="tab-obal">
<table class="vypis">
<tbody>
<?php foreach ($readiness as $k): ?>
<tr>
	<td class="stred"><span class="stitek<?= $k['stav'] === 'info' ? '' : ' stitek-' . ['ok' => 'vydano', 'varovani' => 'koncept'][$k['stav']] ?>" title="<?= e(t(['ok' => 'v pořádku', 'varovani' => 'varování', 'info' => 'for information'][$k['stav']])) ?>"><?= ['ok' => '✓', 'varovani' => '!', 'info' => 'i'][$k['stav']] ?></span></td>
	<td><strong><?= e($k['nazev']) ?></strong></td>
	<td><?= e($k['info']) ?><?php if ($k['seznam'] !== []): ?><ul class="smltxt"><?php foreach ($k['seznam'] as $item): ?><li><?= e($item) ?></li><?php endforeach ?></ul><?php endif ?></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
</fieldset>
<?php endif ?>
<fieldset>
<legend><?= e(t('Mail')) ?></legend>
<p><button class="navigace" type="submit" formaction="<?= e($module->url('test_mail')) ?>"><?= e(t('Send a test e-mail to the site address')) ?></button></p>
</fieldset>
<fieldset>
<legend><?= e(t('Domain and mail')) ?></legend>
<p><?= e($domainWatch === null
    ? t('The mail DNS records (SPF, DMARC, DKIM), the site certificate and the domain registration have not been checked yet. The check runs once a day on its own; the results appear in the table above.')
    : t('Last checked %s. The check runs once a day on its own; the results are in the table above.', format_date((new DateTimeImmutable())->setTimestamp((int) $domainWatch['checked']), true))) ?></p>
<p><button class="navigace" type="submit" formaction="<?= e($module->url('domain_check')) ?>"><?= e(t('Check now')) ?></button></p>
</fieldset>
<fieldset>
<legend><?= e(t('Error log')) ?></legend>
<?php if ($errorLog === []): ?>
<p><?= e(t('No errors – the log is empty.')) ?></p>
<?php else: ?>
<pre class="log-chyb"><?php foreach (array_reverse($errorLog) as $row): ?><?= e(mb_strimwidth(str_replace(KALETA_ROOT, '', $row), 0, 400, '…')) . "\n" ?><?php endforeach ?></pre>
<p><button class="navigace nebezpecne" type="submit" formaction="<?= e($module->url('delete_log')) ?>" data-potvrdit="<?= e(t('Clear the error log?')) ?>"><?= e(t('Clear the log')) ?></button> <span class="smltxt"><?= e(t('Newest first, the last 40 entries from storage/log/chyby.log.')) ?></span></p>
<?php endif ?>
</fieldset>
<fieldset>
<legend><?= e(t('Background jobs (cron)')) ?></legend>
<p><?= e(t('Scheduled news, notifications and outgoing mail run on site visits. A low-traffic site makes them more precise by calling this address every 5 minutes from your hosting\'s cron:')) ?></p>
<?php if ($tasksToken !== ''): ?>
<?php $cronPath = Kaleta\Core\Routes::publicSystemPath('ulohy', $app->db()) ?? 'ulohy' ?>
<p><code>*/5 * * * * curl -s "<?= e($siteUrl . $cronPath) ?>?token=<?= e($tasksToken) ?>" &gt; /dev/null</code></p>
<?php if ($cronPath !== 'ulohy'): ?>
<p class="smltxt"><?= e(t('A cron job that already calls the older address …/ulohy?token= keeps working – there is no need to change it.')) ?></p>
<?php endif ?>
<?php endif ?>
<div class="tab-obal"><table class="vypis">
<thead><tr><th scope="col"><?= e(t('Job')) ?></th><th scope="col"><?= e(t('Runs')) ?></th><th scope="col"><?= e(t('Last run')) ?></th><th scope="col"><?= e(t('Result')) ?></th></tr></thead>
<tbody>
<?php foreach (Kaleta\Core\Scheduler::overview($app->db(), $app->settings()) as $j): ?>
	<tr><td><?= e(t($j['label'])) ?></td><td><?= e($j['where'] === 'cron' ? t('only from cron') : t('cron and visits')) ?><?= $j['interval'] > 0 ? ', ' . e(t('every %s', $j['interval'] >= 3600 ? t('%d h', intdiv($j['interval'], 3600)) : t('%d min', intdiv($j['interval'], 60)))) : '' ?></td>
		<td><?= $j['last_run'] !== null ? e(format_date(new DateTimeImmutable((string) $j['last_run']), true)) : '–' ?></td>
		<td><?php if ($j['last_run'] === null): ?><?= e(t('not run yet')) ?><?php elseif ($j['failures'] > 0): ?><span class="stitek stitek-chyba"><?= e(t('failed %d×', $j['failures'])) ?></span> <?= e($j['last_error']) ?><?php else: ?><span class="stitek stitek-vydano"><?= e(t('ok')) ?></span><?php endif ?></td></tr>
<?php endforeach ?>
</tbody></table></div>
<p><button class="navigace" type="submit" name="novy_token_ulohy" value="1"><?= e(t($tasksToken !== '' ? 'Create a new address (the old one stops working)' : 'Create the cron address')) ?></button></p>
</fieldset>
<fieldset>
<legend><?= e(t('Alerts')) ?></legend>
<p><?= e(t('When a backup, an update, e-mail, a webhook or a background job fails, the site sends one e-mail – at most one an hour, with everything that happened.')) ?></p>
<?php
$field('alerts_enabled', 'Send alert e-mails', 'ano', '');
$field('alerts_email', 'Send them to', 'email', 'Empty = the site e-mail (Settings → General).', 'maxlength="190"');
?>
<p><input class="tl" type="submit" value="<?= e(t('Save settings')) ?>"></p>
</fieldset>
<fieldset>
<legend><?= e(t('Monitoring')) ?></legend>
<?php if ($values['health_token'] !== ''): ?>
<p><?= e(t('Status in JSON format for monitoring tools (UptimeRobot, Zabbix…):')) ?><br><code><?= e($siteUrl . (Kaleta\Core\Routes::publicSystemPath('stav.json', $app->db()) ?? 'stav.json')) ?>?token=<?= e($values['health_token']) ?></code></p>
<?php else: ?>
<p><?= e(t('A monitoring tool can read the status as JSON. Create an access token first.')) ?></p>
<?php endif ?>
<input type="hidden" name="health_token" value="<?= e($values['health_token']) ?>">
<p><button class="navigace" type="submit" name="novy_token" value="1"><?= e(t($values['health_token'] !== '' ? 'Create a new token (the old one stops working)' : 'Create token')) ?></button></p>
</fieldset>
