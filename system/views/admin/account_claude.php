<?php
/**
 * My account → Claude (3.47, U68; only with the extension on): connected applications, personal tokens and a new token.
 *
 * @var Kaleta\Core\App $app
 * @var array<string, mixed> $user
 * @var string $csrf
 * @var string $action  the address the forms post to (this tab)
 * @var string $proofNote  the note shown instead of a password field to an account that signs in with a passkey only
 * @var bool $passkeyOnly
 * @var bool $fresh
 * @var bool $claude
 * @var list<array<string, mixed>> $tokens
 * @var list<array<string, mixed>> $apps
 * @var string $newToken
 * @var string $mcpUrl
 */
?>
<?php if ($claude): ?>
<?php // the buttons of the listed connections (switch, disconnect, revoke) belong to this form through their form attribute:
// the form below then has "Create token" as its only button, so Enter in the token name creates the token (3.10 audit, N310-7) ?>
<form id="claude-connections" method="post" action="<?= $action ?>"><?= $csrf ?></form>
<form class="formular" method="post" action="<?= $action ?>">
<?= $csrf ?>
<fieldset id="claude"><legend><?= e(t('Claude connection')) ?></legend>
<?php if ($newToken !== ''): ?>
<div class="hlaska hlaska-ok">
	<p><strong><?= e(t('The token has been created.')) ?></strong> <?= e(t('Copy it now – it will not be shown again.')) ?></p>
	<p><code class="totp-klic"><?= e($newToken) ?></code></p>
	<p><?= e(t('In Claude Code, run:')) ?></p>
	<p><code class="totp-klic" style="font-size:12px">claude mcp add --transport http kaleta <?= e($mcpUrl) ?> --header "Authorization: Bearer <?= e($newToken) ?>"</code></p>
	<p class="napoveda"><?= e(t('In the Claude app you do not need a token: add a custom connector with the address %s and confirm access by signing in.', $mcpUrl)) ?></p>
</div>
<?php endif ?>
<p><?= e(t('The easiest way is to add a custom connector in the Claude app with the address %s – Claude sends you here to sign in and confirm access, no token to copy. The token below is for Claude Code and other tools without sign-in.', $mcpUrl)) ?></p>
<?= $app->view->render('admin/mcp_address', ['url' => $mcpUrl, 'id' => 'mcp-adresa-ucet']) ?>
<?php $accessLabel = array_map(fn (string $label): string => t($label), Kaleta\Front\OAuth::ACCESS_LABELS); ?>
<?php
// 3.10: the tool set of a connection and the button that switches it ("<set>:token:<id>" or "<set>:app:<client_id>")
// 3.26: a "work" connection (D-W10); 3.36: migration and fleet – one folded choice of every set offered on this site
$toolSetNames = ['essentials' => t('essentials'), 'work' => t('work tools'), 'migration' => t('migration tools'), 'fleet' => t('fleet tools'), 'all' => t('all tools')];
$toolSetLabel = fn (string $set): string => '<span class="stitek">' . e($toolSetNames[Kaleta\Mcp\Catalog::toolSet($set)]) . '</span>';
$toolSetSwitch = function (string $set, string $target) use ($app, $toolSetNames): string {
    $set = Kaleta\Mcp\Catalog::toolSet($set);
    $buttons = '';
    foreach (Kaleta\Mcp\Catalog::offered($app->settings(), $set) as $other) {
        if ($other !== $set) {
            $buttons .= '<button class="navigace" type="submit" form="claude-connections" name="tool_set_switch" value="' . e($other . ':' . $target) . '">' . e($toolSetNames[$other]) . '</button>';
        }
    }

    return '<details class="sada-nastroju"><summary>' . e(t('Change the tool set')) . '</summary><div>' . $buttons . '</div></details>';
};
?>
<?php if ($apps !== []): ?>
<h2><?= e(t('Connected applications')) ?></h2>
<?php foreach ($apps as $a): ?>
<div class="napojeni"><span class="stitek"><?= e($a['nazev']) ?></span> <span class="stitek"><?= e($accessLabel[$a['access']] ?? $accessLabel['read']) ?></span> <?= $toolSetLabel((string) $a['tool_set']) ?> <?= e(t('connected %s', format_date($a['vytvoren']))) ?>, <?= e($a['pouzit'] ? t('naposledy použita %s', format_date($a['pouzit'], true)) : t('not used yet')) ?>
	<?= $toolSetSwitch((string) $a['tool_set'], 'app:' . $a['klient']) ?>
	<button class="navigace nebezpecne" type="submit" form="claude-connections" name="odpojit_klient" value="<?= e($a['klient']) ?>" data-potvrdit="<?= e(t('Disconnect the application? It will not get into the website until you allow it again.')) ?>"><?= e(t('Disconnect')) ?></button></div>
