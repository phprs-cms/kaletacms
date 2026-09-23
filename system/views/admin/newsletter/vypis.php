<?php
/**
 * @var MiroCMS\Core\App $app
 * @var MiroCMS\Admin\Moduly\NewsletterAdmin $modul
 * @var string $csrf
 * @var int $odberatelu
 * @var int $nepotvrzenych
 * @var list<array<string, mixed>> $vydani
 * @var list<array<string, mixed>> $clanky
 * @var bool $maEmail
 * @var bool $maBlok
 * @var array<string, string> $automat  nastavení automatického výběru
 * @var array<string, int> $podleJazyka  potvrzení odběratelé podle jazyka (prázdné, když web nemá další jazyky)
 */
?>
<div class="dlazdice">
	<div class="dlazdice-polozka"><strong><?= $odberatelu ?></strong><span><?= e(t('Odběratelů ·')) ?> <a href="<?= e($modul->url('odberatele')) ?>"><?= e(t('zobrazit')) ?></a><?php if (count($podleJazyka) > 1): ?><br><small><?= e(implode(' · ', array_map(fn (string $j, int $n): string => ($j !== '' ? $j : MiroCMS\Core\Jazyk::vychozi($app->settings())) . ' ' . $n, array_keys($podleJazyka), $podleJazyka))) ?></small><?php endif ?></span></div>
	<div class="dlazdice-polozka"><strong><?= $nepotvrzenych ?></strong><span><?= e(t('Čeká na potvrzení e-mailem')) ?></span></div>
</div>
<?php if (!$maEmail): ?>
<p class="hlaska hlaska-chyba"><?= e(t('Nejprve vyplňte E-mail redakce v Nastavení – z něj newsletter odchází.')) ?></p>
<?php endif ?>
<?php if (!$maBlok): ?>
<p class="hlaska"><?= e(t('Přihlašovací formulář dáte na web blokem „Newsletter“ v sekci')) ?> <a href="<?= e($app->url('admin.php?modul=bloky')) ?>"><?= e(t('Bloky a rozvržení')) ?></a>.</p>
<?php endif ?>

<form class="formular" method="post" action="<?= e($modul->url('uloz')) ?>">
<?= $csrf ?>
<fieldset>
<legend><?= e(t('Nové vydání')) ?></legend>
<div class="radek"><label for="predmet"><?= e(t('Předmět e-mailu')) ?></label><input class="textpole siroke" type="text" id="predmet" name="predmet" maxlength="200" required placeholder="<?= e(t('např. Co jsme tento týden napsali')) ?>"></div>
<div class="radek"><label for="uvod"><?= e(t('Úvodní slovo')) ?></label><div><textarea class="textbox nizky" id="uvod" name="uvod" rows="3"></textarea><span class="napoveda"><?= e(t('Nepovinné – pár vět před výčtem článků.')) ?></span></div></div>
<div class="radek"><span class="popisek"><?= e(t('Články')) ?></span><div class="volby">
<?php foreach ($clanky as $c): ?>
	<label style="white-space:normal"><input type="checkbox" name="clanky[]" value="<?= (int) $c['idc'] ?>"<?= $c['novy'] ? ' checked' : '' ?>> <?= $c['jazyk'] !== '' ? '<span class="stitek stitek-koncept">' . e($c['jazyk']) . '</span> ' : '' ?><?= e($c['titulek']) ?> <small>(<?= e(datum($c['datum'])) ?>)</small></label><br>
<?php endforeach ?>
	<span class="napoveda"><?= e(t('Předvybrané jsou články vydané od posledního newsletteru.')) ?><?php if ($podleJazyka !== []): ?> <?= e(t('Vydání je vždy v jednom jazyce: vyberte články jedné jazykové verze, dostanou je odběratelé přihlášení na téže verzi webu.')) ?><?php endif ?></span>
</div></div>
</fieldset>
<details class="pokrocile">
<summary><?= e(t('Naplánovat na později')) ?></summary>
<div class="radek"><label for="odeslat_v"><?= e(t('Rozeslat v')) ?></label><div><input class="textpole" type="datetime-local" id="odeslat_v" name="odeslat_v" min="<?= e(date('Y-m-d\TH:i')) ?>">
	<button class="navigace" type="submit" name="co" value="naplanovat"><?= e(t('Naplánovat')) ?></button>
	<span class="napoveda"><?= e(t('Naplánované vydání se rozešle samo na pozadí – nemusíte mít otevřenou administraci.')) ?></span></div></div>
</details>
<p class="tlacitka">
	<button class="tl" type="submit" name="co" value="rozeslat" data-potvrdit="<?= e($podleJazyka === [] ? t('Rozeslat newsletter %s odběratelům?', $odberatelu) : t('Rozeslat newsletter odběratelům jazyka vybraných článků?')) ?>"><?= e(t('Rozeslat odběratelům')) ?></button>
	<button class="tl" type="submit" name="co" value="zkouska"><?= e(t('Poslat na zkoušku redakci')) ?></button>
</p>
</form>

