<?php
/** Záložka Stav systému. */
$ikony = ['ok' => '✓', 'varovani' => '!', 'chyba' => '✕'];
$souhrn = MiroCMS\Core\Stav::souhrn($kontroly);
$skupina = '';
?>
<p class="hlaska hlaska-<?= ['ok' => 'ok', 'varovani' => 'varovani', 'chyba' => 'chyba'][$souhrn] ?>"><?= e(t(['ok' => 'Vše v pořádku.', 'varovani' => 'Systém běží, některé položky si zaslouží pozornost.', 'chyba' => 'Nalezeny chyby, které brání správnému provozu.'][$souhrn])) ?></p>
<div class="tab-obal">
<table class="vypis">
<tbody>
<?php foreach ($kontroly as $k): ?>
<?php if ($k['skupina'] !== $skupina): $skupina = $k['skupina']; ?>
<tr><th colspan="3" scope="colgroup"><?= e($skupina) ?></th></tr>
<?php endif ?>
<tr>
	<td class="stred"><span class="stitek stitek-<?= ['ok' => 'vydano', 'varovani' => 'koncept', 'chyba' => 'chyba'][$k['stav']] ?>" title="<?= e(t(['ok' => 'v pořádku', 'varovani' => 'varování', 'chyba' => 'chyba'][$k['stav']])) ?>"><?= $ikony[$k['stav']] ?></span></td>
	<td><strong><?= e($k['nazev']) ?></strong></td>
	<td><?= MiroCMS\Admin\Cesty::odkazy($app->url('admin.php'), (string) $k['info'], ['config', 'vzhled', 'bloky']) ?></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<fieldset>
<legend><?= e(t('Pošta')) ?></legend>
<p><button class="navigace" type="submit" formaction="<?= e($modul->url('test_posty')) ?>"><?= e(t('Odeslat zkušební e-mail na adresu webu')) ?></button></p>
</fieldset>
<fieldset>
<legend><?= e(t('Záznam chyb')) ?></legend>
<?php if ($chybyLog === []): ?>
<p><?= e(t('Žádné chyby – záznam je prázdný.')) ?></p>
<?php else: ?>
<pre class="log-chyb"><?php foreach (array_reverse($chybyLog) as $radek): ?><?= e(mb_strimwidth(str_replace(MIROCMS_ROOT, '', $radek), 0, 400, '…')) . "\n" ?><?php endforeach ?></pre>
<p><button class="navigace nebezpecne" type="submit" formaction="<?= e($modul->url('smaz_log')) ?>" data-potvrdit="<?= e(t('Vyprázdnit záznam chyb?')) ?>"><?= e(t('Vyprázdnit záznam')) ?></button> <span class="smltxt"><?= e(t('Nejnovější nahoře, posledních 40 záznamů ze souboru storage/log/chyby.log.')) ?></span></p>
<?php endif ?>
</fieldset>
<fieldset>
<legend><?= e(t('Úlohy na pozadí (cron)')) ?></legend>
<p><?= e(t('Naplánované novinky, oznámení a odeslání pošty se spouštějí při návštěvách webu. Web s menší návštěvností je zpřesní, když tuto adresu zavoláte každých 5 minut cronem hostingu:')) ?></p>
<?php if ($ulohyToken !== ''): ?>
<p><code>*/5 * * * * curl -s "<?= e($adresaWebu) ?>ulohy?token=<?= e($ulohyToken) ?>" &gt; /dev/null</code></p>
<?php endif ?>
<p><button class="navigace" type="submit" name="novy_token_ulohy" value="1"><?= e(t($ulohyToken !== '' ? 'Vytvořit novou adresu (stará přestane platit)' : 'Vytvořit adresu pro cron')) ?></button></p>
</fieldset>
<fieldset>
<legend><?= e(t('Monitoring')) ?></legend>
<?php if ($hodnoty['stav_token'] !== ''): ?>
<p><?= e(t('Stav ve formátu JSON pro dohledové nástroje (UptimeRobot, Zabbix…):')) ?><br><code><?= e($adresaWebu) ?>stav.json?token=<?= e($hodnoty['stav_token']) ?></code></p>
<?php else: ?>
<p><?= e(t('Dohledový nástroj může stav číst jako JSON. Nejprve vytvořte přístupový token.')) ?></p>
<?php endif ?>
<input type="hidden" name="stav_token" value="<?= e($hodnoty['stav_token']) ?>">
<p><button class="navigace" type="submit" name="novy_token" value="1"><?= e(t($hodnoty['stav_token'] !== '' ? 'Vytvořit nový token (starý přestane platit)' : 'Vytvořit token')) ?></button></p>
</fieldset>
