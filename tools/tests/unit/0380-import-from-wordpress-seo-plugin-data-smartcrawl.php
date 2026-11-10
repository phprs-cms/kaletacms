<?php

declare(strict_types=1);

/* ---------- import from WordPress: SEO plugin data (SmartCrawl, Yoast SEO, Rank Math) ---------- */
check('WpSoubor: čte jen meta klíče SEO pluginů', [array_keys($wpItems[0]['meta']), $wpItems[3]['meta']], [['_wds_title', '_wds_metadesc', '_wds_meta-robots-noindex'], []]);
check('WpImport náhled: SEO data po pluginech (výchozí vzor Yoastu se nepočítá)', $wpState['prehled']['seo'], [
    'SmartCrawl' => ['title' => 2, 'description' => 2, 'noindex' => 0, 'canonical' => 1],
    'Yoast SEO' => ['title' => 0, 'description' => 1, 'noindex' => 1, 'canonical' => 0],
    'Rank Math' => ['title' => 1, 'description' => 1, 'noindex' => 1, 'canonical' => 1],
]);
$wpSeoContext = ['title' => 'Lávka přes Bystřinu', 'sitename' => 'Podhorský zpravodaj', 'sitedesc' => 'Zprávy z údolí', 'excerpt' => 'Po roce oprav.', 'category' => 'Z radnice'];
check('WpSeo::raw: první plugin s vyplněnou hodnotou; Yoast 2 = indexovat', Kaleta\Core\WpSeo::raw(['_yoast_wpseo_meta-robots-noindex' => '2', '_yoast_wpseo_title' => ' T ']), ['plugin' => 'Yoast SEO', 'title' => 'T', 'description' => '', 'noindex' => false, 'canonical' => '']);
check('WpSeo::raw: bez SEO meta', Kaleta\Core\WpSeo::raw(['_thumbnail_id' => '5'])['plugin'], '');
check('WpSeo::robotsNoindex: serializované pole Rank Math jen jako text', array_map(Kaleta\Core\WpSeo::robotsNoindex(...), ['a:2:{i:0;s:7:"noindex";i:1;s:8:"nofollow";}', 'a:1:{i:0;s:5:"index";}', 'a:1:{i:0;s:12:"noimageindex";}', 'noindex,nofollow', 'O:8:"stdClass":0:{}', '']), [true, false, false, true, false, false]);
check('WpSeo::isDefaultPattern: jen proměnné a oddělovače = výchozí vzor pluginu', array_map(Kaleta\Core\WpSeo::isDefaultPattern(...), ['%%title%% %%sep%% %%sitename%%', '%%title%% %%page%% %%sep%% %%sitename%%', '%title% %sep% %sitename%', '%%title%% | %%sitename%%', '%%title%%', '', 'Blog – %%sitename%%', 'Nabídka %%title%%']), [true, true, true, true, true, true, false, false]);
check('WpSeo::title: proměnné Yoastu a SmartCrawlu se doplní, oddělovač je pomlčka', Kaleta\Core\WpSeo::title('Lávka znovu otevřena %%sep%% %%sitename%%', $wpSeoContext), 'Lávka znovu otevřena – Podhorský zpravodaj');
check('WpSeo::title: proměnné Rank Math', Kaleta\Core\WpSeo::title('%title% – fotografie %sep% %sitename%', $wpSeoContext), 'Lávka přes Bystřinu – fotografie – Podhorský zpravodaj');
check('WpSeo::title: výchozí vzor se neimportuje', [Kaleta\Core\WpSeo::title('%%title%% %%sep%% %%sitename%%', $wpSeoContext), Kaleta\Core\WpSeo::title('%title% %page% %sep% %sitename%', $wpSeoContext)], ['', '']);
check('WpSeo::title: stránkování a datum zmizí i s oddělovačem navíc', Kaleta\Core\WpSeo::title('%%title%% %%page%% – %%currentyear%% – Blog', $wpSeoContext), 'Lávka přes Bystřinu – ' . date('Y') . ' – Blog');
check('WpSeo::title: odstraněná proměnná nenechá dvojitý oddělovač', Kaleta\Core\WpSeo::title('%%title%% %%sep%% %%page%% %%sep%% Blog', $wpSeoContext), 'Lávka přes Bystřinu – Blog');
check('WpSeo::title: neznámá proměnná = titulek se zahodí, ne rozbitý', [Kaleta\Core\WpSeo::title('%%title%% %%sep%% %%neznama_promenna%%', $wpSeoContext), Kaleta\Core\WpSeo::resolve('%%title%% %%neznama%%', $wpSeoContext)], ['', null]);
check('WpSeo::title: vlastní pole (cf_) a termy (ct_) se jen odstraní', Kaleta\Core\WpSeo::title('Nabídka %%title%% %%cf_moje_pole%% %%ct_oblast%%', $wpSeoContext), 'Nabídka Lávka přes Bystřinu');
check('WpSeo::title: shodný s titulkem příspěvku se neukládá', Kaleta\Core\WpSeo::title('%%title%%%%cf_x%%', $wpSeoContext), '');
check('WpSeo::title: délka podle sloupce', mb_strlen(Kaleta\Core\WpSeo::title(str_repeat('ž', 300), $wpSeoContext, 200)), 200);
check('WpSeo::description: výtah a hlavní kategorie', Kaleta\Core\WpSeo::description('%%excerpt%% Více v rubrice %%primary_category%%.', $wpSeoContext), 'Po roce oprav. Více v rubrice Z radnice.');
check('WpSeo::description: jen %%excerpt%% je výchozí vzor – web si popis sestaví sám', Kaleta\Core\WpSeo::description('%%excerpt%%', $wpSeoContext), '');
check('WpSeo::description: značky a entity pryč', Kaleta\Core\WpSeo::description('Sýr &amp; <b>víno</b> v %sitename%', $wpSeoContext), 'Sýr & víno v Podhorský zpravodaj');
check('WpSeo::keys: jeden seznam klíčů ze všech pluginů', [count(Kaleta\Core\WpSeo::keys()), in_array('rank_math_robots', Kaleta\Core\WpSeo::keys(), true)], [12, true]);

