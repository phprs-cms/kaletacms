<?php
/**
 * E-mail signature of a person (2.10): the preview as the mail client will show it, a button that copies the rich HTML
 * together with the plain text (image/admin.js, data-kopirovat-podpis), the plain-text version and where to paste it
 * in Gmail, Outlook and Apple Mail.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Collections $module
 * @var array<string, mixed> $k
 * @var array<string, mixed> $p
 * @var array{html: string, text: string} $signature
 */
?>
<p class="napoveda"><?= e(t('Made from the record of %s in the look of your site. When the record or the site changes, open this page again and copy the signature anew.', $p['nazev'])) ?></p>
<div class="podpis-nahled" data-podpis-nahled><?= $signature['html'] ?></div>
<p class="tlacitka"><button type="button" class="tl" data-kopirovat-podpis><?= e(t('Copy signature')) ?></button>
	<a class="navigace" href="<?= e($module->url('item', ['id' => (int) $k['idk'], 'item' => (int) $p['idp']])) ?>"><?= e(t('Back to the item')) ?></a></p>
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
