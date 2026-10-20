<?php
/**
 * The weekly digest as it would go out now (3.17, Fleet\Digest): the subject and the plain text, the recipients.
 *
 * @var Kaleta\Admin\Modules\Fleet $module
 * @var string $subject
 * @var string $text
 * @var list<string> $recipients
 * @var bool $on
 */
?>
<p><a href="<?= e($module->url('alerts')) ?>">← <?= e(t('Alerts and digest')) ?></a></p>
<p class="hlaska<?= $on ? ' hlaska-ok' : '' ?>"><?= e($on ? t('The digest goes out every Monday morning to: %s.', $recipients !== [] ? implode(', ', $recipients) : t('nobody – fill in the recipients')) : t('The digest is off. This is what it would say about the last 7 days.')) ?></p>
<div class="tab-obal"><table class="vypis"><tbody><tr><th scope="row"><?= e(t('Subject')) ?></th><td><?= e($subject) ?></td></tr></tbody></table></div>
<pre class="kod"><?= e($text) ?></pre>
