<?php
/**
 * What a Claude connection may do (2.2): the choice on the OAuth consent screen and when creating a personal token.
 * Never more than the user's role allows. On the consent screen of an application that does not return to Claude's own
 * apps the texts speak of "the application", never of Claude (3.3.4, N65). 3.11 (UX310-2): the levels have one name
 * everywhere (consent screen, My account, Claude settings – Kaleta\Front\OAuth::ACCESS_LABELS), "Drafts only" comes first
 * and the tool set is an advanced choice, folded away (UX310-9).
 *
 * @var string $role the user's role, translated
 * @var string $selected full | drafts | read
 * @var bool|null $claude false = the application is not one of Claude's own apps (default: it is)
 * @var string|null $toolSet all | essentials – the tool set chosen in advance (3.10); null = no choice shown
 * @var string|null $recommended the level marked as recommended (the consent screen: drafts)
 */
$labels = Kaleta\Front\OAuth::ACCESS_LABELS;
$options = ($claude ?? true) ? [
    'drafts' => [$labels['drafts'], 'Claude builds pages, writes news drafts, adds hidden collection items, proposes exceptions to the opening hours, sorts enquiries and prepares look changes – you review and publish them. Nothing on the live site changes without you.'],
    'full' => [$labels['full'], 'Everything your role (%s) allows, publishing included – Claude publishes when you ask it to. The menu and the look still wait in the draft look until they are published.'],
    'read' => [$labels['read'], 'Claude reads pages, settings, the audit and the change log, and suggests changes. It changes nothing.'],
] : [
    'drafts' => [$labels['drafts'], 'The application builds pages, writes news drafts, adds hidden collection items, proposes exceptions to the opening hours, sorts enquiries and prepares look changes – you review and publish them. Nothing on the live site changes without you.'],
    'full' => [$labels['full'], 'Everything your role (%s) allows, publishing included. The menu and the look still wait in the draft look until they are published.'],
    'read' => [$labels['read'], 'The application reads pages, settings, the audit and the change log. It changes nothing.'],
];
?>
<fieldset class="pristup-napojeni">
<legend><?= e(($claude ?? true) ? t('What may Claude do?') : t('What may the application do?')) ?></legend>
<div class="karty-volby">
<?php foreach ($options as $key => [$label, $help]): ?>
	<label class="karta-volba"><input type="radio" name="access" value="<?= e($key) ?>"<?= $selected === $key ? ' checked' : '' ?>><strong><?= e(t($label)) ?><?= ($recommended ?? null) === $key ? ' <span class="stitek stitek-vydano">' . e(t('recommended')) . '</span>' : '' ?></strong><span><?= e(t($help, $role)) ?></span></label>
<?php endforeach ?>
</div>
</fieldset>
<?php if (isset($toolSet)): ?>
<details class="pokrocile"<?= $toolSet === 'all' ? ' open' : '' ?>>
<summary><?= e(t('Advanced (for Claude Code)')) ?></summary>
<fieldset class="pristup-napojeni">
<legend><?= e(($claude ?? true) ? t('How much should Claude load at the start?') : t('How much should the application load at the start?')) ?></legend>
<div class="karty-volby">
<?php foreach (($claude ?? true) ? ['essentials' => ['Essentials – recommended, faster', 'The tools for pages, builds, the look, collections, media and requests. Claude finds the other tools when it needs them.'],
	'all' => ['Everything at once – for Claude Code and scripts', 'Every tool at once – each conversation starts with the whole list.']]
	: ['essentials' => ['Essentials – recommended, faster', 'The tools for pages, builds, the look, collections, media and requests. The application finds the other tools when it needs them.'],
	'all' => ['Everything at once – for Claude Code and scripts', 'Every tool at once – each conversation starts with the whole list.']] as $key => [$label, $help]): ?>
	<label class="karta-volba"><input type="radio" name="tool_set" value="<?= e($key) ?>"<?= $toolSet === $key ? ' checked' : '' ?>><strong><?= e(t($label)) ?></strong><span><?= e(t($help)) ?></span></label>
<?php endforeach ?>
</div>
</fieldset>
</details>
<?php endif ?>
