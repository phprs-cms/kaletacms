<?php

declare(strict_types=1);

/* ---------- 3.0: importers on the common base – Joomla, Drupal (Import\Fetch) and Webflow ---------- */
$joomla = new Kaleta\Import\Joomla(dirname(__DIR__, 3) . '/tools/fixtures/joomla-fetch.json');
$joomlaRecords = iterator_to_array($joomla->read());
check('3.0 Joomla: authors, categories and tags first, then the articles; the trashed one is skipped, state 0 is a draft, a future publish_up is scheduled, archived is published', $kinds($joomlaRecords),
    ['Author:42', 'Author:43', 'Category:2', 'Category:8', 'Category:9', 'Tag:2', 'Tag:3', 'Post:post/published', 'Post:post/draft', 'Post:post/scheduled', 'Post:post/published']);
check('3.0 Joomla: the article – intro and full text with the Read more mark, relative images and links made absolute, the featured image without #joomlaImage, the category and tag keys, the best-guess old address through the category path, metadesc, the author', [
    $joomlaRecords[7]->oldUrl, $joomlaRecords[7]->featureImageUrl, $joomlaRecords[7]->categoryKeys, $joomlaRecords[7]->tagKeys, $joomlaRecords[7]->authorKey, $joomlaRecords[7]->seoDescription, $joomlaRecords[7]->publishedAt,
    str_contains($joomlaRecords[7]->html, "</p>\n<!--more-->\n<p>"), str_contains($joomlaRecords[7]->html, 'src="https://old.example/images/blog/oven.jpg"'), str_contains($joomlaRecords[7]->html, 'href="https://old.example/blog/news/15-summer-market"'),
    $joomlaRecords[9]->oldUrl, $joomlaRecords[10]->oldUrl, $joomlaRecords[4]->parent],
    ['/blog/news/12-hello-from-the-bakery', 'https://old.example/images/blog/oven.jpg', ['9'], ['2'], '42', 'Opening day of the bakery.', '2024-03-10 09:00:00', true, true, true, '/blog/15-summer-market', '/uncategorised/16-archived-thoughts', '8']);
check('3.0 Joomla: the site address from the fetched file, read($skip) continues with the same keys, no e-mails of the users', [$joomla->site(), array_keys(iterator_to_array($joomla->read(9))), $joomlaRecords[0]->email, $joomlaRecords[0]->name], [['nazev' => '', 'adresa' => 'https://old.example'], [9, 10], '', 'Marta Editor']);
check('3.0 Joomla::imagePath and the API page: items with attributes only, the next link; a non-API answer is refused', [
    Kaleta\Import\Joomla::imagePath('images/a.jpg#joomlaImage://local-images/a.jpg?width=1'), Kaleta\Import\Joomla::imagePath('images/b.jpg'),
    Kaleta\Import\Joomla::page(['links' => ['self' => 'a', 'next' => 'https://old.example/api/index.php/v1/content/articles?page[offset]=2'], 'data' => [['id' => '1', 'attributes' => []], 'junk', ['id' => '2']]], 'articles'),
    (function (): string { try { Kaleta\Import\Joomla::page(['foo' => 1], 'articles'); return 'accepted'; } catch (RuntimeException $e) { return 'refused'; } })(),
    Kaleta\Import\Joomla::headers('abc'), Kaleta\Import\Joomla::headers(''), Kaleta\Import\Joomla::firstPage('https://old.example/', 'categories')],
    ['images/a.jpg', 'images/b.jpg', [[['id' => '1', 'attributes' => []]], [], 'https://old.example/api/index.php/v1/content/articles?page[offset]=2'], 'refused',
        ['Accept: application/vnd.api+json', 'X-Joomla-Token: abc'], ['Accept: application/vnd.api+json'], 'https://old.example/api/index.php/v1/content/categories?page[offset]=0&page[limit]=50']);
$joomlaOk = true;
try {
    (new Kaleta\Import\Joomla(dirname(__DIR__, 3) . '/tools/fixtures/drupal-fetch.json'))->verify();
    $joomlaOk = false;
} catch (RuntimeException) {
}
check('3.0 Joomla: a Drupal fetch is refused', $joomlaOk, true);

