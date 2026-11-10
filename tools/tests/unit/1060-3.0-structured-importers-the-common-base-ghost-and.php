<?php

declare(strict_types=1);

/* ---------- 3.0: structured importers – the common base, Ghost and Blogger (Import\…) ---------- */
$ghostPath = dirname(__DIR__, 3) . '/tools/fixtures/ghost-export.json';
$bloggerPath = dirname(__DIR__, 3) . '/tools/fixtures/blogger-export.xml';
$kinds = fn (iterable $records): array => array_map(fn (object $r): string => substr(strrchr(get_class($r), '\\'), 1) . ':' . ($r instanceof Kaleta\Import\Post ? $r->type . '/' . $r->status : $r->key), iterator_to_array($records));
$ghost = new Kaleta\Import\Ghost($ghostPath, 'https://old.example');
$ghost->verify();
$ghostRecords = iterator_to_array($ghost->read());
check('3.0 Ghost: authors and public tags come first, then posts and pages with their status; the internal tag is skipped', $kinds($ghostRecords),
    ['Author:u1', 'Tag:t1', 'Tag:t2', 'Post:post/published', 'Post:post/draft', 'Post:page/published', 'Post:post/scheduled']);
$kiln = $ghostRecords[3];
check('3.0 Ghost: the post – primary tag as the category, the other tag as a tag, __GHOST_URL__ resolved, meta from posts_meta, old address /slug/', [
    $kiln->title, $kiln->categoryKeys, $kiln->tagKeys, $kiln->featureImageUrl, $kiln->seoTitle, $kiln->seoDescription, $kiln->oldUrl, $kiln->excerpt, $kiln->authorKey, str_contains($kiln->html, 'https://old.example/img/team.png')],
    ['Firing the first kiln', ['t1'], ['t2'], 'https://old.example/img/team.png', 'Firing the first kiln – Clay Notes', 'How the first firing of our new kiln went.', '/firing-the-first-kiln/', 'Fourteen hours, one kiln, no cracks.', 'u1', true]);
check('3.0 Ghost: a post with only a lexical document is rendered (heading, paragraphs, bold) and the unknown card is a warning',
    [$ghostRecords[4]->html, $ghostRecords[4]->warnings], ["<h2>Celadon</h2>\n<p>Feldspar, silica and a little <strong>iron</strong>.</p>\n<p>Fire in reduction.</p>\n", ['block:bookmark']]);
check('3.0 Ghost: read($skip) continues with the same keys', array_keys(iterator_to_array($ghost->read(5))), [5, 6]);
check('3.0 Ghost: site name from the settings, address from the administrator', $ghost->site(), ['nazev' => 'Clay Notes', 'adresa' => 'https://old.example']);
$ghostOk = true;
try {
    (new Kaleta\Import\Ghost($bloggerPath))->verify();
    $ghostOk = false;
} catch (RuntimeException) {
}
check('3.0 Ghost: a file that is not a Ghost export is refused', $ghostOk, true);

$blogger = new Kaleta\Import\Blogger($bloggerPath);
$blogger->verify();
$bloggerRecords = iterator_to_array($blogger->read());
check('3.0 Blogger: the author and the labels come before the first post that uses them; settings, template and the comment are skipped', $kinds($bloggerRecords),
    ['Author:http://www.blogger.com/profile/0000000000000000001', 'Post:page/published', 'Tag:vegetables', 'Tag:spring', 'Post:post/published', 'Tag:compost', 'Post:post/draft']);
$beds = $bloggerRecords[4];
check('3.0 Blogger: the post – labels as tags, old address from link rel=alternate, slug from it, thumbnail at full size, dates', [
    $beds->title, $beds->tagKeys, $beds->oldUrl, $beds->slug, $beds->featureImageUrl, $beds->publishedAt, str_contains($beds->html, '<img'), $beds->authorKey],
    ['Planting the first beds', ['vegetables', 'spring'], 'http://127.0.0.1:65000/2019/05/planting-first-beds.html', 'planting-first-beds', 'http://127.0.0.1:65000/s1600/team.png', '2019-05-14T08:30:00.000+02:00', true, 'http://www.blogger.com/profile/0000000000000000001']);
check('3.0 Blogger: app:draft = a draft without an old address; the author’s noreply address is not an e-mail', [$bloggerRecords[6]->status, $bloggerRecords[6]->oldUrl, $bloggerRecords[0]->email], ['draft', '', '']);
check('3.0 Blogger: site from the feed', $blogger->site(), ['nazev' => 'Garden Diary', 'adresa' => 'http://127.0.0.1:65000']);
check('3.0 Blogger::fullSize', array_map(Kaleta\Import\Blogger::fullSize(...), ['https://h.example/a/s72-c/x.png', 'https://h.example/a/w72-h72-p-k-no-nu/x.png', 'https://h.example/a/x.png', '']),
    ['https://h.example/a/s1600/x.png', 'https://h.example/a/s1600/x.png', 'https://h.example/a/x.png', '']);
