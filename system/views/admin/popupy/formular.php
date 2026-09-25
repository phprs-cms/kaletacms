<?php
/**
 * Nastavení pop-up okna: typ, spouštěč, četnost a pravidla zobrazení.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Moduly\Popupy $modul
 * @var string $csrf
 * @var array<string, mixed> $p
 * @var list<array<string, mixed>> $stranky
 * @var list<array<string, mixed>> $kolekce
 * @var array<string, string> $jazyky
 */
use Kaleta\Stavitel\Popupy;

$pr = $p['pravidla'];
$vyber = function (string $jmeno, array $moznosti, string $hodnota, bool $prelozit = true): string {
    $html = '<select id="' . e($jmeno) . '" name="' . e($jmeno) . '">';
    foreach ($moznosti as $k => $n) {
        $html .= '<option value="' . e((string) $k) . '"' . ((string) $k === $hodnota ? ' selected' : '') . '>' . e($prelozit ? t(is_array($n) ? $n[0] : $n) : (is_array($n) ? $n[0] : $n)) . '</option>';
    }

    return $html . '</select>';
};
?>
<p class="navigace-radek"><a class="tl" href="<?= e($modul->url('stavitel', ['id' => $p['idpp']])) ?>"><?= e(t('Upravit obsah v builderu')) ?></a></p>
<form class="formular" method="post" action="<?= e($modul->url('uloz')) ?>">
<?= $csrf ?>
<input type="hidden" name="idpp" value="<?= (int) $p['idpp'] ?>">
<div class="radek"><label for="nazev"><?= e(t('Název okna')) ?></label><div><input class="textpole siroke" id="nazev" name="nazev" value="<?= e($p['nazev']) ?>" maxlength="100" required></div></div>
<div class="radek"><label for="adresa"><?= e(t('Adresa')) ?></label><div><input class="textpole" id="adresa" name="adresa" value="<?= e($p['adresa']) ?>" maxlength="60" pattern="[a-z0-9][a-z0-9\-]*"><span class="napoveda"><?= e(t('Odkaz nebo tlačítko s adresou #popup-%s okno otevře kdykoli – i když se samo neukazuje.', $p['adresa'])) ?></span></div></div>
<div class="radek"><label for="typ"><?= e(t('Typ')) ?></label><div><?= $vyber('typ', Popupy::TYPY, $p['typ']) ?></div></div>
<fieldset>
<legend><?= e(t('Kdy se okno ukáže')) ?></legend>
<div class="radek"><label for="spoustec"><?= e(t('Spouštěč')) ?></label><div><?= $vyber('spoustec', Popupy::SPOUSTECE, $p['spoustec']) ?></div></div>
<div class="radek"><label for="hodnota"><?= e(t('Hodnota spouštěče')) ?></label><div><input class="textpole" size="5" type="number" id="hodnota" name="hodnota" min="0" max="3600" value="<?= (int) $p['hodnota'] ?>"><span class="napoveda"><?= e(t('Sekundy u času a nečinnosti, procenta stránky u rolování, počet stránek u návštěvy.')) ?></span></div></div>
<div class="radek"><label for="cetnost"><?= e(t('Četnost')) ?></label><div><?= $vyber('cetnost', Popupy::CETNOSTI, $p['cetnost']) ?> <label for="dni" class="vradku"><?= e(t('počet dní')) ?></label> <input class="textpole" size="5" type="number" id="dni" name="dni" min="1" max="365" value="<?= (int) $p['dni'] ?>"><span class="napoveda"><?= e(t('Pamatuje si to prohlížeč návštěvníka (sessionStorage a localStorage), ne cookies.')) ?></span></div></div>
</fieldset>
<fieldset>
<legend><?= e(t('Kde se okno ukáže')) ?></legend>
<div class="radek"><span class="popisek"><?= e(t('Místa')) ?></span><div class="volby">
<label><input type="radio" name="kde" value="vse"<?= $pr['kde'] === 'vse' ? ' checked' : '' ?>> <?= e(t('na celém webu')) ?></label>
<label><input type="radio" name="kde" value="vybrane"<?= $pr['kde'] === 'vybrane' ? ' checked' : '' ?>> <?= e(t('jen na vybraných stránkách, v kolekcích nebo v novinkách')) ?></label>
</div></div>
<div class="radek"><label for="stranky"><?= e(t('Stránky')) ?></label><div><select id="stranky" name="stranky[]" multiple size="8">
<?php foreach ($stranky as $s): ?>
	<option value="<?= (int) $s['ids'] ?>"<?= in_array((int) $s['ids'], $pr['stranky'], true) ? ' selected' : '' ?>><?= e(($s['jazyk'] !== '' ? strtoupper($s['jazyk']) . ' · ' : '') . $s['titulek']) ?></option>