$drupal = new Kaleta\Import\Drupal(dirname(__DIR__, 3) . '/tools/fixtures/drupal-fetch.json');
$drupalRecords = iterator_to_array($drupal->read());
check('3.0 Drupal: the author and the tags from the included resources (once, though every page includes them), articles as posts, the unpublished node a draft, the basic page a page', $kinds($drupalRecords),
    ['Author:0a1b2c3d-00aa-4000-8000-0000000000aa', 'Tag:0a1b2c3d-00bb-4000-8000-0000000000b1', 'Tag:0a1b2c3d-00bb-4000-8000-0000000000b2', 'Post:post/published', 'Post:post/published', 'Post:post/draft', 'Post:page/published']);
check('3.0 Drupal: the article – body.processed with the file address made absolute, the image through include, the tag keys, the author, the metatag description, the summary, path.alias as the old address and slug; a node without an alias → /node/nid', [
    $drupalRecords[3]->slug, $drupalRecords[3]->oldUrl, $drupalRecords[3]->featureImageUrl, $drupalRecords[3]->tagKeys, $drupalRecords[3]->authorKey, $drupalRecords[3]->seoDescription, $drupalRecords[3]->excerpt, $drupalRecords[3]->language,
    str_contains($drupalRecords[3]->html, 'src="https://old.example/sites/default/files/2024-03/oven.jpg"'), $drupalRecords[5]->oldUrl, $drupalRecords[6]->oldUrl, $drupalRecords[1]->slug, $drupalRecords[2]->slug, $drupalRecords[0]->name],
    ['hello-from-drupal', '/blog/hello-from-drupal', 'https://old.example/sites/default/files/2024-03/oven.jpg', ['0a1b2c3d-00bb-4000-8000-0000000000b1'], '0a1b2c3d-00aa-4000-8000-0000000000aa', 'Welcome text for the search engines.', 'A warm welcome.', 'en',
        true, '/node/3', '/about', 'sourdough', 'events', 'Marta Editor']);
check('3.0 Drupal: the skipped step is a footnote, Basic for user:password and Bearer otherwise, the API page with included resources and links.next.href', [
    count($drupal->notes()), Kaleta\Import\Drupal::headers('me:secret')[1], Kaleta\Import\Drupal::headers('tok')[1], Kaleta\Import\Drupal::headers(''),
    Kaleta\Import\Drupal::page(['jsonapi' => ['version' => '1.0'], 'data' => [['type' => 'node--article', 'id' => 'x', 'attributes' => []], ['type' => 'bad']], 'included' => [['type' => 'file--file', 'id' => 'f', 'attributes' => []]], 'links' => ['next' => ['href' => 'https://old.example/jsonapi/node/article?page[offset]=2']]], 'articles'),
    (function (): string { try { Kaleta\Import\Drupal::page(['data' => []], 'articles'); return 'accepted'; } catch (RuntimeException $e) { return 'refused'; } })()],
    [4, 'Authorization: Basic ' . base64_encode('me:secret'), 'Authorization: Bearer tok', ['Accept: application/vnd.api+json'],
        [[['type' => 'node--article', 'id' => 'x', 'attributes' => []]], [['type' => 'file--file', 'id' => 'f', 'attributes' => []]], 'https://old.example/jsonapi/node/article?page[offset]=2'], 'refused']);

$webflow = new Kaleta\Import\Webflow(dirname(__DIR__, 3) . '/tools/fixtures/webflow-blog.csv', 'https://www.example.com/blog/');
$webflow->verify();
$webflowRecords = iterator_to_array($webflow->read());
check('3.0 Webflow: the category and the tags of a row come before it (on first sight), Draft and Archived rows are drafts', $kinds($webflowRecords),
    ['Category:c:recipes', 'Tag:t:sourdough', 'Tag:t:spring', 'Post:post/published', 'Category:c:events', 'Tag:t:events', 'Post:post/draft', 'Post:post/draft']);
