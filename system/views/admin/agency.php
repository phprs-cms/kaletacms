<?php
/**
 * Who looks after the site (2.4, Settings → Agency → Built and looked after by): on the sign-in screen and at the foot
 * of the admin, so the client knows whom to ask.
 *
 * @var Kaleta\Core\App $app
 * @var bool $withLogo
 */
$s = $app->settings();
$name = $s->get('agency_name');
if ($name === '') {
    return;
}
$logo = $s->get('agency_logo');
$contacts = array_filter([
    $s->get('agency_email') !== '' ? '<a href="mailto:' . e($s->get('agency_email')) . '">' . e($s->get('agency_email')) . '</a>' : '',
    $s->get('agency_phone') !== '' ? '<a href="tel:' . e(preg_replace('/[^+\d]/', '', $s->get('agency_phone'))) . '">' . e($s->get('agency_phone')) . '</a>' : '',
]);
$nameHtml = $s->get('agency_url') !== '' ? '<a href="' . e($s->get('agency_url')) . '" target="_blank" rel="noopener">' . e($name) . '</a>' : e($name);
?>
<p class="agentura">
<?php if ($withLogo && $logo !== '' && is_file(KALETA_ROOT . '/' . $logo)): ?>
	<img src="<?= e($app->url($logo)) ?>" alt="" height="28">
<?php endif ?>
	<span><?= t('Website by %s', $nameHtml) ?><?= $contacts !== [] ? ' · ' . t('help: %s', implode(', ', $contacts)) : '' ?></span>
</p>
