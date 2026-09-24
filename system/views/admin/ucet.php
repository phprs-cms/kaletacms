<?php
/**
 * Můj účet.
 *
 * @var Kaleta\Core\App $app
 * @var array<string, mixed> $user
 * @var string $csrf
 * @var list<array<string, mixed>> $klice přihlašovací klíče účtu (passkeys)
 * @var list<string> $zalozniKody  právě vytvořené záložní kódy (zobrazí se jen jednou)
 * @var string $noveTajemstvi      rozpracované zapínání dvoufázového přihlášení
 * @var string $uri
 * @var int $zbyvaKodu
 * @var bool $claude  je zapnuté rozšíření Napojení na Claude
 * @var list<array<string, mixed>> $tokeny  osobní tokeny
 * @var list<array<string, mixed>> $aplikace  aplikace připojené přes OAuth (konektor Claude)
 * @var string $novyToken  právě vytvořený token (zobrazí se jen jednou)
 * @var string $adresaMcp
 */
$akce = e($app->url('admin.php?akce=ucet'));
?>
<?php if ($zalozniKody !== []): ?>
<div class="hlaska hlaska-ok">
	<p><strong><?= e(t('Dvoufázové přihlášení je zapnuté.')) ?></strong> <?= e(t('Uložte si záložní kódy – každý jde použít jednou, když nebudete mít telefon. Už se nezobrazí.')) ?></p>
	<p class="zalozni-kody"><?= implode(' &nbsp; ', array_map(e(...), $zalozniKody)) ?></p>
</div>
<?php endif ?>

<form class="formular" method="post" action="<?= $akce ?>">
<?= $csrf ?><input type="hidden" name="co" value="profil">
<fieldset><legend><?= e(t('Moje údaje')) ?></legend>
<div class="radek"><span class="popisek"><?= e(t('Přihlašovací jméno')) ?></span><div><?= e($user['user']) ?> <span class="napoveda"><?= e(t('Mění správce v sekci Uživatelé.')) ?></span></div></div>
<div class="radek"><label for="jmeno"><?= e(t('Jméno')) ?></label><div><input class="textpole siroke" type="text" id="jmeno" name="jmeno" value="<?= e($user['jmeno']) ?>" maxlength="100"><span class="napoveda"><?= e(t('Zobrazuje se u novinek na webu.')) ?></span></div></div>
<div class="radek"><label for="email"><?= e(t('E-mail')) ?></label><input class="textpole siroke" type="email" id="email" name="email" value="<?= e($user['email']) ?>" maxlength="190"></div>
<div class="radek"><label for="url"><?= e(t('Můj web')) ?></label><input class="textpole siroke" type="url" id="url" name="url" value="<?= e($user['url']) ?>" maxlength="255" placeholder="https://"></div>
<div class="radek"><label for="pozice"><?= e(t('Pozice ve firmě')) ?></label><input class="textpole siroke" type="text" id="pozice" name="pozice" value="<?= e($user['pozice']) ?>" maxlength="100" placeholder="<?= e(t('např. vedoucí obchodu')) ?>"></div>
<div class="radek"><label for="foto"><?= e(t('Moje fotka')) ?></label><div><input class="textpole siroke" type="text" id="foto" name="foto" value="<?= e($user['foto']) ?>" maxlength="255" data-obrazek><span class="napoveda"><?= e(t('Čtvercová fotka, stačí 300 × 300 px.')) ?></span></div></div>
<div class="radek"><label for="bio"><?= e(t('Pár vět o mně')) ?></label><div><textarea class="textbox nizky" id="bio" name="bio" rows="4" maxlength="1200"><?= e((string) $user['bio']) ?></textarea><span class="napoveda"><?= e(t('Zobrazí se jako medailonek pod vašimi novinkami. Čím se ve firmě zabýváte a co máte za sebou.')) ?></span></div></div>
<div class="radek"><label for="jazyk"><?= e(t('Jazyk administrace')) ?></label><div><select id="jazyk" name="jazyk">
<?php foreach (Kaleta\Core\Jazyk::ADMINISTRACE as $kodJazyka => $nazevJazyka): ?>
	<option value="<?= e($kodJazyka) ?>"<?= ($user['jazyk'] ?: 'cs') === $kodJazyka ? ' selected' : '' ?>><?= e($nazevJazyka) ?></option>
<?php endforeach ?>
</select><span class="napoveda">Language · Jazyk · Sprache</span></div></div>
</fieldset>
<p class="tlacitka"><input class="tl" type="submit" value="<?= e(t('Uložit údaje')) ?>"></p>
</form>