check('3.0 Webflow: the row – Item ID as the key, the rich text, the summary, the main image, the dates without the parenthesised zone name, the old address under the entered collection folder; references as slugs with names made from them', [
    $webflowRecords[3]->key, $webflowRecords[3]->title, $webflowRecords[3]->oldUrl, $webflowRecords[3]->featureImageUrl, $webflowRecords[3]->excerpt, $webflowRecords[3]->publishedAt, $webflowRecords[3]->categoryKeys, $webflowRecords[3]->tagKeys,
    str_contains($webflowRecords[3]->html, '<img src="https://uploads-ssl.webflow.com/65a0/65a0-oven.png"'), $webflowRecords[6]->publishedAt, $webflowRecords[7]->oldUrl, $webflowRecords[0]->name, $webflowRecords[0]->slug, array_keys(iterator_to_array($webflow->read(6)))],
    ['65a0000000000000000000a1', 'Spring sourdough', 'https://www.example.com/blog/spring-sourdough', 'https://uploads-ssl.webflow.com/65a0/65a0-oven.png', 'A spring recipe for sourdough.', 'Tue Mar 05 2024 10:00:00 GMT+0000', ['c:recipes'], ['t:sourdough', 't:spring'],
        true, 'Mon Apr 01 2024 08:00:00 GMT+0000', 'https://www.example.com/blog/old-news', 'Recipes', 'recipes', [6, 7]]);
check('3.0 Webflow helpers: references, names from slugs, dates; the site is not in the file', [Kaleta\Import\Webflow::references('sourdough; Spring ;sourdough;'), Kaleta\Import\Webflow::nameFromSlug('cold-rise_bread'), Kaleta\Import\Webflow::date('nonsense (Zone)'), $webflow->site(), $webflow->imagesFromAnyHost()],
    [['sourdough', 'spring'], 'Cold rise bread', '', ['nazev' => '', 'adresa' => ''], true]);
$webflowOk = true;
try {
    (new Kaleta\Import\Webflow(dirname(__DIR__, 3) . '/tools/fixtures/ghost-export.json'))->verify();
    $webflowOk = false;
} catch (RuntimeException) {
}
check('3.0 Webflow: a file without the Name and Slug columns is refused', $webflowOk, true);

// the fetcher: the URL rules (pure and with the resolved address), the file name, the step plan
check('3.0 Fetch::allowedUrl – the old site’s domain (with www), http(s), standard ports, no user name', array_map(fn (string $u): bool => Kaleta\Import\Fetch::allowedUrl($u, 'https://old.example'),
    ['http://www.old.example/api/index.php/v1/content/articles', 'https://other.example/api', 'https://old.example:8443/api', 'https://u@old.example/api', 'ftp://old.example/api', 'https://old.example/jsonapi?page[offset]=50']), [true, false, false, false, false, true]);
check('3.0 Fetch::allowedSite – internal, loopback, link-local and private addresses are refused', array_map(Kaleta\Import\Fetch::allowedSite(...), ['http://10.0.0.5', 'http://127.0.0.1', 'http://[::1]/', 'http://169.254.169.254', 'http://192.168.1.1/', 'http://100.64.0.1', 'http://0.0.0.0']), [false, false, false, false, false, false, false]);
check('3.0 Fetch: the file name from the domain, the plan keeps the required first step and known ticked steps in the system’s order, the skeleton is not done', [
    Kaleta\Import\Fetch::fileName('joomla', 'https://www.Old-Site.example/'), Kaleta\Import\Sources::keyOfFile('joomla-old-site-example.json'), Kaleta\Import\Sources::keyOfFile('webflow-blog.csv'), array_keys(Kaleta\Import\Sources::remote()),
    Kaleta\Import\Fetch::state(Kaleta\Import\Joomla::class, 'https://old.example/', ['tags', 'bogus', 'articles'])['kroky'], Kaleta\Import\Fetch::state(Kaleta\Import\Drupal::class, 'https://old.example', [])['kroky'],
    Kaleta\Import\Fetch::skeleton('drupal', 'https://old.example/')['kaleta_fetch']['done'], Kaleta\Import\Fetch::sessionKey('a') === Kaleta\Import\Fetch::sessionKey('a'), Kaleta\Import\Fetch::sessionKey('a') !== Kaleta\Import\Fetch::sessionKey('b')],
    ['joomla-old-site-example.json', 'joomla', 'webflow', ['joomla', 'drupal'], ['articles', 'tags'], ['articles'], false, true, true]);
$halfFetched = tempnam(sys_get_temp_dir(), 'kaleta-fetch');
file_put_contents($halfFetched, json_encode(Kaleta\Import\Fetch::skeleton('joomla', 'https://old.example')));
$halfOk = 'accepted';
try {
    (new Kaleta\Import\Joomla($halfFetched))->verify();
} catch (RuntimeException $e) {
    $halfOk = $e->getMessage();
}
unlink($halfFetched);
check('3.0 Fetch: a file whose fetch did not finish is refused by the Source', $halfOk, 'The fetch from the site did not finish. Start it again.');


