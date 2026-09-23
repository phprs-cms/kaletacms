<?php /** Záložka Čtenáři a platby: registrace, zamčený obsah, předplatné a platby přes Stripe. Proměnné a funkce $pole viz vypis.php. */ ?>
<?php if (!MiroCMS\Core\Rozsireni::je($app->settings(), 'ctenari')): ?>
<p class="hlaska hlaska-varovani"><?= MiroCMS\Admin\Cesty::odkazy($app->url('admin.php'), t('Účty čtenářů, zamčený obsah a předplatné jsou vypnuté. Zapnete je v sekci Rozšíření; nastavení níže se použije potom.'), ['rozsireni']) ?></p>
<?php endif ?>
<fieldset>
<legend><?= e(t('Čtenáři a zamčený obsah')) ?></legend>
<?php
$pole('komentare_jen_prihlaseni', 'Komentovat smí jen přihlášení čtenáři', 'ano', 'Méně spamu a slušnější diskuse; čtenář komentuje pod svým účtem.');
$pole('ctenari_registrace', 'Povolit nové registrace', 'ano');
$pole('zamek_odstavcu', 'Ukázka zamčeného článku', 'cislo', 'Kolik odstavců textu uvidí čtenář bez přístupu (perex vidí vždy). 0 = jen perex.', 'min="0" max="10"');
$pole('paywall_zdarma', 'Článků zdarma měsíčně', 'cislo', 'Měkký paywall: tolik zamčených článků si každý měsíc přečte kdokoli bez přihlášení, potom uvidí výzvu. 0 = vypnuto. Počítá se v prohlížeči čtenáře, vyhledávače vidí články celé.', 'min="0" max="50"');
$pole('predplatne_url', 'Kde získat předplatné', 'text', 'Stránka webu (např. /predplatne) nebo platební odkaz (https://…). U článků pro předplatitele a v účtu čtenáře se ukáže tlačítko „Získat předplatné“. Bez vyplnění čtenář neví, jak se předplatitelem stát.', 'maxlength="255" placeholder="/predplatne"');
$pole('zamek_text', 'Text výzvy pod ukázkou', 'text', 'Nepovinné – například proč se registrovat nebo jak získat předplatné.', 'maxlength="300"');
$stripeHotovo = MiroCMS\Core\Stripe::nastaveno($app->settings());
?>
</fieldset>
<fieldset>
<legend><?= e(t('Platby přes Stripe')) ?> <span class="stitek <?= $stripeHotovo ? 'stitek-vydano' : 'stitek-koncept' ?>"><?= e(t($stripeHotovo ? 'zapnuté' : 'vypnuté')) ?></span></legend>
<p class="napoveda"><?= e(t('Čtenář si předplatné zaplatí kartou sám a web mu ho sám zapne i prodlužuje. Platí se na stránkách služby Stripe – údaje o kartě se na váš web nedostanou. Produkt a ceny si založíte ve Stripe, sem patří jen jejich čísla. Platby se zapnou, jakmile je vyplněný klíč, tajemství webhooku a aspoň jedna cena; tlačítko „Získat předplatné“ pak vede do účtu čtenáře místo na adresu výše.')) ?> <?= MiroCMS\Core\Napoveda::odkaz('ctenari-a-prijmy/platby-stripe', 'Platby přes Stripe') ?></p>
<?php foreach (['stripe_tajny_klic' => ['Tajný klíč', 'sk_… / rk_…', 'Stripe → Developers → API keys. Doporučujeme omezený klíč (Restricted key) jen s právy, která vyjmenovává nápověda. Nejdřív vše vyzkoušejte s testovacím klíčem.'], 'stripe_webhook_tajemstvi' => ['Tajemství webhooku', 'whsec_…', 'Stripe ho ukáže po založení webhooku (Signing secret). Web jím ověřuje, že zprávu o platbě opravdu poslal Stripe.']] as $klic => [$popisek, $ukazka, $napoveda]): ?>
<div class="radek">
	<label for="<?= e($klic) ?>"><?= e(t($popisek)) ?></label>
	<div><input class="textpole siroke" type="password" id="<?= e($klic) ?>" name="<?= e($klic) ?>" value="" autocomplete="off" maxlength="300" placeholder="<?= $hodnoty[$klic] !== '' ? e(t('uložen klíč končící %s – nový vložte jen při změně', $hodnoty[$klic])) : e($ukazka) ?>">
	<span class="napoveda"><?= e(t($napoveda)) ?></span>
<?php if ($hodnoty[$klic] !== ''): ?>
	<label><input type="checkbox" name="<?= e($klic) ?>_smazat" value="1"> <?= e(t('Odebrat uložený klíč')) ?></label>
<?php endif ?>
	</div>
</div>
<?php endforeach ?>
<div class="radek">
	<span class="popisek"><?= e(t('Adresa webhooku')) ?></span>
	<div><code><?= e(rtrim($adresaWebu, '/') . '/platba/stripe') ?></code>
	<span class="napoveda"><?= e(t('Tuto adresu zadejte ve Stripe → Developers → Webhooks a zapněte události:')) ?> <code><?= e(implode(', ', MiroCMS\Core\Stripe::UDALOSTI)) ?></code></span></div>
</div>
<?php
$pole('stripe_cena_mesic', 'Měsíční cena', 'text', 'Číslo opakované ceny ze Stripe (Product catalog → produkt → cena → Copy price ID). Prázdné = měsíční předplatné se nenabízí.', 'maxlength="120" placeholder="price_…" autocomplete="off"');
$pole('stripe_cena_mesic_text', 'Popis měsíční ceny', 'text', 'Text u tlačítka v účtu čtenáře, například „99 Kč měsíčně“. Částku účtuje Stripe podle ceny výše – tady ji jen popisujete.', 'maxlength="60"');
$pole('stripe_cena_rok', 'Roční cena', 'text', 'Prázdné = roční předplatné se nenabízí.', 'maxlength="120" placeholder="price_…" autocomplete="off"');
$pole('stripe_cena_rok_text', 'Popis roční ceny', 'text', 'Například „990 Kč ročně“.', 'maxlength="60"');
?>
</fieldset>