$bloggerOk = true;
try {
    (new Kaleta\Import\Blogger(dirname(__DIR__, 3) . '/tools/fixtures/wordpress-sample.xml'))->verify();
    $bloggerOk = false;
} catch (RuntimeException) {
}
check('3.0 Blogger: a WordPress export is refused', $bloggerOk, true);

// the common base: the preview of a whole file in one pass, the mapping, slug collisions, the registry
$ghostState = Kaleta\Import\Batch::newState('ghost-clay.json');
Kaleta\Import\Batch::analyze($ghostState, 30, $ghostPath);
check('3.0 Batch::analyze – counts, the first titles, the dictionary for later batches and the Ghost footnotes', [
    $ghostState['faze'], $ghostState['celkem'], $ghostState['prehled']['clanky'], $ghostState['prehled']['stranky'], $ghostState['prehled']['stitky'], $ghostState['prehled']['tituly']['page'],
    $ghostState['prehled']['bloky'], isset($ghostState['prehled']['varovani']['no_site_url']), $ghostState['slovnik']['stitky']['t1']['nazev'], count($ghostState['prehled']['poznamky'])],
    ['nahled', 7, ['published' => 1, 'draft' => 1, 'scheduled' => 1], ['published' => 1], 2, ['About the workshop'], ['bookmark' => 1], true, 'Workshop', 3]);
$preview = Kaleta\Import\Preview::empty();
foreach ([new Kaleta\Import\Post('1', 'post', 'Lávka přes Bystřinu', 'lavka', '<p>a</p>'), new Kaleta\Import\Post('2', 'post', 'Lávka', 'lavka', '<p>b</p>'),
    new Kaleta\Import\Post('3', 'page', 'Lávka', 'lavka', '<p>c</p>'), new Kaleta\Import\Post('4', 'post', 'Bez adresy', '', '<img src="/relative.png">', featureImageUrl: 'data:image/png;base64,x')] as $r) {
    Kaleta\Import\Preview::tally($preview, $r);
}
Kaleta\Import\Preview::finish($preview, $blogger);
check('3.0 Preview: two posts with one slug are a duplicate, a page with the same slug is not; images without an absolute address are counted', $preview['varovani'], ['missing_images' => 2, 'duplicate_slugs' => 1]);
check('3.0 Mapping::normalize – unknown choices fall back, authors only to existing users, the language only to a version the site has, the address gets https', Kaleta\Import\Mapping::normalize(
    ['posts' => 'skip', 'pages' => 'nonsense', 'categories' => 'tag', 'authors' => ['u1' => '7', 'u2' => 9, 'u3' => 'x'], 'language' => 'fr', 'drafts' => '', 'default_category' => '-3', 'site_url' => 'old.example/'], ['de'], [7]),
    ['posts' => 'skip', 'pages' => 'page', 'categories' => 'tag', 'tags' => 'tag', 'authors' => ['u1' => 7], 'language' => '', 'drafts' => false, 'builder' => true, 'redirects' => true, 'default_category' => 0, 'site_url' => 'https://old.example']);
check('3.0 Batch: status, dates and the source label', [Kaleta\Import\Batch::status('scheduled'), Kaleta\Import\Batch::status('draft'), Kaleta\Import\Batch::status('sent'),
    Kaleta\Import\Batch::date('2024-03-10T09:00:00.000Z'), Kaleta\Import\Batch::date('', 1789000000), Kaleta\Import\Batch::label('ghost', 'https://www.Old.example/'), Kaleta\Import\Batch::label('blogger', '')],
    [['visible' => 1], ['visible' => 0], null, date('Y-m-d H:i:s', strtotime('2024-03-10T09:00:00.000Z')), date('Y-m-d H:i:s', 1789000000), 'ghost:old.example', 'blogger']);
check('3.0 Sources and file names: the key from the file name, only known systems and extensions, safe upload names', [
    array_keys(Kaleta\Import\Sources::all()), Kaleta\Import\Sources::keyOfFile('ghost-my-blog.json'), Kaleta\Import\Sources::keyOfFile('ghost-my-blog.xml'), Kaleta\Import\Sources::keyOfFile('export.json'),
    Kaleta\Import\Batch::uploadName('blogger', 'Můj blog (2024).xml'), Kaleta\Import\Batch::uploadName('ghost', 'clay.notes.JSON'), Kaleta\Import\Batch::isValidName('../ghost-x.json'), Kaleta\Import\Batch::isValidName('blogger-x.xml')],
    [['ghost', 'blogger', 'joomla', 'drupal', 'webflow'], 'ghost', null, null, 'blogger-muj-blog-2024.xml', 'ghost-clay-notes.json', false, true]);


