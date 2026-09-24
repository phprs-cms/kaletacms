<?php
/**
 * @var Kaleta\Core\Settings $web
 * @var list<array<string, mixed>> $novinky
 * @var string $adresa  absolutní adresa webu s koncovým lomítkem
 */
echo '<?xml version="1.0" encoding="utf-8"?>' . "\n";
?>
<rss version="2.0">
<channel>
	<title><?= e($web->get('nazev_webu')) ?></title>
	<link><?= e($adresa) ?></link>
	<description><?= e($web->get('popis_webu')) ?></description>
	<language><?= e(\Kaleta\Core\Jazyk::kod()) ?></language>
	<generator>Kaleta <?= e(KALETA_VERSION) ?></generator>
<?php foreach ($novinky as $c): ?>
	<item>
		<title><?= e($c['titulek']) ?></title>
		<link><?= e($adresa . 'novinky/' . $c['seo_link']) ?></link>
		<guid isPermaLink="false">novinka-<?= (int) $c['idc'] ?></guid>
		<pubDate><?= e(date(DATE_RSS, strtotime($c['datum']))) ?></pubDate>
		<category><?= e($c['tema_jm']) ?></category>
		<description><?= e($c['uvod']) ?></description>
	</item>
<?php endforeach ?>
</channel>
</rss>
