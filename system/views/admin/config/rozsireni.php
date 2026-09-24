<?php
/** Záložka Rozšíření. */
use Kaleta\Core\Rozsireni;
?>
<p class="hlaska"><?= e(t('Rozšíření jsou volitelné části Kalety. Všechna jsou součástí systému a udržuje je tým Kaleta – nic se nestahuje ani neinstaluje. Vypnuté rozšíření zmizí z menu i z webu, jeho data zůstanou a po zapnutí se vrátí.')) ?></p>
<?php
// kde se zapnuté rozšíření nastavuje – každé žije jinde v administraci, karta proto vede rovnou na to místo
$adm = fn (string $dotaz): string => $app->url('admin.php?' . $dotaz);
$nastaveniRozsireni = [
    'novinky' => [[$adm('modul=novinky'), 'Novinky'], [$adm('modul=kategorie'), 'Kategorie'], [$adm('modul=stitky'), 'Štítky']],
    'poptavky' => [[$adm('modul=poptavky'), 'Poptávky a doba uchování'], [$adm('modul=config&zalozka=zakladni#webhook_poptavky'), 'Webhook do CRM']],
    'newsletter' => [[$adm('modul=odberatele'), 'Odběratelé a export']],
    'statistika' => [[$adm('modul=stat'), 'Statistika'], [$adm('modul=config&zalozka=mereni'), 'Měření']],
    'presmerovani' => [[$adm('modul=presmerovani'), 'Přesměrování']],
    'jazyky' => [[$adm('modul=config&zalozka=zakladni#jazyky_dalsi'), 'Výběr jazyků']],
    'api' => [[$app->url('api/stranky'), 'Ukázka odpovědi API']],
    'asistent' => [['#asistent', 'Poskytovatel, klíč a model']],
    'claude' => [['#claude', 'Jak připojit Clauda']],
];
?>
<div class="rozsireni-seznam">
<?php foreach (Rozsireni::SEZNAM as $klic => [$nazev, $popis]): $zapnuto = in_array($klic, $zapnutaRozsireni, true); ?>
	<div class="rozsireni-karta">
		<input type="checkbox" id="rozsireni-<?= e($klic) ?>" name="rozsireni[]" value="<?= e($klic) ?>"<?= $zapnuto ? ' checked' : '' ?>>
		<span><label for="rozsireni-<?= e($klic) ?>"><strong><?= e(t($nazev)) ?></strong><br><?= e(t($popis)) ?></label>
<?php if ($zapnuto && isset($nastaveniRozsireni[$klic])): ?>
			<span class="rozsireni-odkazy"><?php foreach ($nastaveniRozsireni[$klic] as $i => [$url, $text]): ?><?= $i > 0 ? ' · ' : '' ?><a href="<?= e($url) ?>"><?= e(t($text)) ?></a><?php endforeach ?></span>
<?php endif ?>
		</span>
	</div>
<?php endforeach ?>
</div>
<p class="napoveda"><?= e(t('Odkazy u rozšíření se objeví po jeho zapnutí a uložení.')) ?></p>
<p class="napoveda"><?= e(t('Vždy zapnuté jádro: Stránky, Kolekce, Média, Vzhled webu, Části webu, Menu, Komponenty, Uživatelé a Nastavení.')) ?></p>
<details class="pokrocile" id="asistent"<?= in_array('asistent', $zapnutaRozsireni, true) ? ' open' : '' ?>>
<summary><?= e(t('AI asistent – poskytovatel, klíč a model')) ?></summary>
<input type="hidden" name="ai_poskytovatel_puvodni" value="<?= e($hodnoty['ai_poskytovatel']) ?>">
<div class="radek">
	<label for="ai_poskytovatel"><?= e(t('Poskytovatel')) ?></label>
	<div><select id="ai_poskytovatel" name="ai_poskytovatel">
