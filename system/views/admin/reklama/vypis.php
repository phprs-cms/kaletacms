<?php
/**
 * @var MiroCMS\Core\App $app
 * @var MiroCMS\Admin\Moduly\Reklama $modul
 * @var string $csrf
 * @var list<array<string, mixed>> $reklamy
 * @var string $adsTxt
 */
use MiroCMS\Admin\Moduly\Reklama;
?>
<p class="navigace-radek"><a class="tl" href="<?= e($modul->url('novy')) ?>"><?= e(t('Nová reklama')) ?></a> <a class="navigace" href="<?= e($modul->url('vykaz')) ?>"><?= e(t('Výkaz pro inzerenta (CSV)')) ?></a></p>
<?php if ($app->settings()->bool('cache_stranek')): ?>
<p class="smltxt"><?= e(t('Dokud je rozšíření Reklama zapnuté, cache stránek se nepoužívá – reklamy se střídají a počítají při každém zobrazení.')) ?></p>
<?php endif ?>
<p class="smltxt"><?= e(t('Reklama pod článkem se zobrazuje sama. Ostatní pozice umístíte na web blokem „Reklama“ v sekci')) ?> <a href="<?= e($app->url('admin.php?modul=bloky')) ?>"><?= e(t('Bloky a rozvržení')) ?></a><?= e(t('. Každá reklama je na webu označena slovem „Reklama“.')) ?></p>
<?php if ($reklamy === []): ?>
<?= $app->view->render('admin/prazdno', ['ikona' => 'reklama', 'nadpis' => t('Zatím žádná reklama.'), 'text' => t('Banner nebo kód reklamní sítě přidáte jednou a systém ho střídá na zvolené pozici, počítá zobrazení i kliky.'), 'akce' => [$modul->url('novy'), t('Přidat první reklamu')]]) ?>
<?php endif ?>
<?php if ($reklamy !== []): ?>
<div class="tab-obal">
<table class="vypis">
<thead><tr><th scope="col"><?= e(t('Název')) ?></th><th scope="col"><?= e(t('Pozice')) ?></th><th scope="col"><?= e(t('Platnost')) ?></th><th scope="col"><?= e(t('Zobrazení')) ?></th><th scope="col"><?= e(t('Kliky')) ?></th><th scope="col"><?= e(t('CTR')) ?></th><th scope="col"><?= e(t('Stav')) ?></th><th scope="col"><?= e(t('Akce')) ?></th></tr></thead>
<tbody>
<?php foreach ($reklamy as $r):
    $bezi = $r['aktivni'] && ($r['platna_od'] === null || strtotime($r['platna_od']) <= time()) && ($r['platna_do'] === null || strtotime($r['platna_do']) > time())
        && ($r['max_zobrazeni'] === null || $r['zobrazeni'] < $r['max_zobrazeni']); ?>
<tr<?= $bezi ? '' : ' class="nevydany"' ?>>
	<td><a href="<?= e($modul->url('edit', ['id' => $r['idr']])) ?>"><?= e($r['nazev']) ?></a><br><small><?= e(t($r['typ'] === 'kod' ? 'reklamní kód' : 'banner')) ?></small></td>
	<td><?= e(explode(' (', t(Reklama::POZICE[$r['pozice']] ?? $r['pozice']))[0]) ?></td>
	<td class="cislo"><?= $r['platna_od'] ? e(datum($r['platna_od'])) : '…' ?> – <?= $r['platna_do'] ? e(datum($r['platna_do'])) : '…' ?></td>
	<td class="cislo"><?= pocet((int) $r['zobrazeni']) ?><?= $r['max_zobrazeni'] !== null ? ' / ' . pocet((int) $r['max_zobrazeni']) : '' ?></td>
	<td class="cislo"><?= $r['typ'] === 'kod' ? '–' : pocet((int) $r['kliky']) ?></td>
	<td class="cislo"><?= $r['typ'] === 'kod' || $r['zobrazeni'] == 0 ? '–' : pocet($r['kliky'] / $r['zobrazeni'] * 100, 2) . ' %' ?></td>
	<td><span class="stitek stitek-<?= $bezi ? 'vydano' : 'koncept' ?>"><?= e(t($bezi ? 'běží' : 'neběží')) ?></span></td>
	<td class="akce"><a href="<?= e($modul->url('edit', ['id' => $r['idr']])) ?>"><?= e(t('Upravit')) ?></a> ·
		<form class="vradku" method="post" action="<?= e($modul->url('prepni')) ?>"><?= $csrf ?><input type="hidden" name="idr" value="<?= (int) $r['idr'] ?>"><button class="navigace" type="submit"><?= e(t($r['aktivni'] ? 'Vypnout' : 'Zapnout')) ?></button></form> ·
		<form class="vradku" method="post" action="<?= e($modul->url('smaz')) ?>" data-potvrdit="<?= e(t('Opravdu smazat reklamu i s jejími počty?')) ?>"><?= $csrf ?><input type="hidden" name="idr" value="<?= (int) $r['idr'] ?>"><button class="navigace nebezpecne" type="submit"><?= e(t('Smazat')) ?></button></form></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<?php endif ?>
<details class="pokrocile"<?= $adsTxt !== '' ? ' open' : '' ?>>
<summary><?= e(t('Soubor ads.txt (vyžadují ho reklamní sítě)')) ?></summary>
<form class="formular" method="post" action="<?= e($modul->url('ads_txt')) ?>">
<?= $csrf ?>
<div class="radek"><label for="ads_txt"><?= e(t('Soubor ads.txt')) ?></label><div><textarea class="textbox nizky kod" id="ads_txt" name="ads_txt" rows="4" spellcheck="false"><?= e($adsTxt) ?></textarea><span class="napoveda"><?= e(t('Seznam autorizovaných prodejců reklamy, jak vám ho dodala reklamní síť (např. google.com, pub-…, DIRECT, …). Bude dostupný na adrese /ads.txt.')) ?></span></div></div>
<p class="tlacitka"><input class="tl" type="submit" value="<?= e(t('Uložit ads.txt')) ?>"></p>
</form>
</details>
