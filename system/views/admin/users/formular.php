<?php
/**
 * Uživatel: jméno, přihlášení a role. Oprávnění plynou z role; ruční nastavení je schované v „Podrobném nastavení“.
 *
 * @var MiroCMS\Admin\Moduly\Autori $modul
 * @var string $csrf
 * @var array<string, mixed> $autor
 * @var array<string, string> $chyby
 * @var bool $sam  admin upravuje vlastní účet
 * @var array<string, string> $moduly  ident => název (sekce, ke kterým se přístup nastavuje)
 * @var list<string> $maModuly
 * @var bool $rucne  přístup do sekcí je nastavený ručně (liší se od výchozího pro roli)
 * @var array<int, string> $ostatni
 * @var list<int> $maPodrizene
 */
$chyba = fn (string $pole): string => isset($chyby[$pole]) ? '<span class="chyba-pole" role="alert">' . e(t($chyby[$pole])) . '</span>' : '';
$role = [
    0 => ['Autor', 'Píše a upravuje vlastní články. Vydává je redaktor.'],
    1 => ['Redaktor', 'Upravuje a vydává články všech, spravuje rubriky, komentáře a další obsah.'],
    2 => ['Administrátor', 'Všechno včetně uživatelů, vzhledu a nastavení webu.'],
];
?>
<p class="navigace-radek"><a class="navigace" href="<?= e($modul->url()) ?>"><?= e(t('Zpět na přehled')) ?></a></p>
<?php if (($shrnuti ?? '') !== ''): ?>
<p class="hlaska"><strong><?= e(t('Co teď smí:')) ?></strong> <?= e($shrnuti) ?></p>
<?php endif ?>
<form class="formular" method="post" action="<?= e($modul->url('uloz')) ?>" autocomplete="off">
<?= $csrf ?>
<input type="hidden" name="idu" value="<?= (int) $autor['idu'] ?>">
<div class="radek">
	<label for="jmeno"><?= e(t('Jméno a příjmení')) ?></label>
	<div><input class="textpole siroke" type="text" id="jmeno" name="jmeno" value="<?= e($autor['jmeno']) ?>" maxlength="100"><span class="napoveda"><?= e(t('Zobrazuje se u článků.')) ?></span></div>
</div>
<div class="radek">
	<label for="user"><?= e(t('Přihlašovací jméno')) ?></label>
	<div><input class="textpole" type="text" id="user" name="user" value="<?= e($autor['user']) ?>" maxlength="40" size="30" required><?= $chyba('user') ?></div>
</div>
<div class="radek">
	<label for="email"><?= e(t('E-mail')) ?></label>
	<div><input class="textpole siroke" type="email" id="email" name="email" value="<?= e($autor['email']) ?>" maxlength="190"><?= $chyba('email') ?></div>
</div>
<div class="radek">
	<label for="password"><?= e(t($autor['idu'] ? 'Nové heslo' : 'Heslo')) ?></label>
	<div><input class="textpole" type="password" id="password" name="password" size="30" minlength="10" autocomplete="new-password"<?= $autor['idu'] ? '' : ' required' ?>>
	<label style="font-weight:normal"><input type="checkbox" data-ukaz-heslo="password"> <?= e(t('zobrazit')) ?></label><?= $chyba('password') ?>
	<span class="napoveda"><?= e(t('Alespoň 10 znaků.')) ?> <?= e(t($autor['idu'] ? 'Nechte prázdné, pokud heslo neměníte.' : 'Uživatel si ho pak změní v nabídce Můj účet.')) ?></span></div>
</div>

<fieldset>
<legend><?= e(t('Role')) ?></legend>
<div class="karty-volby karty-volby-text">
<?php foreach ($role as $hodnota => [$nazev, $popis]): ?>
	<label class="karta-volba">
		<input type="radio" name="admin" value="<?= $hodnota ?>"<?= (int) $autor['admin'] === $hodnota ? ' checked' : '' ?><?= $sam ? ' disabled' : '' ?>>
		<strong><?= e(t($nazev)) ?></strong>
		<span><?= e(t($popis)) ?></span>
	</label>
<?php endforeach ?>
</div>
<?php if ($sam): ?>
<p class="napoveda"><?= e(t('Vlastní účet nemůžete zbavit práv administrátora.')) ?></p>
<?php endif ?>
<div class="radek">
	<span class="popisek"><?= e(t('Autor')) ?></span>
	<div class="volby"><label><input type="checkbox" name="pravo_vydavat" value="1"<?= $autor['pravo_vydavat'] ? ' checked' : '' ?>> <?= e(t('smí své články sám vydávat')) ?></label>
	<span class="napoveda"><?= e(t('Týká se jen role Autor. Redaktor a administrátor vydávají vždy.')) ?></span></div>
