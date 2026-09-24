<?php
/**
 * Části webu: záhlaví, patička a obálky – stav (ze šablony / ze stavitele) a vstup do stavitele.
 *
 * @var MiroCMS\Core\App $app
 * @var MiroCMS\Admin\Moduly\Casti $modul
 * @var string $csrf
 * @var array<string, array{0:string, 1:string}> $typy
 * @var list<string> $jazyky  '' = výchozí jazyk webu
 * @var array<string, string> $nazvyJazyku
 * @var array<string, array<string, mixed>> $radky  "typ:jazyk" => stav části
 * @var array<string, list<array<string, mixed>>> $varianty  "typ:jazyk" => varianty (záhlaví, patička)
 * @var array<int, string> $nazvyStranek
 */
?>
<p class="napoveda"><?= e(t('Záhlaví a patička jsou na každé stránce webu. Obálky přidají sekce kolem obsahu, který skládá systém – novinky, výpisu a hlášení 404. Dokud část nepublikujete ze stavitele, kreslí ji šablona.')) ?></p>
<div class="tab-obal">
<table class="vypis">
<thead><tr><th scope="col"><?= e(t('Část')) ?></th><?php if (count($jazyky) > 1): ?><th scope="col"><?= e(t('Jazyk')) ?></th><?php endif ?><th scope="col"><?= e(t('Stav')) ?></th><th scope="col"><?= e(t('Akce')) ?></th></tr></thead>
<tbody>
<?php foreach ($typy as $typ => [$nazev, $popis]): ?>
<?php foreach ($jazyky as $jazyk): $r = $radky[$typ . ':' . $jazyk] ?? null; $parametry = ['typ' => $typ, 'jazyk' => $jazyk]; ?>
<tr>
	<td><a href="<?= e($modul->url('stavitel', $parametry)) ?>"><strong><?= e(t($nazev)) ?></strong></a><br><small class="napoveda"><?= e(t($popis)) ?></small></td>
<?php if (count($jazyky) > 1): ?>
	<td><?= e($nazvyJazyku[$jazyk]) ?></td>
<?php endif ?>
	<td><?php if ($r !== null && $r['publikovana']): ?><span class="stitek stitek-vydano"><?= e(t('ze stavitele')) ?></span><?php else: ?><span class="stitek"><?= e(t('ze šablony')) ?></span><?php endif ?><?= $r !== null && $r['zmeny'] ? ' <span class="stitek stitek-koncept">' . e(t('neuložené změny')) . '</span>' : '' ?></td>
	<td class="akce"><a href="<?= e($modul->url('stavitel', $parametry)) ?>"><?= e(t($r === null ? 'Upravit ve staviteli' : 'Stavitel')) ?></a><?php if (in_array($typ, MiroCMS\Stavitel\Casti::S_VARIANTAMI, true)): ?> · <a href="<?= e($modul->url('varianta', $parametry)) ?>"><?= e(t('Přidat variantu')) ?></a><?php endif ?><?php if ($r !== null): ?> ·
		<form class="vradku" method="post" action="<?= e($modul->url('sablona', $parametry)) ?>" data-potvrdit="<?= e(t('Vrátit část na šablonu? Podoba ze stavitele zůstane ve verzích.')) ?>"><?= $csrf ?><button class="navigace nebezpecne" type="submit"><?= e(t('Vrátit na šablonu')) ?></button></form><?php endif ?></td>
</tr>
<?php foreach ($varianty[$typ . ':' . $jazyk] ?? [] as $v): $pv = $parametry + ['varianta' => $v['varianta']]; $naStrankach = array_filter(array_map(fn (int $i): ?string => $nazvyStranek[$i] ?? null, array_map('intval', json_decode((string) $v['stranky'], true) ?: []))); ?>
<tr>
	<td>↳ <a href="<?= e($modul->url('stavitel', $pv)) ?>"><?= e($v['nazev']) ?></a><br><small class="napoveda"><?= $naStrankach === [] ? e(t('zatím na žádné stránce')) : e(t('na stránkách: %s', implode(', ', $naStrankach))) ?></small></td>
<?php if (count($jazyky) > 1): ?>
	<td></td>
<?php endif ?>
	<td><?php if ($v['publikovana']): ?><span class="stitek stitek-vydano"><?= e(t('varianta')) ?></span><?php else: ?><span class="stitek stitek-koncept"><?= e(t('nepublikovaná')) ?></span><?php endif ?><?= $v['zmeny'] && $v['publikovana'] ? ' <span class="stitek stitek-koncept">' . e(t('neuložené změny')) . '</span>' : '' ?></td>
	<td class="akce"><a href="<?= e($modul->url('stavitel', $pv)) ?>"><?= e(t('Stavitel')) ?></a> · <a href="<?= e($modul->url('varianta', $pv)) ?>"><?= e(t('Stránky')) ?></a> ·
		<form class="vradku" method="post" action="<?= e($modul->url('sablona', $pv)) ?>" data-potvrdit="<?= e(t('Smazat variantu? Vybrané stránky dostanou výchozí podobu.')) ?>"><?= $csrf ?><button class="navigace nebezpecne" type="submit"><?= e(t('Smazat')) ?></button></form></td>
</tr>
<?php endforeach ?>
<?php endforeach ?>
<?php endforeach ?>
</tbody>
</table>
</div>
