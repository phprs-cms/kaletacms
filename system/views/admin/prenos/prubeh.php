<?php
/**
 * Import z WordPressu, krok 3: průběh po dávkách (čtení souboru, import obsahu, stahování obrázků) a výsledek.
 * Dokud není hotovo, formulář se odesílá sám (data-auto-odeslat v image/admin.js) – každé odeslání je jedna dávka.
 *
 * @var Kaleta\Admin\Moduly\Prenos $modul
 * @var Kaleta\Core\App $app
 * @var string $csrf
 * @var array<string, mixed> $stav
 * @var string $chyba  už přeložená chyba poslední dávky (import se zastavil)
 * @var bool $stahovaniMozne  server umí stahovat (curl nebo allow_url_fopen) a má GD
 * @var string $domena  doména starého webu – jen z ní se obrázky stahují
 */
$v = $stav['vysledek'];
$o = $stav['obr'];
$bezi = in_array($stav['faze'], ['analyza', 'import', 'obrazky'], true);
?>
<?= $app->view->render('admin/prenos/kroky', ['krok' => $stav['faze'] === 'analyza' ? 2 : 3]) ?>
<?php if ($chyba !== ''): ?>
<p class="hlaska hlaska-chyba"><?= e(t('Import se zastavil:')) ?> <?= e($chyba) ?></p>
<p class="navigace-radek"><a class="navigace" href="<?= e($modul->url()) ?>"><?= e(t('Zpět na Import a export')) ?></a></p>
<?php elseif ($bezi): ?>
<?php if ($stav['faze'] === 'analyza'): ?>
<p class="hlaska" role="status"><?= e(t('Čtu soubor %s: prošel jsem %s položek. Nechte stránku otevřenou.', $stav['soubor'], (int) $stav['pozice'])) ?></p>
<?php elseif ($stav['faze'] === 'import'): ?>
<p class="hlaska" role="status"><?= e(t('Importuji: %s z %s položek. Nechte stránku otevřenou, pokračuji sám.', (int) $stav['pozice'], (int) $stav['celkem'])) ?></p>
<progress class="prenos-prubeh" max="<?= max(1, (int) $stav['celkem']) ?>" value="<?= (int) $stav['pozice'] ?>"></progress>
<?php else: ?>
<p class="hlaska" role="status"><?= e(t('Stahuji obrázky ze starého webu: hotovo %s z %s novinek a stránek, staženo %s obrázků. Nechte stránku otevřenou, pokračuji sám.', (int) $o['hotovo'], (int) $o['celkem'], (int) $o['stazeno'])) ?></p>
<progress class="prenos-prubeh" max="<?= max(1, (int) $o['celkem']) ?>" value="<?= (int) $o['hotovo'] ?>"></progress>
<?php endif ?>
<form method="post" action="<?= e($modul->url('prubeh', ['soubor' => $stav['soubor']])) ?>" data-auto-odeslat="600">
	<?= $csrf ?>
	<p><button class="tl" type="submit"><?= e(t('Pokračovat')) ?></button></p>
</form>
<?php else: ?>
<p class="hlaska hlaska-ok"><?= e(t('Import obsahu je hotový.')) ?></p>
<div class="dlazdice">
	<div class="dlazdice-polozka"><strong><?= (int) $v['clanky'] ?></strong><span><?= e(t('Nové novinky')) ?></span></div>
	<div class="dlazdice-polozka"><strong><?= (int) $v['stranky'] ?></strong><span><?= e(t('Nové stránky')) ?></span></div>
	<div class="dlazdice-polozka"><strong><?= (int) $v['rubriky'] ?></strong><span><?= e(t('Nové kategorie')) ?></span></div>
	<div class="dlazdice-polozka"><strong><?= (int) $v['presmerovani'] ?></strong><span><?= e(t('Přesměrování ze starých adres')) ?></span></div>
	<div class="dlazdice-polozka"><strong><?= (int) $v['preskoceno'] ?></strong><span><?= e(t('Přeskočeno (převedeno už dříve)')) ?></span></div>
</div>
<p class="navigace-radek"><a class="navigace" href="<?= e($app->url('admin.php?modul=novinky')) ?>"><?= e(t('Zobrazit novinky')) ?></a> <a class="navigace" href="<?= e($modul->url()) ?>"><?= e(t('Zpět na Import a export')) ?></a></p>

<h2><?= e(t('Obrázky ze starého webu')) ?></h2>
<?php if ($stav['faze'] === 'obrazky-hotovo'): ?>
<p class="hlaska <?= (int) $o['chyb'] > 0 ? 'hlaska-varovani' : 'hlaska-ok' ?>"><?= e(t('Staženo %s obrázků, nepodařilo se %s.', (int) $o['stazeno'], (int) $o['chyb'])) ?></p>
<?php if ($o['chyby'] !== []): ?>
<details class="pokrocile"><summary><?= e(t('Poslední obrázky, které se nepodařilo stáhnout')) ?></summary><ul>
<?php foreach ($o['chyby'] as $radek): ?>
	<li><?= e($radek) ?></li>
<?php endforeach ?>
</ul></details>
<?php endif ?>
<?php endif ?>
<?php if (!$stahovaniMozne): ?>
<p class="hlaska"><?= e(t('Tento server neumí stahovat soubory z jiných webů (chybí curl i allow_url_fopen, případně rozšíření GD). Obrázky přeneste ručně: nahrajte je do Médií a v článcích je vyměňte.')) ?></p>
<?php elseif ($domena === ''): ?>
<p class="hlaska"><?= e(t('V souboru chybí adresa starého webu, obrázky proto nejde stáhnout.')) ?></p>
<?php else: ?>
<p><?= e(t('Novinky a stránky zatím ukazují obrázky ze starého webu. Stažením se hlavní obrázky novinek a obrázky v textech uloží do Médií (zmenší se, vzniknou náhledy a WebP) a odkazy v textech se přepíší. Stahuje se výhradně z domény %s a starý web musí být ještě dostupný.', $domena)) ?></p>
<form method="post" action="<?= e($modul->url('obrazky')) ?>"><?= $csrf ?><input type="hidden" name="soubor" value="<?= e($stav['soubor']) ?>">
	<p><button class="tl" type="submit"><?= e(t($stav['faze'] === 'obrazky-hotovo' ? 'Zkusit stáhnout znovu' : 'Stáhnout obrázky ze starého webu')) ?></button></p></form>
<?php endif ?>
<?php endif ?>