</div>
</fieldset>

<details class="pokrocile"<?= $modul->app()->db()->value('SELECT 1 FROM {user_rubriky} WHERE idu = ?', [(int) ($autor['idu'] ?? 0)]) !== null || $rucne || $maPodrizene !== [] || $autor['blokovat'] || !empty($autor['url']) ? ' open' : '' ?>>
<summary><?= e(t('Podrobné nastavení')) ?></summary>
<div class="radek">
	<label for="url"><?= e(t('Web uživatele')) ?></label>
	<input class="textpole siroke" type="url" id="url" name="url" value="<?= e($autor['url']) ?>" maxlength="255" placeholder="https://">
</div>
<div class="radek">
	<span class="popisek"><?= e(t('Přístup do sekcí')) ?></span>
	<div class="volby">
		<label><input type="checkbox" name="rucne" value="1"<?= $rucne ? ' checked' : '' ?>> <?= e(t('nastavit ručně (jinak podle role)')) ?></label><br>
<?php foreach ($moduly as $ident => $nazev): ?>
		<label style="margin-left:22px"><input type="checkbox" name="moduly[]" value="<?= e($ident) ?>"<?= in_array($ident, $maModuly, true) ? ' checked' : '' ?>> <?= e(t($nazev)) ?></label><br>
<?php endforeach ?>
		<span class="napoveda"><?= e(t('Autor má běžně Články, redaktor všechny obsahové sekce. Média mají všichni, administrátor má vše.')) ?></span>
	</div>
</div>
<?php if ($ostatni !== []): ?>
<div class="radek">
	<span class="popisek"><?= e(t('Smí upravovat i články autorů')) ?></span>
	<div class="volby">
<?php foreach ($ostatni as $idu => $jmeno): ?>
		<label><input type="checkbox" name="podrizeni[]" value="<?= (int) $idu ?>"<?= in_array((int) $idu, $maPodrizene, true) ? ' checked' : '' ?>> <?= e($jmeno) ?></label><br>
<?php endforeach ?>
		<span class="napoveda"><?= e(t('Jen pro roli Autor – např. vedoucí rubriky.')) ?></span>
	</div>
</div>
<?php endif ?>
<?php if (!empty($autor['totp_tajemstvi'])): ?>
<div class="radek">
	<span class="popisek"><?= e(t('Dvoufázové přihlášení')) ?></span>
	<div class="volby"><span class="stitek stitek-vydano"><?= e(t('zapnuté')) ?></span> <label><input type="checkbox" name="totp_reset" value="1"> <?= e(t('vypnout (uživatel ztratil telefon i záložní kódy)')) ?></label></div>
</div>
<?php endif ?>
<div class="radek">
	<span class="popisek"><?= e(t('Jen tyto rubriky')) ?></span>
	<div class="volby">
<?php $maRubriky = array_map(intval(...), array_column($modul->app()->db()->all('SELECT idt FROM {user_rubriky} WHERE idu = ?', [(int) ($autor['idu'] ?? 0)]), 'idt'));
foreach (MiroCMS\Admin\Moduly\Rubriky::strom($modul->app()->db()) as $rub): ?>
		<label><input type="checkbox" name="rubriky[]" value="<?= (int) $rub['idt'] ?>"<?= in_array((int) $rub['idt'], $maRubriky, true) ? ' checked' : '' ?>> <?= e(str_repeat('– ', (int) $rub['uroven']) . $rub['nazev']) ?></label><br>
<?php endforeach ?>
		<span class="napoveda"><?= e(t('Nic nezaškrtnuto = smí psát do všech rubrik. Se zaškrtnutím vidí a upravuje jen články z vybraných rubrik a jejich podrubrik. Administrátora omezit nejde.')) ?></span>
	</div>
</div>
<?php if (!$sam): ?>
<div class="radek">
	<span class="popisek"><?= e(t('Zablokovat účet')) ?></span>
	<div class="volby"><label><input type="checkbox" name="blokovat" value="1"<?= $autor['blokovat'] ? ' checked' : '' ?>> <?= e(t('uživatel se nepřihlásí')) ?></label></div>
</div>
<?php endif ?>
</details>
<p class="tlacitka"><input class="tl" type="submit" value="<?= e(t($autor['idu'] ? 'Uložit' : 'Přidat uživatele')) ?>"></p>
</form>
