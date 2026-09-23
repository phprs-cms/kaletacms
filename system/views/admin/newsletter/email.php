<?php
/**
 * HTML podoba newsletteru - tabulkové rozvržení a vložené styly kvůli poštovním programům.
 * Logo a hlavní barva se berou z Identity webu.
 *
 * @var MiroCMS\Core\Settings $web
 * @var array<string, mixed> $vydani
 * @var list<array<string, mixed>> $clanky  články; "adresa" je hotový odkaz (přes počítadlo prokliků)
 * @var string $koren     adresa webu s lomítkem na konci
 * @var string $odhlasit  adresa pro odhlášení
 * @var string $pixel     obrázek 1×1 pro počítání otevření
 */
$abs = fn (string $u): string => preg_match('#^https?://#i', $u) ? $u : rtrim($koren, '/') . '/' . ltrim($u, '/');
$barva = preg_match('/^#[0-9a-f]{6}$/i', $web->get('brand_akcent')) ? $web->get('brand_akcent') : '#1f4fe0';
$logo = $web->get('logo_webu');
?>
<!doctype html>
<html lang="<?= e(MiroCMS\Core\Jazyk::vychozi($web)) ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="color-scheme" content="light"><title><?= e($vydani['predmet']) ?></title></head>
<body style="margin:0;padding:0;background:#f2f4f7;font-family:Georgia,'Times New Roman',serif;color:#14171f">
<div style="display:none;max-height:0;overflow:hidden"><?= e(mb_strimwidth(trim((string) $vydani['uvod']) !== '' ? (string) $vydani['uvod'] : implode(' · ', array_column($clanky, 'titulek')), 0, 140, '…')) ?></div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f2f4f7"><tr><td align="center" style="padding:24px 12px">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff">
	<tr><td style="padding:26px 32px 14px;border-bottom:4px solid <?= e($barva) ?>"><a href="<?= e($koren) ?>" style="font-size:28px;font-weight:bold;color:#14171f;text-decoration:none"><?php if ($logo !== ''): ?><img src="<?= e($abs($logo)) ?>" alt="<?= e($web->get('nazev_webu')) ?>" height="44" style="display:block;height:44px;width:auto;border:0"><?php else: ?><?= e($web->get('nazev_webu')) ?><?php endif ?></a></td></tr>
<?php if (trim((string) $vydani['uvod']) !== ''): ?>
	<tr><td style="padding:24px 32px 0;font-size:17px;line-height:1.55"><?= nl2br(e($vydani['uvod'])) ?></td></tr>
<?php endif ?>
<?php foreach ($clanky as $i => $c): ?>
	<tr><td style="padding:24px 32px 0">
<?php if ($c['obrazek'] !== '' && $i < 3): ?>
		<a href="<?= e($c['adresa']) ?>"><img src="<?= e($abs($c['obrazek'])) ?>" alt="" width="536" style="display:block;width:100%;max-width:536px;height:auto;border:0;margin-bottom:12px"></a>
<?php endif ?>
		<a href="<?= e($c['adresa']) ?>" style="font-size:<?= $i === 0 ? 26 : 20 ?>px;line-height:1.25;font-weight:bold;color:#14171f;text-decoration:none"><?= e($c['titulek']) ?></a>
		<p style="margin:8px 0 0;font-size:16px;line-height:1.5;color:#3a3d45"><?= e(mb_strimwidth(trim(strip_tags($c['uvod'])), 0, 260, '…')) ?></p>
		<p style="margin:10px 0 0;font-family:Arial,sans-serif;font-size:14px"><a href="<?= e($c['adresa']) ?>" style="color:<?= e($barva) ?>;font-weight:bold"><?= e(t('Číst článek →')) ?></a></p>
	</td></tr>
<?php endforeach ?>
	<tr><td style="padding:28px 32px 0"><a href="<?= e($koren) ?>" style="display:inline-block;padding:12px 22px;background:<?= e($barva) ?>;color:#ffffff;font-family:Arial,sans-serif;font-size:15px;font-weight:bold;text-decoration:none;border-radius:4px"><?= e(t('Další články na webu')) ?></a></td></tr>
	<tr><td style="padding:28px 32px 32px;font-family:Arial,sans-serif;font-size:12px;line-height:1.5;color:#667085"><?= e(t('Tento e-mail dostáváte, protože jste se přihlásili k odběru novinek z webu %s.', $web->get('nazev_webu'))) ?><br><a href="<?= e($odhlasit) ?>" style="color:#667085"><?= e(t('Odhlásit odběr')) ?></a><img src="<?= e($pixel) ?>" alt="" width="1" height="1" style="display:block;border:0"></td></tr>
</table>
</td></tr></table>
</body></html>
