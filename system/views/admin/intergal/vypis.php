<?php
/**
 * Média: vlevo složky a filtry, vpravo nahrávání a mřížka obrázků.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Moduly\Galerie $modul
 * @var string $csrf
 * @var list<array<string, mixed>> $obrazky
 * @var int $strana
 * @var int $stran
 * @var int $celkem
 * @var string $limit
 * @var array{sekce: ?int, clanek: int, nepouzite: bool} $filtr
 * @var list<array<string, mixed>> $slozky
 * @var string|null $clanek  titulek článku, podle kterého se filtruje
 */
$aktivniSlozka = null;
foreach ($slozky as $s) {
    if ((int) $s['ids'] === $filtr['sekce']) {
        $aktivniSlozka = $s;
    }
}
$parametry = array_filter(['sekce' => $filtr['sekce'], 'clanek' => $filtr['clanek'] ?: null, 'nepouzite' => $filtr['nepouzite'] ? 1 : null], fn ($v): bool => $v !== null);
$jeVse = $filtr['sekce'] === null && $filtr['clanek'] === 0 && !$filtr['nepouzite'];
?>
<div class="media">
<nav class="media-slozky" aria-label="<?= e(t('Složky')) ?>">
	<a href="<?= e($modul->url()) ?>"<?= $jeVse ? ' class="aktivni"' : '' ?>><?= e(t('Všechna média')) ?></a>
	<a href="<?= e($modul->url('', ['sekce' => 0])) ?>"<?= $filtr['sekce'] === 0 ? ' class="aktivni"' : '' ?>><?= e(t('Nezařazené')) ?></a>
	<a href="<?= e($modul->url('', ['nepouzite' => 1])) ?>"<?= $filtr['nepouzite'] ? ' class="aktivni"' : '' ?>><?= e(t('Nepoužité')) ?></a>
	<strong><?= e(t('Složky')) ?></strong>
<?php foreach ($slozky as $s): ?>
	<a href="<?= e($modul->url('', ['sekce' => $s['ids']])) ?>"<?= $aktivniSlozka === $s ? ' class="aktivni"' : '' ?>><?= e($s['nazev']) ?> <small>(<?= (int) $s['pocet'] ?>)</small></a>
<?php endforeach ?>
	<form method="post" action="<?= e($modul->url('slozka')) ?>">
		<?= $csrf ?>
		<input class="textpole" type="text" name="nazev" placeholder="<?= e(t('nová složka')) ?>" maxlength="100" required aria-label="<?= e(t('Název nové složky')) ?>">
		<button class="navigace" type="submit"><?= e(t('Přidat')) ?></button>
	</form>
</nav>

<div class="media-obsah">
<?php if ($clanek !== null): ?>
<p class="hlaska"><?= e(t('Obrázky použité v novince „%s“.', $clanek)) ?> <a href="<?= e($modul->url()) ?>"><?= e(t('Zobrazit všechna média')) ?></a></p>
<?php endif ?>
<?php if ($aktivniSlozka !== null): ?>
<div class="media-slozka-uprava">
	<form method="post" action="<?= e($modul->url('slozka')) ?>"><?= $csrf ?><input type="hidden" name="ids" value="<?= (int) $aktivniSlozka['ids'] ?>"><input class="textpole" type="text" name="nazev" value="<?= e($aktivniSlozka['nazev']) ?>" maxlength="100" required aria-label="<?= e(t('Název složky')) ?>"> <button class="navigace" type="submit"><?= e(t('Přejmenovat')) ?></button></form>
<?php if ($app->auth()->isAdmin()): ?>
	<form method="post" action="<?= e($modul->url('slozka_smaz')) ?>" data-potvrdit="<?= e(t('Smazat složku? Obrázky v ní zůstanou a přejdou mezi nezařazené.')) ?>"><?= $csrf ?><input type="hidden" name="ids" value="<?= (int) $aktivniSlozka['ids'] ?>"><button class="navigace nebezpecne" type="submit"><?= e(t('Smazat složku')) ?></button></form>
<?php endif ?>
</div>
<?php endif ?>

