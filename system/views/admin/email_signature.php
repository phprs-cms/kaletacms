<?php
/**
 * E-mail signature of a person (2.10; shared by Collections and My account since 3.14): the preview as the mail client will
 * show it, a button that copies the rich HTML together with the plain text (image/admin.js, data-kopirovat-podpis), the
 * plain-text version and where to paste it in Gmail, Outlook and Apple Mail.
 *
 * @var array{html: string, text: string} $signature
 * @var string $intro the sentence above the preview
 * @var array{0: string, 1: string}|null $back [url, label] of a link next to the Copy button, or null
 */
?>
<p class="napoveda"><?= e($intro) ?></p>
<div class="podpis-nahled" data-podpis-nahled><?= $signature['html'] ?></div>
<p class="tlacitka"><button type="button" class="tl" data-kopirovat-podpis><?= e(t('Copy signature')) ?></button>
<?php if (($back ?? null) !== null): ?>
	<a class="navigace" href="<?= e($back[0]) ?>"><?= e($back[1]) ?></a>
<?php endif ?></p>
<details class="pokrocile">
<summary><?= e(t('Plain-text version')) ?></summary>
<textarea class="textbox nizky" rows="6" readonly data-podpis-text><?= e($signature['text']) ?></textarea>
<p class="napoveda"><?= e(t('For mail clients and places that take no formatting – the Copy button puts it on the clipboard together with the formatted signature.')) ?></p>
</details>
<h2><?= e(t('Where to paste it')) ?></h2>
<ul class="podpis-navod">
	<li><strong>Gmail:</strong> <?= e(t('Settings (the gear) → See all settings → General → Signature → Create new, paste the signature into the box (Ctrl+V, on a Mac ⌘V) and click Save Changes at the bottom.')) ?></li>
	<li><strong>Outlook:</strong> <?= e(t('New message → Signature → Signatures… (Outlook on the web: Settings → Mail → Compose and reply), paste the signature and save.')) ?></li>
	<li><strong>Apple Mail:</strong> <?= e(t('Mail → Settings → Signatures, choose the account, click +, paste the signature and untick “Always match my default message font”.')) ?></li>
</ul>
<p class="napoveda"><?= e(t('The photo and the logo load from your website, so they show as long as they stay there.')) ?></p>
