<?php
/**
 * Industry blueprints (2.11): the applied ones with their questions and failing checks, the shipped ones to apply,
 * an upload of a manifest and the export of the current site.
 *
 * @var Kaleta\Admin\Modules\Blueprints $module
 * @var string $csrf
 * @var array<string, array<string, mixed>> $applied
 * @var array<string, array<string, mixed>> $available
 * @var list<array{blueprint: string, fact: string, question: string, help: string, answer: string, type: string}> $questions
 * @var list<array{0: string, 1: string, 2: string}> $findings
 */
use Kaleta\Core\Blueprint;

?>
<p class="hlaska"><?= e(t('A blueprint sets the site up for a kind of business: the ready-made collections it needs, the facts to fill in, questions for the owner, checks in the site audit and instructions for Claude.')) ?></p>
<?php if ($applied !== []): ?>
<h2><?= e(t('Applied')) ?></h2>
<div class="tab-obal"><table class="vypis">
<thead><tr><th scope="col"><?= e(t('Name')) ?></th><th scope="col"><?= e(t('Description')) ?></th><th scope="col"><?= e(t('Actions')) ?></th></tr></thead>
<tbody>
<?php foreach ($applied as $key => $m): ?>
<tr><td><strong><?= e(Blueprint::text($m['name'])) ?></strong></td><td><?= e(Blueprint::text($m['description'])) ?></td>
	<td class="akce"><form method="post" action="<?= e($module->url('remove')) ?>"><?= $csrf ?><input type="hidden" name="key" value="<?= e($key) ?>"><button class="navigace" type="submit"><?= e(t('Remove')) ?></button></form></td></tr>
<?php endforeach ?>
</tbody></table></div>
<?php if ($questions !== []): ?>
<h2><?= e(t('Questions for the owner')) ?></h2>
<form class="formular" method="post" action="<?= e($module->url('answers')) ?>"><?= $csrf ?>
<?php foreach ($questions as $q): ?>
<div class="radek"><label for="q-<?= e($q['fact']) ?>"><?= e($q['question']) ?></label><div>
	<input class="textpole siroke" id="q-<?= e($q['fact']) ?>" name="answer[<?= e($q['fact']) ?>]" value="<?= e($q['answer']) ?>" maxlength="500">
	<span class="napoveda"><?= $q['help'] !== '' ? e($q['help']) . ' · ' : '' ?><code>{{fact.<?= e($q['fact']) ?>}}</code></span></div></div>
<?php endforeach ?>
<p><button class="tl" type="submit"><?= e(t('Save answers')) ?></button></p>
</form>
<?php endif ?>
<h2><?= e(t('Checks')) ?></h2>
<?php if ($findings === []): ?>
<p><?= e(t('Everything the blueprint checks is in place.')) ?></p>
<?php else: ?>
<div class="tab-obal"><table class="vypis">
<thead><tr><th scope="col"><?= e(t('Check')) ?></th><th scope="col"><?= e(t('Blueprint')) ?></th><th scope="col"><?= e(t('Actions')) ?></th></tr></thead>
<tbody>
<?php foreach ($findings as [$name, $message, $edit]): ?>
<tr><td><?= e($message) ?></td><td><?= e($name) ?></td><td class="akce"><a href="<?= e($app->url($edit)) ?>"><?= e(t('Fix')) ?></a></td></tr>
<?php endforeach ?>
</tbody></table></div>
<?php endif ?>
<?php endif ?>
<h2><?= e(t('Apply a blueprint')) ?></h2>
<?php if ($available === []): ?>
<p><?= e(t('No other blueprints are available. Upload a blueprint file below.')) ?></p>
<?php else: ?>
<?php
// 3.3: grouped by kind of business, in the order of Blueprint::GROUPS, with a search over the names and descriptions
$groups = [];
foreach ($available as $key => $m) {
    $groups[$m['group'] ?? 'other'][$key] = $m;
}
$groups = array_filter(array_replace(array_fill_keys(array_keys(Blueprint::GROUPS), []), $groups));
?>
<p><label class="navod-skryte" for="blueprint-search"><?= e(t('Search blueprints')) ?></label><input class="textpole" id="blueprint-search" type="search" placeholder="<?= e(t('Search blueprints')) ?>" data-filtr-karet="#blueprint-groups"></p>
<form id="blueprint-groups" method="post" action="<?= e($module->url('apply')) ?>"><?= $csrf ?>
<?php foreach ($groups as $group => $items): ?>
<section data-filtr-skupina>
<h3><?= e(t(Blueprint::GROUPS[$group] ?? Blueprint::GROUPS['other'])) ?></h3>
<div class="predvolby-kolekci-mrizka">
<?php foreach ($items as $key => $m): ?>
	<button type="submit" name="key" value="<?= e($key) ?>" data-filtr-karta data-potvrdit="<?= e(t('Apply the blueprint %s? It creates its collections with hidden list pages and adds its facts, questions and checks. You can take it off again.', Blueprint::text($m['name']))) ?>"><strong><?= e(Blueprint::text($m['name'])) ?></strong><span><?= e(Blueprint::text($m['description'])) ?></span></button>