<?php endforeach ?>
</select><span class="napoveda"><?= e(t('Víc stránek vyberete s klávesou Ctrl nebo Cmd.')) ?></span></div></div>
<?php if ($kolekce !== []): ?>
<div class="radek"><span class="popisek"><?= e(t('Stránky položek kolekcí')) ?></span><div class="volby">
<?php foreach ($kolekce as $k): ?>
<label><input type="checkbox" name="kolekce[]" value="<?= e($k['seo_link']) ?>"<?= in_array($k['seo_link'], $pr['kolekce'], true) ? ' checked' : '' ?>> <?= e($k['nazev']) ?> (/<?= e($k['seo_link']) ?>/…)</label>
<?php endforeach ?>
</div></div>
<?php endif ?>
<div class="radek"><span class="popisek"><?= e(t('Novinky')) ?></span><div class="volby"><label><input type="checkbox" name="novinky" value="1"<?= $pr['novinky'] ? ' checked' : '' ?>> <?= e(t('výpis novinek, kategorie a jednotlivé novinky')) ?></label></div></div>
<?php if ($jazyky !== []): ?>
<div class="radek"><label for="jazyk"><?= e(t('Jazyková verze')) ?></label><div><?= $vyber('jazyk', ['' => t('všechny')] + $jazyky, $pr['jazyk'], false) ?></div></div>
<?php endif ?>
<div class="radek"><label for="od"><?= e(t('Období')) ?></label><div><input class="textpole" type="date" id="od" name="od" value="<?= e($pr['od']) ?>" aria-label="<?= e(t('od')) ?>"> – <input class="textpole" type="date" id="do" name="do" value="<?= e($pr['do']) ?>" aria-label="<?= e(t('do')) ?>"><span class="napoveda"><?= e(t('Prázdné = bez omezení. Mimo období se okno do stránky vůbec nevloží.')) ?></span></div></div>
<div class="radek"><label for="zarizeni"><?= e(t('Zařízení')) ?></label><div><?= $vyber('zarizeni', Popupy::ZARIZENI, $pr['zarizeni']) ?></div></div>
<div class="radek"><label for="utm"><?= e(t('Jen z kampaně')) ?></label><div><input class="textpole" id="utm" name="utm" value="<?= e($pr['utm']) ?>" maxlength="80"><span class="napoveda"><?= e(t('Text v parametrech utm_* adresy, se kterou návštěvník přišel (např. jaro nebo newsletter). Prázdné = všichni.')) ?></span></div></div>
<div class="radek"><label for="odkud"><?= e(t('Jen odkud přišel')) ?></label><div><input class="textpole" id="odkud" name="odkud" value="<?= e($pr['odkud']) ?>" maxlength="80"><span class="napoveda"><?= e(t('Část adresy webu, ze kterého návštěvník přišel (např. facebook.com). Prázdné = odkudkoli.')) ?></span></div></div>
</fieldset>
<div class="radek"><label for="poradi"><?= e(t('Pořadí')) ?></label><div><input class="textpole" size="5" type="number" id="poradi" name="poradi" value="<?= (int) $p['poradi'] ?>"><span class="napoveda"><?= e(t('Když by se ukázalo víc oken, přednost má menší číslo. Přes otevřené okno se další neotevře.')) ?></span></div></div>
<p class="tlacitka"><input class="tl" type="submit" value="<?= e(t('Uložit nastavení')) ?>"> <a class="navigace" href="<?= e($modul->url()) ?>"><?= e(t('Zpět')) ?></a></p>
</form>
<form class="formular" method="post" action="<?= e($modul->url('vynuluj')) ?>" data-potvrdit="<?= e(t('Vynulovat počitadla zobrazení, zavření a konverzí?')) ?>">
<?= $csrf ?><input type="hidden" name="idpp" value="<?= (int) $p['idpp'] ?>">
<button class="navigace" type="submit"><?= e(t('Vynulovat počitadla')) ?></button>
</form>
<form class="formular" method="post" action="<?= e($modul->url('smaz')) ?>" data-potvrdit="<?= e(t('Smazat pop-up okno? Z webu zmizí hned.')) ?>">
<?= $csrf ?><input type="hidden" name="idpp" value="<?= (int) $p['idpp'] ?>">
<button class="navigace nebezpecne" type="submit"><?= e(t('Smazat okno')) ?></button>
</form>