<form class="nahravani" method="post" enctype="multipart/form-data" action="<?= e($modul->url('nahraj')) ?>" data-nahravani>
	<?= $csrf ?>
	<input type="hidden" name="sekce" value="<?= (int) ($aktivniSlozka['ids'] ?? 0) ?>">
	<label for="soubory"><strong><?= e(t('Nahrát obrázky a přílohy')) ?><?= $aktivniSlozka !== null ? ' – ' . e($aktivniSlozka['nazev']) : '' ?></strong> <?= e(t('– vyberte soubory, nebo je sem přetáhněte myší')) ?></label>
	<input type="file" id="soubory" name="soubory[]" accept="image/jpeg,image/png,image/webp,image/gif,image/svg+xml,.svg,<?= e('.' . implode(',.', Kaleta\Core\Soubory::PRIPONY)) ?>" multiple required>
	<input class="tl" type="submit" value="<?= e(t('Nahrát')) ?>">
	<span class="napoveda"><?= e(t('Obrázky JPG, PNG, WebP a GIF i přílohy ke stažení (PDF, dokumenty, tabulky, ZIP, zvuk, video), nejvýše %s na soubor. Velké fotografie se samy zmenší na %s px a odstraní se z nich údaje o poloze.', $limit, Kaleta\Core\Obrazky::MAX_STRANA)) ?></span>
</form>

<?php if ($obrazky === []): ?>
<?= $app->view->render('admin/prazdno', ['ikona' => 'media', 'nadpis' => t('Žádné obrázky.'), 'text' => t('Nahrajte první fotky formulářem nahoře – nebo je přetáhněte přímo do textu v editoru.')]) ?>
<?php else: ?>
<form method="post" action="<?= e($modul->url('hromadne')) ?>">
<?= $csrf ?>
<div class="galerie-mrizka">
<?php foreach ($obrazky as $o): ?>
	<figure class="galerie-polozka">
<?php if ($o['nahl_poloha'] === ''): ?>
		<a class="galerie-soubor" href="<?= e($app->url($o['obr_poloha'])) ?>" target="_blank" rel="noopener"><span><?= e(strtoupper(pathinfo($o['obr_poloha'], PATHINFO_EXTENSION))) ?></span></a>
<?php else: ?>
		<a href="<?= e($app->url($o['obr_poloha'])) ?>" target="_blank" rel="noopener"><img src="<?= e($app->url($o['nahl_poloha'])) ?>" alt="<?= e($o['nazev']) ?>" loading="lazy" width="<?= (int) $o['nahl_width'] ?>" height="<?= (int) $o['nahl_height'] ?>"></a>
<?php endif ?>
		<figcaption>
			<strong title="<?= e($o['nazev']) ?>"><?= e($o['nazev'] !== '' ? $o['nazev'] : t('bez názvu')) ?></strong>
			<span><?= $o['nahl_poloha'] === '' ? '' : (int) $o['obr_width'] . '&times;' . (int) $o['obr_height'] . ' &middot; ' ?><?= e(Kaleta\Core\Soubory::velikost((int) $o['obr_vel'])) ?> &middot; <span<?= $o['kde'] !== [] ? ' title="' . e(t('Použito: %s', implode(', ', $o['kde']))) . '"' : '' ?>><?= e((int) $o['pouzito'] > 0 ? t('použito %s×', (int) $o['pouzito']) : t('nepoužito')) ?></span></span>
			<span><label><input type="checkbox" name="oznacene[]" value="<?= (int) $o['ido'] ?>"> <?= e(t('označit')) ?></label> &middot; <a href="<?= e($modul->url('vypis', $parametry + ['uprav' => $o['ido'], 'strana' => $strana])) ?>#uprav"><?= e(t('popis')) ?></a></span>
		</figcaption>
	</figure>
<?php endforeach ?>
</div>
<p class="media-hromadne">
	<?= e(t('S označenými:')) ?>
	<select name="do_sekce" aria-label="<?= e(t('Cílová složka')) ?>">
		<option value="0"><?= e(t('– nezařazené –')) ?></option>
<?php foreach ($slozky as $s): ?>
		<option value="<?= (int) $s['ids'] ?>"><?= e($s['nazev']) ?></option>
<?php endforeach ?>
	</select>
	<button class="navigace" type="submit" name="provest" value="presun"><?= e(t('Přesunout do složky')) ?></button>
	<button class="navigace nebezpecne" type="submit" name="provest" value="smaz" data-potvrdit="<?= e(t('Opravdu smazat označené obrázky? Z textů, kde jsou použité, zmizí.')) ?>"><?= e(t('Smazat')) ?></button>
