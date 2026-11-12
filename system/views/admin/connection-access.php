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
 * @var string|null $toolSet all | essentials | work | migration | fleet – the tool set chosen in advance (3.10; work 3.26; migration
 *      and fleet 3.36 – fleet offered only where its extension is on); null = no choice shown
 * @var list<string>|null $toolSets the sets offered on this site (3.36, Catalog::offered); null = those without an extension
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
<?php
// 3.26 (D-W10): an employee's own Claude – their work, enquiries and comments rather than building the site; 3.36: moving a
// site, and the fleet console where the fleet extension is on (Catalog::offered)
$finds = ($claude ?? true) ? 'Claude finds the other tools when it needs them.' : 'The application finds the other tools when it needs them.';
$sets = [
	'essentials' => ['Essentials – recommended, faster', 'The tools for pages, builds, the look, collections, media and requests.'],
	'work' => ['Work – for a colleague’s own Claude', 'My work, enquiries and replies, customers, deals, tasks, comments, job applications, requests and the notebook, with pages and collection items.'],
	'migration' => ['Migration – for moving a site', 'Importing the old site, reading its pages, rebuilding them in the builder, redirects, media and the migration report.'],
	'fleet' => ['Fleet – for the fleet console', 'The sites that report to this console – today, health, inventory, the calendar, the plan and the reports – with tasks, comments and the notebook.'],
	'all' => ['Everything at once – for Claude Code and scripts', 'Every tool at once – each conversation starts with the whole list.'],
];
$offered = $toolSets ?? Kaleta\Mcp\Catalog::offered(null, $toolSet);
?>
<?php foreach (array_intersect_key($sets, array_flip($offered)) as $key => [$label, $help]): ?>
	<label class="karta-volba"><input type="radio" name="tool_set" value="<?= e($key) ?>"<?= $toolSet === $key ? ' checked' : '' ?>><strong><?= e(t($label)) ?></strong><span><?= e(t($help)) ?><?= $key !== 'all' ? ' ' . e(t($finds)) : '' ?></span></label>
<?php endforeach ?>
</div>
</fieldset>
</details>
<?php endif ?>
