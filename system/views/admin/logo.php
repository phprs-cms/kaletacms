<?php
/**
 * Logo MiroCMS pro administraci, přihlášení a instalátor (dočasná podoba – finální logo se teprve navrhne).
 * Značka je vždy modrá; barvu nápisu řídí CSS proměnné --logo-nazev a --logo-cms (světlý / tmavý režim), výchozí platí pro světlý podklad.
 *
 * @var int $vyska     výška v px (výchozí 28)
 * @var bool $jenZnacka  jen čtvercová značka bez nápisu
 */
$vyska = (int) ($vyska ?? 28);
$jenZnacka = !empty($jenZnacka);
?>
<svg class="mirocms-logo" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 <?= $jenZnacka ? '64' : '230' ?> 64" width="<?= round($vyska * ($jenZnacka ? 1 : 230 / 64)) ?>" height="<?= $vyska ?>" fill="none" role="img" aria-label="MiroCMS">
<rect width="64" height="64" rx="14" fill="#2b5be3"/><polygon fill="#ffffff" points="14,46 14,18 22,18 32,32 42,18 50,18 50,46 43,46 43,29 32,44 21,29 21,46"/>
<?php if (!$jenZnacka): ?>
<text x="76" y="44" font-family="system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif" font-size="34" font-weight="700" letter-spacing="-0.5"><tspan fill="var(--logo-nazev, #0f1424)">Miro</tspan><tspan fill="var(--logo-cms, #2b5be3)">CMS</tspan></text>
<?php endif ?>
</svg>
