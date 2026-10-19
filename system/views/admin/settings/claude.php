<?php
/**
 * Claude settings (3.2, Modules\ClaudeSettings): how to connect, the instructions every connection gets, the guardrails,
 * and every connection of the site – moved here from Features so Claude has one home.
 *
 * @var Kaleta\Core\App $app
 * @var array<string, string> $values
 * @var list<array<string, mixed>> $connections
 * @var list<array{ids: int|string, titulek: string, seo_link: string, jazyk: string}> $protectablePages
 */
$mcpUrl = Kaleta\Core\AskClaude::mcpUrl($app);
$lastCall = Kaleta\Core\AskClaude::lastCall($app->db());
$accessNames = array_map(fn (string $label): string => t($label), Kaleta\Front\OAuth::ACCESS_LABELS);
?>
<?php if (!in_array('claude', $enabledExtensions, true)): ?>
<p class="hlaska hlaska-chyba"><?= e(t('The Claude connection is switched off.')) ?> <a href="<?= e($app->url('admin.php?module=extensions')) ?>"><?= e(t('Features')) ?></a></p>
<?php endif ?>
<h2><?= e(t('How to connect')) ?></h2>
<p class="<?= $lastCall !== null ? 'hlaska hlaska-ok' : 'hlaska' ?>"><?= e($lastCall !== null ? t('Connected – Claude last worked on the site %s.', format_date($lastCall, true)) : t('Not connected yet – no Claude connection has called the site.')) ?></p>
<ol class="navod">
	<li><?= e(t('In the Claude app open Settings → Connectors → Add custom connector.')) ?></li>
	<li><?= e(t('Enter this address:')) ?><?= $app->view->render('admin/mcp_address', ['url' => $mcpUrl, 'id' => 'mcp-adresa-nastaveni']) ?></li>
	<li><?= e(t('Claude sends you here to sign in. You choose what it may do: drafts only, which you publish (recommended), also publish, or only read.')) ?></li>
</ol>
<p class="napoveda"><?= e(t('In Claude Code, just run:')) ?> <code>claude mcp add --transport http kaleta <?= e($mcpUrl) ?></code>. <?= e(t('Connected applications and personal tokens for other tools are in')) ?> <a href="<?= e($app->url('admin.php?action=account#claude')) ?>"><?= e(t('My account')) ?></a>.
<?= e(t('In a subfolder, the Claude app finds the sign-in on its own; for a tool that does not, use a personal token.')) ?></p>
<?php if (!empty($instructionsChange)): // 3.15 (N44): the last change, shown once to the administrator who opens the settings ?>
<div class="hlaska hlaska-varovani" id="zmena-pokynu"><p><?= e(t('The instructions for Claude changed – check them.')) ?> <?= e(Kaleta\Core\ClaudeInstructions::who($instructionsChange)) ?></p>
	<details><summary><?= e(t('Before the change')) ?></summary><p style="white-space:pre-wrap"><?= e($instructionsChange['old'] !== '' ? $instructionsChange['old'] : t('(empty)')) ?></p></details></div>
<?php endif ?>
<div class="radek">
	<label for="claude_instructions"><?= e(t('Instructions for Claude')) ?></label>
	<div><textarea class="textpole siroke" id="claude_instructions" name="claude_instructions" rows="6" maxlength="5000" placeholder="<?= e(t('e.g. We address customers informally. Say “renovation”, never “reconstruction”. Keep headings short. Always offer a free visit.')) ?>"><?= e($values['claude_instructions']) ?></textarea>
	<span class="napoveda"><?= e(t('Brand voice, words to use or avoid, house rules. Every Claude connection gets them when it connects, and they are its resource kaleta://instructions.')) ?></span></div>
</div>
<h3><?= e(t('Guardrails for Claude')) ?></h3>
<p class="napoveda"><?= e(t('They hold for every Claude connection on top of its access. Claude is told why when it hits one, so it can tell you.')) ?></p>
<div class="radek">
	<label for="claude_change_limit"><?= e(t('Changes per connection and hour')) ?></label>
	<div><input class="textpole" type="number" id="claude_change_limit" name="claude_change_limit" min="0" max="10000" value="<?= e($values['claude_change_limit']) ?>">
	<span class="napoveda"><?= e(t('0 = no limit. A long run of changes stops here until the next hour.')) ?></span></div>