<form class="formular" method="post" action="<?= e($modul->url('automat')) ?>">
<?= $csrf ?>
<details class="pokrocile"<?= $automat['newsletter_auto'] !== 'vypnuto' ? ' open' : '' ?>>
<summary><?= e(t('Automatický newsletter')) ?><?= $automat['newsletter_auto'] !== 'vypnuto' ? ' – ' . e(t('zapnutý')) : '' ?></summary>
<div class="radek"><label for="newsletter_auto"><?= e(t('Posílat sám')) ?></label><div><select id="newsletter_auto" name="newsletter_auto">
	<option value="vypnuto"><?= e(t('ne – newsletter sestavuji ručně')) ?></option>
	<option value="tydne"<?= $automat['newsletter_auto'] === 'tydne' ? ' selected' : '' ?>><?= e(t('jednou týdně')) ?></option>
	<option value="denne"<?= $automat['newsletter_auto'] === 'denne' ? ' selected' : '' ?>><?= e(t('každý den')) ?></option>
</select><span class="napoveda"><?= e(t('Výběr až osmi článků vydaných od posledního newsletteru – připnuté a nejčtenější napřed. Když nic nového nevyšlo, nic se neposílá.')) ?></span></div></div>
<div class="radek"><label for="newsletter_den"><?= e(t('Den a hodina')) ?></label><div><select id="newsletter_den" name="newsletter_den" aria-label="<?= e(t('Den v týdnu')) ?>">
<?php foreach ([1 => 'pondělí', 'úterý', 'středa', 'čtvrtek', 'pátek', 'sobota', 'neděle'] as $cislo => $den): ?>
	<option value="<?= $cislo ?>"<?= (int) $automat['newsletter_den'] === $cislo ? ' selected' : '' ?>><?= e(t($den)) ?></option>
<?php endforeach ?>
</select> <input class="textpole" type="number" name="newsletter_hodina" value="<?= (int) $automat['newsletter_hodina'] ?>" min="0" max="23" aria-label="<?= e(t('Hodina')) ?>"> <?= e(t('hodin')) ?>
<span class="napoveda"><?= e(t('Den platí pro týdenní newsletter. Odejde při první návštěvě webu po této hodině (nebo přesně, máte-li nastavený cron).')) ?></span></div></div>
<div class="radek"><label for="newsletter_uvod"><?= e(t('Úvodní slovo')) ?></label><textarea class="textbox radkovy" id="newsletter_uvod" name="newsletter_uvod" rows="2" maxlength="1000"><?= e($automat['newsletter_uvod']) ?></textarea></div>
<p class="tlacitka"><input class="tl" type="submit" value="<?= e(t('Uložit')) ?>"></p>
</details>
</form>

<?php if ($vydani !== []): ?>
<h3><?= e(t('Odeslaná vydání')) ?></h3>
<div class="tab-obal"><table class="vypis">
<thead><tr><th scope="col"><?= e(t('Předmět')) ?></th><th scope="col"><?= e(t('Vytvořeno')) ?></th><th scope="col"><?= e(t('Stav')) ?></th><th scope="col"><?= e(t('Příjemců')) ?></th><th scope="col"><?= e(t('Otevřeno')) ?></th><th scope="col"><?= e(t('Prokliků')) ?></th></tr></thead>
<tbody>
<?php foreach ($vydani as $v): ?>
<tr><td><?= $v['jazyk'] !== '' ? '<span class="stitek stitek-koncept">' . e($v['jazyk']) . '</span> ' : '' ?><?= e($v['predmet']) ?><?= $v['auto'] ? ' <span class="stitek stitek-koncept">' . e(t('automat')) . '</span>' : '' ?></td><td class="cislo"><?= e(datum($v['vytvoreno'], true)) ?></td>
	<td><?php if ($v['odeslano']): ?><span class="stitek stitek-vydano"><?= e(t('odesláno')) ?></span>
<?php elseif ($v['odeslat_v'] !== null && (int) $v['pocet'] === 0 && strtotime($v['odeslat_v']) > time()): ?><?= e(t('naplánováno na %s', datum($v['odeslat_v'], true))) ?>
		<form class="vradku" method="post" action="<?= e($modul->url('zrus')) ?>" data-potvrdit="<?= e(t('Zrušit naplánované vydání?')) ?>"><?= $csrf ?><input type="hidden" name="idn" value="<?= (int) $v['idn'] ?>"><button class="navigace" type="submit"><?= e(t('Zrušit')) ?></button></form>
<?php elseif ($v['odeslat_v'] !== null): ?><?= e(t('rozesílá se na pozadí')) ?>
<?php else: ?><a href="<?= e($modul->url('rozeslat', ['id' => $v['idn']])) ?>"><?= e(t('pokračovat v rozesílce')) ?></a><?php endif ?></td>
	<td class="cislo"><?= (int) $v['pocet'] ?></td>
	<td class="cislo"><?= (int) $v['otevreno'] ?><?= (int) $v['pocet'] > 0 ? ' <small>(' . min(100, (int) round($v['otevreno'] / $v['pocet'] * 100)) . ' %)</small>' : '' ?></td>
	<td class="cislo"><?= (int) $v['prokliku'] ?></td></tr>
<?php endforeach ?>
</tbody></table></div>
<?php endif ?>
<p class="smltxt"><?= e(t('Otevření a prokliky jsou jen souhrnná čísla – u jednotlivých odběratelů se nic nesleduje. Otevření je orientační: část poštovních programů obrázky nenačítá, jiné je načítají samy.')) ?></p>
