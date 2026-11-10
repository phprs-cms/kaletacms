<?php
/**
 * The deal of an enquiry (3.31, Admin\Modules\Pipeline::enquiryBox): its stage and "Open the deal" when it has one, and
 * "Create deal" unless an open one exists – 3.32: a link to the short New deal form prefilled from the enquiry (Core\Deals::enquiryDefaults:
 * the title from who and what, the customer, the person handling it), saved through Core\Deals::createFromEnquiry. Only for
 * people who may open the Pipeline.
 *
 * @var string $csrf
 * @var array{idp: int, deal: ?array{id: int, stage: string, closed: bool}, label: string, kind: string, url: string, createUrl: string, formUrl: string, canCreate: bool} $box
 */
$deal = $box['deal'];
?>
<section class="zakaznik-box obchod-box" id="deal" data-deal-box="<?= $deal !== null ? (int) $deal['id'] : 0 ?>">
<h2><?= e(t('Deal')) ?></h2>
<?php if ($deal !== null): ?>
<p><a href="<?= e($box['url']) ?>" data-deal-open><strong><?= e(t('Deal #%s', (string) $deal['id'])) ?></strong></a> <span class="stitek<?= $box['kind'] === 'won' ? ' stitek-vydano' : ($box['kind'] === 'lost' ? ' stitek-chyba' : '') ?>"><?= e($box['label']) ?></span></p>
<?php if (!$deal['closed']): ?>
<p><a class="navigace" href="<?= e($box['url']) ?>"><?= e(t('Open the deal')) ?></a></p>
<?php endif ?>
<?php else: ?>
<p class="napoveda"><?= e(t('Turn the enquiry into a deal to follow it to won or lost – the customer and the responsible person come along.')) ?></p>
<?php endif ?>
<?php if ($box['canCreate']): /* 3.32 (U25): the short form prefilled from the enquiry – the person checks the title and adds the value */ ?>
<p><a class="navigace" href="<?= e($box['formUrl']) ?>" data-deal-create><?= e(t($deal !== null ? 'Create a new deal' : 'Create deal')) ?></a></p>
<?php endif ?>
</section>