</div>
<div class="radek">
	<span class="popisek"><?= e(t('Deleting')) ?></span>
	<div class="volby"><label><input type="hidden" name="claude_destructive" value="0"><input type="checkbox" name="claude_destructive" value="1"<?= $values['claude_destructive'] === '1' ? ' checked' : '' ?>> <?= e(t('Claude may delete, trash and discard drafts')) ?></label>
	<span class="napoveda"><?= e(t('Off: those tools are refused for every connection; you do them in the admin.')) ?></span></div>
</div>
<div class="radek">
	<span class="popisek"><?= e(t('Connecting apps')) ?></span>
	<div class="volby"><label><input type="hidden" name="claude_apps_only" value="0"><input type="checkbox" name="claude_apps_only" value="1"<?= $values['claude_apps_only'] === '1' ? ' checked' : '' ?>> <?= e(t('Only allow Claude’s own apps to connect')) ?></label>
	<span class="napoveda"><?= e(t('On: only Claude on the web, desktop and phone (claude.ai, claude.com) and Claude Code on your computer can register and sign in. Connections that already exist keep working. Off: other MCP applications can connect too, after a warning on the consent screen.')) ?></span></div>
</div>
<?php // 3.12 (UX310-19): a list of the site's pages to tick instead of page numbers; a protected page not in the list (in the trash) stays protected
$protectedIds = preg_match_all('/\d+/', $values['claude_protected_pages'], $m) ? array_map('intval', $m[0]) : [];
$listedIds = array_map(fn (array $page): int => (int) $page['ids'], $protectablePages); ?>
<div class="radek">
	<span class="popisek" id="claude_protected_pages"><?= e(t('Protected pages')) ?></span>
	<div><input type="hidden" name="claude_protected_pages[]" value="">
<?php foreach (array_diff($protectedIds, $listedIds) as $hiddenId): ?>
	<input type="hidden" name="claude_protected_pages[]" value="<?= (int) $hiddenId ?>">
<?php endforeach ?>
<?php if ($protectablePages === []): ?>
	<span class="napoveda"><?= e(t('The site has no pages yet.')) ?></span>
<?php else: ?>
	<div class="volby-seznam" role="group" aria-labelledby="claude_protected_pages">
<?php foreach ($protectablePages as $page): ?>
		<label><input type="checkbox" name="claude_protected_pages[]" value="<?= (int) $page['ids'] ?>"<?= in_array((int) $page['ids'], $protectedIds, true) ? ' checked' : '' ?>> <?= e((string) $page['titulek']) ?> <small>(/<?= e(($page['jazyk'] !== '' ? $page['jazyk'] . '/' : '') . $page['seo_link']) ?>)</small></label>
<?php endforeach ?>
	</div>
<?php endif ?>
	<span class="napoveda"><?= e(t('Pages Claude must not change – neither their settings nor their build.')) ?></span></div>
</div>

<h2><?= e(t('Connections')) ?></h2>
<?php if ($connections === []): ?>
<p><?= e(t('Nobody has connected Claude yet.')) ?></p>
<?php else: ?>
<div class="tab-obal"><table class="vypis">
<thead><tr><th scope="col"><?= e(t('Connection')) ?></th><th scope="col"><?= e(t('User')) ?></th><th scope="col"><?= e(t('Access')) ?></th><th scope="col"><?= e(t('Last used')) ?></th><th scope="col"><?= e(t('Expires')) ?></th></tr></thead>
<tbody>
<?php foreach ($connections as $c): ?>
<tr><td><?= e((string) $c['nazev']) ?><?= $c['klient'] === null ? ' <span class="stitek">' . e(t('personal token')) . '</span>' : '' ?></td><td><?= e((string) $c['user']) ?></td><td><?= e($accessNames[(string) $c['access']] ?? (string) $c['access']) ?></td>
	<td><?= $c['pouzit'] !== null ? e(format_date((string) $c['pouzit'], true)) : '–' ?></td><td><?= $c['expirace'] !== null ? e(format_date((string) $c['expirace'])) : e(t('never')) ?></td></tr>
<?php endforeach ?>
</tbody></table></div>
<?php endif ?>
<p class="napoveda"><?= e(t('Each person creates and revokes their own connections in')) ?> <a href="<?= e($app->url('admin.php?action=account#claude')) ?>"><?= e(t('My account')) ?></a>.</p>
