<?php
/**
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Moduly\Menu $modul
 * @var string $csrf
 * @var string $umisteni  hlavni | paticka
 * @var string $jazyk     sloupec jazyka ('' = výchozí)
 * @var bool $automaticke hlavní menu se zatím skládá samo
 * @var list<array<string, mixed>> $polozky
 * @var list<array{ids:int, titulek:string, skryta:bool}> $stranky
 * @var array<string, string> $jazyky
 */
$volba = ['umisteni' => $umisteni, 'jazyk' => $jazyk];
?>
<nav class="zalozky" aria-label="<?= e(t('Menu')) ?>">
<?php foreach (Kaleta\Core\Menu::UMISTENI as $klic => $nazev): ?>
	<a href="<?= e($modul->url('', ['umisteni' => $klic, 'jazyk' => $jazyk])) ?>"<?= $klic === $umisteni ? ' class="aktivni" aria-current="true"' : '' ?>><?= e(t($nazev)) ?></a>
<?php endforeach ?>
</nav>
<?php if (count($jazyky) > 1): ?>
<p class="smltxt"><?= e(t('Jazyková verze:')) ?>
<?php foreach ($jazyky as $kod => $nazev): ?>
	<a class="navigace<?= $kod === $jazyk ? ' aktivni' : '' ?>" href="<?= e($modul->url('', ['umisteni' => $umisteni, 'jazyk' => $kod])) ?>"<?= $kod === $jazyk ? ' aria-current="true"' : '' ?>><?= e($nazev) ?></a>
<?php endforeach ?></p>
<?php endif ?>
<p class="smltxt"><?= e(t($umisteni === 'hlavni'
    ? ($automaticke ? 'Menu se zatím skládá samo ze stránek zaškrtnutých „v navigaci“. Když ho tady upravíte a uložíte, bude platit tahle podoba.' : 'Pořadí měníte přetažením nebo šipkami. Šipkou vpravo zařadíte položku do podmenu té nad ní.')
    : 'Odkazy v patičce webu (zásady ochrany soukromí, kontakt, kariéra…). Použije je šablona i prvek Navigace nastavený na menu v patičce.')) ?></p>

<form method="post" action="<?= e($modul->url('uloz', $volba)) ?>" class="menu-formular" data-menu>
<?= $csrf ?>
<input type="hidden" name="polozky" value="">
<script type="application/json" data-menu-data><?= json_encode(['polozky' => $polozky, 'stranky' => $stranky], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<ol class="menu-editor" data-menu-seznam></ol>
<p class="napoveda" data-menu-prazdne hidden><?= e(t('Menu je prázdné – přidejte první položku.')) ?></p>
<fieldset class="menu-pridat">
	<legend><?= e(t('Přidat položku')) ?></legend>
	<label><?= e(t('Stránka')) ?>
		<select data-menu-stranka>
<?php foreach ($stranky as $s): ?>
			<option value="<?= $s['ids'] ?>"><?= e($s['titulek']) ?><?= $s['skryta'] ? ' (' . e(t('skrytá')) . ')' : '' ?></option>
<?php endforeach ?>
		</select>
	</label>
	<button class="navigace" type="button" data-menu-pridej="stranka"><?= e(t('Přidat stránku')) ?></button>
	<button class="navigace" type="button" data-menu-pridej="odkaz"><?= e(t('Vlastní odkaz')) ?></button>
<?php if (Kaleta\Core\Rozsireni::je($app->settings(), 'novinky')): ?>
	<button class="navigace" type="button" data-menu-pridej="novinky"><?= e(t('Novinky')) ?></button>
<?php endif ?>
	<button class="navigace" type="button" data-menu-pridej="skupina" title="<?= e(t('Položka bez odkazu, která jen otevírá podmenu')) ?>"><?= e(t('Skupina')) ?></button>
</fieldset>
<p class="tlacitka"><button class="tl" type="submit"><?= e(t('Uložit menu')) ?></button></p>
</form>
<?php if (!$automaticke || $umisteni !== 'hlavni'): ?>
<form class="vradku" method="post" action="<?= e($modul->url('automaticky', $volba)) ?>" data-potvrdit="<?= e(t($umisteni === 'hlavni' ? 'Vrátit menu k automatickému skládání ze stránek? Vaše úpravy se zahodí.' : 'Vyprázdnit menu v patičce?')) ?>"><?= $csrf ?><button class="navigace nebezpecne" type="submit"><?= e(t($umisteni === 'hlavni' ? 'Vrátit na automatické menu' : 'Vyprázdnit menu')) ?></button></form>
<?php endif ?>
<script src="<?= e($app->url('image/menu.js')) ?>?v=<?= e(KALETA_VERSION) ?>" defer></script>
