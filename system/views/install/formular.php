<?php
/**
 * @var string $base
 * @var list<array{nazev:string, ok:bool, info:string}> $pozadavky
 * @var array<string, string> $data
 * @var array<string, string> $chyby
 * @var string $jazyk  jazyk instalace (cs, en)
 * @var array<string, string> $jazyky
 */
$chyba = fn (string $pole): string => isset($chyby[$pole]) ? '<span class="chyba-pole" role="alert">' . e($chyby[$pole]) . '</span>' : '';
$splneno = !in_array(false, array_column($pozadavky, 'ok'), true);
?>
<!doctype html>
<html lang="<?= e($jazyk) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e(t('Instalace MiroCMS')) ?></title>
<link rel="icon" type="image/svg+xml" href="<?= e($base) ?>/image/mirocms-znacka.svg">
<link rel="alternate icon" type="image/png" sizes="32x32" href="<?= e($base) ?>/image/mirocms-znacka-32.png">
<link rel="apple-touch-icon" href="<?= e($base) ?>/image/mirocms-znacka-180.png">
<link rel="stylesheet" href="<?= e($base) ?>/image/install.css?v=<?= e(MIROCMS_VERSION) ?>">
</head>
<body>
<main class="instalator">
<nav class="jazyky" aria-label="Language">
<?php foreach ($jazyky as $kod => $nazevJazyka): ?>
	<a href="?jazyk=<?= e($kod) ?>"<?= $kod === $jazyk ? ' class="aktivni" aria-current="true"' : '' ?> lang="<?= e($kod) ?>"><?= e($nazevJazyka) ?></a>
<?php endforeach ?>
</nav>
<header class="uvod">
	<div class="znacka"><?php $vyska = 40; $jenZnacka = false; require MIROCMS_SYSTEM . '/views/admin/logo.php'; ?></div>
	<h1><?= e(t('Instalace MiroCMS')) ?></h1>
	<p><?= e(t('Tři krátké kroky a váš web běží. Vše lze později změnit v administraci.')) ?></p>
</header>

<section class="krok">
	<h2><span>1</span> <?= e(t('Kontrola serveru')) ?></h2>
	<ul class="kontrola">
<?php foreach ($pozadavky as $p): ?>
		<li<?= $p['ok'] ? '' : ' class="spatne"' ?>><div><?= e(t($p['nazev'])) ?> <small>– <?= e($p['info']) ?></small></div></li>
<?php endforeach ?>
	</ul>
</section>

<?php if (!$splneno): ?>
<p class="hlaska hlaska-chyba" role="alert"><?= e(t('Server nesplňuje požadavky. Opravte položky označené křížkem a obnovte stránku.')) ?></p>
<?php else: ?>
<?php if ($chyby !== []): ?>
<p class="hlaska hlaska-chyba" role="alert"><?= e(t('Instalaci se nepodařilo dokončit – zkontrolujte zvýrazněná pole.')) ?></p>
<?php endif ?>
<form method="post" autocomplete="off">
<input type="hidden" name="jazyk" value="<?= e($jazyk) ?>">
<section class="krok">
	<h2><span>2</span> <?= e(t('Databáze')) ?></h2>
	<p><?= e(t('MySQL nebo MariaDB. Prázdnou databázi založte předem – na hostingu v jeho administraci.')) ?></p>
	<div class="pole">
		<div class="cele s-portem">
			<div><label for="db_host"><?= e(t('Server')) ?></label><input type="text" id="db_host" name="db_host" value="<?= e($data['db_host']) ?>"></div>
			<div><label for="db_port"><?= e(t('Port')) ?></label><input type="number" id="db_port" name="db_port" value="<?= e($data['db_port']) ?>"></div>
		</div>
		<div><label for="db_name"><?= e(t('Název databáze')) ?></label><input type="text" id="db_name" name="db_name" value="<?= e($data['db_name']) ?>" required><?= $chyba('db_name') ?></div>
		<div><label for="db_prefix"><?= e(t('Předpona tabulek')) ?></label><input type="text" id="db_prefix" name="db_prefix" value="<?= e($data['db_prefix']) ?>" required><?= $chyba('db_prefix') ?></div>
		<div><label for="db_user"><?= e(t('Uživatel')) ?></label><input type="text" id="db_user" name="db_user" value="<?= e($data['db_user']) ?>" required></div>
		<div><label for="db_password"><?= e(t('Heslo')) ?></label><input type="password" id="db_password" name="db_password" autocomplete="off"></div>
	</div>
</section>

<section class="krok">
	<h2><span>3</span> <?= e(t('Web a administrátor')) ?></h2>
	<p><?= e(t('Účet, kterým se poprvé přihlásíte do administrace.')) ?></p>
	<div class="pole">
		<div class="cele"><label for="nazev_webu"><?= e(t('Název webu')) ?></label><input type="text" id="nazev_webu" name="nazev_webu" value="<?= e($data['nazev_webu']) ?>" required></div>
		<div><label for="user"><?= e(t('Přihlašovací jméno')) ?></label><input type="text" id="user" name="user" value="<?= e($data['user']) ?>" required><?= $chyba('user') ?></div>
		<div><label for="jmeno"><?= e(t('Jméno a příjmení')) ?></label><input type="text" id="jmeno" name="jmeno" value="<?= e($data['jmeno']) ?>"><span class="napoveda"><?= e(t('Zobrazuje se u novinek.')) ?></span></div>
		<div class="cele"><label for="email"><?= e(t('E-mail')) ?></label><input type="email" id="email" name="email" value="<?= e($data['email']) ?>"><?= $chyba('email') ?></div>
		<div><label for="password"><?= e(t('Heslo')) ?></label><input type="password" id="password" name="password" autocomplete="new-password" minlength="10" required><?= $chyba('password') ?><span class="napoveda"><?= e(t('Alespoň 10 znaků.')) ?></span></div>
		<div><label for="password2"><?= e(t('Heslo znovu')) ?></label><input type="password" id="password2" name="password2" autocomplete="new-password" required></div>
		<div class="cele"><label for="casove_pasmo"><?= e(t('Časové pásmo')) ?></label><select id="casove_pasmo" name="casove_pasmo">
<?php foreach (DateTimeZone::listIdentifiers() as $pasmo): ?>
			<option value="<?= e($pasmo) ?>"<?= $data['casove_pasmo'] === $pasmo ? ' selected' : '' ?>><?= e(str_replace('_', ' ', $pasmo)) ?></option>
<?php endforeach ?>
		</select><span class="napoveda"><?= e(t('Podle něj se vydávají naplánované novinky a zobrazují data.')) ?></span></div>
	</div>
</section>

<div class="akce">
	<button class="tlacitko" type="submit"><?= e(t('Nainstalovat MiroCMS')) ?></button>
	<small><?= e(t('Vytvoří tabulky v databázi a soubor config.php.')) ?></small>
</div>
</form>
<?php endif ?>
</main>
</body>
</html>
