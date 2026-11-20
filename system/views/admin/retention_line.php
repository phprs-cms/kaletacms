<?php
/**
 * 3.48 B (U40): "Retention: 24 months, then deleted – change" beside a section's settings. The periods are set in Settings →
 * Privacy and cookies (Core\Retention); the link leads to the field there, for administrators (Settings is theirs).
 *
 * @var Kaleta\Core\App $app
 * @var string $key a key of Core\Retention::PERIODS
 */
use Kaleta\Core\Retention;

$row = Retention::PERIODS[$key];
$setting = $row['setting'] !== '' ? $row['setting'] : Retention::PERIODS[$row['follows'] ?? 'enquiries']['setting'];
?>
<span data-retention="<?= e($key) ?>"><?= e(t('Retention: %s', Retention::summary($app->settings(), $key))) ?><?php if ($app->auth()->isAdmin()): ?> – <a href="<?= e($app->url('admin.php?' . Kaleta\Admin\Modules\Settings::fieldUrl($setting))) ?>"><?= e(t('change')) ?></a><?php endif ?></span>
