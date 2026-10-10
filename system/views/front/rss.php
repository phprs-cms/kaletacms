<?php
/**
 * @var Kaleta\Core\Settings $web
 * @var list<array<string, mixed>> $novinky
 * @var string $adresa  absolute url of the site with a trailing slash
 * @var callable(string): string $odkaz  absolute url of a news item by its slug
 */
echo '<?xml version="1.0" encoding="utf-8"?>' . "\n";
?>
<rss version="2.0">
<channel>
	<title><?= e($web->get('site_name')) ?></title>
	<link><?= e($adresa) ?></link>
	<description><?= e($web->get('site_description')) ?></description>
	<language><?= e(\Kaleta\Core\Language::tag(\Kaleta\Core\Language::code())) ?></language>
	<generator>Kaleta <?= e(KALETA_VERSION) ?></generator>
<?php foreach ($novinky as $c): ?>
	<item>
		<title><?= e($c['titulek']) ?></title>
		<link><?= e($odkaz((string) $c['seo_link'])) ?></link>
		<guid isPermaLink="false">novinka-<?= (int) $c['idc'] ?></guid>
		<pubDate><?= e(date(DATE_RSS, strtotime($c['datum']))) ?></pubDate>
		<category><?= e($c['tema_jm']) ?></category>
		<description><?= e($c['uvod']) ?></description>
	</item>
<?php endforeach ?>
</channel>
</rss>