<?php foreach (Kaleta\Core\Asistent::POSKYTOVATELE as $klic => [$nazev, , $konzole]): ?>
		<option value="<?= e($klic) ?>"<?= $hodnoty['ai_poskytovatel'] === $klic ? ' selected' : '' ?>><?= e(t($nazev)) ?></option>
<?php endforeach ?>
	</select>
	<span class="napoveda"><?= e(t('Klíč si vytvoříte u poskytovatele:')) ?>
<?php foreach (Kaleta\Core\Asistent::POSKYTOVATELE as [$nazev, , $konzole]): ?>
		<a href="<?= e($konzole) ?>" target="_blank" rel="noopener"><?= e(t($nazev)) ?></a>
<?php endforeach ?>
		· <?= e(t('Platíte jen za skutečné použití, jeden návrh stojí řádově haléře. Klíč se ukládá jen na vašem webu.')) ?></span></div>
</div>
<div class="radek">
	<label for="ai_klic"><?= e(t('Klíč API')) ?></label>
	<div><input class="textpole siroke" type="password" id="ai_klic" name="ai_klic" value="" autocomplete="off" placeholder="<?= $hodnoty['ai_klic'] !== '' ? e(t('uložen klíč končící %s – nový vložte jen při změně', $hodnoty['ai_klic'])) : '' ?>">
<?php if ($hodnoty['ai_klic'] !== ''): ?>
	<label><input type="checkbox" name="ai_klic_smazat" value="1"> <?= e(t('Odebrat uložený klíč')) ?></label>
<?php endif ?>
	</div>
</div>
<div class="radek">
	<label for="ai_model"><?= e(t('Model')) ?></label>
	<div><input class="textpole" id="ai_model" name="ai_model" value="<?= e($hodnoty['ai_model']) ?>" list="ai_modely" maxlength="80" spellcheck="false">
	<datalist id="ai_modely">
<?php foreach (Kaleta\Core\Asistent::MODELY as $klic => $nazev): ?>
		<option value="<?= e($klic) ?>"><?= e(t($nazev)) ?></option>
<?php endforeach ?>
	</datalist>
	<span class="napoveda"><?= e(t('U Claude vyberte z nabídky (doporučený je Sonnet). U ostatních poskytovatelů napište přesný název modelu z jejich dokumentace – nabídka modelů se tam často mění.')) ?></span></div>
</div>
<p class="napoveda"><?= e(t('Asistent jen navrhuje – o každé změně rozhoduje člověk. Při použití se text odešle zvolenému poskytovateli; bez kliknutí na tlačítko asistenta se nikam nic neposílá.')) ?></p>
</details>
<?php if (in_array('claude', $zapnutaRozsireni, true)): $adresaMcp = $app->request->origin() . $app->url('mcp'); ?>
<details class="pokrocile" id="claude" open>
<summary><?= e(t('Napojení na Claude – jak připojit')) ?></summary>
<ol class="navod">
	<li><?= e(t('V aplikaci Claude otevřete Nastavení → Konektory → Přidat vlastní konektor.')) ?></li>
	<li><?= e(t('Jako adresu zadejte:')) ?> <code class="totp-klic" style="font-size:13px"><?= e($adresaMcp) ?></code></li>
	<li><?= e(t('Claude vás pošle sem přihlásit a potvrdit přístup. Pracuje pak s právy vašeho účtu – stavby a novinky ukládá jako koncept.')) ?></li>
</ol>
<p class="napoveda"><?= e(t('V Claude Code stačí příkaz:')) ?> <code>claude mcp add --transport http kaleta <?= e($adresaMcp) ?></code>. <?= e(t('Připojené aplikace a osobní tokeny pro jiné nástroje najdete v')) ?> <a href="<?= e($app->url('admin.php?akce=ucet#claude')) ?>"><?= e(t('Můj účet')) ?></a>.
<?= e(t('Konektor potřebuje web na HTTPS v kořeni domény.')) ?></p>
</details>
<?php endif ?>
