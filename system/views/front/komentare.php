<?php
/**
 * Komentáře pod článkem: výpis (reakce o úroveň níž) a formulář.
 *
 * @var array<string, mixed> $clanek
 * @var list<array<string, mixed>> $koreny
 * @var array<int, list<array<string, mixed>>> $reakce
 * @var int $pocet
 * @var string $akce   adresa pro odeslání
 * @var string $pole   skrytá pole antispamu
 * @var string $zprava
 * @var string $chyba
 * @var array<string, mixed>|null $ctenar  přihlášený čtenář (komentuje pod svým účtem)
 * @var bool $jenPrihlaseni  komentovat smí jen přihlášení a čtenář přihlášený není
 * @var string $prihlaseni  adresa přihlášení s návratem na článek
 * @var string $nahlasit  adresa pro nahlášení komentáře
 */
$komentar = function (array $k) use (&$komentar, $reakce, $nahlasit): void { ?>
	<li class="komentar" id="komentar-<?= (int) $k['idk'] ?>">
		<p class="komentar-hlava"><strong><?= e($k['od']) ?></strong><?= $k['idct'] !== null ? ' <span class="komentar-ctenar" title="' . e(t('registrovaný čtenář')) . '">✓</span>' : '' ?> <time datetime="<?= e(date('c', strtotime($k['datum']))) ?>"><?= e(datum($k['datum'], true)) ?></time>
<?php if ($k['reakce_na'] === null): ?>
			<button type="button" class="komentar-reagovat" data-reagovat="<?= (int) $k['idk'] ?>" data-jmeno="<?= e($k['od']) ?>"><?= e(t('Reagovat')) ?></button>
<?php endif ?>
		</p>
		<div class="komentar-text"><?= nl2br(e($k['obsah'])) ?></div>
		<form class="komentar-nahlasit" method="post" action="<?= e($nahlasit) ?>"><input type="hidden" name="idk" value="<?= (int) $k['idk'] ?>"><button type="submit" title="<?= e(t('Upozornit redakci na nevhodný komentář')) ?>"><?= e(t('nahlásit')) ?></button></form>
<?php if (!empty($reakce[(int) $k['idk']])): ?>
		<ul class="komentare-reakce"><?php foreach ($reakce[(int) $k['idk']] as $r) { $komentar($r); } ?></ul>
<?php endif ?>
	</li>
<?php };
?>
<section class="komentare obal-uzky" id="komentare">
	<h2><?= e(t('Komentáře')) ?><?= $pocet > 0 ? ' (' . $pocet . ')' : '' ?></h2>
<?php if ($zprava !== ''): ?>
	<p class="komentare-zprava" role="status"><?= e($zprava) ?></p>
<?php endif ?>
<?php if ($chyba !== ''): ?>
	<p class="komentare-zprava komentare-chyba" role="alert"><?= e($chyba) ?></p>
<?php endif ?>
<?php if ($koreny !== []): ?>
	<ul class="komentare-seznam"><?php foreach ($koreny as $k) { $komentar($k); } ?></ul>
<?php else: ?>
	<p><?= e(t('Zatím tu žádný komentář není. Buďte první.')) ?></p>
<?php endif ?>
<?php if ($jenPrihlaseni): ?>
	<p class="komentare-prihlaseni"><?= e(t('Komentovat mohou přihlášení čtenáři.')) ?> <a class="mc-tl" href="<?= e($prihlaseni) ?>"><?= e(t('Přihlásit se')) ?></a></p>
<?php else: ?>
	<form class="komentar-formular" method="post" action="<?= e($akce) ?>">
		<?= $pole ?>
		<input type="hidden" name="idc" value="<?= (int) $clanek['idc'] ?>">
		<input type="hidden" name="reakce_na" value="0">
		<p class="komentar-reakce-info" hidden><?= e(t('Reagujete na komentář:')) ?> <strong></strong> <button type="button" data-zrusit-reakci><?= e(t('zrušit')) ?></button></p>
<?php if ($ctenar !== null): ?>
		<p class="komentar-ucet"><?= e(t('Komentujete jako')) ?> <strong><?= e($ctenar['jmeno'] !== '' ? $ctenar['jmeno'] : ucfirst((string) strstr($ctenar['email'], '@', true))) ?></strong></p>
<?php else: ?>
		<p><label for="kom-od"><?= e(t('Jméno')) ?></label><input type="text" id="kom-od" name="od" maxlength="60" required autocomplete="name"></p>
		<p><label for="kom-mail"><?= e(t('E-mail')) ?> <small><?= e(t('(nepovinný, nezveřejní se)')) ?></small></label><input type="email" id="kom-mail" name="od_mail" maxlength="190" autocomplete="email"></p>
<?php endif ?>
		<p><label for="kom-obsah"><?= e(t('Komentář')) ?></label><textarea id="kom-obsah" name="obsah" rows="5" maxlength="5000" required></textarea></p>
		<p class="komentar-volba"><label><input type="checkbox" name="upozornit" value="1"<?= $ctenar !== null ? ' checked' : '' ?>> <?= e(t('Dát mi e-mailem vědět, když mi někdo odpoví')) ?></label></p>
		<p><button type="submit"><?= e(t('Odeslat komentář')) ?></button></p>
	</form>
<?php endif ?>
</section>