<?php endforeach ?>
<?php endif ?>
<p><?= e(t('Claude will work with the site')) ?> <strong><?= e(t('in your name and with your permissions')) ?></strong>: <?= e(t((int) $user['admin'] === 2 ? 'write and edit pages and news, and manage categories, collections and the look of the site.' : 'write and edit news.')) ?> <?= e(t('With “Drafts only”, pages and news stay drafts until you publish them; with “Can also publish”, Claude publishes when you ask it to. All its changes are in the Change log.')) ?></p>
<h2><?= e(t('Personal tokens (Claude Code and other tools)')) ?></h2>
<p class="napoveda"><?= e(t('For tools without sign-in. Protect a token like a password.')) ?></p>
<?php foreach ($tokens as $t): ?>
<div class="napojeni"><span class="stitek"><?= e($t['nazev']) ?></span> <span class="stitek"><?= e($accessLabel[$t['access']] ?? $accessLabel['read']) ?></span> <?= $toolSetLabel((string) $t['tool_set']) ?> <?= e(t('created %s', format_date($t['vytvoren']))) ?>, <?= e($t['pouzit'] ? t('naposledy použit %s', format_date($t['pouzit'], true)) : t('zatím nepoužit')) ?>,
	<?= $t['expirace'] === null ? e(t('no expiry')) : ($t['expirace'] < date('Y-m-d H:i:s') ? '<strong>' . e(t('expired %s', format_date($t['expirace']))) . '</strong>' : e(t('valid until %s', format_date($t['expirace'])))) ?>
	<?= $toolSetSwitch((string) $t['tool_set'], 'token:' . (int) $t['idt']) ?>
	<button class="navigace nebezpecne" type="submit" form="claude-connections" name="smaz_token" value="<?= (int) $t['idt'] ?>" data-potvrdit="<?= e(t('Revoke the token? Claude will no longer be able to sign in with it.')) ?>"><?= e(t('Revoke token')) ?></button></div>
<?php endforeach ?>
<div class="radek"><label for="token-nazev"><?= e(t('Name of the new token')) ?></label><div><input class="textpole" type="text" id="token-nazev" name="nazev" maxlength="100" size="30" placeholder="<?= e(t('e.g. Claude on my laptop')) ?>"></div></div>
<div class="radek"><label for="token-platnost"><?= e(t('Valid for')) ?></label><div><select id="token-platnost" name="platnost">
<?php foreach (Kaleta\Admin\Account::TOKEN_LIFETIMES as $days): ?>
	<option value="<?= $days ?>"<?= $days === 365 ? ' selected' : '' ?>><?= e($days === 0 ? t('no expiry') : ($days === 365 ? t('1 year') : t('%d days', $days))) ?></option>
<?php endforeach ?>
</select><span class="napoveda"><?= e(t('An expired token stops working on its own; you then create a new one. A token nobody uses for %d days is reported in System status.', Kaleta\Core\SecurityHygiene::CONNECTION_DAYS)) ?></span></div></div>
<?= $app->view->render('admin/connection-access', ['role' => t(Kaleta\Core\Auth::TYPES[(int) $user['admin']] ?? ''), 'selected' => 'full', 'toolSet' => 'all', 'toolSets' => Kaleta\Mcp\Catalog::offered($app->settings())]) ?>
<?php if ($passkeyOnly): ?>
<?= $proofNote ?>
<?php else: ?>
<div class="radek"><label for="token-heslo"><?= e(t('Current password')) ?></label><div><input class="textpole" type="password" id="token-heslo" name="soucasne" size="30" autocomplete="current-password" required><span class="napoveda"><?= e(t('Needed to create a token – a token works without the password and without two-factor sign-in.')) ?></span></div></div>
<?php endif ?>
<p class="tlacitka"><button class="tl" type="submit" name="co" value="token_novy"><?= e(t('Create token')) ?></button></p>
</fieldset>
</form>
<?php endif ?>
