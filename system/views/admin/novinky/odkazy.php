<?php
/**
 * Nefunkční odkazy v novinkách.
 *
 * @var Kaleta\Admin\Moduly\Novinky $modul
 * @var string $csrf
 * @var list<array<string, mixed>> $odkazy
 * @var int $zkontrolovano
 * @var int $celkem
 * @var bool $zapnuto
 */
?>
<p class="navigace-radek"><a class="navigace" href="<?= e($modul->url()) ?>"><?= e(t('Zpět na přehled novinek')) ?></a></p>
<?php if (!$zapnuto): ?>
<p class="hlaska"><?= e(t('Kontrola odkazů je vypnutá (Nastavení → Základní → Další možnosti).')) ?></p>
<?php endif ?>
<p class="smltxt"><?= e(t('Systém na pozadí prochází vydané novinky – jednu za pět minut, každou jednou za měsíc – a zkouší, jestli odkazy v nich ještě fungují. Zkontrolováno novinek: %s z %s.', $zkontrolovano, $celkem)) ?></p>
<?php if ($odkazy === []): ?>
<p><?= e(t('Žádný nefunkční odkaz nebyl nalezen.')) ?></p>
<?php else: ?>
<div class="tab-obal"><table class="vypis">
<thead><tr><th scope="col"><?= e(t('Novinka')) ?></th><th scope="col"><?= e(t('Odkaz')) ?></th><th scope="col"><?= e(t('Problém')) ?></th><th scope="col"><?= e(t('Zjištěno')) ?></th><th scope="col"><?= e(t('Akce')) ?></th></tr></thead>
<tbody>
<?php foreach ($odkazy as $o): ?>
<tr>
	<td><a href="<?= e($modul->url('edit', ['id' => (int) $o['idc']])) ?>"><?= e($o['titulek']) ?></a></td>
	<td style="word-break:break-all"><a href="<?= e($o['url']) ?>" target="_blank" rel="noopener noreferrer"><?= e(mb_strimwidth($o['url'], 0, 90, '…')) ?></a></td>
	<td><?= e((int) $o['stav'] === 0 ? t('server neodpovídá') : ((int) $o['stav'] === 404 ? t('stránka neexistuje (404)') : t('chyba %s', (int) $o['stav']))) ?></td>
	<td class="cislo"><?= e(datum($o['cas'])) ?></td>
	<td class="akce"><form class="vradku" method="post" action="<?= e($modul->url('odkazy')) ?>"><?= $csrf ?><input type="hidden" name="idc" value="<?= (int) $o['idc'] ?>"><button class="navigace" type="submit"><?= e(t('Zkontrolovat znovu')) ?></button></form></td>
</tr>
<?php endforeach ?>
</tbody></table></div>
<?php endif ?>
