<?php
/**
 * Link to the guide article about the open part of the administration (Admin\Guide, 2.4).
 *
 * @var string $url
 * @var bool $inApp  3.45: a chapter of the guide in the administration (Admin\Guide::CHAPTERS) – same tab
 */
?>
<a class="navod-odkaz" href="<?= e($url) ?>"<?= empty($inApp) ? ' target="_blank" rel="noopener"' : '' ?>><svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H20v15H6.5A2.5 2.5 0 0 0 4 20.5zM4 20.5A2.5 2.5 0 0 0 6.5 23H20v-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/></svg><?= e(t('How to use this')) ?><?php if (empty($inApp)): ?><span class="navod-skryte"> – <?= e(t('guide, opens in a new tab')) ?></span><?php endif ?></a>
