<?php
/**
 * Newsletter e-mail (Core\Mailing::render): tables and inline styles, because mail clients (Outlook, Gmail) ignore most
 * of CSS. Colours, fonts and the corner radius come from the site's design system. Not overridable – one template for all.
 *
 * @var string $language
 * @var string $subject
 * @var string $preheader
 * @var list<string> $paragraphs intro paragraphs (plain text)
 * @var list<array{id: int, title: string, url: string, intro: string, image: string, date: string}> $items
 * @var array{0: string, 1: string}|null $button label and absolute URL
 * @var string $siteName
 * @var string $siteUrl
 * @var string $logo absolute URL of a raster logo, or empty
 * @var list<string> $company footer lines
 * @var array<string, string> $colors design system colours (primarni, sekundarni, text, pozadi, plocha, tlumeny, na-primarni)
 * @var string $headingFont
 * @var string $textFont
 * @var string $radius
 * @var string $unsubscribe placeholder of the subscriber's unsubscribe link
 */
$c = array_map(fn (string $v): string => preg_match('/^#[0-9a-f]{3,8}$/iD', $v) ? $v : '#000000', $colors);
$font = e($textFont);
$headFont = e($headingFont);
$link = static fn (string $text): string => preg_replace_callback('~https?://[^\s<>"]+~', fn (array $m): string => '<a href="' . $m[0] . '" style="color:' . $c['primarni'] . ';">' . $m[0] . '</a>', $text) ?? $text;
?>
<!DOCTYPE html>
<html lang="<?= e(Kaleta\Core\Language::tag($language)) ?>" dir="ltr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<title><?= e($subject) ?></title>
<style>
	body { margin: 0; padding: 0; }
	img { border: 0; line-height: 100%; outline: none; text-decoration: none; }
	a { color: <?= $c['primarni'] ?>; }
	@media (max-width: 620px) { .ka-obal { width: 100% !important; } .ka-odsazeni { padding-left: 20px !important; padding-right: 20px !important; } }
</style>
</head>
<body style="margin:0;padding:0;background:<?= $c['plocha'] ?>;">
<?php if ($preheader !== ''): ?>
<div style="display:none;max-height:0;overflow:hidden;mso-hide:all;font-size:1px;line-height:1px;color:<?= $c['plocha'] ?>;"><?= e($preheader) ?>&#8199;&#65279;&#847;&#8199;&#65279;&#847;&#8199;&#65279;&#847;&#8199;&#65279;&#847;</div>
<?php endif ?>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:<?= $c['plocha'] ?>;">
<tr><td align="center" style="padding:24px 12px;">
<table role="presentation" class="ka-obal" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;max-width:600px;background:<?= $c['pozadi'] ?>;border-radius:<?= e($radius) ?>;">
<tr><td class="ka-odsazeni" style="padding:28px 40px 8px 40px;font-family:<?= $headFont ?>;">
<?php if ($logo !== ''): ?>
	<a href="<?= e($siteUrl) ?>"><img src="<?= e($logo) ?>" alt="<?= e($siteName) ?>" height="40" style="display:block;height:40px;width:auto;"></a>
<?php else: ?>
	<a href="<?= e($siteUrl) ?>" style="font-family:<?= $headFont ?>;font-size:20px;font-weight:700;color:<?= $c['text'] ?>;text-decoration:none;"><?= e($siteName) ?></a>
<?php endif ?>
</td></tr>
<tr><td class="ka-odsazeni" style="padding:16px 40px 0 40px;">
	<h1 style="margin:0 0 16px 0;font-family:<?= $headFont ?>;font-size:26px;line-height:1.25;font-weight:700;color:<?= $c['text'] ?>;"><?= e($subject) ?></h1>
<?php foreach ($paragraphs as $p): ?>
	<p style="margin:0 0 16px 0;font-family:<?= $font ?>;font-size:16px;line-height:1.6;color:<?= $c['text'] ?>;"><?= nl2br($link(e($p)), false) ?></p>
<?php endforeach ?>
</td></tr>
<?php foreach ($items as $item): ?>
<tr><td class="ka-odsazeni" style="padding:16px 40px 8px 40px;">
<?php if ($item['image'] !== ''): ?>
	<a href="<?= e($item['url']) ?>"><img src="<?= e($item['image']) ?>" alt="" width="520" style="display:block;width:100%;max-width:520px;height:auto;border-radius:<?= e($radius) ?>;margin:0 0 12px 0;"></a>
<?php endif ?>
	<h2 style="margin:0 0 8px 0;font-family:<?= $headFont ?>;font-size:20px;line-height:1.3;font-weight:700;"><a href="<?= e($item['url']) ?>" style="color:<?= $c['text'] ?>;text-decoration:none;"><?= e($item['title']) ?></a></h2>
<?php if ($item['intro'] !== ''): ?>
	<p style="margin:0 0 8px 0;font-family:<?= $font ?>;font-size:15px;line-height:1.6;color:<?= $c['text'] ?>;"><?= e($item['intro']) ?></p>
<?php endif ?>
	<p style="margin:0 0 8px 0;font-family:<?= $font ?>;font-size:15px;line-height:1.6;"><a href="<?= e($item['url']) ?>" style="color:<?= $c['primarni'] ?>;font-weight:600;"><?= e(t('Read more')) ?></a></p>
</td></tr>
<?php endforeach ?>
<?php if ($button !== null): ?>
<tr><td class="ka-odsazeni" align="left" style="padding:16px 40px 8px 40px;">
	<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr><td style="background:<?= $c['primarni'] ?>;border-radius:<?= e($radius) ?>;">
		<a href="<?= e($button[1]) ?>" style="display:inline-block;padding:12px 24px;font-family:<?= $font ?>;font-size:16px;font-weight:600;line-height:1.2;color:<?= $c['na-primarni'] ?>;text-decoration:none;border-radius:<?= e($radius) ?>;"><?= e($button[0]) ?></a>
	</td></tr></table>
</td></tr>
<?php endif ?>
<tr><td class="ka-odsazeni" style="padding:24px 40px 32px 40px;"></td></tr>
</table>
<table role="presentation" class="ka-obal" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;max-width:600px;">
<tr><td class="ka-odsazeni" style="padding:20px 40px;font-family:<?= $font ?>;font-size:13px;line-height:1.6;color:<?= $c['tlumeny'] ?>;">
<?php foreach ($company as $line): ?>
	<?= e($line) ?><br>
<?php endforeach ?>
	<?= e(t('You receive this e-mail because you subscribed to news from %s.', $siteName)) ?><br>
	<a href="<?= $unsubscribe ?>" style="color:<?= $c['tlumeny'] ?>;"><?= e(t('Unsubscribe')) ?></a>
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