<?php endforeach ?>
</div>
</section>
<?php endforeach ?>
<p class="napoveda" data-filtr-prazdne hidden><?= e(t('No blueprint matches the search.')) ?></p>
</form>
<?php endif ?>
<h3><?= e(t('None of them fits?')) ?></h3>
<p><?= e(t('Ask Claude to make a blueprint for your kind of business: it asks you what it needs to know, shows you the draft and applies it only when you agree. In the Claude app, the prompt is called draft_blueprint.')) ?></p>
<?php $claude = Kaleta\Core\ClaudeConnection::status($app->db(), $app->settings()); // 3.18: without a connection the request waits – said before it is saved ?>
<form class="formular" method="post" action="<?= e($app->url('admin.php?module=requests&action=save')) ?>"><?= $csrf ?>
<?= Kaleta\Core\ClaudeConnection::blocked($claude) ? $app->view->render('admin/claude_notice', ['app' => $app, 'claude' => $claude]) : '' ?>
<input type="hidden" name="quick" value="1">
<div class="radek"><label for="blueprint-request"><?= e(t('Request for Claude')) ?></label><div><textarea class="textpole siroke" id="blueprint-request" name="text" rows="3" required><?= e(t('Make a blueprint for our kind of business. We are … (what we do and for whom). Ask me what you need to know.')) ?></textarea></div></div>
<p><button class="navigace" type="submit"><?= e(Kaleta\Core\ClaudeConnection::reachable($claude) ? t('Send to Claude') : t('Save for later')) ?></button></p>
</form>
<h3><?= e(t('From a file')) ?></h3>
<form class="formular" method="post" action="<?= e($module->url('apply')) ?>" enctype="multipart/form-data"><?= $csrf ?>
<div class="radek"><label for="manifest"><?= e(t('Blueprint file (.json)')) ?></label><div><input id="manifest" name="manifest" type="file" accept=".json,application/json" required> <button class="navigace" type="submit"><?= e(t('Apply')) ?></button></div></div>
</form>
<h2><?= e(t('Export this site as a blueprint')) ?></h2>
<form class="formular" method="get" action="<?= e($app->url('admin.php')) ?>">
<input type="hidden" name="module" value="blueprints"><input type="hidden" name="action" value="export">
<div class="radek"><label for="b-key"><?= e(t('Key')) ?></label><div><input class="textpole" id="b-key" name="key" pattern="[a-z][a-z0-9_]{1,39}" required placeholder="dental_clinic"></div></div>
<div class="radek"><label for="b-name"><?= e(t('Name')) ?></label><div><input class="textpole" id="b-name" name="name" maxlength="100"></div></div>
<p class="napoveda"><?= e(t('The presets your collections come from, your facts without their values, and the questions, checks and instructions of the applied blueprints.')) ?></p>
<p><button class="navigace" type="submit"><?= e(t('Download')) ?></button></p>
</form>
