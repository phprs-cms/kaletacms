<?php
/**
 * @var MiroCMS\Admin\Moduly\CtenariAdmin $modul
 * @var MiroCMS\Core\App $app
 * @var string $csrf
 * @var string $q
 * @var list<array<string, mixed>> $ctenari
 * @var array<string, int> $pocty
 * @var bool $stripe  platby přes Stripe jsou nastavené
 */
$stavyStripe = ['aktivni' => ['Stripe: platí', 'vydano'], 'konci' => ['Stripe: neobnoví se', 'koncept'], 'nezaplaceno' => ['Stripe: platba neprošla', 'koncept'], 'zruseno' => ['Stripe: zrušeno', 'koncept']];
?>
<div class="dlazdice">
<?php foreach ($pocty as $popis => $cislo): ?>
	<div class="dlazdice-polozka"><strong><?= $cislo ?></strong><span><?= e(t($popis)) ?></span></div>
<?php endforeach ?>
</div>
<form class="smltxt" method="get" action="<?= e($app->url('admin.php')) ?>">
	<input type="hidden" name="modul" value="ctenari">
	<input class="textpole" type="search" name="q" value="<?= e($q) ?>" placeholder="<?= e(t('hledat e-mail nebo jméno')) ?>" aria-label="<?= e(t('Hledat čtenáře')) ?>">
	<input class="tl" type="submit" value="<?= e(t('Hledat')) ?>">
	<a class="navigace" href="<?= e($modul->url('', ['format' => 'csv'])) ?>"><?= e(t('Stáhnout CSV')) ?></a>
</form>
<p class="smltxt"><?= e(t($stripe
	? 'Článek zamknete v jeho editoru volbou „Kdo smí číst“. Předplatné si čtenáři platí sami přes Stripe a datum se prodlužuje samo; ručně ho můžete zapsat komukoli dál – například po platbě na účet nebo jako dárek.'
	: 'Článek zamknete v jeho editoru volbou „Kdo smí číst“. Předplatné se zapisuje ručně – například po přijetí platby na účet.')) ?> <?= MiroCMS\Core\Napoveda::odkaz('ctenari-a-prijmy/platby-stripe', 'Platby přes Stripe') ?></p>
<?php if ($ctenari === []): ?>
<p><?= e(t('Zatím se nikdo nezaregistroval. Přihlášení čtenářů je na adrese')) ?> <a href="<?= e($app->url('ctenar')) ?>" target="_blank" rel="noopener"><?= e($app->url('ctenar')) ?></a> <?= e(t('– odkaz přidáte i blokem „Účet čtenáře“.')) ?></p>
<?php else: ?>
<div class="tab-obal"><table class="vypis">
<thead><tr><th scope="col"><?= e(t('E-mail')) ?></th><th scope="col"><?= e(t('Jméno')) ?></th><th scope="col"><?= e(t('Registrace')) ?></th><th scope="col"><?= e(t('Naposledy')) ?></th><th scope="col"><?= e(t('Předplatné')) ?></th><th scope="col"><?= e(t('Akce')) ?></th></tr></thead>
<tbody>
<?php foreach ($ctenari as $c): $plati = $c['predplatne_do'] !== null && $c['predplatne_do'] >= date('Y-m-d'); $bezi = MiroCMS\Front\Ctenari::beziStripe($c); ?>
<tr>
	<td><?= e($c['email']) ?><?= $c['potvrzen'] ? '' : ' <span class="stitek stitek-koncept">' . e(t('nepotvrdil e-mail')) . '</span>' ?></td>
	<td><?= e($c['jmeno']) ?></td>
	<td class="cislo"><?= e(datum($c['vytvoren'])) ?></td>
	<td class="cislo"><?= $c['naposledy'] ? e(datum($c['naposledy'])) : '–' ?></td>
	<td>
		<form method="post" action="<?= e($modul->url('predplatne')) ?>" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
			<?= $csrf ?><input type="hidden" name="idct" value="<?= (int) $c['idct'] ?>">
			<span class="stitek stitek-<?= $plati ? 'vydano' : 'koncept' ?>"><?= e($plati ? t('do %s', datum($c['predplatne_do'])) : t($c['predplatne_do'] !== null ? 'skončilo' : 'nemá')) ?></span>
<?php if (isset($stavyStripe[$c['predplatne_stav']])): ?>
			<span class="stitek stitek-<?= $stavyStripe[$c['predplatne_stav']][1] ?>" title="<?= e((string) $c['stripe_predplatne']) ?>"><?= e(t($stavyStripe[$c['predplatne_stav']][0])) ?></span>
<?php elseif ($plati): ?>
			<span class="smltxt"><?= e(t('zapsáno ručně')) ?></span>
<?php endif ?>
			<select name="volba" aria-label="<?= e(t('Změna předplatného')) ?>" data-odeslat-pri-zmene>
				<option value=""><?= e(t('změnit…')) ?></option>
				<option value="1"><?= e(t('+ 1 měsíc')) ?></option>
				<option value="3"><?= e(t('+ 3 měsíce')) ?></option>
				<option value="12"><?= e(t('+ 1 rok')) ?></option>
<?php if ($c['predplatne_do'] !== null): ?>
				<option value="zrusit"><?= e(t('zrušit')) ?></option>
<?php endif ?>
			</select>
			<noscript><button class="navigace" type="submit">OK</button></noscript>
		</form>
	</td>
	<td class="akce"><form class="vradku" method="post" action="<?= e($modul->url('smaz')) ?>" data-potvrdit="<?= e(($bezi ? t('Čtenář má běžící předplatné ve Stripe. Smazáním účtu se nezruší – zrušte ho nejdřív ve Stripe, jinak se čtenáři budou dál strhávat platby.') . ' ' : '') . t('Smazat účet čtenáře %s?', $c['email'])) ?>"><?= $csrf ?><input type="hidden" name="idct" value="<?= (int) $c['idct'] ?>"><button class="navigace nebezpecne" type="submit"><?= e(t('Smazat')) ?></button></form></td>
</tr>
<?php endforeach ?>
</tbody></table></div>
<?php endif ?>
