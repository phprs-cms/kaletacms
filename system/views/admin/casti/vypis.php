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
	<td class="akce"><a href="<?= e($modul->url('stavitel', $parametry)) ?>"><?= e(t($r === null ? 'Upravit ve staviteli' : 'Stavitel')) ?></a><?php if ($r !== null): ?> ·
		<form class="vradku" method="post" action="<?= e($modul->url('sablona', $parametry)) ?>" data-potvrdit="<?= e(t('Vrátit část na šablonu? Podoba ze stavitele zůstane ve verzích.')) ?>"><?= $csrf ?><button class="navigace nebezpecne" type="submit"><?= e(t('Vrátit na šablonu')) ?></button></form><?php endif ?></td>
</tr>
<?php endforeach ?>
<?php endforeach ?>
</tbody>
</table>
</div>