</p>
</form>

<?php foreach ($obrazky as $o): if ((int) $o['ido'] !== $app->request->getInt('uprav')) { continue; } ?>
<form class="formular" id="uprav" method="post" action="<?= e($modul->url('uloz')) ?>">
	<?= $csrf ?>
	<input type="hidden" name="ido" value="<?= (int) $o['ido'] ?>">
	<div class="radek"><label for="nazev"><?= e(t('Název (alternativní text)')) ?></label><div><input class="textpole siroke" type="text" id="nazev" name="nazev" value="<?= e($o['nazev']) ?>" maxlength="150"><span class="napoveda"><?= e(t('Popište, co na obrázku je - čtou ho čtečky obrazovky i vyhledávače.')) ?></span></div></div>
	<div class="radek"><label for="popis"><?= e(t('Popisek pod obrázkem')) ?></label><input class="textpole siroke" type="text" id="popis" name="popis" value="<?= e($o['popis']) ?>" maxlength="500"></div>
	<div class="radek"><label for="autor"><?= e(t('Autor obrázku')) ?></label><div><input class="textpole siroke" type="text" id="autor" name="autor" value="<?= e($o['autor'] ?? '') ?>" maxlength="120"><span class="napoveda"><?= e(t('Uvede se pod hlavní fotkou novinky, pokud novinka nemá vlastního autora fotky.')) ?></span></div></div>
<?php if ($o['nahl_poloha'] !== '' && !str_ends_with($o['obr_poloha'], '.svg')): [$ox, $oy] = array_map('intval', explode(' ', str_replace('%', '', $o['ohnisko'] ?: '50% 50%'))) + [1 => 50]; ?>
	<div class="radek"><span class="popisek"><?= e(t('Ohnisko ořezu')) ?></span><div>
		<div class="ohnisko" data-ohnisko><img src="<?= e($app->url($o['nahl_poloha'])) ?>" alt=""><span class="ohnisko-bod" style="left:<?= $ox ?>%;top:<?= $oy ?>%"></span></div>
		<label><?= e(t('Vodorovně')) ?> <input class="textpole" type="number" name="ohnisko_x" min="0" max="100" value="<?= $ox ?>" size="3"> %</label>
		<label><?= e(t('Svisle')) ?> <input class="textpole" type="number" name="ohnisko_y" min="0" max="100" value="<?= $oy ?>" size="3"> %</label>
		<span class="napoveda"><?= e(t('Klepněte do náhledu na to, co musí zůstat vidět, když se fotka ořízne do jiného tvaru (karta, pozadí sekce).')) ?></span></div></div>
<?php endif ?>
	<p class="tlacitka"><input class="tl" type="submit" value="<?= e(t('Uložit')) ?>"></p>
</form>
<?php if (preg_match('/\.(jpg|png|webp)$/', $o['obr_poloha'])): ?>
<form class="formular" method="post" action="<?= e($modul->url('nahradit')) ?>" enctype="multipart/form-data">
	<?= $csrf ?>
	<input type="hidden" name="ido" value="<?= (int) $o['ido'] ?>">
	<div class="radek"><label for="soubor-nahrada"><?= e(t('Nahradit soubor')) ?></label><div><input type="file" id="soubor-nahrada" name="soubor" accept="image/jpeg,image/png,image/webp" required>
		<span class="napoveda"><?= e(t('Nová fotka se ukáže všude, kde je stará použitá – adresa souboru se nezmění.')) ?></span></div></div>
	<p class="tlacitka"><input class="navigace" type="submit" value="<?= e(t('Nahradit')) ?>"></p>
</form>
<?php endif ?>
<?php endforeach ?>

<?php if ($stran > 1): ?>
<p class="strankovani">
<?php for ($s = 1; $s <= $stran; $s++): ?>
	<?= $s === $strana ? '<strong>[' . $s . ']</strong>' : '<a href="' . e($modul->url('', $parametry + ['strana' => $s])) . '">' . $s . '</a>' ?>
<?php endfor ?>
</p>
<?php endif ?>
<?php endif ?>
</div>
</div>
