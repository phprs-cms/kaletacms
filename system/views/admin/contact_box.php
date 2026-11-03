<?php
/**
 * The customer of an enquiry or a booking (3.28, Admin\Modules\Contacts::box): the linked contact with its company, the
 * responsible person and what else they have, "Open customer"; a row from before 3.28 that the job has not linked yet gets
 * "Link to a contact". Only for people who may open Customers.
 *
 * @var string $csrf
 * @var array{kind: string, id: int, contact: ?array<string, mixed>, canLink: bool, counts: ?array{enquiries: int, bookings: int, subscribed: bool}, owner: string, url: string, linkUrl: string} $box
 */
use Kaleta\Core\Contacts;

$c = $box['contact'];
?>
<section class="zakaznik-box" id="contact" data-contact-box="<?= $c !== null ? (int) $c['id'] : 0 ?>">
<h2><?= e(t('Customer')) ?></h2>
<?php if ($c !== null): ?>
<p class="zakaznik-box-jmeno"><a href="<?= e($box['url']) ?>"><strong><?= bdi(Contacts::displayName($c)) ?></strong></a><?= ($c['company_name'] ?? null) !== null ? ' · ' . bdi((string) $c['company_name']) : '' ?></p>
<p class="napoveda"><?= e(t('Responsible person: %s', $box['owner'] !== '' ? $box['owner'] : t('nobody'))) ?><?php if ($box['counts'] !== null): ?> · <?= e(t('Enquiries: %d', $box['counts']['enquiries'])) ?> · <?= e(t('Bookings: %d', $box['counts']['bookings'])) ?><?= $box['counts']['subscribed'] ? ' · ' . e(t('Subscribed to the newsletter')) : '' ?><?php endif ?></p>
<p><a class="navigace" href="<?= e($box['url']) ?>" data-contact-open><?= e(t('Open customer')) ?></a></p>
<?php elseif ($box['canLink']): ?>
<p class="napoveda"><?= e(t('Not linked to a customer yet – Kaleta links older records a few hundred at a time.')) ?></p>
<form class="vradku" method="post" action="<?= e($box['linkUrl']) ?>" data-contact-link>
	<?= $csrf ?><input type="hidden" name="kind" value="<?= e($box['kind']) ?>"><input type="hidden" name="id" value="<?= (int) $box['id'] ?>">
	<button class="navigace" type="submit"><?= e(t('Link to a contact')) ?></button></form>
<?php else: ?>
<p class="napoveda" data-contact-none><?= e(t('No customer – there is no valid e-mail address to link it by.')) ?></p>
<?php endif ?>
</section>