<form class="formular" method="post" action="<?= $akce ?>" autocomplete="off">
<?= $csrf ?><input type="hidden" name="co" value="heslo">
<fieldset><legend><?= e(t('Změna hesla')) ?></legend>
<div class="radek"><label for="soucasne"><?= e(t('Současné heslo')) ?></label><div><input class="textpole" type="password" id="soucasne" name="soucasne" size="30" autocomplete="current-password" required></div></div>
<div class="radek"><label for="nove"><?= e(t('Nové heslo')) ?></label><div><input class="textpole" type="password" id="nove" name="nove" size="30" minlength="10" autocomplete="new-password" required><span class="napoveda"><?= e(t('Alespoň 10 znaků.')) ?></span></div></div>
<div class="radek"><label for="nove2"><?= e(t('Nové heslo znovu')) ?></label><div><input class="textpole" type="password" id="nove2" name="nove2" size="30" autocomplete="new-password" required></div></div>
<?php if ($tokeny !== []): ?>
<div class="radek"><span class="popisek"><?= e(t('Napojení')) ?></span><div class="volby"><label><input type="checkbox" name="zrusit_tokeny" value="1" checked> <?= e(t('zrušit i tokeny napojení (Claude, API)')) ?></label>
	<span class="napoveda"><?= e(t('Token funguje bez hesla i bez dvoufázového přihlášení. Měníte-li heslo kvůli podezření na zneužití, nechte zaškrtnuté a napojení pak vytvořte znovu.')) ?></span></div></div>
<?php endif ?>
</fieldset>
<p class="tlacitka"><input class="tl" type="submit" value="<?= e(t('Změnit heslo')) ?>"></p>
</form>

<form class="formular" method="post" action="<?= $akce ?>" autocomplete="off">
<?= $csrf ?>
<fieldset><legend><?= e(t('Dvoufázové přihlášení')) ?></legend>
<?php if ($user['totp_tajemstvi'] !== ''): ?>
<p><span class="stitek stitek-vydano"><?= e(t('zapnuté')) ?></span> <?= e(t('Při přihlášení zadáváte kromě hesla i kód z aplikace. Zbývá záložních kódů: %d.', $zbyvaKodu)) ?></p>
<input type="hidden" name="co" value="totp_vypni">
<div class="radek"><label for="vyp-heslo"><?= e(t('Heslo pro potvrzení')) ?></label><div><input class="textpole" type="password" id="vyp-heslo" name="soucasne" size="30" autocomplete="current-password" required></div></div>
<p class="tlacitka"><button class="navigace" type="submit"><?= e(t('Vypnout dvoufázové přihlášení')) ?></button></p>
<?php elseif ($noveTajemstvi !== ''): ?>
<input type="hidden" name="co" value="totp_potvrd">
<ol>
	<li><?= e(t('V ověřovací aplikaci (Google Authenticator, Microsoft Authenticator, 1Password, Aegis…) přidejte nový účet ručním zadáním klíče:')) ?><br><code class="totp-klic"><?= e(trim(chunk_split($noveTajemstvi, 4, ' '))) ?></code><br><small><a href="<?= e($uri) ?>"><?= e(t('Na mobilu můžete klepnout sem – odkaz otevře ověřovací aplikaci.')) ?></a></small></li>
	<li><?= e(t('Opište šestimístný kód, který aplikace ukazuje:')) ?></li>
</ol>
<div class="radek"><label for="kod"><?= e(t('Kód z aplikace')) ?></label><div><input class="textpole" type="text" id="kod" name="kod" size="12" maxlength="7" inputmode="numeric" autocomplete="one-time-code" required autofocus></div></div>
<p class="tlacitka"><input class="tl" type="submit" value="<?= e(t('Potvrdit a zapnout')) ?>"></p>
<?php else: ?>
<p><?= e(t('Účet je chráněný jen heslem. S dvoufázovým přihlášením se bez vašeho telefonu nepřihlásí ani ten, kdo heslo uhodne nebo ukradne.')) ?></p>
<input type="hidden" name="co" value="totp_start">
<p class="tlacitka"><input class="tl" type="submit" value="<?= e(t('Zapnout dvoufázové přihlášení')) ?>"></p>
<?php endif ?>
</fieldset>
</form>

<?php if ($user['totp_tajemstvi'] !== ''): ?>
<form class="formular" method="post" action="<?= $akce ?>" data-klice="<?= $akce ?>">
<?= $csrf ?>
<fieldset><legend><?= e(t('Přihlašovací klíče')) ?></legend>
<p><?= e(t('Otisk prstu, Face ID, Windows Hello nebo bezpečnostní klíč místo opisování kódu z aplikace. Kód a záložní kódy fungují dál – pro případ, že zařízení nebudete mít u sebe.')) ?></p>
<?php if ($klice !== []): ?>
<div class="tab-obal">
<table class="vypis">
<thead><tr><th scope="col"><?= e(t('Zařízení')) ?></th><th scope="col"><?= e(t('Přidáno')) ?></th><th scope="col"><?= e(t('Naposledy použito')) ?></th><th scope="col"><?= e(t('Akce')) ?></th></tr></thead>
<tbody>
<?php foreach ($klice as $k): ?>
<tr>
	<td><?= e($k['nazev']) ?></td>
	<td class="cislo"><?= e(datum((string) $k['vytvoreno'])) ?></td>
	<td class="cislo"><?= $k['pouzito'] !== null ? e(datum((string) $k['pouzito'])) : '–' ?></td>
	<td class="akce"><button class="navigace nebezpecne" type="submit" name="idk" value="<?= (int) $k['idk'] ?>" data-potvrdit="<?= e(t('Odebrat přihlašovací klíč? Přihlásit se půjde dál kódem z aplikace.')) ?>"><?= e(t('Smazat')) ?></button></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<input type="hidden" name="co" value="klic_smaz">
<?php endif ?>
<div class="radek"><label for="klic-nazev"><?= e(t('Název zařízení')) ?></label><div><input class="textpole" type="text" id="klic-nazev" name="nazev" size="30" maxlength="80" placeholder="<?= e(t('např. MacBook, telefon')) ?>"></div></div>
<p class="tlacitka"><button class="navigace" type="button" data-klic-pridat><?= e(t('Přidat klíč z tohoto zařízení')) ?></button></p>
<p class="hlaska hlaska-chyba" data-klic-chyba hidden role="alert"></p>
<p class="napoveda" data-klic-nepodporuje hidden><?= e(t('Tento prohlížeč přihlašovací klíče nepodporuje, nebo web neběží na HTTPS.')) ?></p>
</fieldset>
</form>
<script src="<?= e($app->url('image/klice.js')) ?>?v=<?= e(KALETA_VERSION) ?>" defer></script>
<?php endif ?>

<?php if ($claude): ?>
<form class="formular" method="post" action="<?= $akce ?>">
<?= $csrf ?>
<fieldset><legend><?= e(t('Napojení na Claude')) ?></legend>
<?php if ($novyToken !== ''): ?>
<div class="hlaska hlaska-ok">
	<p><strong><?= e(t('Token je vytvořený.')) ?></strong> <?= e(t('Zkopírujte si ho teď – už se nezobrazí.')) ?></p>
	<p><code class="totp-klic"><?= e($novyToken) ?></code></p>
	<p><?= e(t('V Claude Code spusťte:')) ?></p>
	<p><code class="totp-klic" style="font-size:12px">claude mcp add --transport http kaleta <?= e($adresaMcp) ?> --header "Authorization: Bearer <?= e($novyToken) ?>"</code></p>
	<p class="napoveda"><?= e(t('V aplikaci Claude token nepotřebujete: přidejte vlastní konektor s adresou %s a přístup potvrďte přihlášením.', $adresaMcp)) ?></p>
</div>
<?php endif ?>
<p><?= e(t('Nejjednodušší je přidat v aplikaci Claude vlastní konektor s adresou %s – Claude vás pošle sem přihlásit a potvrdit přístup, žádný token nekopírujete. Token níže je pro Claude Code a jiné nástroje bez přihlášení.', $adresaMcp)) ?></p>
<?php if ($aplikace !== []): ?>
<h3><?= e(t('Připojené aplikace')) ?></h3>
<?php foreach ($aplikace as $a): ?>
<p><span class="stitek"><?= e($a['nazev']) ?></span> <?= e(t('připojena %s', datum($a['vytvoren']))) ?>, <?= e($a['pouzit'] ? t('naposledy použita %s', datum($a['pouzit'], true)) : t('zatím nepoužita')) ?>
	<button class="navigace nebezpecne" type="submit" name="odpojit_klient" value="<?= e($a['klient']) ?>" data-potvrdit="<?= e(t('Odpojit aplikaci? Do webu se už nedostane, dokud ji znovu nepovolíte.')) ?>"><?= e(t('Odpojit')) ?></button></p>
<?php endforeach ?>
<?php endif ?>
<p><?= e(t('Claude bude s webem pracovat')) ?> <strong><?= e(t('vaším jménem a s vašimi právy')) ?></strong>: <?= e(t((int) $user['admin'] === 2 ? 'psát a upravovat stránky a novinky, spravovat kategorie a tvořit šablony webu.' : 'psát a upravovat novinky.')) ?> <?= e(t('Nové novinky zakládá jako koncepty a nové stránky jako skryté. Všechny jeho zásahy najdete v Protokolu změn. Token chraňte jako heslo.')) ?></p>
<?php foreach ($tokeny as $t): ?>
<p><span class="stitek"><?= e($t['nazev']) ?></span> <?= e(t('vytvořen %s', datum($t['vytvoren']))) ?>, <?= e($t['pouzit'] ? t('naposledy použit %s', datum($t['pouzit'], true)) : t('zatím nepoužit')) ?>
	<button class="navigace nebezpecne" type="submit" name="smaz_token" value="<?= (int) $t['idt'] ?>" data-potvrdit="<?= e(t('Zrušit token? Claude se jím už nepřihlásí.')) ?>"><?= e(t('Zrušit token')) ?></button></p>
<?php endforeach ?>
<div class="radek"><label for="token-nazev"><?= e(t('Název nového tokenu')) ?></label><div><input class="textpole" type="text" id="token-nazev" name="nazev" maxlength="100" size="30" placeholder="<?= e(t('např. Claude na notebooku')) ?>"></div></div>
<p class="tlacitka"><button class="tl" type="submit" name="co" value="token_novy"><?= e(t('Vytvořit token')) ?></button></p>
</fieldset>
</form>
<?php endif ?>
